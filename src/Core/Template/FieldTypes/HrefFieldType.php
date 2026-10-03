<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HrefFieldType extends BaseFieldType {

	public function getType(): string {
		return 'href';
	}

	public function getAliases(): array {
		return [ 'href', 'slug' ];
	}

	protected function label(): string {
		return __( 'URL / Slug', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'The URL slug for the page.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'slug';
	}

	public function getUiKind(): string {
		return 'text';
	}

	public function getUiValidation(): array {
		return [ 'normalize' => 'slug', 'validate' => 'unique' ];
	}

	public function shouldStorePostMeta(): bool {
		return false;
	}

	public function getDefinition(): array {
		$definition                     = parent::getDefinition();
		$definition['required']         = true;
		$definition['can_be_overruled'] = false;
		$definition['allow_multiple']   = false;
		return $definition;
	}

	public function sanitize( string $value, array $options ): string {
		unset( $options );
		$value = sanitize_text_field( $value );
		$value = trim( $value );

		if ( $value === '' ) {
			return '';
		}

		if ( strpos( $value, '@code_' ) !== false ) {
			return $value;
		}

		return sanitize_title( $value );
	}
}
