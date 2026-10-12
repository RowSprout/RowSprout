<?php

namespace RowSprout\ThirdParty\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use RowSprout\Core\Grid\PageGridRenderer;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every generated page of ONE selected template. Elementor matches
 * stored content by get_name() ('rowsprout_page_grid'), so never change it
 * without a content migration.
 *
 * Filters to rowsprout_page_id > 0: a group without a page yet would
 * otherwise call get_the_title()/get_permalink() on 0 and render broken
 * links. Deliberately NOT also filtered to status='completed': a group
 * planned for a refresh (Pro's Planning tab) has status 'scheduled' while
 * its existing page stays live, so rowsprout_page_id is the reliable
 * signal. Links between sibling pages of different templates are a
 * separate widget in RowSprout Pro (get_name() 'rowsprout_sibling_links').
 */
final class GridWidget extends Widget_Base {

	public function get_name() {
		return 'rowsprout_page_grid';
	}

	public function get_title() {
		return __( 'Grid', 'rowsprout' );
	}

	public function get_icon() {
		return 'eicon-post-list';
	}

	public function get_categories() {
		return [ 'rowsprout_pages' ];
	}

	// Deliberately no show_in_panel() override (unlike every other widget in
	// this namespace, which all gate on PageFieldResolver::isRowSproutPostType()):
	// this widget resolves a SELECTED template's own generated locations, not
	// anything about the CURRENT post, so it's just as usable on a regular
	// post/page as on a rowsprout_page/rowsprout_template. Falls through to
	// Widget_Base's own default (always shown).

	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			[
				'label' => __( 'Settings', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'rowsprout_page',
			[
				'label'   => __( 'RowSprout template', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => PageFieldResolver::getTemplateOptionsForSelect(),
				'default' => '',
			]
		);

		$this->add_control(
			'sort_direction',
			[
				'label'   => __( 'Sort direction', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'column',
				'options' => [
					'row'    => __( 'By row (horizontal)', 'rowsprout' ),
					'column' => __( 'By column (vertical)', 'rowsprout' ),
				],
				'selectors' => [
					'{{WRAPPER}} .rowsprout-page-grid' => 'grid-auto-flow: {{VALUE}}',
				],
			]
		);

		$this->add_responsive_control(
			'columns',
			[
				'label'          => __( 'Columns', 'rowsprout' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 6,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'selectors'      => [
					'{{WRAPPER}} .rowsprout-page-grid' => 'display: grid; grid-template-columns: repeat({{VALUE}}, 1fr);',
				],
				'condition' => [ 'sort_direction' => 'row' ],
			]
		);

		$this->add_responsive_control(
			'rows',
			[
				'label'          => __( 'Rows', 'rowsprout' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 40,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'selectors'      => [
					'{{WRAPPER}} .rowsprout-page-grid' => 'display: grid; grid-template-rows: repeat({{VALUE}}, 1fr);',
				],
				'condition' => [ 'sort_direction' => 'column' ],
			]
		);

		$this->add_control(
			'layout_type',
			[
				'label'   => __( 'Layout Type', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'cart' => __( 'Card Layout', 'rowsprout' ),
					'list' => __( 'List Layout', 'rowsprout' ),
				],
				'default' => 'list',
			]
		);

		$this->add_control(
			'show_thumb',
			[
				'label'        => __( 'Show thumbnail', 'rowsprout' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'rowsprout' ),
				'label_off'    => __( 'No', 'rowsprout' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => [ 'layout_type' => 'cart' ],
			]
		);

		$this->add_control(
			'thumb_size',
			[
				'label'   => __( 'Thumbnail size', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'thumbnail' => __( 'Thumbnail', 'rowsprout' ),
					'medium'    => __( 'Medium', 'rowsprout' ),
					'large'     => __( 'Large', 'rowsprout' ),
					'full'      => __( 'Full', 'rowsprout' ),
				],
				'default'   => 'thumbnail',
				'condition' => [ 'show_thumb' => 'yes', 'layout_type' => 'cart' ],
				'selectors' => [
					'{{WRAPPER}} .rowsprout-page-thumb img.thumbnail' => 'width: 100%; object-fit: cover;',
				],
			]
		);

		$this->add_control(
			'show_title_type',
			[
				'label'   => __( 'Show title', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'parent',
				'options' => [
					'parent' => __( 'Parent title', 'rowsprout' ),
					'child'  => __( 'Child title', 'rowsprout' ),
				],
			]
		);

		$this->add_control(
			'sort_order',
			[
				'label'   => __( 'Sort items', 'rowsprout' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => [
					''     => __( 'No sorting', 'rowsprout' ),
					'asc'  => __( 'Alphabetical (A-Z)', 'rowsprout' ),
					'desc' => __( 'Alphabetical (Z-A)', 'rowsprout' ),
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_item',
			[
				'label' => __( 'Item', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'equal_height_css',
			[
				'type'      => Controls_Manager::HIDDEN,
				'default'   => 'yes',
				'selectors' => [
					'{{WRAPPER}} .rowsprout-page-item'       => 'display: flex; flex-direction: column;',
					'{{WRAPPER}} .rowsprout-page-item-inner' => 'height: 100%; display: flex; flex-direction: column;',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'item_border',
				'selector' => '{{WRAPPER}} .rowsprout-page-item .rowsprout-page-item-inner',
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'item_box_shadow',
				'selector' => '{{WRAPPER}} .rowsprout-page-item .rowsprout-page-item-inner',
			]
		);

		$this->add_responsive_control(
			'item_border_radius',
			[
				'label'      => __( 'Border radius', 'rowsprout' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .rowsprout-page-item .rowsprout-page-item-inner' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'item_padding',
			[
				'label'      => __( 'Padding', 'rowsprout' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .rowsprout-page-item .rowsprout-page-item-inner' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'column_gap',
			[
				'label'      => __( 'Gap between columns', 'rowsprout' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px'  => [ 'min' => 0, 'max' => 100 ],
					'em'  => [ 'min' => 0, 'max' => 10 ],
					'rem' => [ 'min' => 0, 'max' => 10 ],
				],
				'default'   => [ 'size' => 16, 'unit' => 'px' ],
				'selectors' => [ '{{WRAPPER}} .rowsprout-page-grid' => 'column-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'row_gap',
			[
				'label'      => __( 'Gap between rows', 'rowsprout' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px'  => [ 'min' => 0, 'max' => 100 ],
					'em'  => [ 'min' => 0, 'max' => 10 ],
					'rem' => [ 'min' => 0, 'max' => 10 ],
				],
				'default'   => [ 'size' => 16, 'unit' => 'px' ],
				'selectors' => [ '{{WRAPPER}} .rowsprout-page-grid' => 'row-gap: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_title',
			[
				'label' => __( 'Title', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => __( 'Color', 'rowsprout' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [ '{{WRAPPER}} .rowsprout-page-title' => 'color: {{VALUE}};' ],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .rowsprout-page-item-inner',
			]
		);

		$this->add_responsive_control(
			'title_padding',
			[
				'label'      => __( 'Padding', 'rowsprout' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em' ],
				'selectors'  => [
					'{{WRAPPER}} .rowsprout-page-title-wrapper' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style_thumb',
			[
				'label'     => __( 'Thumbnail', 'rowsprout' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [ 'show_thumb' => 'yes', 'layout_type' => 'cart' ],
			]
		);

		$this->add_responsive_control(
			'thumb_width',
			[
				'label'      => __( 'Max width', 'rowsprout' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 20, 'max' => 300 ] ],
				'selectors'  => [ '{{WRAPPER}} .rowsprout-page-thumb' => 'max-width: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'thumb_height',
			[
				'label'      => __( 'Height', 'rowsprout' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [ 'px' => [ 'min' => 20, 'max' => 300 ] ],
				'selectors'  => [ '{{WRAPPER}} .rowsprout-page-thumb img' => 'height: {{SIZE}}{{UNIT}};' ],
			]
		);

		$this->add_responsive_control(
			'thumb_border_radius',
			[
				'label'      => __( 'Border radius', 'rowsprout' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .rowsprout-page-thumb img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render(): void {
		echo wp_kses_post( PageGridRenderer::render( $this->get_settings_for_display() ) );
	}
}
