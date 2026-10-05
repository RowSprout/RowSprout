<?php

namespace RowSprout\Core\Transfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder tokens carry the id of the template they were typed against
 * (@code_<code>_<templateId>@, see GroupPlaceholderTokenResolver), so a
 * template imported under a new id must have those ids rewritten, or none
 * of its placeholders resolve. Page builders store a token in a link field
 * url-encoded (%40code_…%40, see MarkupTokenReplacer), so both forms count.
 */
final class PlaceholderTokenIds {

	// The code may itself end in "_<digits>" (a second "Text" property gets
	// "text_2"); the greedy code part leaves only the last number as the id.
	private const PATTERN = '/(@|%40)code_([a-z0-9_]+)_(\d+)(@|%40)/';

	/**
	 * Rewrites the template id of every token whose id is a key of $map.
	 * Tokens with any other id are left alone.
	 *
	 * @param array<int, int> $map Old template id => new template id.
	 */
	public static function remap( string $text, array $map ): string {
		if ( $map === [] || ( strpos( $text, '@code_' ) === false && strpos( $text, '%40code_' ) === false ) ) {
			return $text;
		}

		$result = preg_replace_callback(
			self::PATTERN,
			static function ( array $match ) use ( $map ): string {
				$oldId = (int) $match[3];
				if ( ! isset( $map[ $oldId ] ) ) {
					return $match[0];
				}

				return $match[1] . 'code_' . $match[2] . '_' . (int) $map[ $oldId ] . $match[4];
			},
			$text
		);

		return is_string( $result ) ? $result : $text;
	}

	/**
	 * remap() on every string inside $value (arrays are walked recursively;
	 * a JSON string such as Elementor's _elementor_data is handled as plain
	 * text, which is safe: only digits inside a token change).
	 *
	 * @param mixed           $value
	 * @param array<int, int> $map
	 * @return mixed
	 */
	public static function remapRecursive( $value, array $map ) {
		if ( is_string( $value ) ) {
			return self::remap( $value, $map );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::remapRecursive( $item, $map );
			}
		}

		return $value;
	}

	/**
	 * $text with every token's template id removed, for comparing URL
	 * patterns of different templates.
	 */
	public static function strip( string $text ): string {
		$result = preg_replace( self::PATTERN, '$1code_$2$4', $text );

		return is_string( $result ) ? $result : $text;
	}
}
