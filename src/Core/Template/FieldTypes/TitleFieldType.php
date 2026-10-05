<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TitleFieldType extends BaseFieldType {

	public function getType(): string {
		return 'title';
	}

	protected function label(): string {
		return __( 'Title', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'Main title of the page.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'title';
	}

	public function getUiKind(): string {
		return 'text';
	}

	public function getUiValidation(): array {
		return [ 'normalize' => 'title' ];
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
		// /u: without it, whether a byte such as 0xA0 (the second byte of "à")
		// counts as whitespace depends on PHP's locale. Trimmed after
		// collapsing: trim() only knows ASCII whitespace, so a title wrapped in
		// non-breaking spaces would keep a space at each end (normalizeTitle()
		// in groups-metabox.js trims those too).
		$collapsed = preg_replace( '/\s+/u', ' ', $value );
		return is_string( $collapsed ) ? trim( $collapsed ) : $value;
	}
}
