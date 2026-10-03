<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\Template\FieldTypes\FieldTypeManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FieldValueSanitizer {

	/**
	 * @param mixed $raw
	 * @param array<int, string> $options
	 */
	public static function sanitize( string $type, string $fieldType, $raw, array $options ): string {
		return FieldTypeManager::sanitize( $type, $fieldType, $raw, $options );
	}
}
