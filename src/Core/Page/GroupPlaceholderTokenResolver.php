<?php

namespace RowSprout\Core\Page;

use RowSprout\Core\TemplateMeta;
use RowSprout\Core\Template\FieldTypes\FieldTypeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupPlaceholderTokenResolver {

	/**
	 * @param array<string, mixed> $group
	 * @param int|string $codeId
	 * @param int|string $parentCodeId
	 * @param array<int, int|string> $aliasCodeIds Extra IDs (besides $codeId and
	 *        $parentCodeId) whose @code_..._<id>@ tokens should resolve to this
	 *        same group's data too — e.g. every WPML sibling translation's
	 *        template ID, since WPML duplicates a template's href/title
	 *        pattern text verbatim, tokens and all, so that text keeps
	 *        referencing whichever template ID the token was originally typed
	 *        against (see RowSprout Pro's WpmlPlaceholderAliasIds).
	 * @return array<string, string>
	 */
	public static function resolve( array $group, $codeId, $parentCodeId, int $templatePostId = 0, array $aliasCodeIds = [] ): array {
		$ids = array_values( array_unique( array_filter(
			array_merge( [ $codeId, $parentCodeId ], $aliasCodeIds ),
			static function ( $value ): bool {
				return $value !== null;
			}
		) ) );

		$tokens = [];
		foreach ( $ids as $anId ) {
			// English-only token names: older Dutch-named tokens
			// (@code_naam_@, @code_titel_@, @code_algemene_tekst_@) are not
			// resolved; content still using them must be rewritten to the
			// English tokens.
			$tokens[ '@code_title_' . $anId . '@' ] = (string) ( $group['title'] ?? '' );
			$tokens[ '@code_href_' . $anId . '@' ]  = (string) ( $group['href'] ?? '' );
			$tokens[ '@code_slug_' . $anId . '@' ]  = (string) ( $group['href'] ?? '' );
		}

		$fieldCodeMap = [];
		$fieldTypeMap = [];
		if ( $templatePostId > 0 ) {
			$config = TemplateMeta::get( $templatePostId );
			foreach ( (array) ( $config['field_types'] ?? [] ) as $fieldType ) {
				if ( ! is_array( $fieldType ) ) {
					continue;
				}
				$key  = sanitize_key( (string) ( $fieldType['key'] ?? '' ) );
				$code = sanitize_key( (string) ( $fieldType['code'] ?? $key ) );
				$type = sanitize_key( (string) ( $fieldType['type'] ?? '' ) );
				if ( $key !== '' && $code !== '' ) {
					$fieldCodeMap[ $key ] = $code;
				}
				if ( $key !== '' && $type !== '' ) {
					$fieldTypeMap[ $key ] = $type;
				}
			}
		}

		$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];
		foreach ( $fields as $fieldKey => $fieldConfig ) {
			$key = sanitize_key( (string) $fieldKey );
			if ( $key === '' ) {
				continue;
			}

			$value = '';
			if ( is_array( $fieldConfig ) ) {
				$rawValue = $fieldConfig['value'] ?? '';
				$value    = is_scalar( $rawValue ) ? (string) $rawValue : '';
			} elseif ( is_scalar( $fieldConfig ) ) {
				$value = (string) $fieldConfig;
			}

			$code = $fieldCodeMap[ $key ] ?? '';
			if ( $code === '' && is_array( $fieldConfig ) ) {
				$code = sanitize_key( (string) ( $fieldConfig['code'] ?? '' ) );
			}
			if ( $code === '' ) {
				$code = $key;
			}

			$fieldType = '';
			if ( is_array( $fieldConfig ) ) {
				$fieldType = sanitize_key( (string) ( $fieldConfig['type'] ?? '' ) );
			}
			if ( $fieldType === '' && isset( $fieldTypeMap[ $key ] ) ) {
				$fieldType = $fieldTypeMap[ $key ];
			}
			if ( $fieldType === '' ) {
				$fieldType = $key;
			}

			if ( ! FieldTypeManager::shouldReplaceTokens( $fieldType ) ) {
				continue;
			}

			/**
			 * The text a property's placeholder is replaced with, everywhere it
			 * appears: page content, title, URL pattern and post meta. The value
			 * arrives as stored (after the type's sanitize()); return the text to
			 * insert, e.g. a formatted date for a date type. In HTML attributes and
			 * block attributes the result is escaped for that spot
			 * (MarkupTokenReplacer); in running text it is inserted as is, so
			 * escape anything that must not become markup. Filter only your own
			 * types: changing a title or URL-slug value changes page titles and
			 * addresses too.
			 *
			 * @param string $value
			 * @param string $fieldType The property type (getType()).
			 * @param array{key:string, code:string, group:array<string, mixed>, template_id:int} $context
			 */
			$value = (string) apply_filters( 'rowsprout_placeholder_value', $value, $fieldType, [
				'key'         => $key,
				'code'        => $code,
				'group'       => $group,
				'template_id' => $templatePostId,
			] );

			foreach ( self::getFieldTokenAliases( $key, $code, $fieldConfig ) as $alias ) {
				foreach ( $ids as $anId ) {
					$tokenForId = '@code_' . $alias . '_' . $anId . '@';

					if ( $value === '' && isset( $tokens[ $tokenForId ] ) && $tokens[ $tokenForId ] !== '' ) {
						// Keep existing non-empty fallback value (e.g. inherited parent title/href).
						continue;
					}

					$tokens[ $tokenForId ] = $value;
				}
			}
		}

		return $tokens;
	}

	/**
	 * @param mixed $fieldConfig
	 * @return array<int, string>
	 */
	private static function getFieldTokenAliases( string $fieldKey, string $code, $fieldConfig ): array {
		$aliases = [ $fieldKey, $code ];
		$fieldType = '';

		if ( is_array( $fieldConfig ) && isset( $fieldConfig['type'] ) ) {
			$fieldType = sanitize_key( (string) $fieldConfig['type'] );
		}

		if ( in_array( $fieldKey, [ 'title' ], true ) || in_array( $fieldType, [ 'title' ], true ) || $code === 'title' ) {
			$aliases = array_merge( $aliases, [ 'title' ] );
		}

		if ( in_array( $fieldKey, [ 'href', 'slug' ], true ) || in_array( $fieldType, [ 'href', 'slug' ], true ) || in_array( $code, [ 'href', 'slug' ], true ) ) {
			$aliases = array_merge( $aliases, [ 'href', 'slug' ] );
		}

		if ( in_array( $fieldKey, [ 'textarea' ], true ) || in_array( $fieldType, [ 'textarea', 'richtext' ], true ) ) {
			$aliases = array_merge( $aliases, [ 'textarea', 'content' ] );
		}

		/**
		 * Extra token aliases for a property type an add-on registers.
		 *
		 * @param array<int, string> $aliases
		 */
		$aliases = (array) apply_filters( 'rowsprout_placeholder_field_aliases', $aliases, $fieldKey, $fieldType, $code );

		$aliases = array_filter( array_map( 'sanitize_key', $aliases ) );

		return array_values( array_unique( $aliases ) );
	}
}
