<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EmailFieldType extends BaseFieldType {

	public function getType(): string {
		return 'email';
	}

	protected function label(): string {
		return __( 'Email', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'For email addresses.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'email';
	}

	public function getUiKind(): string {
		return 'email';
	}

	public function getUiValidation(): array {
		return [ 'validate' => 'email' ];
	}

	public function sanitize( string $value, array $options ): string {
		unset( $options );
		return sanitize_email( $value );
	}
}
