<?php

namespace RowSprout\Core\Page;

use RowSprout\Core\TemplateMeta;
use RowSprout\Core\Template\FieldTypes\FieldTypeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GeneratedFieldMetaWriter {

	/**
	 * @param array<string, mixed> $group
	 */
	public static function apply( int $targetPostId, array $group, int $templatePostId ): void {
		$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];
		if ( $fields === [] ) {
			return;
		}

		$fieldCodeMap = [];
		$fieldTypeMap = [];
		$config       = TemplateMeta::get( $templatePostId );
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

		foreach ( $fields as $fieldKey => $fieldConfig ) {
			$key = sanitize_key( (string) $fieldKey );
			if ( $key === '' ) {
				continue;
			}

			$rawValue = '';
			if ( is_array( $fieldConfig ) ) {
				$rawValue = $fieldConfig['value'] ?? '';
			} elseif ( is_scalar( $fieldConfig ) ) {
				$rawValue = $fieldConfig;
			}
			$value = is_scalar( $rawValue ) ? (string) $rawValue : '';

			$type = '';
			if ( is_array( $fieldConfig ) ) {
				$type = sanitize_key( (string) ( $fieldConfig['type'] ?? '' ) );
			}
			if ( $type === '' && isset( $fieldTypeMap[ $key ] ) ) {
				$type = $fieldTypeMap[ $key ];
			}
			if ( $type === '' ) {
				$type = $key;
			}

			if ( ! FieldTypeManager::shouldStorePostMeta( $type ) ) {
				continue;
			}

			$code = '';
			if ( is_array( $fieldConfig ) ) {
				$code = sanitize_key( (string) ( $fieldConfig['code'] ?? '' ) );
			}
			if ( $code === '' && isset( $fieldCodeMap[ $key ] ) ) {
				$code = $fieldCodeMap[ $key ];
			}
			if ( $code === '' ) {
				$code = $key;
			}

			$fieldId = 0;
			if ( is_array( $fieldConfig ) ) {
				$fieldId = absint( $fieldConfig['id'] ?? 0 );
			}

			$metaKey = FieldTypeManager::resolvePostMetaKey( $type, $key, $code, $fieldId );
			if ( $metaKey === '' ) {
				continue;
			}

			if ( $value === '' ) {
				delete_post_meta( $targetPostId, $metaKey );
				continue;
			}

			update_post_meta( $targetPostId, $metaKey, wp_slash( $value ) );
		}
	}
}
