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
		// sanitize_text_field() drops percent-encoded octets, which would turn
		// an encoded slug (zurich-%e6%9d%b1%e4%ba%ac) into "zurich"; decode it
		// first when that gives valid UTF-8 (normalizeSlug() in
		// groups-metabox.js does the same).
		$decoded = rawurldecode( $value );
		if ( preg_match( '//u', $decoded ) === 1 ) {
			$value = $decoded;
		}
		$value = sanitize_text_field( $value );
		$value = trim( $value );

		if ( $value === '' ) {
			return '';
		}

		if ( strpos( $value, '@code_' ) !== false ) {
			return $value;
		}

		// Stored readable (zurich-東京, not zurich-%e6%9d%b1%e4%ba%ac): the
		// groups table shows this value, and PageBuilder's own sanitize_title()
		// encodes it again for post_name.
		return rawurldecode( sanitize_title( $value ) );
	}
}
