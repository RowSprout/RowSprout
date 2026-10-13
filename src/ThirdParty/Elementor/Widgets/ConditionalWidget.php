<?php

namespace RowSprout\ThirdParty\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;
use RowSprout\Core\BlockBindings\PropertyBindingSource;
use RowSprout\Core\Conditions\ConditionEvaluator;
use RowSprout\Core\Page\PageBuilder;
use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "If / then": shows "Then" when the current page's property values match
 * the rules, else "Otherwise". Rules are a list, each joined to the one above
 * with "and" or "or" ("and" binds first: A and B or C = (A and B) or C), each
 * with an operator list per kind of property, shown for the chosen
 * property's kind. "Then" and "Otherwise" show a property (formatted as the
 * block bindings format it), own text with @code_…@ tokens (replaced when the
 * page is generated; in the editor for the preview group) or, with Elementor
 * Pro active, a saved Elementor template.
 *
 * Resolved when the page is shown, like the other RowSprout widgets. When the
 * outcome depends on a rule on today (ConditionEvaluator::isRelative()),
 * both outputs go into the page and assets/js/conditional-widget.js picks
 * again with today's date in the site's time zone, so a page cache cannot
 * keep a stale outcome.
 */
final class ConditionalWidget extends Widget_Base {

	public const SCRIPT_HANDLE = 'rowsprout-conditional-widget';

	public const JOIN_AND = 'and';
	public const JOIN_OR  = 'or';

	/** Output types without text to show. */
	private const NO_OUTPUT_TYPES = [ 'thumbnail', 'file', 'icon' ];

	private const TAGS = [ 'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

	/** How an item list is shown. */
	private const LIST_DISPLAYS = [ 'lines', 'bullets', 'numbers', 'commas' ];

	/** @var array<int, int> Elementor templates being rendered (a template showing itself). */
	private static $rendering = [];

	public function get_name() {
		return 'rowsprout_page_conditional_widget';
	}

	public function get_title() {
		return __( 'If / then', 'rowsprout' );
	}

	public function get_icon() {
		return 'eicon-flow';
	}

	public function get_categories() {
		return [ 'rowsprout_page' ];
	}

	public function get_keywords() {
		return [ 'if', 'then', 'else', 'condition', 'conditional', 'rowsprout' ];
	}

	public function show_in_panel() {
		return PageFieldResolver::isRowSproutPostType();
	}

	public function get_script_depends() {
		return [ self::SCRIPT_HANDLE ];
	}

	/**
	 * Whether "an Elementor template" can be shown: only with Elementor Pro,
	 * whose Template widget is the regular way to place one.
	 */
	public static function templatesAvailable(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	protected function register_controls() {
		$properties = PageFieldResolver::getFieldCodeOptions( null );
		$byKind     = self::codesByKind();
		$sources    = [
			'property' => __( 'A property', 'rowsprout' ),
			'text'     => __( 'Own text', 'rowsprout' ),
		];
		if ( self::templatesAvailable() ) {
			$sources['template'] = __( 'An Elementor template', 'rowsprout' );
		}
		$templates = self::templatesAvailable() ? self::templateOptions() : [];

		// One section (Elementor needs one), with headings for If / Then / Otherwise.
		$this->start_controls_section(
			'section_if',
			[
				'label' => __( 'If / then', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);
		$this->add_control(
			'preview_note',
			[
				'type' => Controls_Manager::RAW_HTML,
				// Filled by the editor script (editorScript()) from the preview.
				'raw'  => '<div class="rowsprout-conditional-panel-note"></div>',
			]
		);
		$this->add_control(
			'rules_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Each rule is joined to the one above with "and" or "or"; "and" goes first, so "A and B or C" means "(A and B) or C". A date is written as YYYY-MM-DD.', 'rowsprout' ),
				'content_classes' => 'elementor-descriptor',
			]
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'join',
			[
				'label'       => __( 'And / or', 'rowsprout' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					self::JOIN_AND => __( 'and', 'rowsprout' ),
					self::JOIN_OR  => __( 'or', 'rowsprout' ),
				],
				'default'     => self::JOIN_AND,
				// Hidden for the first rule by editorStyles().
				'classes'     => 'rowsprout-conditional-join',
			]
		);
		$repeater->add_control(
			'rule_property',
			[
				'label'   => __( 'Property', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $properties,
				'default' => '',
			]
		);
		// One operator list per kind of property, shown for the properties of
		// that kind (Elementor cannot change a list's options on the fly).
		$withValue  = [];
		$withSecond = [];
		foreach ( $byKind as $kind => $codes ) {
			$control = 'op_' . $kind;
			$repeater->add_control(
				$control,
				[
					'label'     => __( 'Operator', 'rowsprout' ),
					'type'      => Controls_Manager::SELECT,
					'options'   => ConditionEvaluator::operatorsFor( $kind ),
					'default'   => ConditionEvaluator::defaultOperator( $kind ),
					'condition' => [ 'rule_property' => $codes ],
				]
			);
			$takesValue = array_values( array_filter( array_keys( ConditionEvaluator::operatorsFor( $kind ) ), static function ( string $operator ): bool {
				return ConditionEvaluator::argCount( $operator ) > 0;
			} ) );
			if ( $takesValue !== [] ) {
				$withValue[] = self::termsFor( $codes, $control, $takesValue );
			}
			if ( isset( ConditionEvaluator::operatorsFor( $kind )['between'] ) ) {
				$withSecond[] = self::termsFor( $codes, $control, [ 'between' ] );
			}
		}
		$repeater->add_control(
			'value',
			[
				'label'      => __( 'Value', 'rowsprout' ),
				'type'       => Controls_Manager::TEXT,
				'default'    => '',
				'conditions' => self::visibleWhen( $withValue ),
			]
		);
		$repeater->add_control(
			'value2',
			[
				'label'      => __( 'And', 'rowsprout' ),
				'type'       => Controls_Manager::TEXT,
				'default'    => '',
				'conditions' => self::visibleWhen( $withSecond ),
			]
		);
		$this->add_control(
			'rules',
			[
				'label'       => __( 'If', 'rowsprout' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [ [ 'join' => self::JOIN_AND ] ],
				// The property's label, and "· or" for a rule joined with "or".
				'title_field' => '<# var rsLabels = ' . wp_json_encode( array_diff_key( $properties, [ '' => true ] ) ) . '; #>{{ ( rsLabels[ rule_property ] || rule_property || ' . wp_json_encode( __( '(no property)', 'rowsprout' ) ) . ' ) + ( join === ' . wp_json_encode( self::JOIN_OR ) . ' ? ' . wp_json_encode( ' · ' . __( 'or', 'rowsprout' ) ) . ' : "" ) }}',
			]
		);
		$this->add_control(
			'heading_then',
			[
				'label'     => __( 'Then', 'rowsprout' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->addOutputControls( '', $sources, 'property', $templates );
		$this->add_control(
			'heading_otherwise',
			[
				'label'     => __( 'Otherwise', 'rowsprout' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);
		$this->addOutputControls( 'else_', $sources + [ 'none' => __( 'Nothing', 'rowsprout' ) ], 'none', $templates );
		$listCodes = $byKind[ ConditionEvaluator::KIND_LIST ] ?? [];
		if ( $listCodes !== [] ) {
			$this->add_control(
				'list_display',
				[
					'label'      => __( 'Show a list as', 'rowsprout' ),
					'type'       => Controls_Manager::SELECT,
					'options'    => [
						'lines'   => __( 'Lines', 'rowsprout' ),
						'bullets' => __( 'Bulleted list', 'rowsprout' ),
						'numbers' => __( 'Numbered list', 'rowsprout' ),
						'commas'  => __( 'Separated by commas', 'rowsprout' ),
					],
					'default'    => 'lines',
					'separator'  => 'before',
					'conditions' => [
						'relation' => 'or',
						'terms'    => [
							[ 'relation' => 'and', 'terms' => [ [ 'name' => 'source', 'operator' => '===', 'value' => 'property' ], [ 'name' => 'property', 'operator' => 'in', 'value' => $listCodes ] ] ],
							[ 'relation' => 'and', 'terms' => [ [ 'name' => 'else_source', 'operator' => '===', 'value' => 'property' ], [ 'name' => 'else_property', 'operator' => 'in', 'value' => $listCodes ] ] ],
						],
					],
				]
			);
		}
		$this->add_control(
			'html_tag',
			[
				'label'       => __( 'HTML tag', 'rowsprout' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => array_combine( self::TAGS, self::TAGS ),
				'default'     => 'div',
				'separator'   => 'before',
				'description' => __( 'For text (one paragraph of own text too). Several paragraphs, a bulleted or numbered list or a template are always in a div.', 'rowsprout' ),
			]
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			[
				'label' => __( 'Text', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'style_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'For a property and own text. An Elementor template keeps its own styling.', 'rowsprout' ),
				'content_classes' => 'elementor-descriptor',
			]
		);
		$this->add_responsive_control(
			'align',
			[
				'label'     => __( 'Alignment', 'rowsprout' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'left'    => [ 'title' => __( 'Left', 'rowsprout' ), 'icon' => 'eicon-text-align-left' ],
					'center'  => [ 'title' => __( 'Center', 'rowsprout' ), 'icon' => 'eicon-text-align-center' ],
					'right'   => [ 'title' => __( 'Right', 'rowsprout' ), 'icon' => 'eicon-text-align-right' ],
					'justify' => [ 'title' => __( 'Justified', 'rowsprout' ), 'icon' => 'eicon-text-align-justify' ],
				],
				'selectors' => [
					'{{WRAPPER}} .rowsprout-conditional' => 'text-align: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'text_typography',
				'selector' => '{{WRAPPER}} .rowsprout-conditional',
			]
		);
		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-conditional' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(),
			[
				'name'     => 'text_shadow',
				'selector' => '{{WRAPPER}} .rowsprout-conditional',
			]
		);
		$this->add_responsive_control(
			'paragraph_spacing',
			[
				'label'       => __( 'Space between paragraphs', 'rowsprout' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => [ 'px', 'em' ],
				'range'       => [
					'px' => [ 'min' => 0, 'max' => 60 ],
					'em' => [ 'min' => 0, 'max' => 4, 'step' => 0.1 ],
				],
				'selectors'   => [
					'{{WRAPPER}} .rowsprout-conditional p, {{WRAPPER}} .rowsprout-conditional ul, {{WRAPPER}} .rowsprout-conditional ol' => 'margin-top: 0; margin-bottom: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .rowsprout-conditional > :last-child' => 'margin-bottom: 0;',
				],
				'description' => __( 'Own text and rich text with paragraphs or lists.', 'rowsprout' ),
			]
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_links',
			[
				'label' => __( 'Links', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'link_typography',
				'selector' => '{{WRAPPER}} .rowsprout-conditional a',
			]
		);
		$this->add_control(
			'link_color',
			[
				'label'     => __( 'Link color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-conditional a' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_control(
			'link_hover_color',
			[
				'label'     => __( 'Link hover color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-conditional a:hover, {{WRAPPER}} .rowsprout-conditional a:focus' => 'color: {{VALUE}};',
				],
			]
		);
		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_otherwise',
			[
				'label' => __( 'Otherwise', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'otherwise_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Only when "Otherwise" is shown; what you leave empty follows "Text".', 'rowsprout' ),
				'content_classes' => 'elementor-descriptor',
			]
		);
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'else_typography',
				'selector' => '{{WRAPPER}} .rowsprout-conditional.rowsprout-conditional--else',
			]
		);
		$this->add_control(
			'else_color',
			[
				'label'     => __( 'Text color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-conditional.rowsprout-conditional--else' => 'color: {{VALUE}};',
				],
			]
		);
		$this->add_control(
			'else_background',
			[
				'label'     => __( 'Background color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-conditional.rowsprout-conditional--else' => 'background-color: {{VALUE}};',
				],
			]
		);
		$this->add_responsive_control(
			'else_padding',
			[
				'label'      => __( 'Padding', 'rowsprout' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .rowsprout-conditional.rowsprout-conditional--else' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		$this->end_controls_section();
	}

	/**
	 * "Then" / "Otherwise": the source and its property, text or template.
	 *
	 * @param array<string, string> $sources
	 * @param array<string, string> $templates
	 */
	private function addOutputControls( string $prefix, array $sources, string $default, array $templates ): void {
		$this->add_control(
			$prefix . 'source',
			[
				'label'   => __( 'Show', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $sources,
				'default' => $default,
			]
		);
		$this->add_control(
			$prefix . 'property',
			[
				'label'     => __( 'Property', 'rowsprout' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => self::outputOptions(),
				'default'   => '',
				'condition' => [ $prefix . 'source' => 'property' ],
			]
		);
		$this->add_control(
			$prefix . 'text',
			[
				'label'     => __( 'Text', 'rowsprout' ),
				'type'      => Controls_Manager::WYSIWYG,
				'default'   => '',
				'condition' => [ $prefix . 'source' => 'text' ],
			]
		);
		if ( isset( $sources['template'] ) ) {
			$this->add_control(
				$prefix . 'template',
				[
					'label'       => __( 'Template', 'rowsprout' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => $templates,
					'default'     => '',
					'condition'   => [ $prefix . 'source' => 'template' ],
					'description' => __( 'RowSprout widgets in the template show this page\'s values; @code_…@ tokens typed as text in it are not replaced.', 'rowsprout' ),
				]
			);
		}
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$postId     = (int) get_the_ID();
		$templateId = get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0;
		$context    = PageFieldResolver::resolveGroupContext( $postId, $templateId );
		$decision   = self::decide( (array) ( $settings['rules'] ?? [] ), $context['group'] ?? null );

		if ( $decision['client'] === null ) {
			$prefix = $decision['matches'] ? '' : 'else_';
			$html   = $this->output( $settings, $prefix, $context );
			if ( $html !== '' ) {
				echo $this->wrap( $settings, $prefix, $html, '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formatted by PropertyBindingSource::format(), the widget's own text or Elementor.
			}
		} else {
			// Both outputs; the script shows the right one.
			echo '<div class="rowsprout-conditional-cases" data-rs-conditional="' . esc_attr( (string) wp_json_encode( $decision['client'] ) ) . '">';
			foreach ( [ 'then' => '', 'else' => 'else_' ] as $name => $prefix ) {
				$shown = $decision['matches'] === ( $name === 'then' );
				echo $this->wrap( $settings, $prefix, $this->output( $settings, $prefix, $context ), ' data-rs-case="' . esc_attr( $name ) . '"' . ( $shown ? '' : ' hidden' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above.
			}
			echo '</div>';
		}

		if ( self::isEditor() ) {
			$this->renderEditorNote( $decision, $context, $settings );
		}
	}

	/**
	 * One output in its element: text in the chosen tag with the text styles
	 * (rowsprout-conditional, --then / --else), a template or a bulleted or
	 * numbered list in a div (a template keeps its own styling).
	 *
	 * @param array<string, mixed> $settings
	 */
	private function wrap( array $settings, string $prefix, string $html, string $attributes ): string {
		if ( ( $settings[ $prefix . 'source' ] ?? '' ) === 'template' ) {
			return '<div class="rowsprout-conditional-template"' . $attributes . '>' . $html . '</div>';
		}
		$tag = in_array( $settings['html_tag'] ?? 'div', self::TAGS, true ) ? (string) $settings['html_tag'] : 'div';
		if ( $tag !== 'div' && preg_match( '#^\s*<p>((?:(?!</?p[\s>]).)*)</p>\s*$#is', $html, $single ) === 1 ) {
			// One paragraph (as the text editor makes it): its text goes in the chosen tag.
			$html = $single[1];
		}
		if ( preg_match( '/<(ul|ol|p|div|h[1-6])[\s>]/i', $html ) === 1 && $tag !== 'div' ) {
			// Blocks cannot go inside a p, span or heading.
			$tag = 'div';
		}
		$tag = tag_escape( $tag );

		return '<' . $tag . ' class="rowsprout-conditional rowsprout-conditional--' . ( $prefix === '' ? 'then' : 'else' ) . '"' . $attributes . '>' . $html . '</' . $tag . '>';
	}

	/**
	 * An item list (one item per line) as lines, a bulleted or numbered list,
	 * or separated by commas.
	 */
	public static function listHtml( string $value, string $display ): string {
		$items = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ?: [] ), 'strlen' ) );
		if ( $items === [] ) {
			return '';
		}
		$items = array_map( 'esc_html', $items );
		switch ( $display ) {
			case 'bullets':
				return '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
			case 'numbers':
				return '<ol><li>' . implode( '</li><li>', $items ) . '</li></ol>';
			case 'commas':
				return implode( esc_html_x( ', ', 'list separator', 'rowsprout' ), $items );
			default:
				return implode( '<br>', $items );
		}
	}

	/**
	 * Whether a group matches the rules ("matches", with $today, default
	 * today in the site's time zone). "client" is set when the outcome
	 * depends on a rule on today: the "or" groups that today can still
	 * decide, each with only its rules on today ({ d: the property's day,
	 * o: operator, n: days }; its other rules all matched here).
	 *
	 * @param array<int, mixed>         $rules
	 * @param array<string, mixed>|null $group
	 * @return array{matches: bool, client: array<string, mixed>|null, missing: array<int, string>, changed: array<int, int>, ruleCount: int}
	 */
	public static function decide( array $rules, ?array $group, ?string $today = null ): array {
		$groups    = [];
		$missing   = [];
		$changed   = [];
		$ruleCount = 0;

		foreach ( array_values( $rules ) as $index => $rule ) {
			$rule = is_array( $rule ) ? $rule : [];
			$code = (string) ( $rule['rule_property'] ?? '' );
			if ( $code === '' ) {
				continue;
			}
			// "or" starts a new "and" group.
			if ( $ruleCount === 0 || ( $rule['join'] ?? '' ) === self::JOIN_OR ) {
				$groups[] = [ 'results' => [], 'today' => [] ];
			}
			$ruleCount++;
			$last  = count( $groups ) - 1;
			$field = $group === null ? null : PageFieldResolver::getFieldByCode( $group, $code );
			if ( $field === null ) {
				$missing[]                    = $code;
				$groups[ $last ]['results'][] = false;
				$groups[ $last ]['decided'][] = false;
				continue;
			}
			$fallback = false;
			$operator = self::operatorFor( $rule, ConditionEvaluator::kindOf( $field['type'] ), $fallback );
			if ( $fallback ) {
				$changed[] = $index;
			}
			$value  = (string) ( $rule['value'] ?? '' );
			$result = ConditionEvaluator::test( $field['value'], $field['type'], $operator, $value, (string) ( $rule['value2'] ?? '' ), $today );
			$day    = ConditionEvaluator::isRelative( $operator ) ? ConditionEvaluator::dayOf( $field['value'], $field['type'] ) : null;
			$groups[ $last ]['results'][] = $result;
			if ( $day !== null ) {
				$groups[ $last ]['today'][] = [ 'd' => $day, 'o' => $operator, 'n' => max( 0, (int) $value ) ];
			} else {
				$groups[ $last ]['decided'][] = $result;
			}
		}

		$matches = false;
		$open    = [];
		$always  = false;
		foreach ( $groups as $andGroup ) {
			$matches = $matches || ConditionEvaluator::matches( $andGroup['results'], ConditionEvaluator::MATCH_ALL );
			if ( in_array( false, $andGroup['decided'] ?? [], true ) ) {
				// Never matches, whatever the date.
				continue;
			}
			if ( $andGroup['today'] === [] ) {
				$always = true;
				continue;
			}
			$open[] = $andGroup['today'];
		}

		return [
			'matches'   => $matches,
			// Only when today can still change the outcome.
			'client'    => ! $always && $open !== [] ? [ 'offset' => (int) round( wp_timezone()->getOffset( new \DateTimeImmutable( 'now' ) ) / 60 ), 'groups' => $open ] : null,
			'missing'   => array_values( array_unique( $missing ) ),
			'changed'   => $changed,
			'ruleCount' => $ruleCount,
		];
	}

	/**
	 * The operator a rule uses for its property's kind: the one chosen in that
	 * kind's list, else the kind's default ($fallback then says so: the list
	 * had something that does not fit, or nothing while other kinds' lists
	 * had a choice, e.g. after the property's type changed).
	 *
	 * @param array<string, mixed> $rule
	 */
	private static function operatorFor( array $rule, string $kind, bool &$fallback ): string {
		$chosen   = (string) ( $rule[ 'op_' . $kind ] ?? '' );
		$fallback = false;
		if ( isset( ConditionEvaluator::operatorsFor( $kind )[ $chosen ] ) ) {
			return $chosen;
		}
		if ( $chosen !== '' ) {
			$fallback = true;
		} else {
			foreach ( ConditionEvaluator::kinds() as $other ) {
				if ( ! empty( $rule[ 'op_' . $other ] ) ) {
					$fallback = true;
					break;
				}
			}
		}

		return ConditionEvaluator::defaultOperator( $kind );
	}

	/**
	 * The HTML of "Then" ($prefix '') or "Otherwise" ('else_').
	 *
	 * @param array<string, mixed>      $settings
	 * @param array<string, mixed>|null $context
	 */
	private function output( array $settings, string $prefix, ?array $context ): string {
		$source = (string) ( $settings[ $prefix . 'source' ] ?? ( $prefix === '' ? 'property' : 'none' ) );

		if ( $source === 'text' ) {
			$text = (string) ( $settings[ $prefix . 'text' ] ?? '' );
			if ( $context !== null && strpos( $text, '@code_' ) !== false ) {
				// Generated pages had their tokens replaced already; this is the editor's preview.
				$templateId = (int) $context['template_id'];
				$text       = PageBuilder::applyGroupPlaceholderTokensToMarkup( $text, (array) $context['group'], $templateId, (int) wp_get_post_parent_id( $templateId ), $templateId );
			}

			return trim( $text ) === '' ? '' : $this->parse_text_editor( $text );
		}
		if ( $source === 'template' ) {
			return self::renderTemplate( (int) ( $settings[ $prefix . 'template' ] ?? 0 ) );
		}
		$property = (string) ( $settings[ $prefix . 'property' ] ?? '' );
		if ( $source !== 'property' || $property === '' || $context === null ) {
			return '';
		}
		$field = PageFieldResolver::getFieldByCode( (array) $context['group'], $property );
		if ( $field === null ) {
			return '';
		}
		if ( ConditionEvaluator::kindOf( $field['type'] ) === ConditionEvaluator::KIND_LIST ) {
			$display = in_array( $settings['list_display'] ?? 'lines', self::LIST_DISPLAYS, true ) ? (string) $settings['list_display'] : 'lines';
			return self::listHtml( $field['value'], $display );
		}

		return (string) PropertyBindingSource::format( $field['value'], $field['type'], 'content' );
	}

	/**
	 * A saved Elementor template's content (Elementor Pro only; never a
	 * template that is already being rendered, so one cannot show itself).
	 */
	private static function renderTemplate( int $templateId ): string {
		if ( $templateId <= 0 || ! self::templatesAvailable() || in_array( $templateId, self::$rendering, true ) || get_post_type( $templateId ) !== 'elementor_library' || get_post_status( $templateId ) !== 'publish' ) {
			return '';
		}
		self::$rendering[] = $templateId;
		try {
			$html = (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $templateId, true );
		} finally {
			array_pop( self::$rendering );
		}

		return $html;
	}

	/**
	 * In the editor only, for the panel: whether the preview group matches,
	 * and rules that cannot be checked.
	 *
	 * @param array<string, mixed>      $decision
	 * @param array<string, mixed>|null $context
	 * @param array<string, mixed>      $settings
	 */
	private function renderEditorNote( array $decision, ?array $context, array $settings ): void {
		if ( $context === null ) {
			$notes = [ __( 'This template has no groups yet, so the rules cannot be checked.', 'rowsprout' ) ];
		} elseif ( $decision['ruleCount'] === 0 ) {
			$notes = [ __( 'No rule has a property yet: "Otherwise" is shown.', 'rowsprout' ) ];
		} elseif ( $decision['matches'] ) {
			$notes = [ __( 'Preview group: the rules match, so "Then" is shown.', 'rowsprout' ) ];
		} else {
			$notes = [ ( $settings['else_source'] ?? 'none' ) === 'none' ? __( 'Preview group: the rules do not match, and "Otherwise" shows nothing.', 'rowsprout' ) : __( 'Preview group: the rules do not match, so "Otherwise" is shown.', 'rowsprout' ) ];
		}
		if ( $decision['client'] !== null ) {
			$notes[] = __( 'A rule depends on today: the page checks it again when it is shown.', 'rowsprout' );
		}
		if ( $decision['missing'] !== [] && $context !== null ) {
			/* translators: %s: comma-separated property codes. */
			$notes[] = sprintf( __( 'Not in this template (these rules never match): %s', 'rowsprout' ), implode( ', ', $decision['missing'] ) );
		}
		foreach ( $decision['changed'] as $index ) {
			/* translators: %d: rule number. */
			$notes[] = sprintf( __( 'Rule %d: the operator does not fit the property\'s type (changed?), so "is not empty" / "is checked" is used. Choose the operator again.', 'rowsprout' ), (int) $index + 1 );
		}
		foreach ( [ '' => __( 'Then', 'rowsprout' ), 'else_' => __( 'Otherwise', 'rowsprout' ) ] as $prefix => $label ) {
			if ( ( $settings[ $prefix . 'source' ] ?? '' ) === 'template' && ! self::templatesAvailable() ) {
				/* translators: %s: "Then" or "Otherwise". */
				$notes[] = sprintf( __( '%s shows an Elementor template, which needs Elementor Pro: nothing is shown.', 'rowsprout' ), $label );
			}
		}

		// Not in the canvas: editorScript() shows it at the top of the Content tab.
		echo '<div class="rowsprout-conditional-note" hidden>';
		foreach ( $notes as $note ) {
			echo '<div>' . esc_html( $note ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * The current template's property codes by the kind their type compares
	 * as (only the kinds the template has).
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function codesByKind(): array {
		$byKind = [];
		foreach ( (array) ( TemplateMeta::get( PageFieldResolver::resolveCurrentTemplateId() )['field_types'] ?? [] ) as $definition ) {
			$code = is_array( $definition ) ? (string) ( $definition['code'] ?? '' ) : '';
			if ( $code !== '' ) {
				$byKind[ ConditionEvaluator::kindOf( (string) ( $definition['type'] ?? '' ) ) ][] = $code;
			}
		}

		return $byKind;
	}

	/**
	 * Terms for "the rule's property is one of $codes and that kind's operator
	 * is one of $operators".
	 *
	 * @param array<int, string> $codes
	 * @param array<int, string> $operators
	 * @return array<string, mixed>
	 */
	private static function termsFor( array $codes, string $control, array $operators ): array {
		return [
			'relation' => 'and',
			'terms'    => [
				[ 'name' => 'rule_property', 'operator' => 'in', 'value' => $codes ],
				[ 'name' => $control, 'operator' => 'in', 'value' => $operators ],
			],
		];
	}

	/**
	 * Shown when one of $alternatives holds.
	 *
	 * @param array<int, array<string, mixed>> $alternatives
	 * @return array<string, mixed>
	 */
	private static function visibleWhen( array $alternatives ): array {
		// Nothing can need it: a condition that never holds.
		return [
			'relation' => 'or',
			'terms'    => $alternatives === [] ? [ [ 'name' => 'rule_property', 'operator' => '===', 'value' => '__never__' ] ] : $alternatives,
		];
	}

	/**
	 * Properties an output can show (not images, files or icons).
	 *
	 * @return array<string, string>
	 */
	private static function outputOptions(): array {
		$types = [];
		foreach ( (array) ( TemplateMeta::get( PageFieldResolver::resolveCurrentTemplateId() )['field_types'] ?? [] ) as $definition ) {
			if ( is_array( $definition ) && ! in_array( (string) ( $definition['type'] ?? '' ), self::NO_OUTPUT_TYPES, true ) ) {
				$types[] = (string) ( $definition['type'] ?? '' );
			}
		}

		return PageFieldResolver::getFieldCodeOptions( array_values( array_unique( $types ) ) );
	}

	/**
	 * Saved Elementor templates that are content: sections, containers and
	 * pages (not loop items, popups, theme parts or the kit), newest first.
	 *
	 * @return array<string, string>
	 */
	private static function templateOptions(): array {
		$options = [ '' => __( '-- Select --', 'rowsprout' ) ];
		$posts   = get_posts( [
			'post_type'        => 'elementor_library',
			'post_status'      => 'publish',
			'numberposts'      => 200,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		] );
		foreach ( $posts as $post ) {
			$type = (string) get_post_meta( $post->ID, '_elementor_template_type', true );
			if ( ! in_array( $type, [ 'section', 'container', 'page' ], true ) ) {
				continue;
			}
			$options[ (string) $post->ID ] = ( $post->post_title !== '' ? $post->post_title : '#' . $post->ID ) . ( $type !== '' ? ' (' . $type . ')' : '' );
		}

		return $options;
	}

	/**
	 * Editor CSS: no "And / or" for the first rule (a control cannot know its
	 * row's position).
	 */
	public static function editorStyles(): void {
		wp_add_inline_style( 'elementor-editor', '.elementor-control-rules .elementor-repeater-fields:first-child .rowsprout-conditional-join{display:none}'
			. '.rowsprout-conditional-panel-note:empty{display:none}'
			. '.rowsprout-conditional-panel-note{padding:6px 10px;border-left:3px solid #93003c;background:#fdf2f6;color:#1d2327;font-size:12px;line-height:1.5}'
			. '.rowsprout-conditional-panel-note div+div{margin-top:4px}' );
	}

	/**
	 * Editor script: copies the widget's note from the preview (rendered
	 * hidden, see renderEditorNote()) to the top of its Content tab, again
	 * after every change, since the panel itself is built only once.
	 */
	public static function editorScript(): void {
		wp_add_inline_script( 'elementor-editor', <<<'JS'
(function () {
	var timer = null;
	function sync() {
		var target = document.querySelector('.rowsprout-conditional-panel-note');
		if (!target || !window.elementor || !elementor.$preview || !elementor.$preview[0]) {
			return;
		}
		var page = elementor.getPanelView && elementor.getPanelView().getCurrentPageView && elementor.getPanelView().getCurrentPageView();
		var model = page && page.model;
		var doc = elementor.$preview[0].contentDocument;
		if (!model || !doc) {
			return;
		}
		var note = doc.querySelector('.elementor-element-' + model.get('id') + ' .rowsprout-conditional-note');
		var html = note ? note.innerHTML : '';
		if (target.innerHTML !== html) {
			target.innerHTML = html;
		}
	}
	function later() {
		clearTimeout(timer);
		timer = setTimeout(sync, 80);
	}
	function watch(node) {
		if (node && window.MutationObserver) {
			new MutationObserver(later).observe(node, { childList: true, subtree: true });
		}
	}
	jQuery(window).on('elementor:init', function () {
		elementor.hooks.addAction('panel/open_editor/widget/rowsprout_page_conditional_widget', later);
		elementor.on('preview:loaded', function () {
			watch(elementor.$preview[0].contentDocument && elementor.$preview[0].contentDocument.body);
			later();
		});
		watch(document.getElementById('elementor-panel'));
	});
}());
JS
		);
	}

	private static function isEditor(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$elementor = \Elementor\Plugin::$instance;

		return ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) || ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() );
	}
}
