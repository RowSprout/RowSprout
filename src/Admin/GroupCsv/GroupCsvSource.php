<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a file that is not CSV into CSV, before the import reads it (the
 * wizard, and an import without it): an Excel workbook (.xlsx, XlsxReader),
 * or whatever an add-on converts through the rowsprout_group_csv_convert
 * filter (RowSprout Pro: JSON feeds). A CSV or text file passes unchanged.
 * The result is UTF-8, comma-separated, so the usual detection reads it.
 */
final class GroupCsvSource {

	/**
	 * @param string               $bytes   The file as fetched or uploaded.
	 * @param string               $name    Its URL or file name (a hint only).
	 * @param array<string, mixed> $options sheet: the worksheet (or list) to read; others for add-ons.
	 * @return array{bytes: string, format: string, sheets: array<int, string>, sheet: string, label: string, error?: string}
	 *         format '' for a file that passed unchanged; label: what to call
	 *         the parts in "sheets" ('' = worksheet).
	 */
	public static function convert( string $bytes, string $name = '', array $options = [] ): array {
		$sheet = is_string( $options['sheet'] ?? null ) ? $options['sheet'] : '';

		/**
		 * Converts a file the import cannot read as CSV. Return null to leave
		 * it, or an array: bytes (CSV), format (a short name such as "json"),
		 * sheets (the parts a user can choose from, e.g. the lists in a JSON
		 * file), sheet (the one read) and label (what to call them, e.g.
		 * "List"), or error (a message to show).
		 *
		 * @param array<string, mixed>|null $converted
		 * @param string                    $bytes
		 * @param string                    $name    URL or file name.
		 * @param array<string, mixed>      $options sheet: the part asked for ('' = the default).
		 */
		$converted = apply_filters( 'rowsprout_group_csv_convert', null, $bytes, $name, $options );
		if ( is_array( $converted ) ) {
			return [
				'bytes'  => (string) ( $converted['bytes'] ?? '' ),
				'format' => (string) ( $converted['format'] ?? 'other' ),
				'sheets' => array_values( array_map( 'strval', (array) ( $converted['sheets'] ?? [] ) ) ),
				'sheet'  => (string) ( $converted['sheet'] ?? '' ),
				'label'  => (string) ( $converted['label'] ?? '' ),
			] + ( isset( $converted['error'] ) ? [ 'error' => (string) $converted['error'] ] : [] );
		}

		if ( XlsxReader::isXlsx( $bytes ) ) {
			try {
				$book = XlsxReader::read( $bytes, $sheet );
			} catch ( \RuntimeException $e ) {
				return [ 'bytes' => '', 'format' => 'xlsx', 'sheets' => [], 'sheet' => '', 'label' => '', 'error' => $e->getMessage() ];
			}

			return [ 'bytes' => self::toCsv( $book['rows'] ), 'format' => 'xlsx', 'sheets' => $book['sheets'], 'sheet' => $book['sheet'], 'label' => '' ];
		}
		if ( self::looksLikeJson( $bytes ) ) {
			return [ 'bytes' => '', 'format' => 'json', 'sheets' => [], 'sheet' => '', 'label' => '', 'error' => __( 'This is a JSON feed. Importing JSON needs RowSprout Pro; without it, use a CSV or Excel file.', 'rowsprout' ) ];
		}
		if ( strncmp( $bytes, "PK\x03\x04", 4 ) === 0 || strncmp( $bytes, "\xD0\xCF\x11\xE0", 4 ) === 0 ) {
			// Another zip (e.g. .ods, .numbers) or an old .xls.
			return [ 'bytes' => '', 'format' => 'other', 'sheets' => [], 'sheet' => '', 'label' => '', 'error' => __( 'This file is not CSV or an Excel workbook (.xlsx). Save it as .xlsx or CSV and try again.', 'rowsprout' ) ];
		}

		return [ 'bytes' => $bytes, 'format' => '', 'sheets' => [], 'sheet' => '', 'label' => '' ];
	}

	/**
	 * Whether the bytes are a JSON object or list (which a CSV never is).
	 */
	public static function looksLikeJson( string $bytes ): bool {
		$text = ltrim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $bytes ) );
		if ( $text === '' || ( $text[0] !== '{' && $text[0] !== '[' ) ) {
			return false;
		}
		json_decode( $text );

		return json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Rows as CSV: UTF-8, commas, a value quoted when it has a comma, a
	 * quote or a line break, CRLF between rows.
	 *
	 * @param array<int, array<int, string>> $rows
	 */
	public static function toCsv( array $rows ): string {
		$lines = [];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( $row as $cell ) {
				$cell    = (string) $cell;
				$cells[] = preg_match( '/[",\r\n]/', $cell ) === 1 ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
			}
			$lines[] = implode( ',', $cells );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}
}
