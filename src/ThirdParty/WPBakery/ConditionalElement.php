<?php

namespace RowSprout\ThirdParty\WPBakery;

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
 * "If / then" for WPBakery, on Core\Conditions like the Elementor widget and
 * the block: the container [rowsprout_if rules="…"] holds the fixed
 * containers [rowsprout_then] and [rowsprout_else], each holding any
 * elements. "rules" is a param_group (WPBakery stores it url-encoded JSON;
 * vc_param_group_parse_atts() reads it) of rules in the RuleSet shape:
 * join, rule_property, op_<kind> (one dropdown per kind the template has,
 * shown for the properties of that kind), value, value2.
 *
 * On the page the shortcode shows the Then or the Else elements through
 * ConditionalOutput (source "inner"), decided when the page is shown; rules
 * on today use the conditional-content script. In WPBakery's frontend
 * editor both are shown, labelled, so both can be edited. The edit window
 * gets the preview note (a param that asks POST
 * rowsprout/v1/templates/<id>/conditions, see ConditionalBlock).
 */
final class ConditionalElement {

	public const IF_TAG   = 'rowsprout_if';
	public const THEN_TAG = 'rowsprout_then';
	public const ELSE_TAG = 'rowsprout_else';

	/** @var array<int, bool> In the frontend editor: whether the preview group matches, per open [rowsprout_if]. */
	private static $previewMatches = [];

	public static function register(): void {
		add_action( 'vc_before_init', [ self::class, 'mapElements' ] );
		add_action( 'vc_after_init', [ self::class, 'declareContainers' ] );
		add_action( 'vc_backend_editor_enqueue_js_css', [ self::class, 'enqueueEditor' ] );
		add_action( 'vc_frontend_editor_enqueue_js_css', [ self::class, 'enqueueEditor' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueueEditablePage' ] );
		add_shortcode( self::IF_TAG, [ self::class, 'renderIf' ] );
		add_shortcode( self::THEN_TAG, [ self::class, 'renderArea' ] );
		add_shortcode( self::ELSE_TAG, [ self::class, 'renderArea' ] );
	}

	public static function mapElements(): void {
		if ( ! function_exists( 'vc_lean_map' ) ) {
			return;
		}
		if ( function_exists( 'vc_add_shortcode_param' ) ) {
			vc_add_shortcode_param( 'rowsprout_conditional_note', [ self::class, 'noteParam' ] );
		}
		vc_lean_map( self::IF_TAG, [ self::class, 'ifSettings' ] );
		vc_lean_map( self::THEN_TAG, [ self::class, 'thenSettings' ] );
		vc_lean_map( self::ELSE_TAG, [ self::class, 'elseSettings' ] );
	}

	/**
	 * WPBakery's container classes for the three elements (they extend its
	 * own, so only once it has loaded).
	 */
	public static function declareContainers(): void {
		if ( class_exists( '\WPBakeryShortCodesContainer' ) && ! class_exists( '\RowSprout_WPBakery_Conditional_Container' ) ) {
			require_once __DIR__ . '/conditional-containers.php';
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function ifSettings(): array {
		$templateId = self::editedTemplateId();
		$properties = [ __( '-- Select --', 'rowsprout' ) => '' ];
		foreach ( (array) ( TemplateMeta::get( $templateId )['field_types'] ?? [] ) as $definition ) {
			$code = is_array( $definition ) ? (string) ( $definition['code'] ?? '' ) : '';
			if ( $code !== '' ) {
				$properties[ (string) ( $definition['label'] ?? $code ) ] = $code;
			}
		}
		$ruleParams = [
			[
				'type'        => 'dropdown',
				'heading'     => __( 'And / or', 'rowsprout' ),
				'param_name'  => 'join',
				'value'       => [ __( 'and', 'rowsprout' ) => RuleSet::JOIN_AND, __( 'or', 'rowsprout' ) => RuleSet::JOIN_OR ],
				'std'         => RuleSet::JOIN_AND,
				'description' => __( 'With the rule above; not used for the first rule.', 'rowsprout' ),
			],
			[
				'type'        => 'dropdown',
				'heading'     => __( 'Property', 'rowsprout' ),
				'param_name'  => 'rule_property',
				'value'       => $properties,
				'admin_label' => true,
			],
		];
		// One operator list per kind of property, shown for the properties of that kind.
		foreach ( RuleSet::codesByKind( $templateId ) as $kind => $codes ) {
			$ruleParams[] = [
				'type'       => 'dropdown',
				'heading'    => __( 'Operator', 'rowsprout' ),
				'param_name' => 'op_' . $kind,
				// The default first: WPBakery shows the first option for a rule that has none yet.
				'value'      => array_flip( [ ConditionEvaluator::defaultOperator( $kind ) => ConditionEvaluator::operatorsFor( $kind )[ ConditionEvaluator::defaultOperator( $kind ) ] ] + ConditionEvaluator::operatorsFor( $kind ) ),
				'std'        => ConditionEvaluator::defaultOperator( $kind ),
				'dependency' => [ 'element' => 'rule_property', 'value' => $codes ],
			];
		}
		$ruleParams[] = [
			'type'        => 'textfield',
			'heading'     => __( 'Value', 'rowsprout' ),
			'param_name'  => 'value',
			'description' => __( 'For an operator that needs one. A date is written as YYYY-MM-DD.', 'rowsprout' ),
			'dependency'  => [ 'element' => 'rule_property', 'not_empty' => true ],
		];
		$ruleParams[] = [
			'type'        => 'textfield',
			'heading'     => __( 'And', 'rowsprout' ),
			'param_name'  => 'value2',
			'description' => __( 'Only for "is between".', 'rowsprout' ),
			'dependency'  => [ 'element' => 'rule_property', 'not_empty' => true ],
		];

		return [
			'name'                    => __( 'If / then', 'rowsprout' ),
			'base'                    => self::IF_TAG,
			'category'                => __( 'RowSprout', 'rowsprout' ),
			'description'             => __( 'Shows "Then" when the page\'s property values match the rules, else "Else".', 'rowsprout' ),
			'icon'                    => 'dashicons dashicons-randomize',
			'php_class_name'          => 'RowSprout_WPBakery_Conditional_If',
			'as_parent'               => [ 'only' => self::THEN_TAG . ',' . self::ELSE_TAG ],
			'is_container'            => true,
			'content_element'         => self::editedTemplateId() > 0,
			'show_settings_on_create' => true,
			'js_view'                 => 'VcColumnView',
			'default_content'         => '[' . self::THEN_TAG . '][/' . self::THEN_TAG . '][' . self::ELSE_TAG . '][/' . self::ELSE_TAG . ']',
			'params'                  => [
				[
					'type'       => 'rowsprout_conditional_note',
					'heading'    => __( 'Preview', 'rowsprout' ),
					'param_name' => 'preview_note',
					'value'      => $templateId > 0 ? '/rowsprout/v1/templates/' . $templateId . '/conditions' : '',
				],
				[
					'type'        => 'param_group',
					'heading'     => __( 'If', 'rowsprout' ),
					'param_name'  => 'rules',
					'description' => __( 'Each rule is joined to the one above with "and" or "or"; "and" goes first, so "A and B or C" means "(A and B) or C".', 'rowsprout' ),
					'value'       => rawurlencode( (string) wp_json_encode( [ [ 'join' => RuleSet::JOIN_AND ] ] ) ),
					'params'      => $ruleParams,
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function thenSettings(): array {
		return self::areaSettings( self::THEN_TAG, __( 'Then', 'rowsprout' ), 'RowSprout_WPBakery_Conditional_Then' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function elseSettings(): array {
		return self::areaSettings( self::ELSE_TAG, __( 'Else', 'rowsprout' ), 'RowSprout_WPBakery_Conditional_Else' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function areaSettings( string $tag, string $name, string $class ): array {
		return [
			'name'            => $name,
			'base'            => $tag,
			'category'        => __( 'RowSprout', 'rowsprout' ),
			'icon'            => 'dashicons dashicons-randomize',
			'php_class_name'  => $class,
			'as_child'        => [ 'only' => self::IF_TAG ],
			'as_parent'       => [ 'except' => self::IF_TAG . ',' . self::THEN_TAG . ',' . self::ELSE_TAG ],
			'is_container'    => true,
			'content_element' => false,
			'show_settings_on_create' => false,
			'js_view'         => 'VcColumnView',
			'params'          => [],
		];
	}

	/**
	 * WPBakery's editors (backend and frontend): assets/js/conditional-wpbakery.js
	 * with the template's note route, the operators' value counts and the
	 * labels, and its CSS.
	 */
	public static function enqueueEditor(): void {
		$templateId = self::editedTemplateId();
		if ( $templateId <= 0 && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post && $GLOBALS['post']->post_type === PostTypes::TEMPLATE ) {
			$templateId = (int) $GLOBALS['post']->ID;
		}
		if ( $templateId <= 0 ) {
			return;
		}
		$path = 'assets/js/conditional-wpbakery.js';
		$file = ROWSPROUT_PATH . $path;
		wp_enqueue_script( 'rowsprout-conditional-wpbakery', plugins_url( $path, ROWSPROUT_FILE ), [ 'jquery' ], file_exists( $file ) ? (string) filemtime( $file ) : ROWSPROUT_VERSION, true );
		$args = [];
		foreach ( ConditionEvaluator::operators() as $operator => $definition ) {
			$args[ $operator ] = $definition['args'];
		}
		wp_add_inline_script(
			'rowsprout-conditional-wpbakery',
			'window.rowsproutConditionalWpb = ' . wp_json_encode(
				[
					'rest'  => rest_url( 'rowsprout/v1/templates/' . $templateId . '/conditions' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'args'  => $args,
					'i18n'  => [
						'ifThen'       => __( 'If / then', 'rowsprout' ),
						'then'         => __( 'Then', 'rowsprout' ),
						'else'         => __( 'Else', 'rowsprout' ),
						'previewGroup' => __( 'Preview group', 'rowsprout' ),
					],
				]
			) . ';',
			'before'
		);
		wp_register_style( 'rowsprout-conditional-wpbakery', false, [], ROWSPROUT_VERSION );
		wp_add_inline_style( 'rowsprout-conditional-wpbakery', self::editorCss() );
		wp_enqueue_style( 'rowsprout-conditional-wpbakery' );
	}

	/**
	 * The page inside WPBakery's frontend editor (an iframe): the labelled
	 * areas' CSS.
	 */
	public static function enqueueEditablePage(): void {
		if ( ! function_exists( 'vc_is_page_editable' ) || ! vc_is_page_editable() ) {
			return;
		}
		wp_register_style( 'rowsprout-conditional-wpbakery', false, [], ROWSPROUT_VERSION );
		wp_add_inline_style( 'rowsprout-conditional-wpbakery', self::editorCss() );
		wp_enqueue_style( 'rowsprout-conditional-wpbakery' );
	}

	/**
	 * The backend editor's labels and preview marking, the first rule's "And /
	 * or" hidden, the frontend editor's labelled areas.
	 */
	public static function editorCss(): string {
		return '.vc_ui-panel-window[data-vc-shortcode="rowsprout_if"] .vc_param_group-list>.vc_param:first-child [data-vc-shortcode-param-name="rules_join"]{display:none!important}'
			. '.rs-wpb-label,.rowsprout-wpb-area-label{margin:4px 8px;font:600 11px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:.04em;text-transform:uppercase;color:#757575}'
			. '.rs-wpb-label--if{color:#1d2327}'
			. '.rs-wpb-preview{display:none;margin-left:6px;padding:0 6px;border-radius:2px;background:#93003c;color:#fff;letter-spacing:0}'
			. '.rs-wpb-is-preview>.rs-wpb-label .rs-wpb-preview,.rowsprout-wpb-area.rs-wpb-is-preview>.rowsprout-wpb-area-label .rs-wpb-preview{display:inline-block}'
			. '.rs-wpb-is-preview{outline:2px solid #93003c;outline-offset:-2px}'
			. '.rowsprout-wpb-area{margin:0 0 8px;padding:4px 0;border:1px dashed #c3c4c7}';
	}

	/**
	 * The edit window's preview note: filled by conditional-wpbakery.js.
	 *
	 * @param array<string, mixed> $settings
	 * @param mixed                $value
	 */
	public static function noteParam( $settings, $value ): string {
		$path = (string) ( $settings['value'] ?? '' );

		return '<div class="rowsprout-wpb-note" style="padding:6px 10px;border-left:3px solid #93003c;background:#fdf2f6;color:#1d2327;font-size:12px;line-height:1.5" data-rest="' . esc_url( $path !== '' ? rest_url( ltrim( $path, '/' ) ) : '' ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"></div>'
			. '<input type="hidden" name="' . esc_attr( (string) ( $settings['param_name'] ?? '' ) ) . '" class="wpb_vc_param_value" value="" />';
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function renderIf( $atts, ?string $content = null ): string {
		$atts    = is_array( $atts ) ? $atts : [];
		$content = (string) $content;
		$areas   = [ '' => '', 'else_' => '' ];
		$pattern = get_shortcode_regex( [ self::THEN_TAG, self::ELSE_TAG ] );
		if ( preg_match_all( '/' . $pattern . '/s', $content, $found, PREG_SET_ORDER ) ) {
			foreach ( $found as $match ) {
				$prefix = $match[2] === self::THEN_TAG ? '' : 'else_';
				if ( $areas[ $prefix ] === '' ) {
					$areas[ $prefix ] = do_shortcode( (string) $match[5] );
				}
			}
		}

		$rules   = function_exists( 'vc_param_group_parse_atts' ) ? vc_param_group_parse_atts( (string) ( $atts['rules'] ?? '' ) ) : json_decode( rawurldecode( (string) ( $atts['rules'] ?? '' ) ), true );
		$rules   = is_array( $rules ) ? $rules : [];
		$postId  = (int) get_the_ID();
		$context = PageFieldResolver::resolveGroupContext( $postId, get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0 );

		if ( self::isFrontendEditor() ) {
			// Both, labelled, so both can be edited; the preview group's one marked.
			self::$previewMatches[] = $context !== null && RuleSet::decide( $rules, $context['group'] )['matches'];
			$html = do_shortcode( $content );
			array_pop( self::$previewMatches );

			return '<div class="rowsprout-wpb-if">' . $html . '</div>';
		}

		$result  = ConditionalOutput::render(
			[
				'rules'       => $rules,
				'source'      => 'inner',
				'else_source' => trim( wp_strip_all_tags( $areas['else_'], true ) ) === '' && strpos( $areas['else_'], '<img' ) === false ? 'none' : 'inner',
			],
			$context,
			[
				'inner' => static function ( string $prefix ) use ( $areas ): string {
					return $areas[ $prefix ] ?? '';
				},
			]
		);

		return $result['html'] === '' ? '' : '<div class="rowsprout-wpb-if">' . $result['html'] . '</div>';
	}

	/**
	 * Then / Else on their own: their elements (inside [rowsprout_if] they are
	 * read by renderIf()).
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function renderArea( $atts, ?string $content, string $tag = '' ): string {
		$html = do_shortcode( (string) $content );
		if ( ! self::isFrontendEditor() ) {
			return $html;
		}

		$isElse  = $tag === self::ELSE_TAG;
		$matches = self::$previewMatches === [] ? null : end( self::$previewMatches );
		$preview = $matches !== null && $matches === ! $isElse ? ' rs-wpb-is-preview' : '';

		return '<div class="rowsprout-wpb-area rowsprout-wpb-area--' . ( $isElse ? 'else' : 'then' ) . $preview . '"><div class="rowsprout-wpb-area-label">' . esc_html( $isElse ? __( 'Else', 'rowsprout' ) : __( 'Then', 'rowsprout' ) ) . '<span class="rs-wpb-preview">' . esc_html__( 'Preview group', 'rowsprout' ) . '</span></div>' . $html . '</div>';
	}

	private static function isFrontendEditor(): bool {
		return function_exists( 'vc_is_inline' ) && vc_is_inline();
	}

	/**
	 * The template open in WPBakery's backend editor (post.php?post=…) or
	 * frontend editor (post_id / vc_post_id request params).
	 */
	private static function editedTemplateId(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only lookup of which post the editor shows.
		foreach ( [ 'post', 'post_id', 'vc_post_id' ] as $key ) {
			if ( isset( $_REQUEST[ $key ] ) ) {
				$postId = absint( $_REQUEST[ $key ] );
				if ( $postId > 0 && get_post_type( $postId ) === PostTypes::TEMPLATE ) {
					return $postId;
				}
			}
		}
		// phpcs:enable

		return 0;
	}
}
