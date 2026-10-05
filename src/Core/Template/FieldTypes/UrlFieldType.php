<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UrlFieldType extends BaseFieldType {

	public function getType(): string {
		return 'url';
	}

	protected function label(): string {
		return __( 'URL', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'For web addresses or links.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'url';
	}

	public function getUiKind(): string {
		return 'url';
	}

	public function getUiValidation(): array {
		return [ 'validate' => 'url' ];
	}

	public function sanitize( string $value, array $options ): string {
		unset( $options );
		return esc_url_raw( $value );
	}
}
