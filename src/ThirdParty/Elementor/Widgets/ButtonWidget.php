<?php

namespace RowSprout\ThirdParty\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Includes\Widgets\Traits\Button_Trait;
use Elementor\Widget_Base;
use RowSprout\Core\PostTypes;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A call-to-action button whose link comes from a property: URL as-is,
 * Email as mailto:, Phone (RowSprout Pro's 'tel' type, listed only when such a
 * property exists) as tel:. The label is the button text or, optionally, a
 * Text field property.
 *
 * Reuses Elementor's own Button_Trait (the code behind Elementor's Button
 * widget, public since 3.4) for every content/style control and for the
 * markup, so it looks and behaves like a native button. Its 'link' control is
 * removed — the link is the property's value — and content_template() is
 * overridden to nothing so the editor preview is rendered server-side with
 * the resolved property instead of the trait's JS template.
 *
 * On a generated page an empty link property hides the button (a CTA that
 * goes nowhere is worse than none); while editing the template it still
 * shows, linking to '#', so it can be styled.
 */
final class ButtonWidget extends Widget_Base {
	use Button_Trait;

	/**
	 * Property type => href prefix.
	 *
	 * @var array<string, string>
	 */
	private const LINK_TYPES = [
		'url'   => '',
		'email' => 'mailto:',
		'tel'   => 'tel:',
	];

	/**
	 * Link/text resolved from properties for the render in progress.
	 *
	 * @var array<string, mixed>
	 */
	private $resolved = [];

	public function get_name() {
		return 'rowsprout_page_button_widget';
	}

	public function get_title() {
		return __( 'Button', 'rowsprout' );
	}

	public function get_icon() {
		return 'eicon-button';
	}

	public function get_categories() {
		return [ 'rowsprout_page' ];
	}

	public function get_keywords() {
		return [ 'rowsprout', 'button', 'link', 'email', 'cta' ];
	}

	public function show_in_panel() {
		return PageFieldResolver::isRowSproutPostType();
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_rowsprout_link',
			[
				'label' => __( 'Link', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'link_field_code',
			[
				'label'       => __( 'Link property', 'rowsprout' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => PageFieldResolver::getFieldCodeOptions( array_keys( self::LINK_TYPES ) ),
				'default'     => '',
				'description' => __( 'URL, Email (opens the mail app) or Phone (calls).', 'rowsprout' ),
			]
		);

		$this->add_control(
			'link_new_tab',
			[
				'label' => __( 'Open in new tab', 'rowsprout' ),
				'type'  => Controls_Manager::SWITCHER,
			]
		);

		$this->add_control(
			'link_nofollow',
			[
				'label' => __( 'Add nofollow', 'rowsprout' ),
				'type'  => Controls_Manager::SWITCHER,
			]
		);

		$this->add_control(
			'text_field_code',
			[
				'label'       => __( 'Text property', 'rowsprout' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [ '' => __( '-- Use the button text --', 'rowsprout' ) ] + array_slice( PageFieldResolver::getFieldCodeOptions( 'textfield' ), 1, null, true ),
				'default'     => '',
				'description' => __( 'Optional: take the button text from a Text field property.', 'rowsprout' ),
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_button',
			[
				'label' => __( 'Button', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->register_button_content_controls();
		$this->remove_control( 'link' );

		$this->end_controls_section();

		$this->start_controls_section(
			'section_style',
			[
				'label' => __( 'Button', 'rowsprout' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->register_button_style_controls();

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$postId   = (int) get_the_ID();
		$editing  = get_post_type( $postId ) === PostTypes::TEMPLATE;
		$context  = PageFieldResolver::resolveGroupContext( $postId, $editing ? $postId : 0 );
		$group    = $context['group'] ?? [];

		$href = '';
		$link = PageFieldResolver::getFieldByCode( $group, (string) ( $settings['link_field_code'] ?? '' ) );
		if ( $link !== null && $link['value'] !== '' && isset( self::LINK_TYPES[ $link['type'] ] ) ) {
			$value = $link['type'] === 'tel' ? preg_replace( '/[^0-9+]/', '', $link['value'] ) : $link['value'];
			$href  = esc_url_raw( self::LINK_TYPES[ $link['type'] ] . $value, [ 'http', 'https', 'mailto', 'tel' ] );
		}

		if ( $href === '' ) {
			if ( ! $editing ) {
				return;
			}
			$href = '#';
		}

		$this->resolved = [
			'link' => [
				'url'         => $href,
				'is_external' => ( $settings['link_new_tab'] ?? '' ) === 'yes' ? 'on' : '',
				'nofollow'    => ( $settings['link_nofollow'] ?? '' ) === 'yes' ? 'on' : '',
			],
		];

		$label = PageFieldResolver::getFieldByCode( $group, (string) ( $settings['text_field_code'] ?? '' ) );
		if ( $label !== null && $label['value'] !== '' ) {
			$this->resolved['text'] = $label['value'];
		}

		$this->render_button();
		$this->resolved = [];
	}

	/**
	 * Feeds the resolved link/text to Button_Trait::render_button(), which
	 * reads them through this method. Overriding here rather than calling
	 * set_settings(): Elementor caches the display settings before render()
	 * runs and set_settings() does not clear that cache (only the newer
	 * reset_render_state() does, which older Elementor versions lack).
	 *
	 * @param string|null $setting_key
	 * @return mixed
	 */
	public function get_settings_for_display( $setting_key = null ) {
		if ( empty( $this->resolved ) ) {
			return parent::get_settings_for_display( $setting_key );
		}

		$settings = array_merge( (array) parent::get_settings_for_display(), $this->resolved );

		return $setting_key === null ? $settings : ( $settings[ $setting_key ] ?? null );
	}

	/**
	 * Intentionally empty: without a JS template Elementor renders the editor
	 * preview through render(), so it shows the resolved property.
	 */
	protected function content_template() {}
}
