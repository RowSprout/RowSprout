<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NumberFieldType extends BaseFieldType {

	public function getType(): string {
		return 'number';
	}

	protected function label(): string {
		return __( 'Number', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'For numeric values.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'number';
	}

	public function getUiKind(): string {
		return 'number';
	}

	public function sanitize( string $value, array $options ): string {
		unset( $options );
		return is_numeric( $value ) ? (string) $value : sanitize_text_field( $value );
	}
}
