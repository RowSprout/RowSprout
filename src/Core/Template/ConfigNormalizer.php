<?php

namespace RowSprout\Core\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConfigNormalizer {

	/**
	 * @param array $config
	 * @return array
	 */
	public static function normalizeConfig( array $config ): array {
		$groups     = isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : [];
		$childExtra = isset( $config['child_extra'] ) && is_array( $config['child_extra'] ) ? $config['child_extra'] : [];

		return [
			'version'            => 1,
			'rowsprout_page_href'  => isset( $config['rowsprout_page_href'] ) ? self::sanitizeHref( (string) $config['rowsprout_page_href'] ) : '',
			'field_types'        => self::normalizeFieldTypes( $config['field_types'] ?? [] ),
			'groups'             => array_values( $groups ),
			'child_extra'        => array_values( $childExtra ),
			// Bumped on every TemplateMeta::save() (see its own docblock) —
			// lets a save compare "what this form was rendered from" against
			// "what's actually stored right now" to detect a concurrent
			// change (e.g. an MCP API write) since the form was loaded.
			'config_updated_at'  => isset( $config['config_updated_at'] ) ? (string) $config['config_updated_at'] : '',
		];
	}

	/**
	 * The template-level href is a PATTERN (see TitleHrefMetaBoxRenderer):
	 * normally "/@code_href_<id>@", optionally with literal text around the
	 * token. PageBuilder replaces the tokens and runs the result through
	 * sanitize_title() to get the page's post_name, so only the slug part
	 * matters — an absolute URL here would just be flattened into a slug
	 * (HrefPatternValidator warns when the href token is missing; a child
	 * template's href may be fixed text). The
	 * stored pattern itself must keep its tokens intact, so it can't be run through
	 * sanitize_title() the way a single per-group href/slug value is (see
	 * HrefFieldType::sanitize()): that would strip the leading slash,
	 * lowercase everything, and mangle the tokens.
	 * esc_url_raw() isn't safe here either — it prepends "http://" to any
	 * value that doesn't already start with "/" or a scheme, which corrupts
	 * a bare token used as the entire href (e.g. "@code_title_123@").
	 * Instead: replace whitespace with a hyphen (a real href never
	 * legitimately contains one — sanitize_text_field() only collapses it,
	 * it doesn't remove it — and a hyphen is what someone typing "test
	 * page" actually means) and strip the handful of characters with no
	 * legitimate place in a URL, leaving path separators, scheme/host
	 * punctuation, and tokens intact.
	 */
	private static function sanitizeHref( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( $value === '' ) {
			return '';
		}

		$value = (string) preg_replace( '/\s+/u', '-', $value );
		$value = (string) preg_replace( '/[<>"\'`]/', '', $value );

		return $value;
	}

	/**
	 * @param mixed $raw
	 * @return array
	 */
	private static function normalizeFieldTypes( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$definitions        = FieldTypeRegistry::getDefinitions();
		$result             = [];
		$seenTitles         = [];
		foreach ( $raw as $ft ) {
			if ( ! is_array( $ft ) || ! isset( $ft['type'] ) ) {
				continue;
			}
			$type  = sanitize_key( (string) $ft['type'] );
			$key   = sanitize_key( (string) ( $ft['key'] ?? $type ) );
			$base  = isset( $definitions[ $type ] ) && is_array( $definitions[ $type ] ) ? $definitions[ $type ] : [];
			$label = sanitize_text_field( (string) ( $ft['label'] ?? ( $base['label'] ?? $type ) ) );

			if ( $type === '' || $key === '' ) {
				continue;
			}

			$titleKey = mb_strtolower( trim( $label ) );
			if ( $titleKey === '' || isset( $seenTitles[ $titleKey ] ) ) {
				continue;
			}
			$seenTitles[ $titleKey ] = true;

			$result[] = [
				'key'              => $key,
				'type'             => $type,
				'label'            => $label,
				'code'             => sanitize_key( (string) ( $ft['code'] ?? self::generateCodeFromTitle( $label, (string) ( $base['default_code'] ?? $type ) ) ) ),
				'field_type'       => sanitize_key( (string) ( $ft['field_type'] ?? ( $base['type'] ?? 'text' ) ) ),
				'options'          => self::normalizeFieldOptions( $ft['options'] ?? ( $base['default_options'] ?? [] ) ),
				'can_be_overruled' => ! in_array( $type, [ 'title', 'href', 'slug' ], true ) && (bool) ( $ft['can_be_overruled'] ?? $base['can_be_overruled'] ?? true ),
			'required'         => (bool) ( $ft['required'] ?? $base['required'] ?? false ),
			];
		}

		return $result;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	private static function normalizeFieldOptions( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$result = [];
		foreach ( $raw as $option ) {
			if ( is_scalar( $option ) ) {
				$option = sanitize_text_field( (string) $option );
				if ( $option !== '' ) {
					$result[] = $option;
				}
			}
		}

		return array_values( array_unique( $result ) );
	}

	private static function generateCodeFromTitle( string $title, string $fallback = 'field' ): string {
		return SavePayloadSanitizer::generateCodeFromTitle( $title, $fallback );
	}
}
