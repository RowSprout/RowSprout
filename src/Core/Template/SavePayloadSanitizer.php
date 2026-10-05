<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavePayloadSanitizer {

	/**
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	public static function normalizeOptions( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = preg_split( '/\r?\n/', $raw ) ?: [];
			}
		}

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

	/**
	 * @param string $type
	 * @param string $fieldType
	 * @param mixed  $raw
	 * @param array<int, string> $options
	 */
	public static function sanitizeFieldValue( string $type, string $fieldType, $raw, array $options ): string {
		return FieldValueSanitizer::sanitize( $type, $fieldType, $raw, $options );
	}

	/**
	 * @param array $rawFields
	 * @return array
	 */
	public static function sanitizeGroupFields( array $rawFields ): array {
		$sanitized    = [];
		$usedFieldIds = [];

		foreach ( $rawFields as $fieldKey => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = sanitize_key( (string) $fieldKey );
			if ( $key === '' ) {
				continue;
			}

			$type       = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'textfield';
			$definition = TemplateMeta::getFieldTypeDefinition( $type );
			if ( $type === '' ) {
				$type = 'textfield';
			}
			$fieldType = sanitize_key( (string) ( $field['field_type'] ?? ( $definition['type'] ?? $type ) ) );
			$options   = self::normalizeOptions( $field['options'] ?? ( $definition['default_options'] ?? [] ) );
			$value     = self::sanitizeFieldValue( $type, $fieldType, $field['value'] ?? '', $options );

			$canBeOverruled = isset( $field['can_be_overruled'] ) ? (bool) $field['can_be_overruled'] : true;
			if ( in_array( $type, [ 'title', 'href', 'slug' ], true ) ) {
				$canBeOverruled = false;
			}

			$sanitized[ $key ] = [
				'type'             => $type,
				'value'            => $value,
				'id'               => self::ensureUniqueId( isset( $field['id'] ) ? absint( $field['id'] ) : self::generateNumericId(), $usedFieldIds ),
				'can_be_overruled' => $canBeOverruled,
			];
		}

		if ( ! isset( $sanitized['title'] ) ) {
			$sanitized['title'] = [
				'type'             => 'title',
				'value'            => '',
				'id'               => self::ensureUniqueId( self::generateNumericId(), $usedFieldIds ),
				'can_be_overruled' => false,
			];
		}

		if ( ! isset( $sanitized['href'] ) && ! isset( $sanitized['slug'] ) ) {
			$sanitized['href'] = [
				'type'             => 'href',
				'value'            => '',
				'id'               => self::ensureUniqueId( self::generateNumericId(), $usedFieldIds ),
				'can_be_overruled' => false,
			];
		}

		return $sanitized;
	}

	/**
	 * @param array  $fields
	 * @param string $type
	 */
	public static function getFieldValueByType( array $fields, string $type ): string {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$fieldType = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( $fieldType !== $type ) {
				continue;
			}
			$value = $field['value'] ?? '';
			return is_scalar( $value ) ? (string) $value : '';
		}

		return '';
	}

	public static function generateNumericId(): int {
		return (int) wp_rand( 100000000, 2147483647 );
	}

	/**
	 * @param int   $id
	 * @param array<int, bool> $used
	 */
	public static function ensureUniqueId( int $id, array &$used ): int {
		if ( $id <= 0 ) {
			$id = self::generateNumericId();
		}

		while ( isset( $used[ $id ] ) ) {
			$id = self::generateNumericId();
		}

		$used[ $id ] = true;

		return $id;
	}

	/**
	 * The placeholder code for a property label: accents transliterated the
	 * way WordPress does for slugs (remove_accents(), locale-aware), then
	 * lowercase a-z, 0-9 and underscores ("Prijs per uur" → prijs_per_uur,
	 * "Città" → citta). A label with nothing Latin in it ("東京") falls back
	 * to $fallback — pass the property type's default code. Mirrored by
	 * sanitizeCode() in assets/js/groups-metabox.js.
	 */
	public static function generateCodeFromTitle( string $title, string $fallback = 'field' ): string {
		$code = self::codeFromText( $title );
		if ( $code === '' ) {
			$code = self::codeFromText( $fallback );
		}
		return $code !== '' ? $code : 'field';
	}

	/**
	 * $text as a code: transliterated, lowercase a-z, 0-9 and underscores;
	 * '' when nothing is left. A valid code comes back unchanged.
	 */
	public static function codeFromText( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		return trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', $text ), '_' );
	}

	/**
	 * $code, or $code_2, $code_3 … when it is already in $used; records the
	 * result in $used. Two properties sharing a code would share one
	 * placeholder, and the page would show one property's value for both.
	 *
	 * @param array<string, bool> $used
	 */
	public static function uniqueCode( string $code, array &$used ): string {
		$candidate = $code;
		for ( $n = 2; isset( $used[ $candidate ] ); $n++ ) {
			$candidate = $code . '_' . $n;
		}
		$used[ $candidate ] = true;
		return $candidate;
	}
}
