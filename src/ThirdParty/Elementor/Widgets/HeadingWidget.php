<?php

namespace RowSprout\ThirdParty\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use RowSprout\Core\PostTypes;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copy of TextareaWidget for the 'textfield' property type ("Text field"
 * — short free text/a single word, see TextfieldFieldType) instead of
 * 'textarea', with an added control for which heading tag (h1-h6) wraps the
 * resolved text — for template content like an H2/H3 that should vary per
 * group (e.g. a location name), the same way TextareaWidget already
 * does for longer body text.
 */
final class HeadingWidget extends Widget_Base {

	public function get_name() {
		return 'rowsprout_page_heading_widget';
	}

	public function get_title() {
		return __( 'Heading', 'rowsprout' );
	}

	public function get_icon() {
		return 'eicon-t-letter';
	}

	public function get_categories() {
		return [ 'rowsprout_page' ];
	}

	public function show_in_panel() {
		return PageFieldResolver::isRowSproutPostType();
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			[
				'label' => __( 'Content', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'field_code',
			[
				'label'   => __( 'Text field', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => PageFieldResolver::getFieldCodeOptions( 'textfield' ),
				'default' => '',
			]
		);

		$this->add_control(
			'heading_tag',
			[
				'label'   => __( 'HTML tag', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'h1' => 'H1',
					'h2' => 'H2',
					'h3' => 'H3',
					'h4' => 'H4',
					'h5' => 'H5',
					'h6' => 'H6',
				],
				'default' => 'h2',
			]
		);

		$this->add_control(
			'fallback_text',
			[
				'label'   => __( 'Fallback text', 'rowsprout' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Lorem ipsum dolor sit amet', 'rowsprout' ),
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

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'text_typography',
				'selector' => '{{WRAPPER}} .rowsprout-page-heading-text',
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-page-heading-text' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$fieldCode  = isset( $settings['field_code'] ) ? (string) $settings['field_code'] : '';
		$headingTag = isset( $settings['heading_tag'] ) && preg_match( '/^h[1-6]$/', (string) $settings['heading_tag'] )
			? (string) $settings['heading_tag']
			: 'h2';
		$text = '';

		if ( $fieldCode !== '' ) {
			$postId     = (int) get_the_ID();
			$templateId = get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0;
			$context    = PageFieldResolver::resolveGroupContext( $postId, $templateId );

			if ( $context !== null ) {
				$field = PageFieldResolver::getFieldByCode( $context['group'], $fieldCode );
				if ( $field !== null ) {
					$text = $field['value'];
				}
			}
		}

		if ( $text === '' ) {
			$text = isset( $settings['fallback_text'] ) ? (string) $settings['fallback_text'] : '';
		}

		if ( $text === '' ) {
			return;
		}

		printf(
			'<%1$s class="rowsprout-page-heading-text">%2$s</%1$s>',
			esc_attr( $headingTag ),
			esc_html( $text )
		);
	}
}
