<?php

namespace RowSprout\Core\BlockBindings;

use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block Bindings source "rowsprout/property": lets core blocks (Paragraph,
 * Heading, List item, Button, Image, Post Date, …) take an attribute from a
 * template property, picked in the block editor's Attributes panel
 * (assets/js/block-editor-bindings.js registers the editor side).
 *
 * Unlike an inline @code_…@ token, a binding is resolved when the page is
 * RENDERED: the generated page's post_content keeps the binding, and
 * getValue() looks up that page's group (PageFieldResolver — generic despite
 * its Elementor namespace; on a template it falls back to the preview/first
 * group). Returning null keeps the block's own content, so an empty property
 * shows whatever the author typed as fallback.
 *
 * format() is shared with the editor preview (TemplateBlockEditor), so what
 * the editor shows is exactly what the page renders. Rich-text attributes
 * (content, text) get HTML: escaped text, line breaks for Textarea, one line
 * per item for Item-list, the site's date format for dates, Yes/No for a
 * checkbox. URL attributes get a URL (mailto: for Email). Plain attributes
 * (alt, title, …) get text; core escapes them.
 *
 * Block Bindings exist since WP 6.5 (the editor's field picker since 6.9);
 * on older versions this simply registers nothing.
 */
final class PropertyBindingSource {

	public const NAME = 'rowsprout/property';

	private const URL_ATTRIBUTES      = [ 'url' ];
	private const DATE_ATTRIBUTES     = [ 'datetime' ];
	private const RICH_ATTRIBUTES     = [ 'content', 'text', 'caption' ];
	private const IGNORED_ATTRIBUTES  = [ 'linkTarget', 'rel', 'id' ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'registerSource' ] );
	}

	public static function registerSource(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			self::NAME,
			[
				'label'              => __( 'RowSprout property', 'rowsprout' ),
				'get_value_callback' => [ self::class, 'getValue' ],
				'uses_context'       => [ 'postId', 'postType' ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $sourceArgs     { key: property code }
	 * @param \WP_Block            $blockInstance
	 * @param string               $attributeName
	 * @return string|null
	 */
	public static function getValue( array $sourceArgs, $blockInstance, string $attributeName ) {
		$code = sanitize_key( (string) ( $sourceArgs['key'] ?? '' ) );
		if ( $code === '' ) {
			return null;
		}

		$postId = (int) ( $blockInstance->context['postId'] ?? 0 );
		if ( $postId <= 0 ) {
			$postId = (int) get_the_ID();
		}

		$context = PageFieldResolver::resolveGroupContext( $postId, get_post_type( $postId ) === PostTypes::TEMPLATE ? $postId : 0 );
		if ( $context === null ) {
			return null;
		}

		$field = PageFieldResolver::getFieldByCode( $context['group'], $code );

		return $field === null ? null : self::format( $field['value'], $field['type'], $attributeName );
	}

	/**
	 * @return string|null null = keep the block's own value
	 */
	public static function format( string $value, string $type, string $attributeName ): ?string {
		if ( $value === '' || in_array( $attributeName, self::IGNORED_ATTRIBUTES, true ) ) {
			return null;
		}

		if ( in_array( $attributeName, self::URL_ATTRIBUTES, true ) ) {
			return self::formatUrl( $value, $type );
		}

		if ( in_array( $attributeName, self::DATE_ATTRIBUTES, true ) ) {
			/**
			 * Value for a date attribute (the Post Date block). None of the free
			 * types is a date; an add-on that adds one returns the value here.
			 *
			 * @param string|null $date null = keep the block's own value
			 */
			$date = apply_filters( 'rowsprout_property_binding_date', null, $value, $type );
			return is_string( $date ) && $date !== '' ? $date : null;
		}

		$html = self::formatHtml( $value, $type );
		if ( in_array( $attributeName, self::RICH_ATTRIBUTES, true ) ) {
			return $html;
		}

		// Plain attributes (alt, title, …) are escaped by core when the block
		// renders, so hand over unescaped text or it ends up escaped twice.
		return trim( wp_specialchars_decode( wp_strip_all_tags( str_replace( '<br>', ' ', $html ) ), ENT_QUOTES ) );
	}

	private static function formatUrl( string $value, string $type ): ?string {
		if ( $type === 'email' ) {
			return is_email( $value ) ? 'mailto:' . $value : null;
		}

		/**
		 * URL for a property type an add-on registers (e.g. a phone or file
		 * type): a string handles it ('' = keep the block's own value), null
		 * leaves it to the default below.
		 *
		 * @param string|null $url
		 */
		$filtered = apply_filters( 'rowsprout_property_binding_url', null, $value, $type );
		if ( is_string( $filtered ) ) {
			return $filtered !== '' ? $filtered : null;
		}

		$url = esc_url_raw( $value );

		return $url !== '' ? $url : null;
	}

	private static function formatHtml( string $value, string $type ): string {
		if ( $type === 'textarea' ) {
			return nl2br( esc_html( $value ), false );
		}

		/**
		 * HTML for a property type an add-on registers: a string handles it
		 * (already escaped), null leaves it to the plain-text default.
		 *
		 * @param string|null $html
		 */
		$filtered = apply_filters( 'rowsprout_property_binding_html', null, $value, $type );

		return is_string( $filtered ) ? $filtered : esc_html( $value );
	}

	/**
	 * For the editor: every property of the template as a bindable field,
	 * with preview values for each kind of attribute, taken from the same
	 * group the page would show (preview/first group), through format().
	 *
	 * @return array<int, array{label:string, key:string, preview:array{content:?string, url:?string, datetime:?string, plain:?string}}>
	 */
	public static function editorFields( int $templateId ): array {
		$definitions = TemplateMeta::getFieldTypeDefinitions();
		$context     = PageFieldResolver::resolveGroupContext( $templateId, $templateId );
		$fields      = [];

		foreach ( (array) ( TemplateMeta::get( $templateId )['field_types'] ?? [] ) as $fieldType ) {
			if ( ! is_array( $fieldType ) ) {
				continue;
			}

			$code = sanitize_key( (string) ( $fieldType['code'] ?? '' ) );
			$type = (string) ( $fieldType['type'] ?? '' );
			if ( $code === '' ) {
				continue;
			}

			$field = $context !== null ? PageFieldResolver::getFieldByCode( $context['group'], $code ) : null;
			$value = $field['value'] ?? '';

			$fields[] = [
				'label'   => (string) ( $fieldType['label'] ?? $code ) . ' (' . (string) ( $definitions[ $type ]['label'] ?? $type ) . ')',
				'key'     => $code,
				'preview' => [
					'content'  => self::format( $value, $type, 'content' ),
					'url'      => self::format( $value, $type, 'url' ),
					'datetime' => self::format( $value, $type, 'datetime' ),
					'plain'    => self::format( $value, $type, 'alt' ),
				],
			];
		}

		return $fields;
	}
}
