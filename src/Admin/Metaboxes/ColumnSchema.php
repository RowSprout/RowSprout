<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ColumnSchema {

	/**
	 * Build ordered column definitions from stored field_types.
	 *
	 * @param array $config
	 * @return array
	 */
	public static function buildColumns( array $config ): array {
		$fieldTypes = isset( $config['field_types'] ) && is_array( $config['field_types'] ) ? $config['field_types'] : [];
		$columns    = [];
		$hasTitle   = false;
		$hasHref    = false;

		foreach ( $fieldTypes as $ft ) {
			if ( ! is_array( $ft ) || ! isset( $ft['type'] ) ) {
				continue;
			}
			$type       = sanitize_key( (string) $ft['type'] );
			$key        = sanitize_key( (string) ( $ft['key'] ?? $type ) );
			$label      = sanitize_text_field( (string) ( $ft['label'] ?? $type ) );
			$definition = TemplateMeta::getFieldTypeDefinition( $type );
			$fieldType  = sanitize_key( (string) ( $ft['field_type'] ?? ( $definition['type'] ?? 'text' ) ) );
			$options    = self::normalizeOptions( $ft['options'] ?? ( $definition['default_options'] ?? [] ) );
			$locked     = in_array( $type, [ 'title', 'href', 'slug' ], true );

			if ( $type === '' || $key === '' ) {
				continue;
			}
			if ( $type === 'title' ) {
				$hasTitle = true;
			}
			if ( $type === 'href' || $type === 'slug' ) {
				$hasHref = true;
				if ( $type === 'slug' ) {
					$type  = 'href';
					$key   = 'href';
					$label = __( 'Href', 'rowsprout' );
				}
			}

			$columns[] = [
				'key'             => $key,
				'type'            => $type,
				'label'           => $label ?: $type,
				'code'            => '',
				'field_type'      => $fieldType ?: 'text',
				'options'         => $options,
				'locked'          => $locked,
				'can_be_overruled' => $locked ? false : (bool) ( $ft['can_be_overruled'] ?? true ),
				'required'         => (bool) ( $ft['required'] ?? false ),
			];
		}

		if ( ! $hasTitle ) {
			array_unshift( $columns, [
				'key'             => 'title',
				'type'            => 'title',
				'label'           => __( 'Title', 'rowsprout' ),
				'code'            => '',
				'field_type'      => 'text',
				'options'         => [],
				'locked'          => true,
				'can_be_overruled' => false,
				'required'         => true,
			] );
		}

		if ( ! $hasHref ) {
			$titlePos = array_search( 'title', array_column( $columns, 'type' ), true );
			$insertAt = $titlePos !== false ? (int) $titlePos + 1 : 1;
			array_splice( $columns, $insertAt, 0, [[
				'key'             => 'href',
				'type'            => 'href',
				'label'           => __( 'Href', 'rowsprout' ),
				'code'            => '',
				'field_type'      => 'text',
				'options'         => [],
				'locked'          => true,
				'can_be_overruled' => false,
				'required'         => true,
			]] );
		}

		foreach ( $columns as $i => $column ) {
			$columns[ $i ]['code'] = self::generateCodeFromTitle( (string) ( $column['label'] ?? '' ), (string) ( $column['key'] ?? '' ) );
		}

		return $columns;
	}

	/**
	 * Normalize options from stored JSON or arrays.
	 *
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
	 * Generate a positive numeric ID for group rows.
	 */
	public static function generateNumericId(): int {
		return (int) wp_rand( 100000000, 2147483647 );
	}

	public static function generateCodeFromTitle( string $title, string $fallback = 'field' ): string {
		$code = sanitize_key( $title );
		if ( $code === '' ) {
			$code = sanitize_key( $fallback );
		}
		return $code !== '' ? $code : 'field';
	}
}
