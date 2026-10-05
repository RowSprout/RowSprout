<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface FieldTypeContract {

	public function getType(): string;

	/**
	 * @return array<int, string>
	 */
	public function getAliases(): array;

	/**
	 * @return array{label:string,description:string,default_code:string,type:string,supports_options:bool,default_options:array<int, string>,can_be_overruled:bool,required:bool}
	 */
	public function getDefinition(): array;

	public function getUiKind(): string;

	public function shouldReplaceTokens(): bool;

	public function shouldStorePostMeta(): bool;

	public function resolvePostMetaKey( string $fieldKey, string $code, int $fieldId = 0 ): string;

	/**
	 * @return array<string, string>
	 */
	public function getUiValidation(): array;

	/**
	 * @param array<int, string> $options
	 */
	public function sanitize( string $value, array $options ): string;
}
