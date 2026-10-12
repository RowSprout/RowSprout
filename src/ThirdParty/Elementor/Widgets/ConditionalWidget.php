<?php

namespace RowSprout\ThirdParty\Elementor\Widgets;

use Elementor\Controls_Manager;
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
 * "If / then": shows one of several outputs depending on the current page's
 * property values. Cases are tried in order, each with up to three rules
 * (fixed slots: Elementor cannot nest repeaters; each with an operator
 * list per kind of property, shown for the chosen property's kind); the
 * first that matches is
 * shown, else "Otherwise". An output is a property (formatted as the block
 * bindings format it) or own text with @code_…@ tokens (replaced when the
 * page is generated; in the editor for the preview group).
 *
 * Resolved when the page is shown, like the other RowSprout widgets. Rules
 * that depend on today (ConditionEvaluator::isRelative()) put every output
 * that can still match in the page, and assets/js/conditional-widget.js
 * picks again with today's date in the site's time zone, so a page cache
 * cannot keep a stale outcome.
 */
final class ConditionalWidget extends Widget_Base {

	public const SCRIPT_HANDLE = 'rowsprout-conditional-widget';

	private const RULE_SLOTS = 3;

	/** Output types without text to show. */
	private const NO_OUTPUT_TYPES = [ 'thumbnail', 'file', 'icon' ];

	private const TAGS = [ 'div', 'p', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

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

	protected function register_controls() {
		$properties = PageFieldResolver::getFieldCodeOptions( null );
		$outputs    = self::outputOptions();
		$byKind     = self::codesByKind();
		$sources    = [
			'property' => __( 'A property', 'rowsprout' ),
			'text'     => __( 'Own text', 'rowsprout' ),
		];

		$this->start_controls_section(
			'section_cases',
			[
				'label' => __( 'If … then', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'cases_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'The cases are tried from top to bottom; the first one whose rules match is shown. A date is written as YYYY-MM-DD.', 'rowsprout' ),
				'content_classes' => 'elementor-descriptor',
			]
		);

		$repeater = new Repeater();
		$repeater->add_control(
			'case_label',
			[
				'label'   => __( 'Name', 'rowsprout' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Case', 'rowsprout' ),
			]
		);
		for ( $slot = 1; $slot <= self::RULE_SLOTS; $slot++ ) {
			$on = [];
			if ( $slot > 1 ) {
				$repeater->add_control(
					'rule' . $slot . '_on',
					[
						/* translators: %d: rule number, 2 or 3. */
						'label'     => sprintf( __( 'Rule %d', 'rowsprout' ), $slot ),
						'type'      => Controls_Manager::SWITCHER,
						'default'   => '',
						'separator' => 'before',
					]
				);
				$on = [ 'rule' . $slot . '_on' => 'yes' ];
			}
			$repeater->add_control(
				'rule' . $slot . '_field',
				[
					/* translators: %d: rule number. */
					'label'     => $slot === 1 ? __( 'If property', 'rowsprout' ) : sprintf( __( 'Property (rule %d)', 'rowsprout' ), $slot ),
					'type'      => Controls_Manager::SELECT,
					'options'   => $properties,
					'default'   => '',
					'condition' => $on,
					'separator' => $slot === 1 ? 'before' : 'default',
				]
			);
			// One operator list per kind of property, shown for the properties of
			// that kind (Elementor cannot change a list's options on the fly).
			$withValue  = [];
			$withSecond = [];
			foreach ( $byKind as $kind => $codes ) {
				$control = 'rule' . $slot . '_op_' . $kind;
				$repeater->add_control(
					$control,
					[
						'label'     => __( 'Operator', 'rowsprout' ),
						'type'      => Controls_Manager::SELECT,
						'options'   => ConditionEvaluator::operatorsFor( $kind ),
						'default'   => ConditionEvaluator::defaultOperator( $kind ),
						'condition' => $on + [ 'rule' . $slot . '_field' => $codes ],
					]
				);
				$takesValue = array_values( array_filter( array_keys( ConditionEvaluator::operatorsFor( $kind ) ), static function ( string $operator ): bool {
					return ConditionEvaluator::argCount( $operator ) > 0;
				} ) );
				if ( $takesValue !== [] ) {
					$withValue[] = self::termsFor( $slot, $codes, $control, $takesValue );
				}
				if ( isset( ConditionEvaluator::operatorsFor( $kind )['between'] ) ) {
					$withSecond[] = self::termsFor( $slot, $codes, $control, [ 'between' ] );
				}
			}
			$repeater->add_control(
				'rule' . $slot . '_value',
				[
					'label'      => __( 'Value', 'rowsprout' ),
					'type'       => Controls_Manager::TEXT,
					'default'    => '',
					'conditions' => self::visibleWhen( $slot, $withValue ),
				]
			);
			$repeater->add_control(
				'rule' . $slot . '_value2',
				[
					'label'      => __( 'And', 'rowsprout' ),
					'type'       => Controls_Manager::TEXT,
					'default'    => '',
					'conditions' => self::visibleWhen( $slot, $withSecond ),
				]
			);
		}
		$repeater->add_control(
			'match',
			[
				'label'      => __( 'Show when', 'rowsprout' ),
				'type'       => Controls_Manager::SELECT,
				'options'    => [
					ConditionEvaluator::MATCH_ALL => __( 'all rules match', 'rowsprout' ),
					ConditionEvaluator::MATCH_ANY => __( 'any rule matches', 'rowsprout' ),
				],
				'default'    => ConditionEvaluator::MATCH_ALL,
				'separator'  => 'before',
				'conditions' => [
					'relation' => 'or',
					'terms'    => [
						[ 'name' => 'rule2_on', 'operator' => '===', 'value' => 'yes' ],
						[ 'name' => 'rule3_on', 'operator' => '===', 'value' => 'yes' ],
					],
				],
			]
		);
		$repeater->add_control(
			'source',
			[
				'label'     => __( 'Then show', 'rowsprout' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $sources,
				'default'   => 'property',
				'separator' => 'before',
			]
		);
		$repeater->add_control(
			'property',
			[
				'label'     => __( 'Property', 'rowsprout' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $outputs,
				'default'   => '',
				'condition' => [ 'source' => 'property' ],
			]
		);
		$repeater->add_control(
			'text',
			[
				'label'     => __( 'Text', 'rowsprout' ),
				'type'      => Controls_Manager::WYSIWYG,
				'default'   => '',
				'condition' => [ 'source' => 'text' ],
			]
		);

		$this->add_control(
			'cases',
			[
				'label'       => __( 'Cases', 'rowsprout' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [ [ 'case_label' => __( 'Case', 'rowsprout' ) ] ],
				'title_field' => '{{{ case_label }}}',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_otherwise',
			[
				'label' => __( 'Otherwise', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);
		$this->add_control(
			'else_source',
			[
				'label'   => __( 'Show', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $sources + [ 'none' => __( 'Nothing', 'rowsprout' ) ],
				'default' => 'none',
			]
		);
		$this->add_control(
			'else_property',
			[
				'label'     => __( 'Property', 'rowsprout' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => $outputs,
				'default'   => '',
				'condition' => [ 'else_source' => 'property' ],
			]
		);
		$this->add_control(
			'else_text',
			[
				'label'     => __( 'Text', 'rowsprout' ),
				'type'      => Controls_Manager::WYSIWYG,
				'default'   => '',
				'condition' => [ 'else_source' => 'text' ],
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
			'html_tag',
			[
				'label'   => __( 'HTML tag', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => array_combine( self::TAGS, self::TAGS ),
				'default' => 'div',
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
		$this->end_controls_section();
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$postId     = (int) get_the_ID();
		$templateId = get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0;
		$context    = PageFieldResolver::resolveGroupContext( $postId, $templateId );
		$decision   = self::decide( (array) ( $settings['cases'] ?? [] ), (string) ( $settings['else_source'] ?? 'none' ) !== 'none', $context['group'] ?? null );
		$tag        = in_array( $settings['html_tag'] ?? 'div', self::TAGS, true ) ? (string) $settings['html_tag'] : 'div';

		if ( $decision['client'] === null ) {
			$html = $decision['chosen'] === null ? '' : $this->output( $settings, $decision['chosen'], $context );
			if ( $html !== '' ) {
				echo '<' . tag_escape( $tag ) . ' class="rowsprout-conditional">' . $html . '</' . tag_escape( $tag ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- formatted by PropertyBindingSource::format() or the widget's own text.
			}
		} else {
			// Every output that can still match; the script shows the right one.
			echo '<div class="rowsprout-conditional-cases" data-rs-conditional="' . esc_attr( (string) wp_json_encode( $decision['client'] ) ) . '">';
			foreach ( $decision['entries'] as $entry ) {
				echo '<' . tag_escape( $tag ) . ' class="rowsprout-conditional" data-rs-case="' . esc_attr( (string) $entry ) . '"' . ( $entry === $decision['chosen'] ? '' : ' hidden' ) . '>';
				echo $this->output( $settings, $entry, $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above.
				echo '</' . tag_escape( $tag ) . '>';
			}
			echo '</div>';
		}

		if ( self::isEditor() ) {
			$this->renderEditorNote( $decision, $context, $settings );
		}
	}

	/**
	 * Which output a group gets. "chosen" is a case index or "else" (null:
	 * nothing); "client" is set when rules depend on today: then "entries"
	 * lists every output that can still match, in order, and "client" holds
	 * what the script needs to pick one (the other rules already decided).
	 *
	 * @param array<int, mixed>         $cases
	 * @param array<string, mixed>|null $group
	 * @param string|null               $today Y-m-d, for tests.
	 * @return array{chosen: int|string|null, entries: array<int, int|string>, client: array<string, mixed>|null, missing: array<int, string>, empty: array<int, int>, changed: array<int, array{0: int, 1: int}>}
	 */
	public static function decide( array $cases, bool $hasElse, ?array $group, ?string $today = null ): array {
		$entries = [];
		$client  = [];
		$chosen  = null;
		$missing = [];
		$empty   = [];
		$changed = [];
		$relative = false;
		$final   = false;

		foreach ( array_values( $cases ) as $index => $case ) {
			$case  = is_array( $case ) ? $case : [];
			$rules = self::rules( $case );
			if ( $rules === [] ) {
				$empty[] = $index;
				continue;
			}
			$static  = [];
			$dynamic = [];
			$results = [];
			foreach ( $rules as $rule ) {
				$field = $group === null ? null : PageFieldResolver::getFieldByCode( $group, $rule['field'] );
				if ( $field === null ) {
					$missing[] = $rule['field'];
					$static[]  = false;
					$results[] = false;
					continue;
				}
				$fallback = false;
				$operator = self::operatorFor( $rule, ConditionEvaluator::kindOf( $field['type'] ), $fallback );
				if ( $fallback ) {
					$changed[] = [ $index, $rule['slot'] ];
				}
				$result = ConditionEvaluator::test( $field['value'], $field['type'], $operator, $rule['value'], $rule['value2'], $today );
				$day    = ConditionEvaluator::isRelative( $operator ) ? ConditionEvaluator::dayOf( $field['value'], $field['type'] ) : null;
				if ( $day !== null ) {
					$dynamic[] = [ 'd' => $day, 'o' => $operator, 'n' => max( 0, (int) $rule['value'] ) ];
				} else {
					$static[] = $result;
				}
				$results[] = $result;
			}
			$match   = ( $case['match'] ?? '' ) === ConditionEvaluator::MATCH_ANY ? ConditionEvaluator::MATCH_ANY : ConditionEvaluator::MATCH_ALL;
			$matches = ConditionEvaluator::matches( $results, $match );
			if ( $chosen === null && $matches ) {
				$chosen = $index;
			}

			if ( $dynamic === [] ) {
				if ( $matches ) {
					// Always shown when reached: later cases and "Otherwise" never are.
					$entries[] = $index;
					$client[]  = [ 'i' => $index, 'm' => ConditionEvaluator::MATCH_ALL, 's' => [ true ], 'r' => [] ];
					$final     = true;
					break;
				}
				continue;
			}
			$relative  = true;
			$entries[] = $index;
			$client[]  = [ 'i' => $index, 'm' => $match, 's' => $static, 'r' => $dynamic ];
		}

		if ( ! $final && $hasElse ) {
			$entries[] = 'else';
		}
		if ( $chosen === null && $hasElse ) {
			$chosen = 'else';
		}

		return [
			'chosen'  => $chosen,
			'entries' => $relative ? $entries : [],
			'client'  => $relative ? [ 'offset' => (int) round( wp_timezone()->getOffset( new \DateTimeImmutable( 'now' ) ) / 60 ), 'cases' => $client ] : null,
			'missing' => array_values( array_unique( $missing ) ),
			'empty'   => $empty,
			'changed' => $changed,
		];
	}

	/**
	 * A case's rules: rule 1 always, rules 2 and 3 when switched on, each
	 * with a property chosen.
	 *
	 * @param array<string, mixed> $case
	 * @return array<int, array{slot: int, field: string, operators: array<string, string>, value: string, value2: string}>
	 */
	private static function rules( array $case ): array {
		$rules = [];
		for ( $slot = 1; $slot <= self::RULE_SLOTS; $slot++ ) {
			if ( $slot > 1 && ( $case[ 'rule' . $slot . '_on' ] ?? '' ) !== 'yes' ) {
				continue;
			}
			$field = (string) ( $case[ 'rule' . $slot . '_field' ] ?? '' );
			if ( $field === '' ) {
				continue;
			}
			$operators = [];
			foreach ( ConditionEvaluator::kinds() as $kind ) {
				if ( isset( $case[ 'rule' . $slot . '_op_' . $kind ] ) ) {
					$operators[ $kind ] = (string) $case[ 'rule' . $slot . '_op_' . $kind ];
				}
			}
			$rules[] = [
				'slot'      => $slot,
				'field'     => $field,
				'operators' => $operators,
				'value'     => (string) ( $case[ 'rule' . $slot . '_value' ] ?? '' ),
				'value2'    => (string) ( $case[ 'rule' . $slot . '_value2' ] ?? '' ),
			];
		}

		return $rules;
	}

	/**
	 * The operator a rule uses for its property's kind: the one chosen in that
	 * kind's list, else the kind's default ($fallback then says so: the list
	 * had something that does not fit, or nothing while other kinds' lists
	 * had a choice, e.g. after the property's type changed).
	 *
	 * @param array{operators: array<string, string>} $rule
	 */
	private static function operatorFor( array $rule, string $kind, bool &$fallback ): string {
		$chosen   = $rule['operators'][ $kind ] ?? '';
		$fallback = false;
		if ( isset( ConditionEvaluator::operatorsFor( $kind )[ $chosen ] ) ) {
			return $chosen;
		}
		$fallback = $chosen !== '' || $rule['operators'] !== [];

		return ConditionEvaluator::defaultOperator( $kind );
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
	 * Terms for "this rule's property is one of $codes and that kind's
	 * operator is one of $operators".
	 *
	 * @param array<int, string> $codes
	 * @param array<int, string> $operators
	 * @return array<string, mixed>
	 */
	private static function termsFor( int $slot, array $codes, string $control, array $operators ): array {
		return [
			'relation' => 'and',
			'terms'    => [
				[ 'name' => 'rule' . $slot . '_field', 'operator' => 'in', 'value' => $codes ],
				[ 'name' => $control, 'operator' => 'in', 'value' => $operators ],
			],
		];
	}

	/**
	 * Shown when one of $alternatives holds (and rule 2/3 is switched on).
	 *
	 * @param array<int, array<string, mixed>> $alternatives
	 * @return array<string, mixed>
	 */
	private static function visibleWhen( int $slot, array $alternatives ): array {
		// Nothing can need it: a condition that never holds.
		$any   = $alternatives === [] ? [ [ 'name' => 'rule' . $slot . '_field', 'operator' => '===', 'value' => '__never__' ] ] : $alternatives;
		$terms = [ [ 'relation' => 'or', 'terms' => $any ] ];
		if ( $slot > 1 ) {
			array_unshift( $terms, [ 'name' => 'rule' . $slot . '_on', 'operator' => '===', 'value' => 'yes' ] );
		}

		return [ 'relation' => 'and', 'terms' => $terms ];
	}

	/**
	 * The HTML of one output: a case's or "Otherwise".
	 *
	 * @param array<string, mixed>      $settings
	 * @param int|string                $entry
	 * @param array<string, mixed>|null $context
	 */
	private function output( array $settings, $entry, ?array $context ): string {
		if ( $entry === 'else' ) {
			$source   = (string) ( $settings['else_source'] ?? 'none' );
			$property = (string) ( $settings['else_property'] ?? '' );
			$text     = (string) ( $settings['else_text'] ?? '' );
		} else {
			$case     = (array) ( array_values( (array) ( $settings['cases'] ?? [] ) )[ (int) $entry ] ?? [] );
			$source   = (string) ( $case['source'] ?? 'property' );
			$property = (string) ( $case['property'] ?? '' );
			$text     = (string) ( $case['text'] ?? '' );
		}

		if ( $source === 'text' ) {
			if ( $context !== null && strpos( $text, '@code_' ) !== false ) {
				// Generated pages had their tokens replaced already; this is the editor's preview.
				$templateId = (int) $context['template_id'];
				$text       = PageBuilder::applyGroupPlaceholderTokensToMarkup( $text, (array) $context['group'], $templateId, (int) wp_get_post_parent_id( $templateId ), $templateId );
			}

			return trim( $text ) === '' ? '' : $this->parse_text_editor( $text );
		}
		if ( $source !== 'property' || $property === '' || $context === null ) {
			return '';
		}
		$field = PageFieldResolver::getFieldByCode( (array) $context['group'], $property );

		return $field === null ? '' : (string) PropertyBindingSource::format( $field['value'], $field['type'], 'content' );
	}

	/**
	 * In the editor only: which case the preview group gets, and rules on a
	 * property the template no longer has.
	 *
	 * @param array<string, mixed>      $decision
	 * @param array<string, mixed>|null $context
	 * @param array<string, mixed>      $settings
	 */
	private function renderEditorNote( array $decision, ?array $context, array $settings ): void {
		$cases = array_values( (array) ( $settings['cases'] ?? [] ) );
		if ( $context === null ) {
			$line = __( 'This template has no groups yet, so no case can be checked.', 'rowsprout' );
		} elseif ( $decision['chosen'] === 'else' ) {
			$line = __( 'Preview group: no case matches, so "Otherwise" is shown.', 'rowsprout' );
		} elseif ( $decision['chosen'] === null ) {
			$line = __( 'Preview group: no case matches, and "Otherwise" shows nothing.', 'rowsprout' );
		} else {
			$label = (string) ( $cases[ (int) $decision['chosen'] ]['case_label'] ?? '' );
			/* translators: 1: case number, 2: case name. */
			$line = sprintf( __( 'Preview group: case %1$d (%2$s) matches.', 'rowsprout' ), (int) $decision['chosen'] + 1, $label );
		}
		$notes = [ $line ];
		if ( $decision['client'] !== null ) {
			$notes[] = __( 'A rule depends on today: the page checks it again when it is shown.', 'rowsprout' );
		}
		if ( $decision['missing'] !== [] && $context !== null ) {
			/* translators: %s: comma-separated property codes. */
			$notes[] = sprintf( __( 'Not in this template (these rules never match): %s', 'rowsprout' ), implode( ', ', $decision['missing'] ) );
		}
		foreach ( $decision['changed'] as [ $index, $slot ] ) {
			/* translators: 1: case number, 2: rule number. */
			$notes[] = sprintf( __( 'Case %1$d, rule %2$d: the operator does not fit the property\'s type (changed?), so "is not empty" / "is checked" is used. Choose the operator again.', 'rowsprout' ), (int) $index + 1, (int) $slot );
		}
		foreach ( $decision['empty'] as $index ) {
			/* translators: %d: case number. */
			$notes[] = sprintf( __( 'Case %d has no rule with a property yet and is skipped.', 'rowsprout' ), (int) $index + 1 );
		}

		echo '<div class="rowsprout-conditional-note" style="margin-top:6px;padding:4px 8px;border-left:3px solid #93003c;background:#fdf2f6;color:#1d2327;font:12px/1.5 sans-serif">';
		foreach ( $notes as $note ) {
			echo '<div>' . esc_html( $note ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * Properties an output can show (not images, files or icons).
	 *
	 * @return array<string, string>
	 */
	private static function outputOptions(): array {
		$templateId = PageFieldResolver::resolveCurrentTemplateId();
		$types      = [];
		foreach ( (array) ( TemplateMeta::get( $templateId )['field_types'] ?? [] ) as $definition ) {
			if ( is_array( $definition ) && ! in_array( (string) ( $definition['type'] ?? '' ), self::NO_OUTPUT_TYPES, true ) ) {
				$types[] = (string) ( $definition['type'] ?? '' );
			}
		}

		return $types === [] ? PageFieldResolver::getFieldCodeOptions( [] ) : PageFieldResolver::getFieldCodeOptions( array_values( array_unique( $types ) ) );
	}

	private static function isEditor(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$elementor = \Elementor\Plugin::$instance;

		return ( isset( $elementor->editor ) && $elementor->editor->is_edit_mode() ) || ( isset( $elementor->preview ) && $elementor->preview->is_preview_mode() );
	}
}
