<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file formats a template's groups can be downloaded in, and writing a
 * table in one of them.
 *
 * The UTF-8 formats start with a byte order mark: without it Excel reads a
 * CSV file in the computer's legacy code page and mangles every accented
 * letter. The semicolon format is what Excel expects (and writes) with Dutch,
 * German and other regional settings that use a decimal comma.
 */
final class GroupCsvFormat {

	public const DEFAULT_FORMAT = 'csv-utf8';

	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Characters that make a spreadsheet app treat a cell as a formula.
	 */
	private const FORMULA_START = "=+-@\t\r";

	/**
	 * @return array<string, array{label:string, delimiter:string, bom:bool, extension:string}>
	 */
	public static function all(): array {
		return [
			'csv-utf8'           => [
				'label'     => __( 'CSV UTF-8, comma-separated', 'rowsprout' ),
				'delimiter' => ',',
				'bom'       => true,
				'extension' => 'csv',
			],
			'csv-utf8-semicolon' => [
				'label'     => __( 'CSV UTF-8, semicolon-separated', 'rowsprout' ),
				'delimiter' => ';',
				'bom'       => true,
				'extension' => 'csv',
			],
			'tab'                => [
				'label'     => __( 'Text UTF-8, tab-separated', 'rowsprout' ),
				'delimiter' => "\t",
				'bom'       => true,
				'extension' => 'txt',
			],
			'csv-plain'          => [
				'label'     => __( 'CSV, comma-separated, without byte order mark', 'rowsprout' ),
				'delimiter' => ',',
				'bom'       => false,
				'extension' => 'csv',
			],
		];
	}

	/**
	 * @return array{label:string, delimiter:string, bom:bool, extension:string}
	 */
	public static function get( string $format ): array {
		$formats = self::all();

		return $formats[ $format ] ?? $formats[ self::DEFAULT_FORMAT ];
	}

	/**
	 * RFC 4180 with CRLF line endings, a cell quoted only when it needs to be.
	 *
	 * @param array<int, array<int, string>> $rows
	 */
	public static function encode( array $rows, string $format ): string {
		$definition = self::get( $format );
		$delimiter  = $definition['delimiter'];

		$lines = [];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( $row as $cell ) {
				$cells[] = self::encodeCell( self::guardFormula( (string) $cell ), $delimiter );
			}
			$lines[] = implode( $delimiter, $cells );
		}

		return ( $definition['bom'] ? self::BOM : '' ) . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * A value that starts like a formula (=, +, -, @) would be run by Excel or
	 * Google Sheets when the file is opened ("CSV injection"); a leading
	 * apostrophe makes them show it as text. unguardFormula() takes it off
	 * again on import, so values survive a round trip. Plain numbers (-5)
	 * are left alone.
	 */
	public static function guardFormula( string $value ): string {
		if ( $value !== '' && strpos( self::FORMULA_START, $value[0] ) !== false && ! is_numeric( $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	public static function unguardFormula( string $value ): string {
		if ( strlen( $value ) > 1 && $value[0] === "'" && strpos( self::FORMULA_START, $value[1] ) !== false ) {
			return substr( $value, 1 );
		}

		return $value;
	}

	private static function encodeCell( string $value, string $delimiter ): string {
		if ( $value === '' ) {
			return '';
		}

		// Leading or trailing spaces are quoted too: some apps trim them otherwise.
		if ( strpbrk( $value, $delimiter . "\"\r\n" ) !== false || $value !== trim( $value ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}
}
