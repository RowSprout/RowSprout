<?php

namespace RowSprout\Core\Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces @code_…@ placeholder tokens in markup without breaking it.
 *
 * A plain str_replace() pastes a group value in raw. In running text that is
 * what we want — RowSprout Pro's Rich text property is meant to land as HTML —
 * but a value with a double quote (allowed by sanitize_text_field(), so most
 * property types) breaks the markup around it:
 *
 * - inside an HTML attribute (alt="@code_…@" → alt="Keuken "op maat""), and
 * - inside a block's attribute JSON in its <!-- wp:… {…} --> delimiter,
 *   after which parse_blocks() drops all of that block's attributes.
 *
 * So: block content is parsed, tokens are replaced in the DECODED attributes
 * and in each block's own HTML, and serialize_blocks() re-encodes the JSON.
 * In HTML, attribute values that contain a token are rewritten through
 * WP_HTML_Tag_Processor::set_attribute(), which escapes them (esc_url() for
 * URL attributes such as href/src). Everything else — text — keeps the
 * previous raw replacement.
 *
 * WP_HTML_Tag_Processor exists since WP 6.2; without it attributes fall back
 * to the plain replacement (the old behaviour).
 */
final class MarkupTokenReplacer {

	/**
	 * @param array<string, string> $tokens token => value
	 */
	public static function replace( string $value, array $tokens ): string {
		if ( $tokens === [] || ( strpos( $value, '@code_' ) === false && strpos( $value, '%40code_' ) === false ) ) {
			return $value;
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $value ) ) {
			return self::replaceInBlocks( $value, $tokens );
		}

		return self::replaceInHtml( $value, $tokens );
	}

	/**
	 * @param array<string, string> $tokens
	 */
	private static function replaceInBlocks( string $content, array $tokens ): string {
		$blocks = parse_blocks( $content );
		foreach ( $blocks as $index => $block ) {
			$blocks[ $index ] = self::replaceInBlock( $block, $tokens );
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * @param array<string, mixed>  $block
	 * @param array<string, string> $tokens
	 * @return array<string, mixed>
	 */
	private static function replaceInBlock( array $block, array $tokens ): array {
		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			array_walk_recursive(
				$block['attrs'],
				static function ( &$item ) use ( $tokens ) {
					if ( is_string( $item ) ) {
						$item = self::replaceInHtml( $item, $tokens );
					}
				}
			);
		}

		// serialize_blocks() writes innerContent (strings, with null where an
		// inner block goes); innerHTML is kept in step for anyone reading it.
		$block['innerHTML'] = self::replaceInHtml( (string) ( $block['innerHTML'] ?? '' ), $tokens );
		foreach ( (array) ( $block['innerContent'] ?? [] ) as $index => $chunk ) {
			if ( is_string( $chunk ) ) {
				$block['innerContent'][ $index ] = self::replaceInHtml( $chunk, $tokens );
			}
		}
		foreach ( (array) ( $block['innerBlocks'] ?? [] ) as $index => $inner ) {
			$block['innerBlocks'][ $index ] = self::replaceInBlock( $inner, $tokens );
		}

		return $block;
	}

	/**
	 * @param array<string, string> $tokens
	 */
	private static function replaceInHtml( string $html, array $tokens ): string {
		if ( strpos( $html, '@code_' ) === false && strpos( $html, '%40code_' ) === false ) {
			return $html;
		}

		if ( strpos( $html, '[' ) !== false ) {
			$html = self::replaceInShortcodeAttributes( $html, $tokens );
		}

		if ( strpos( $html, '<' ) !== false && class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$processor = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag() ) {
				foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
					$attribute = $processor->get_attribute( $name );
					if ( is_string( $attribute ) && strpos( $attribute, '@code_' ) !== false ) {
						$processor->set_attribute( $name, self::replacePlain( $attribute, $tokens ) );
					}
				}
			}
			$html = $processor->get_updated_html();
		}

		return self::replacePlain( $html, $tokens );
	}

	/**
	 * A shortcode attribute value can't hold a raw double quote, so how a value
	 * must be encoded there depends on the page builder that wrote it — e.g.
	 * WPBakery stores " as `` and [ ] as `{` `}` (see
	 * ThirdParty\WPBakery\WPBakeryIntegration). Without an encoder from filter
	 * rowsprout_shortcode_attribute_encoder, shortcodes keep the plain replacement.
	 *
	 * The encoder also gets the url-encoded form of each token (%40code_…%40),
	 * which builders use in link fields, and must return the value encoded the
	 * same way.
	 *
	 * @param array<string, string> $tokens
	 */
	private static function replaceInShortcodeAttributes( string $html, array $tokens ): string {
		$encoder = apply_filters( 'rowsprout_shortcode_attribute_encoder', null );
		if ( ! is_callable( $encoder ) ) {
			return $html;
		}

		// [tag ...attributes...] — opening tags only; attribute values in double quotes.
		return (string) preg_replace_callback(
			'/\[[A-Za-z_][\w-]*\s[^\]]*\]/',
			static function ( array $shortcode ) use ( $encoder, $tokens ) {
				return (string) preg_replace_callback(
					'/(\s[\w-]+=")([^"]*)(")/',
					static function ( array $attribute ) use ( $encoder, $tokens ) {
						$value = $attribute[2];
						if ( strpos( $value, '@code_' ) === false && strpos( $value, '%40code_' ) === false ) {
							return $attribute[0];
						}
						foreach ( $tokens as $token => $replacement ) {
							$value = str_replace( $token, (string) call_user_func( $encoder, $replacement, false ), $value );
							$value = str_replace( rawurlencode( $token ), (string) call_user_func( $encoder, $replacement, true ), $value );
						}
						return $attribute[1] . $value . $attribute[3];
					},
					$shortcode[0]
				);
			},
			$html
		);
	}

	/**
	 * Same semantics as the original PageBuilder::applyGroupPlaceholderTokens():
	 * one str_replace() per token, in resolver order.
	 *
	 * @param array<string, string> $tokens
	 */
	public static function replacePlain( string $value, array $tokens ): string {
		foreach ( $tokens as $token => $replacement ) {
			$value = str_replace( $token, $replacement, $value );
		}

		return $value;
	}
}
