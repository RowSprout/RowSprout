<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a groups import did, or would do on a dry run (the wizard's summary).
 */
final class GroupCsvImportResult {

	/**
	 * Skipped rows listed by number; the rest is only counted.
	 */
	private const MAX_LISTED = 20;

	/** @var array<int, string> */
	private $errors = [];

	/** @var array<int, string> */
	private $warnings = [];

	/** @var array<int, string> */
	private $skipped = [];

	/** @var int */
	private $skippedCount = 0;

	/** @var array<int, string> */
	private $newProperties = [];

	/** @var int */
	public $added = 0;

	/** @var int */
	public $updated = 0;

	/** @var int */
	public $unchanged = 0;

	/** @var bool */
	public $saved = false;

	public function addError( string $message ): void {
		$this->errors[] = $message;
	}

	public function hasErrors(): bool {
		return $this->errors !== [];
	}

	public function addWarning( string $message ): void {
		$this->warnings[] = $message;
	}

	public function addSkipped( string $message ): void {
		$this->skippedCount++;
		if ( count( $this->skipped ) < self::MAX_LISTED ) {
			$this->skipped[] = $message;
		}
	}

	public function addNewProperty( string $label ): void {
		$this->newProperties[] = $label;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'errors'        => $this->errors,
			'warnings'      => $this->warnings,
			'skipped'       => $this->skipped,
			'skippedCount'  => $this->skippedCount,
			'newProperties' => $this->newProperties,
			'added'         => $this->added,
			'updated'       => $this->updated,
			'unchanged'     => $this->unchanged,
			'saved'         => $this->saved,
		];
	}
}
