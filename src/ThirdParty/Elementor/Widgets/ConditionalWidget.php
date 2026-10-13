<?php

namespace RowSprout\ThirdParty\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Repeater;
use Elementor\Widget_Base;
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
 * "If / then" for Elementor: shows "Then" when the current page's property
 * values match the rules, else "Else". The rules, their evaluation and the
 * markup are the editor-independent Core\Conditions (RuleSet,
 * ConditionalOutput, ConditionalNotes); this widget adds Elementor's panel
 * (the rules as a repeater with an operator list per kind of property,
 * shown for the chosen property's kind; Then/Else as a property, own text
 * or, with Elementor Pro active, a saved Elementor template), the
 * template rendering and the preview note at the top of the Content tab.
 *
 * Resolved when the page is shown, like the other RowSprout widgets.
 */
final class ConditionalWidget extends Widget_Base {

	/** Output types without text to show. */
	private const NO_OUTPUT_TYPES = [ 'thumbnail', 'file', 'icon' ];

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
		return [ ConditionalOutput::SCRIPT_HANDLE ];
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
		$byKind     = RuleSet::codesByKind( PageFieldResolver::resolveCurrentTemplateId() );
		$sources    = [
			'property' => __( 'A property', 'rowsprout' ),
			'text'     => __( 'Own text', 'rowsprout' ),
		];
		if ( self::templatesAvailable() ) {
			$sources['template'] = __( 'An Elementor template', 'rowsprout' );
		}
		$templates = self::templatesAvailable() ? self::templateOptions() : [];

		// One section (Elementor needs one), with headings for If / Then / Else.
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
					RuleSet::JOIN_AND => __( 'and', 'rowsprout' ),
					RuleSet::JOIN_OR  => __( 'or', 'rowsprout' ),
				],
				'default'     => RuleSet::JOIN_AND,
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
				'default'     => [ [ 'join' => RuleSet::JOIN_AND ] ],
				// The property's label, and "· or" for a rule joined with "or".
				'title_field' => '<# var rsLabels = ' . wp_json_encode( array_diff_key( $properties, [ '' => true ] ) ) . '; #>{{ ( rsLabels[ rule_property ] || rule_property || ' . wp_json_encode( __( '(no property)', 'rowsprout' ) ) . ' ) + ( join === ' . wp_json_encode( RuleSet::JOIN_OR ) . ' ? ' . wp_json_encode( ' · ' . __( 'or', 'rowsprout' ) ) . ' : "" ) }}',
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
				'label'     => __( 'Else', 'rowsprout' ),
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
				'label' => __( 'Else', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);
		$this->add_control(
			'otherwise_note',
			[
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Only when "Else" is shown; what you leave empty follows "Text".', 'rowsprout' ),
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
	 * "Then" / "Else": the source and its property, text or template.
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
		// Then's tag is "html_tag"; Else's "else_html_tag", empty = the same as Then.
		$tags = array_combine( ConditionalOutput::TAGS, ConditionalOutput::TAGS );
		$this->add_control(
			$prefix . 'html_tag',
			[
				'label'       => __( 'HTML tag', 'rowsprout' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $prefix === '' ? $tags : [ '' => __( 'Same as Then', 'rowsprout' ) ] + $tags,
				'default'     => $prefix === '' ? 'div' : '',
				'condition'   => [ $prefix . 'source' => [ 'property', 'text' ] ],
				'description' => __( 'Several paragraphs, a bulleted or numbered list are always in a div.', 'rowsprout' ),
			]
		);
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$postId     = (int) get_the_ID();
		$templateId = get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0;
		$context    = PageFieldResolver::resolveGroupContext( $postId, $templateId );
		$result     = ConditionalOutput::render( $settings, $context, [
			'text'     => function ( string $text ): string {
				return $this->parse_text_editor( $text );
			},
			'template' => static function ( int $id ): string {
				return self::renderTemplate( $id );
			},
		] );
		echo $result['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by ConditionalOutput: formatted properties, the widget's own text, Elementor's template.

		if ( self::isEditor() ) {
			$this->renderEditorNote( $result['decision'], $context, $settings );
		}
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
	 * The editor note (ConditionalNotes, plus a template that needs Elementor
	 * Pro), hidden in the canvas: editorScript() shows it at the top of the
	 * Content tab.
	 *
	 * @param array<string, mixed>      $decision
	 * @param array<string, mixed>|null $context
	 * @param array<string, mixed>      $settings
	 */
	private function renderEditorNote( array $decision, ?array $context, array $settings ): void {
		$notes = ConditionalNotes::messages( $decision, $context, $settings );
		foreach ( [ '' => __( 'Then', 'rowsprout' ), 'else_' => __( 'Else', 'rowsprout' ) ] as $prefix => $label ) {
			if ( ( $settings[ $prefix . 'source' ] ?? '' ) === 'template' && ! self::templatesAvailable() ) {
				/* translators: %s: "Then" or "Else". */
				$notes[] = sprintf( __( '%s shows an Elementor template, which needs Elementor Pro: nothing is shown.', 'rowsprout' ), $label );
			}
		}

		echo '<div class="rowsprout-conditional-note" hidden>';
		foreach ( $notes as $note ) {
			echo '<div>' . esc_html( $note ) . '</div>';
		}
		echo '</div>';
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
