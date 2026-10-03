<?php

namespace RowSprout\Blocks;

use RowSprout\Core\PostTypes;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RowSprout's own blocks for the block editor — only where core blocks plus
 * Block Bindings (Core\BlockBindings\PropertyBindingSource) can't do it:
 *
 * - "Grid" (rowsprout/page-grid, category "RowSprout pages"): the generated pages of
 *   one template; any post type, like the Elementor Grid widget. Same markup
 *   via Core\Grid\PageGridRenderer.
 *
 * The Item List block is RowSprout Pro's, together with the Item-list field
 * type. A Textarea needs no block — a bound Paragraph keeps its line breaks.
 *
 * The Grid is dynamic (rendered here, save() is null), so a page always shows
 * current data; the editor previews them through ServerSideRender. Titles
 * carry no "RowSprout" prefix: the inserter category already says it.
 */
final class BlockRegistry {

	public const CATEGORY_PAGES = 'rowsprout-pages';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerBlocks' ] );
		add_filter( 'block_categories_all', [ self::class, 'addCategories' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'addEditorData' ] );
	}

	/**
	 * @param array<int, array<string, mixed>> $categories
	 * @return array<int, array<string, mixed>>
	 */
	public static function addCategories( $categories ): array {
		$categories = is_array( $categories ) ? $categories : [];
		$categories[] = [ 'slug' => self::CATEGORY_PAGES, 'title' => __( 'RowSprout pages', 'rowsprout' ), 'icon' => null ];

		return $categories;
	}

	public static function registerBlocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$scriptPath = 'assets/js/blocks.js';
		$scriptFile = ROWSPROUT_PATH . $scriptPath;
		wp_register_script(
			'rowsprout-blocks',
			plugins_url( $scriptPath, ROWSPROUT_FILE ),
			[ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-server-side-render', 'wp-i18n', 'wp-data' ],
			file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : ROWSPROUT_VERSION,
			true
		);

		register_block_type(
			'rowsprout/page-grid',
			[
				'api_version'     => 3,
				'title'           => __( 'Grid', 'rowsprout' ),
				'description'     => __( 'The generated pages of one RowSprout template, as a list or as cards.', 'rowsprout' ),
				'category'        => self::CATEGORY_PAGES,
				'icon'            => 'grid-view',
				'keywords'        => [ 'rowsprout', 'pages', 'grid', 'list' ],
				'editor_script_handles' => [ 'rowsprout-blocks' ],
				'attributes'      => [
					'templateId' => [ 'type' => 'string', 'default' => '' ],
					'layout'     => [ 'type' => 'string', 'default' => 'list' ],
					'columns'    => [ 'type' => 'number', 'default' => 3 ],
					'showThumb'  => [ 'type' => 'boolean', 'default' => true ],
					'thumbSize'  => [ 'type' => 'string', 'default' => 'medium' ],
					'titleType'  => [ 'type' => 'string', 'default' => 'parent' ],
					'sortOrder'  => [ 'type' => 'string', 'default' => '' ],
				],
				'supports'        => [
					'html'       => false,
					'align'      => [ 'wide', 'full' ],
					'color'      => [ 'text' => true, 'background' => true, 'link' => true ],
					'typography' => [ 'fontSize' => true ],
					'spacing'    => [ 'margin' => true, 'padding' => true, 'blockGap' => true ],
				],
				'render_callback' => [ self::class, 'renderPageGrid' ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public static function renderPageGrid( array $attributes ): string {
		$columns = max( 1, min( 6, (int) ( $attributes['columns'] ?? 3 ) ) );
		$gap     = 'var(--wp--style--block-gap, 1rem)';

		$grid = \RowSprout\Core\Grid\PageGridRenderer::render(
			[
				'rowsprout_page'      => (string) ( $attributes['templateId'] ?? '' ),
				'layout_type'     => ( $attributes['layout'] ?? 'list' ) === 'cards' ? 'cart' : 'list',
				'show_thumb'      => ! empty( $attributes['showThumb'] ) ? 'yes' : 'no',
				'thumb_size'      => (string) ( $attributes['thumbSize'] ?? 'medium' ),
				'show_title_type' => ( $attributes['titleType'] ?? 'parent' ) === 'child' ? 'child' : 'parent',
				'sort_order'      => in_array( $attributes['sortOrder'] ?? '', [ 'asc', 'desc' ], true ) ? $attributes['sortOrder'] : '',
			],
			'display:grid;grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));gap:' . $gap . ';'
		);

		return '<div ' . get_block_wrapper_attributes( [ 'class' => 'rowsprout-page-grid-block' ] ) . '>' . $grid . '</div>';
	}

	/**
	 * Data for assets/js/blocks.js: the templates for the Grid picker.
	 */
	public static function addEditorData(): void {
		$postId   = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0;
		$postType = $postId > 0 ? get_post_type( $postId ) : '';

		$templates = [];
		foreach ( PageFieldResolver::getTemplateOptionsForSelect() as $value => $label ) {
			$templates[] = [ 'value' => (string) $value, 'label' => (string) $label ];
		}

		wp_add_inline_script(
			'rowsprout-blocks',
			'window.rowsproutBlocks = ' . wp_json_encode(
				[
					'isTemplate'     => $postType === PostTypes::TEMPLATE,
					'templates'      => $templates,
				]
			) . ';',
			'before'
		);
	}
}
