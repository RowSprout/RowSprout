<?php

namespace RowSprout\Blocks;

use RowSprout\Core\Conditions\ConditionalNotes;
use RowSprout\Core\Conditions\ConditionalOutput;
use RowSprout\Core\Conditions\ConditionEvaluator;
use RowSprout\Core\Conditions\RuleSet;
use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "If / then" for the block editor: rowsprout/conditional holds the rules
 * (attribute "rules", the shape of Core\Conditions\RuleSet, the same as the
 * Elementor widget) and two fixed inner blocks, rowsprout/conditional-then and
 * rowsprout/conditional-else, each holding any blocks. Dynamic: the page
 * shows the Then or Else blocks through Core\Conditions\ConditionalOutput
 * (source "inner"), decided when the page is shown, like the Elementor
 * widget; rules on today put both in the page for the conditional-content
 * script. The editor side is assets/js/conditional-block.js; the preview
 * note comes from POST rowsprout/v1/templates/<id>/conditions.
 */
final class ConditionalBlock {

	public const NAME = 'rowsprout/conditional';
	public const THEN = 'rowsprout/conditional-then';
	public const ELSE = 'rowsprout/conditional-else';

	private const SCRIPT = 'rowsprout-conditional-block';
	private const STYLE  = 'rowsprout-conditional-block-editor';

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerBlocks' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'addEditorData' ] );
		add_action( 'rest_api_init', [ self::class, 'registerRoute' ] );
		// After RowSprout Pro's (priority 10), which adds the same category.
		add_filter( 'block_categories_all', [ self::class, 'addCategory' ], 20 );
	}

	/**
	 * The "RowSprout" block category (the current page's own data), unless
	 * RowSprout Pro added it already.
	 *
	 * @param array<int, array<string, mixed>> $categories
	 * @return array<int, array<string, mixed>>
	 */
	public static function addCategory( $categories ): array {
		$categories = is_array( $categories ) ? $categories : [];
		if ( ! in_array( 'rowsprout', array_column( $categories, 'slug' ), true ) ) {
			$categories[] = [ 'slug' => 'rowsprout', 'title' => __( 'RowSprout', 'rowsprout' ), 'icon' => null ];
		}

		return $categories;
	}

	public static function registerBlocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$scriptPath = 'assets/js/conditional-block.js';
		$scriptFile = ROWSPROUT_PATH . $scriptPath;
		wp_register_script(
			self::SCRIPT,
			plugins_url( $scriptPath, ROWSPROUT_FILE ),
			[ 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data', 'wp-api-fetch', 'wp-compose' ],
			file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : ROWSPROUT_VERSION,
			true
		);
		wp_set_script_translations( self::SCRIPT, 'rowsprout' );
		// The editor canvas (an iframe): the Then/Else areas and the preview marking.
		wp_register_style( self::STYLE, false, [], ROWSPROUT_VERSION );
		wp_add_inline_style( self::STYLE, self::editorCss() );

		register_block_type(
			self::NAME,
			[
				'api_version'           => 3,
				'title'                 => __( 'If / then', 'rowsprout' ),
				'description'           => __( 'Shows "Then" when the page\'s property values match the rules, else "Else".', 'rowsprout' ),
				'category'              => 'rowsprout',
				'icon'                  => 'randomize',
				'keywords'              => [ 'if', 'then', 'else', 'condition', 'conditional', 'rowsprout' ],
				'editor_script_handles' => [ self::SCRIPT ],
				'editor_style_handles'  => [ self::STYLE ],
				'attributes'            => [
					'rules' => [ 'type' => 'array', 'default' => [ [ 'join' => RuleSet::JOIN_AND ] ] ],
				],
				'supports'              => [
					'html'    => false,
					'align'   => [ 'wide', 'full' ],
					'spacing' => [ 'margin' => true, 'padding' => true ],
				],
				'render_callback'       => [ self::class, 'render' ],
			]
		);
		foreach ( [ self::THEN => __( 'Then', 'rowsprout' ), self::ELSE => __( 'Else', 'rowsprout' ) ] as $name => $title ) {
			register_block_type(
				$name,
				[
					'api_version'           => 3,
					'title'                 => $title,
					'category'              => 'rowsprout',
					'parent'                => [ self::NAME ],
					'editor_script_handles' => [ self::SCRIPT ],
					'supports'              => [ 'html' => false, 'inserter' => false, 'reusable' => false, 'lock' => false ],
				]
			);
		}
	}

	/**
	 * The Then or Else blocks of the current page's group.
	 *
	 * @param array<string, mixed> $attributes
	 */
	public static function render( array $attributes, string $content = '', ?\WP_Block $block = null ): string {
		$areas = [ '' => '', 'else_' => '' ];
		if ( $block !== null ) {
			foreach ( $block->inner_blocks as $inner ) {
				if ( $inner->name === self::THEN ) {
					$areas[''] = $inner->render();
				} elseif ( $inner->name === self::ELSE ) {
					$areas['else_'] = $inner->render();
				}
			}
		}
		$postId     = (int) get_the_ID();
		$templateId = get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0;
		$context    = PageFieldResolver::resolveGroupContext( $postId, $templateId );
		$result     = ConditionalOutput::render(
			[
				'rules'       => (array) ( $attributes['rules'] ?? [] ),
				'source'      => 'inner',
				'else_source' => trim( $areas['else_'] ) === '' ? 'none' : 'inner',
			],
			$context,
			[
				'inner' => static function ( string $prefix ) use ( $areas ): string {
					return $areas[ $prefix ] ?? '';
				},
			]
		);
		if ( $result['html'] === '' ) {
			return '';
		}

		return '<div ' . get_block_wrapper_attributes( [ 'class' => 'rowsprout-conditional-block' ] ) . '>' . $result['html'] . '</div>';
	}

	public static function registerRoute(): void {
		register_rest_route(
			'rowsprout/v1',
			'/templates/(?P<id>\d+)/conditions',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'check' ],
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					$postId = (int) $request['id'];
					return get_post_type( $postId ) === PostTypes::TEMPLATE && current_user_can( 'edit_post', $postId );
				},
				'args'                => [
					'rules'     => [ 'type' => 'array', 'default' => [] ],
					'elseEmpty' => [ 'type' => 'boolean', 'default' => false ],
				],
			]
		);
	}

	/**
	 * The editor note for the template's preview group.
	 */
	public static function check( \WP_REST_Request $request ): \WP_REST_Response {
		$templateId = (int) $request['id'];
		$context    = PageFieldResolver::resolveGroupContext( $templateId, $templateId );
		$decision   = RuleSet::decide( (array) $request->get_param( 'rules' ), $context['group'] ?? null );

		return new \WP_REST_Response(
			[
				'matches' => $context !== null && $decision['matches'],
				'hasGroup' => $context !== null,
				'notes'   => ConditionalNotes::messages( $decision, $context, [ 'else_source' => $request->get_param( 'elseEmpty' ) ? 'none' : 'inner' ] ),
			]
		);
	}

	/**
	 * Data for assets/js/conditional-block.js: the template's properties
	 * with their kind, and the operators per kind.
	 */
	public static function addEditorData(): void {
		$postId   = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0;
		$postType = $postId > 0 ? get_post_type( $postId ) : '';

		$properties = [];
		if ( $postType === PostTypes::TEMPLATE ) {
			foreach ( (array) ( TemplateMeta::get( $postId )['field_types'] ?? [] ) as $definition ) {
				$code = is_array( $definition ) ? (string) ( $definition['code'] ?? '' ) : '';
				if ( $code !== '' ) {
					$properties[] = [
						'value' => $code,
						'label' => (string) ( $definition['label'] ?? $code ),
						'kind'  => ConditionEvaluator::kindOf( (string) ( $definition['type'] ?? '' ) ),
					];
				}
			}
		}
		$operators = [];
		$defaults  = [];
		foreach ( ConditionEvaluator::kinds() as $kind ) {
			foreach ( ConditionEvaluator::operatorsFor( $kind ) as $value => $label ) {
				$operators[ $kind ][] = [ 'value' => $value, 'label' => $label, 'args' => ConditionEvaluator::argCount( $value ) ];
			}
			$defaults[ $kind ] = ConditionEvaluator::defaultOperator( $kind );
		}

		// The sidebar (outside the canvas): the preview note and the rules.
		wp_register_style( self::STYLE . '-panel', false, [], ROWSPROUT_VERSION );
		wp_add_inline_style( self::STYLE . '-panel', '.rowsprout-conditional-panel-note{margin:0 0 12px;padding:6px 10px;border-left:3px solid #93003c;background:#fdf2f6;color:#1d2327;font-size:12px;line-height:1.5}'
			. '.rowsprout-conditional-panel-note div+div{margin-top:4px}'
			. '.rowsprout-conditional-rule{margin:0 0 12px;padding:12px 0 0;border-top:1px solid #e0e0e0}'
			. '.rowsprout-conditional-rule .components-base-control{margin-bottom:8px}' );
		wp_enqueue_style( self::STYLE . '-panel' );

		wp_add_inline_script(
			self::SCRIPT,
			'window.rowsproutConditionalBlock = ' . wp_json_encode(
				[
					'isTemplate' => $postType === PostTypes::TEMPLATE,
					'restPath'   => $postType === PostTypes::TEMPLATE ? '/rowsprout/v1/templates/' . $postId . '/conditions' : '',
					'properties' => $properties,
					'operators'  => $operators,
					'defaults'   => $defaults,
				]
			) . ';',
			'before'
		);
	}

	/**
	 * The editor canvas: Then and Else as labelled areas, the one the preview
	 * group gets marked, and the toolbar's "Then" / "Else" view.
	 */
	private static function editorCss(): string {
		return '.rs-cond>.rs-cond-area{border:1px dashed #c3c4c7;border-radius:4px;padding:8px 12px;margin:0 0 8px}'
			. '.rs-cond-area-label{display:flex;gap:8px;align-items:center;margin:0 0 6px;font:600 11px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:.04em;text-transform:uppercase;color:#757575}'
			. '.rs-cond-area-preview{display:none;padding:1px 6px;border-radius:2px;background:#93003c;color:#fff;letter-spacing:0}'
			. '.rs-cond.rs-cond-preview-then>.rs-cond-area--then,.rs-cond.rs-cond-preview-else>.rs-cond-area--else{border:2px solid #93003c}'
			. '.rs-cond.rs-cond-preview-then>.rs-cond-area--then .rs-cond-area-preview,.rs-cond.rs-cond-preview-else>.rs-cond-area--else .rs-cond-area-preview{display:inline-block}'
			. '.rs-cond.is-showing-then>.rs-cond-area--else,.rs-cond.is-showing-else>.rs-cond-area--then{display:none}'
			. '.rs-cond.is-showing-then>.rs-cond-area,.rs-cond.is-showing-else>.rs-cond-area{border:0;padding:0}'
			. '.rs-cond.is-showing-then>.rs-cond-area>.rs-cond-area-label,.rs-cond.is-showing-else>.rs-cond-area>.rs-cond-area-label{display:none}';
	}
}
