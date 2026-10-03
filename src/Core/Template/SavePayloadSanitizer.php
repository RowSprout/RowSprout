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

	public static function generateCodeFromTitle( string $title, string $fallback = 'field' ): string {
		$code = sanitize_key( $title );
		if ( $code === '' ) {
			$code = sanitize_key( $fallback );
		}
		return $code !== '' ? $code : 'field';
	}
}
