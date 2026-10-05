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

	/**
	 * A valid address, or '' — never a different one. sanitize_email() drops
	 * every character it does not accept, which turns "jörg@müller.de" into
	 * the valid-looking "jrg@mller.de". An internationalised domain is
	 * stored in its ASCII form (müller.de → xn--mller-kva.de, when the intl
	 * extension is available), which every mail client understands; an
	 * address that is still not plain ASCII after that is refused.
	 */
	public function sanitize( string $value, array $options ): string {
		unset( $options );
		$value = trim( $value );
		$at    = strrpos( $value, '@' );

		if ( $at !== false && function_exists( 'idn_to_ascii' ) ) {
			$domain = substr( $value, $at + 1 );
			if ( preg_match( '/[^\x00-\x7F]/', $domain ) ) {
				$ascii = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( is_string( $ascii ) && $ascii !== '' ) {
					$value = substr( $value, 0, $at + 1 ) . $ascii;
				}
			}
		}

		$clean = sanitize_email( $value );

		return $clean === $value ? $clean : '';
	}
}
