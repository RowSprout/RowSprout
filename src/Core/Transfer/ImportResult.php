<?php

namespace RowSprout\Core\Transfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a TemplateImporter run did: the templates it created, and the
 * warnings and errors to show the user. Add-ons hooked into the import
 * receive it, so they can report their own warnings.
 */
final class ImportResult {

	/**
	 * @var array<int, array{id:int, source_id:int}>
	 */
	private $created = [];

	/**
	 * @var array<int, string>
	 */
	private $warnings = [];

	/**
	 * @var array<int, string>
	 */
	private $errors = [];

	public function addCreated( int $templateId, int $sourceId ): void {
		$this->created[] = [
			'id'        => $templateId,
			'source_id' => $sourceId,
		];
	}

	public function addWarning( string $message ): void {
		if ( $message !== '' && ! in_array( $message, $this->warnings, true ) ) {
			$this->warnings[] = $message;
		}
	}

	public function addError( string $message ): void {
		if ( $message !== '' && ! in_array( $message, $this->errors, true ) ) {
			$this->errors[] = $message;
		}
	}

	/**
	 * @return array<int, array{id:int, source_id:int}>
	 */
	public function created(): array {
		return $this->created;
	}

	/**
	 * @return array<int, string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * @return array<int, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * @return array{created: array<int, array{id:int, source_id:int}>, warnings: array<int, string>, errors: array<int, string>}
	 */
	public function toArray(): array {
		return [
			'created'  => $this->created,
			'warnings' => $this->warnings,
			'errors'   => $this->errors,
		];
	}

	/**
	 * @param array<string, mixed> $data A toArray() result.
	 */
	public static function fromArray( array $data ): self {
		$result = new self();
		foreach ( (array) ( $data['created'] ?? [] ) as $created ) {
			if ( is_array( $created ) ) {
				$result->addCreated( (int) ( $created['id'] ?? 0 ), (int) ( $created['source_id'] ?? 0 ) );
			}
		}
		foreach ( (array) ( $data['warnings'] ?? [] ) as $warning ) {
			$result->addWarning( (string) $warning );
		}
		foreach ( (array) ( $data['errors'] ?? [] ) as $error ) {
			$result->addError( (string) $error );
		}

		return $result;
	}
}
