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
 * Renders a chosen textarea-type field as a text block.
 */
final class TextareaWidget extends Widget_Base {

	public function get_name() {
		return 'rowsprout_page_textarea_widget';
	}

	public function get_title() {
		return __( 'Textarea', 'rowsprout' );
	}

	public function get_icon() {
		return 'eicon-text-area';
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
				'options' => PageFieldResolver::getFieldCodeOptions( 'textarea' ),
				'default' => '',
			]
		);

		$this->add_control(
			'fallback_text',
			[
				'label'   => __( 'Fallback text', 'rowsprout' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 4,
				'default' => __( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.', 'rowsprout' ),
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
				'selector' => '{{WRAPPER}} .rowsprout-page-textarea',
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => __( 'Text color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .rowsprout-page-textarea' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings  = $this->get_settings_for_display();
		$fieldCode = isset( $settings['field_code'] ) ? (string) $settings['field_code'] : '';
		$text      = '';

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

		echo '<div class="rowsprout-page-textarea">' . nl2br( esc_html( $text ) ) . '</div>';
	}
}
