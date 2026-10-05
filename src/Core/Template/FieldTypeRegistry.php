<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\Template\FieldTypes\FieldTypeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldTypeRegistry {

	/**
	 * @return array<string, array{label:string,description:string,default_code:string,type?:string,supports_options?:bool,default_options?:array<int, string>,can_be_overruled?:bool,required?:bool}>
	 */
	public static function getDefinitions(): array {
		$definitions = FieldTypeManager::definitions();

		$definitions = apply_filters( 'rowsprout_field_type_definitions', $definitions );

		return self::normalizeDefinitions( is_array( $definitions ) ? $definitions : [] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function getDefinition( string $type ): array {
		$definitions = self::getDefinitions();
		return isset( $definitions[ $type ] ) && is_array( $definitions[ $type ] ) ? $definitions[ $type ] : [];
	}

	/**
	 * @param array $definitions
	 * @return array
	 */
	private static function normalizeDefinitions( array $definitions ): array {
		$result = [];
		foreach ( $definitions as $type => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$type = sanitize_key( (string) $type );
			if ( $type === '' ) {
				continue;
			}

			$label      = sanitize_text_field( (string) ( $definition['label'] ?? $type ) );
			$defaultCode = sanitize_key( (string) ( $definition['default_code'] ?? $type ) );
			$fieldType  = sanitize_key( (string) ( $definition['type'] ?? 'text' ) );

			$result[ $type ] = [
				'label'              => $label !== '' ? $label : $type,
				'description'        => sanitize_text_field( (string) ( $definition['description'] ?? '' ) ),
				'default_code'       => $defaultCode !== '' ? $defaultCode : $type,
				'type'               => $fieldType !== '' ? $fieldType : 'text',
				'supports_options'   => ! empty( $definition['supports_options'] ),
				'default_options'    => self::normalizeOptions( $definition['default_options'] ?? [] ),
				'can_be_overruled'   => array_key_exists( 'can_be_overruled', $definition ) ? (bool) $definition['can_be_overruled'] : true,
				'required'           => ! empty( $definition['required'] ),
				'allow_multiple'     => array_key_exists( 'allow_multiple', $definition ) ? (bool) $definition['allow_multiple'] : true,
			];
		}

		return $result;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, string>
	 */
	private static function normalizeOptions( $raw ): array {
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
}
