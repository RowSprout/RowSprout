<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\Template\FieldTypes\FieldTypeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldUiResolver {

	public static function resolveKind( string $type, string $kind ): string {
		$type = sanitize_key( $type );
		$kind = sanitize_key( $kind );

		$handler = FieldTypeManager::find( $type );
		if ( ! $handler ) {
			$handler = FieldTypeManager::find( $kind );
		}

		if ( $handler ) {
			return $handler->getUiKind();
		}

		return $kind !== '' ? $kind : 'text';
	}

	/**
	 * @return array<string, string>
	 */
	public static function getClientTypeKindMap(): array {
		return FieldTypeManager::uiKindMap();
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public static function getClientValidationMap(): array {
		return FieldTypeManager::uiValidationMap();
	}
}
