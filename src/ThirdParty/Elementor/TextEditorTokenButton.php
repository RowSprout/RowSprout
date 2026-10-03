<?php

namespace RowSprout\ThirdParty\Elementor;

use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\InsertableTokenFields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "RowSprout property" menu button to the TinyMCE toolbar of Elementor's
 * Text Editor widget while a rowsprout_template is open in Elementor. It inserts
 * the same @code_<code>_<templateId>@ placeholder token the Properties tab
 * shows (PropertyTableRenderer), so the value is filled in at generation time
 * by PageMetaReplicator exactly like a hand-typed token — no new runtime
 * mechanism.
 *
 * How it reaches every Text Editor instance: Elementor renders one hidden
 * wp_editor() ('elementorwpeditor', in Editor_Common_Scripts_Settings::
 * get_wp_editor_config()) and each Text Editor control clones that editor's
 * tinyMCEPreInit settings. That method first calls remove_all_filters() on
 * 'mce_buttons' and 'mce_external_plugins' — but only at priority 10, so
 * these filters use priority 20 to survive. They are added on
 * 'elementor/editor/init', which fires before the editor scripts (and with
 * them the wp_editor config) are built, and only for templates, so the classic
 * WordPress editor and unrelated Elementor documents are untouched.
 *
 * Which properties are offered (and why Textarea / Item-list are not) is
 * decided in Core\Template\InsertableTokenFields, shared with the block
 * editor's rich-text button.
 */
final class TextEditorTokenButton {

	private const PLUGIN_NAME = 'rowsprout_tokens';
	private const BUTTON_NAME = 'rowsprout_token';
	private const PRIORITY    = 20;

	/** @var int */
	private static $templateId = 0;

	public static function register(): void {
		add_action( 'elementor/editor/init', [ self::class, 'onEditorInit' ] );
		add_action( 'current_screen', [ self::class, 'onClassicEditScreen' ] );
	}

	public static function onEditorInit(): void {
		self::enableFor( self::resolveEditedTemplateId() );
	}

	/**
	 * The classic editing screen of a template (the block editor has its own
	 * rich-text button, assets/js/block-editor-token-button.js). Its TinyMCE
	 * settings are also what WPBakery's text editors clone, so the button shows
	 * up there too (ThirdParty\WPBakery\WPBakeryIntegration).
	 *
	 * @param \WP_Screen $screen
	 */
	public static function onClassicEditScreen( $screen ): void {
		if ( ! $screen instanceof \WP_Screen || $screen->base !== 'post' || $screen->post_type !== PostTypes::TEMPLATE || $screen->is_block_editor() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which template is being edited (0 on post-new.php).
		self::enableFor( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, true );
	}

	private static function enableFor( int $templateId, bool $allowNew = false ): void {
		if ( $templateId <= 0 && ! $allowNew ) {
			return;
		}

		self::$templateId = $templateId;

		add_filter( 'mce_external_plugins', [ self::class, 'addPlugin' ], self::PRIORITY );
		add_filter( 'mce_buttons', [ self::class, 'addButton' ], self::PRIORITY );
		add_filter( 'tiny_mce_before_init', [ self::class, 'addSettings' ], self::PRIORITY );
	}

	/**
	 * @param array<string, string> $plugins
	 * @return array<string, string>
	 */
	public static function addPlugin( $plugins ): array {
		$relativePath = 'assets/js/text-editor-token-button.js';
		$scriptFile   = ROWSPROUT_PATH . $relativePath;
		$version      = file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : ROWSPROUT_VERSION;

		$plugins                      = is_array( $plugins ) ? $plugins : [];
		$plugins[ self::PLUGIN_NAME ] = add_query_arg( 'ver', $version, plugins_url( $relativePath, ROWSPROUT_FILE ) );

		return $plugins;
	}

	/**
	 * @param array<int, string> $buttons
	 * @return array<int, string>
	 */
	public static function addButton( $buttons ): array {
		$buttons = is_array( $buttons ) ? $buttons : [];

		// Elementor's rearrangeButtons() moves buttons around named
		// neighbours (e.g. 'blockquote'), so append rather than insert
		// relative to a core button that a site may have removed.
		if ( ! in_array( self::BUTTON_NAME, $buttons, true ) ) {
			$buttons[] = self::BUTTON_NAME;
		}

		return $buttons;
	}

	/**
	 * _WP_Editors::_parse_init() prints a value that starts with '{' and ends
	 * with '}' as a raw JS object, so the JSON below arrives in TinyMCE as an
	 * object (read with editor.getParam()) and is cloned into every Text
	 * Editor instance along with the rest of the base editor's settings.
	 *
	 * @param array<string, mixed> $init
	 * @return array<string, mixed>
	 */
	public static function addSettings( $init ): array {
		$init = is_array( $init ) ? $init : [];

		$settings = wp_json_encode(
			[
				'fields' => InsertableTokenFields::forTemplate( self::$templateId ),
				'i18n'   => [
					'button'      => __( 'Insert RowSprout property', 'rowsprout' ),
					'asText'      => __( 'Insert as text', 'rowsprout' ),
					'asEmailLink' => __( 'Insert as email link', 'rowsprout' ),
					'asLink'      => __( 'Insert as link', 'rowsprout' ),
					'empty'       => __( 'This template has no properties yet', 'rowsprout' ),
				],
			],
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( is_string( $settings ) ) {
			$init['rowsprout_tokens'] = $settings;
		}

		return $init;
	}

	private static function resolveEditedTemplateId(): int {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance->editor ) ) {
			return 0;
		}

		$postId = (int) \Elementor\Plugin::$instance->editor->get_post_id();

		return $postId > 0 && get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0;
	}
}
