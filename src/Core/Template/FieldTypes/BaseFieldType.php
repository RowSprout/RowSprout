<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BaseFieldType implements FieldTypeContract {

	abstract protected function label(): string;

	abstract protected function description(): string;

	abstract protected function defaultCode(): string;

	public function getAliases(): array {
		return [ $this->getType() ];
	}

	public function getUiValidation(): array {
		return [];
	}

	public function shouldReplaceTokens(): bool {
		return true;
	}

	public function shouldStorePostMeta(): bool {
		return true;
	}

	public function resolvePostMetaKey( string $fieldKey, string $code, int $fieldId = 0 ): string {
		if ( $fieldId > 0 ) {
			return '_rowsprout_page_field_id_' . absint( $fieldId );
		}

		$metaCode = sanitize_key( $code !== '' ? $code : $fieldKey );
		return $metaCode !== '' ? '_rowsprout_page_field_' . $metaCode : '';
	}

	public function sanitize( string $value, array $options ): string {
		unset( $options );
		return sanitize_text_field( $value );
	}

	public function getDefinition(): array {
		return [
			'label' => $this->label(),
			'description' => $this->description(),
			'default_code' => $this->defaultCode(),
			'type' => $this->getUiKind(),
			'supports_options' => false,
			'default_options' => [],
			'can_be_overruled' => true,
			'required' => false,
			'allow_multiple' => true,
		];
	}
}
