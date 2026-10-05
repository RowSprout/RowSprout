<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldTypeManager {

	/**
	 * @return array<int, FieldTypeContract>
	 */
	public static function all(): array {
		$classes = [
			TitleFieldType::class,
			HrefFieldType::class,
			TextfieldFieldType::class,
			TextareaFieldType::class,
			UrlFieldType::class,
			EmailFieldType::class,
			NumberFieldType::class,
		];

		$types = [];
		foreach ( $classes as $className ) {
			if ( class_exists( $className ) ) {
				$instance = new $className();
				if ( $instance instanceof FieldTypeContract ) {
					$types[] = $instance;
				}
			}
		}

		$types = apply_filters( 'rowsprout_field_types', $types );

		return is_array( $types ) ? array_values( array_filter( $types, static function ( $type ): bool {
			return $type instanceof FieldTypeContract;
		} ) ) : [];
	}

	public static function find( string $type ): ?FieldTypeContract {
		$type = sanitize_key( $type );
		if ( $type === '' ) {
			return null;
		}

		foreach ( self::all() as $definition ) {
			$aliases = array_map( 'sanitize_key', $definition->getAliases() );
			if ( in_array( $type, $aliases, true ) ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * @return array<string, array{label:string,description:string,default_code:string,type:string,supports_options:bool,default_options:array<int, string>,can_be_overruled:bool,required:bool}>
	 */
	public static function definitions(): array {
		$result = [];
		foreach ( self::all() as $type ) {
			$result[ $type->getType() ] = $type->getDefinition();
		}
		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	public static function uiKindMap(): array {
		$map = [];
		foreach ( self::all() as $type ) {
			foreach ( $type->getAliases() as $alias ) {
				$alias = sanitize_key( $alias );
				if ( $alias !== '' ) {
					$map[ $alias ] = $type->getUiKind();
				}
			}
		}
		return $map;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public static function uiValidationMap(): array {
		$map = [];
		foreach ( self::all() as $type ) {
			$rules = $type->getUiValidation();
			if ( $rules === [] ) {
				continue;
			}
			foreach ( $type->getAliases() as $alias ) {
				$alias = sanitize_key( $alias );
				if ( $alias !== '' ) {
					$map[ $alias ] = $rules;
				}
			}
		}
		return $map;
	}

	/**
	 * @param mixed $raw
	 * @param array<int, string> $options
	 */
	public static function sanitize( string $type, string $fieldType, $raw, array $options ): string {
		$value = is_scalar( $raw ) ? (string) $raw : '';
		$type  = sanitize_key( $type );
		$kind  = sanitize_key( $fieldType !== '' ? $fieldType : $type );

		$handler = self::find( $type );
		if ( ! $handler ) {
			$handler = self::find( $kind );
		}

		if ( ! $handler ) {
			return sanitize_text_field( $value );
		}

		return $handler->sanitize( $value, $options );
	}

	public static function shouldReplaceTokens( string $type ): bool {
		$handler = self::find( $type );
		return $handler ? $handler->shouldReplaceTokens() : true;
	}

	public static function shouldStorePostMeta( string $type ): bool {
		$handler = self::find( $type );
		return $handler ? $handler->shouldStorePostMeta() : false;
	}

	public static function resolvePostMetaKey( string $type, string $fieldKey, string $code, int $fieldId = 0 ): string {
		$handler = self::find( $type );
		if ( ! $handler ) {
			return '';
		}

		$fieldKey = sanitize_key( $fieldKey );
		$code     = sanitize_key( $code );

		return $handler->resolvePostMetaKey( $fieldKey, $code, $fieldId );
	}
}
