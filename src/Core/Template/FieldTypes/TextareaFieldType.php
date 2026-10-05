<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TextareaFieldType extends BaseFieldType {

	public function getType(): string {
		return 'textarea';
	}

	protected function label(): string {
		return __( 'Textarea', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'Suitable for longer text or multiple lines.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'text';
	}

	public function getUiKind(): string {
		return 'textarea';
	}

	public function sanitize( string $value, array $options ): string {
		unset( $options );
		return sanitize_textarea_field( $value );
	}
}
