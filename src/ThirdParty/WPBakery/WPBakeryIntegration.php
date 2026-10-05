<?php

namespace RowSprout\ThirdParty\WPBakery;

use RowSprout\Core\Grid\PageGridRenderer;
use RowSprout\Core\PostTypes;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPBakery Page Builder (also as bundled with ThemeForest themes, e.g. Salient's
 * js_composer_salient). Everything here only runs when WPBakery is loaded.
 *
 * WPBakery stores a page as shortcodes in post_content and saves through
 * wp_update_post() from both its backend editor (the classic post form) and its
 * frontend editor (admin-ajax vc_save), so SavePost::handleSave() runs as for
 * any classic save; for a post built with WPBakery it also switches the block
 * editor off, so templates get the classic screen with the RowSprout metabox.
 *
 * - Enabled for rowsprout_template by default (vc_set_default_editor_post_types —
 *   only the default; a site that customised WPBakery's Role Manager
 *   post types decides there).
 * - Tokens in shortcode attributes: WPBakery's editor writes " as ``, [ as `{`
 *   and ] as `}` in attribute values, and url-encodes link fields
 *   (url:%40code_…%40|title:…). encodeAttributeValue() is the encoder for
 *   MarkupTokenReplacer, so a replaced value is stored the way WPBakery itself
 *   would have written it.
 * - Elements "Grid" (RowSprout pages) and "Item List" (RowSprout), same output as the
 *   Elementor widget / blocks (PageGridRenderer, ItemListValues).
 * - The inline token button in WPBakery's text editors comes for free: they
 *   clone the classic screen's TinyMCE settings, where
 *   TextEditorTokenButton::onClassicEditScreen() adds it.
 */
final class WPBakeryIntegration {

	public static function register(): void {
		add_action( 'vc_after_init', [ self::class, 'enableForTemplates' ], 99 );
		add_filter( 'vc_check_post_type_validation', [ self::class, 'allowTemplatesByDefault' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'noticeWhenDisabledForTemplates' ] );
		add_action( 'vc_before_init', [ self::class, 'mapElements' ] );
		add_filter( 'rowsprout_shortcode_attribute_encoder', [ self::class, 'provideEncoder' ] );
		add_shortcode( 'rowsprout_page_grid', [ self::class, 'renderGrid' ] );
	}

	private static function isActive(): bool {
		return defined( 'WPB_VC_VERSION' );
	}

	/**
	 * Theme-bundled builds may ignore vc_set_default_editor_post_types():
	 * Salient's js_composer_salient hard-codes its default list (page, post,
	 * portfolio) in Vc_Manager::editorDefaultPostTypes(). So templates are also
	 * allowed through vc_check_post_type()'s own filter — but only while the
	 * Role Manager has no explicit post-type choice for this user's role; an
	 * explicit choice (on, off or a custom list) stays in charge.
	 *
	 * @param bool|null $valid
	 * @param string    $type
	 * @return bool|null
	 */
	public static function allowTemplatesByDefault( $valid, $type ) {
		if ( $valid !== null || $type !== PostTypes::TEMPLATE || ! function_exists( 'vc_user_access' ) ) {
			return $valid;
		}

		$stateKey = vc_user_access()->part( 'post_types' )->getStateKey();

		return array_key_exists( $stateKey, (array) wp_get_current_user()->get_role_caps() ) ? $valid : true;
	}

	/**
	 * A Role Manager post-type list made before RowSprout was installed won't
	 * include templates, and nothing on the template screen would say why
	 * WPBakery's editor is missing there. Shown to people who can change it.
	 */
	public static function noticeWhenDisabledForTemplates(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::isActive() || ! $screen || $screen->base !== 'post' || $screen->post_type !== PostTypes::TEMPLATE
			|| ! current_user_can( 'manage_options' ) || ! function_exists( 'vc_check_post_type' ) || vc_check_post_type( PostTypes::TEMPLATE ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>' . esc_html__( 'WPBakery Page Builder is turned off for RowSprout templates in its Role Manager, so you can\'t design this template with it yet.', 'rowsprout' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=vc-roles' ) ) . '">' . esc_html__( 'Open WPBakery Role Manager', 'rowsprout' ) . '</a>'
			. ' — ' . esc_html__( 'under Post types, add "RowSprout Template".', 'rowsprout' ) . '</p></div>';
	}

	public static function enableForTemplates(): void {
		if ( ! function_exists( 'vc_set_default_editor_post_types' ) || ! function_exists( 'vc_default_editor_post_types' ) ) {
			return;
		}

		vc_set_default_editor_post_types( array_values( array_unique( array_merge( (array) vc_default_editor_post_types(), [ PostTypes::TEMPLATE ] ) ) ) );
	}

	/**
	 * @param mixed $encoder
	 * @return mixed
	 */
	public static function provideEncoder( $encoder ) {
		return self::isActive() ? [ self::class, 'encodeAttributeValue' ] : $encoder;
	}

	/**
	 * @param bool $urlEncoded true when the token was found url-encoded (a link field)
	 */
	public static function encodeAttributeValue( string $value, bool $urlEncoded ): string {
		if ( $urlEncoded ) {
			return rawurlencode( $value );
		}

		return str_replace( [ '"', '[', ']' ], [ '``', '`{`', '`}`' ], $value );
	}

	public static function mapElements(): void {
		if ( ! function_exists( 'vc_lean_map' ) ) {
			return;
		}

		// Lean: the settings (with the template / property lists) are built only
		// when WPBakery needs them, inside the editor request.
		vc_lean_map( 'rowsprout_page_grid', [ self::class, 'gridSettings' ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function gridSettings(): array {
		$templates = [];
		foreach ( PageFieldResolver::getTemplateOptionsForSelect() as $id => $label ) {
			$templates[ (string) $label ] = (string) $id;
		}

		return [
			'name'        => __( 'Grid', 'rowsprout' ),
			'base'        => 'rowsprout_page_grid',
			'category'    => __( 'RowSprout pages', 'rowsprout' ),
			'description' => __( 'The generated pages of one RowSprout template.', 'rowsprout' ),
			'icon'        => 'dashicons dashicons-grid-view',
			'params'      => [
				[ 'type' => 'dropdown', 'heading' => __( 'RowSprout template', 'rowsprout' ), 'param_name' => 'template', 'value' => $templates, 'admin_label' => true ],
				[ 'type' => 'dropdown', 'heading' => __( 'Layout', 'rowsprout' ), 'param_name' => 'layout', 'value' => [ __( 'List', 'rowsprout' ) => 'list', __( 'Cards', 'rowsprout' ) => 'cards' ], 'std' => 'list' ],
				[ 'type' => 'dropdown', 'heading' => __( 'Columns', 'rowsprout' ), 'param_name' => 'columns', 'value' => [ '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6' ], 'std' => '3' ],
				[ 'type' => 'checkbox', 'heading' => __( 'Show thumbnail', 'rowsprout' ), 'param_name' => 'show_thumb', 'value' => [ __( 'Yes', 'rowsprout' ) => 'yes' ], 'std' => 'yes', 'dependency' => [ 'element' => 'layout', 'value' => 'cards' ] ],
				[ 'type' => 'dropdown', 'heading' => __( 'Thumbnail size', 'rowsprout' ), 'param_name' => 'thumb_size', 'value' => [ __( 'Thumbnail', 'rowsprout' ) => 'thumbnail', __( 'Medium', 'rowsprout' ) => 'medium', __( 'Large', 'rowsprout' ) => 'large', __( 'Full', 'rowsprout' ) => 'full' ], 'std' => 'medium', 'dependency' => [ 'element' => 'layout', 'value' => 'cards' ] ],
				[ 'type' => 'dropdown', 'heading' => __( 'Show title', 'rowsprout' ), 'param_name' => 'title_type', 'value' => [ __( 'Parent title', 'rowsprout' ) => 'parent', __( 'Child title', 'rowsprout' ) => 'child' ], 'std' => 'parent' ],
				[ 'type' => 'dropdown', 'heading' => __( 'Sort items', 'rowsprout' ), 'param_name' => 'sort', 'value' => [ __( 'No sorting', 'rowsprout' ) => '', __( 'Alphabetical (A-Z)', 'rowsprout' ) => 'asc', __( 'Alphabetical (Z-A)', 'rowsprout' ) => 'desc' ] ],
			],
		];
	}

	/**
	 * The template open in WPBakery's backend editor (post.php?post=…) or
	 * frontend editor (post_id / vc_post_id request params).
	 */
	private static function editedTemplateId(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only lookup of which post the editor shows.
		foreach ( [ 'post', 'post_id', 'vc_post_id' ] as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) {
				$postId = absint( $_REQUEST[ $key ] );
				if ( $postId > 0 && get_post_type( $postId ) === PostTypes::TEMPLATE ) {
					return $postId;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 0;
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function renderGrid( $atts ): string {
		$atts    = shortcode_atts( [ 'template' => '', 'layout' => 'list', 'columns' => '3', 'show_thumb' => 'yes', 'thumb_size' => 'medium', 'title_type' => 'parent', 'sort' => '' ], (array) $atts, 'rowsprout_page_grid' );
		$columns = max( 1, min( 6, (int) $atts['columns'] ) );

		return '<div class="rowsprout-page-grid-wpb">' . PageGridRenderer::render(
			[
				'rowsprout_page'      => (string) $atts['template'],
				'layout_type'     => $atts['layout'] === 'cards' ? 'cart' : 'list',
				'show_thumb'      => $atts['show_thumb'] === 'yes' ? 'yes' : 'no',
				'thumb_size'      => (string) $atts['thumb_size'],
				'show_title_type' => $atts['title_type'] === 'child' ? 'child' : 'parent',
				'sort_order'      => in_array( $atts['sort'], [ 'asc', 'desc' ], true ) ? $atts['sort'] : '',
			],
			'display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));gap:1rem;'
		) . '</div>';
	}
}
