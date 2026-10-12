<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a CSV file on the server exactly the way the import wizard reads it
 * in the browser (assets/js/group-csv.js: detectEncoding(), decode(),
 * detectDelimiter(), parse(), table()), so a file imported without the
 * wizard, such as a scheduled sync in RowSprout Pro, gives the same rows as
 * the wizard showed. Keep both in step; rowsprout-support tests them against
 * the same files.
 */
final class GroupCsvParser {

	/** The wizard's delimiter choices. */
	public const DELIMITERS = [
		'tab'       => "\t",
		'semicolon' => ';',
		'comma'     => ',',
		'space'     => ' ',
	];

	/** The wizard's encodings (its option values) => the name PHP knows. */
	private const ENCODINGS = [
		'utf-8'        => 'UTF-8',
		'windows-1252' => 'Windows-1252',
		'iso-8859-15'  => 'ISO-8859-15',
		'windows-1250' => 'Windows-1250',
		'utf-16le'     => 'UTF-16LE',
		'utf-16be'     => 'UTF-16BE',
	];

	/**
	 * The encoding a byte order mark announces, else UTF-8 when the bytes are
	 * valid UTF-8, else Windows-1252 (guessed).
	 *
	 * @return array{encoding: string, guessed: bool}
	 */
	public static function detectEncoding( string $bytes ): array {
		if ( strncmp( $bytes, "\xEF\xBB\xBF", 3 ) === 0 ) {
			return [ 'encoding' => 'utf-8', 'guessed' => false ];
		}
		if ( strncmp( $bytes, "\xFF\xFE", 2 ) === 0 ) {
			return [ 'encoding' => 'utf-16le', 'guessed' => false ];
		}
		if ( strncmp( $bytes, "\xFE\xFF", 2 ) === 0 ) {
			return [ 'encoding' => 'utf-16be', 'guessed' => false ];
		}
		if ( mb_check_encoding( $bytes, 'UTF-8' ) ) {
			return [ 'encoding' => 'utf-8', 'guessed' => false ];
		}

		return [ 'encoding' => 'windows-1252', 'guessed' => true ];
	}

	/**
	 * The bytes as UTF-8 text, as the browser's TextDecoder gives them: a byte
	 * order mark of the encoding itself is dropped, and bytes that are not
	 * valid in it become U+FFFD.
	 */
	public static function decode( string $bytes, string $encoding ): string {
		$encoding = strtolower( $encoding );
		$name     = self::ENCODINGS[ $encoding ] ?? 'UTF-8';
		$boms     = [ 'utf-8' => "\xEF\xBB\xBF", 'utf-16le' => "\xFF\xFE", 'utf-16be' => "\xFE\xFF" ];
		if ( isset( $boms[ $encoding ] ) && strncmp( $bytes, $boms[ $encoding ], strlen( $boms[ $encoding ] ) ) === 0 ) {
			$bytes = (string) substr( $bytes, strlen( $boms[ $encoding ] ) );
		}

		$substitute = mb_substitute_character();
		mb_substitute_character( 0xFFFD );
		if ( $name === 'UTF-8' ) {
			$text = mb_scrub( $bytes, 'UTF-8' );
		} elseif ( in_array( $name, mb_list_encodings(), true ) ) {
			$text = (string) mb_convert_encoding( $bytes, 'UTF-8', $name );
		} elseif ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $name, 'UTF-8//IGNORE', $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an undefined byte is dropped, not an error.
			$text      = is_string( $converted ) ? $converted : mb_scrub( $bytes, 'UTF-8' );
		} else {
			$text = mb_scrub( $bytes, 'UTF-8' );
		}
		mb_substitute_character( $substitute );

		return $text;
	}

	/**
	 * The delimiter used most on the first line outside quotes (tab,
	 * semicolon or comma; comma when none); Excel's "sep=;" first line wins.
	 *
	 * @return array{delimiter: string, sepLine: bool}
	 */
	public static function detectDelimiter( string $text ): array {
		$firstLine = (string) ( preg_split( '/\r\n|\r|\n/', $text, 2 )[0] ?? '' );
		// JavaScript's "." matches anything but a line terminator.
		if ( preg_match( '/^sep=([^\x{2028}\x{2029}])\z/iu', $firstLine, $match ) === 1 ) {
			return [ 'delimiter' => $match[1], 'sepLine' => true ];
		}
		$unquoted  = (string) preg_replace( '/"[^"]*"/', '', $firstLine );
		$best      = 'comma';
		$bestCount = 0;
		foreach ( [ 'tab', 'semicolon', 'comma' ] as $name ) {
			$count = substr_count( $unquoted, self::DELIMITERS[ $name ] );
			if ( $count > $bestCount ) {
				$best      = $name;
				$bestCount = $count;
			}
		}

		return [ 'delimiter' => self::DELIMITERS[ $best ], 'sepLine' => false ];
	}

	/**
	 * RFC 4180 as Excel reads it: a qualifier only opens a quoted value at the
	 * start of a cell, a doubled qualifier inside one is a literal, and line
	 * breaks inside one are part of the value. With $consecutive a run of
	 * delimiters counts as one.
	 *
	 * @param string $delimiters Every character in it is a delimiter.
	 * @return array<int, array<int, string>>
	 */
	public static function parse( string $text, string $delimiters, string $qualifier, bool $consecutive ): array {
		$delimiterList = $delimiters === '' ? [] : (array) preg_split( '//u', $delimiters, -1, PREG_SPLIT_NO_EMPTY );
		$qualifier     = $qualifier === '' ? '' : (string) mb_substr( $qualifier, 0, 1 );
		$delimiterSet  = array_fill_keys( $delimiterList, true );

		// The next character that matters, as bytes: a UTF-8 character's bytes
		// only match where that character starts, so no /u is needed.
		$specials = array_merge( $delimiterList, $qualifier !== '' ? [ $qualifier ] : [], [ "\r\n", "\r", "\n" ] );
		usort( $specials, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$pattern = '/' . implode( '|', array_map( static function ( $special ) { return preg_quote( $special, '/' ); }, array_unique( $specials ) ) ) . '/';

		$records        = [];
		$row            = [];
		$cell           = '';
		$inQuotes       = false;
		$atCellStart    = true;
		$afterDelimiter = false;
		$length         = strlen( $text );
		$position       = 0;

		while ( $position < $length ) {
			if ( preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE, $position ) !== 1 ) {
				$rest = (string) substr( $text, $position );
				$cell .= $rest;
				if ( ! $inQuotes && $rest !== '' ) {
					$atCellStart    = false;
					$afterDelimiter = false;
				}
				break;
			}
			[ $token, $at ] = $match[0];
			if ( $at > $position ) {
				$cell .= (string) substr( $text, $position, $at - $position );
				if ( ! $inQuotes ) {
					$atCellStart    = false;
					$afterDelimiter = false;
				}
			}
			$position = $at + strlen( $token );

			if ( $inQuotes ) {
				if ( $token === $qualifier ) {
					if ( $qualifier !== '' && substr( $text, $position, strlen( $qualifier ) ) === $qualifier ) {
						$cell     .= $qualifier;
						$position += strlen( $qualifier );
					} else {
						$inQuotes = false;
					}
				} else {
					$cell .= $token;
				}
				continue;
			}
			if ( $qualifier !== '' && $token === $qualifier && $atCellStart ) {
				$inQuotes       = true;
				$atCellStart    = false;
				$afterDelimiter = false;
				continue;
			}
			if ( isset( $delimiterSet[ $token ] ) ) {
				if ( ! ( $consecutive && $afterDelimiter ) ) {
					$row[] = $cell;
					$cell  = '';
				}
				$atCellStart    = true;
				$afterDelimiter = true;
				continue;
			}
			if ( $token === "\r\n" || $token === "\r" || $token === "\n" ) {
				$row[]          = $cell;
				$records[]      = $row;
				$row            = [];
				$cell           = '';
				$atCellStart    = true;
				$afterDelimiter = false;
				continue;
			}
			// The qualifier in the middle of a cell is an ordinary character.
			$cell          .= $token;
			$atCellStart    = false;
			$afterDelimiter = false;
		}
		if ( $cell !== '' || $row !== [] || $inQuotes ) {
			$row[]     = $cell;
			$records[] = $row;
		}

		return $records;
	}

	/**
	 * The records from the start row on: the header (or null) and the data
	 * rows with their row numbers in the file; rows without any value are
	 * left out.
	 *
	 * @param array<int, array<int, string>> $records
	 * @return array{header: ?array<int, string>, rows: array<int, array<int, string>>, numbers: array<int, int>, width: int}
	 */
	public static function table( array $records, int $startRow, bool $headers ): array {
		$startRow    = max( 1, $startRow );
		$rows        = array_slice( $records, $startRow - 1 );
		$header      = null;
		$firstNumber = $startRow;
		if ( $headers && $rows !== [] ) {
			$header = array_shift( $rows );
			$firstNumber++;
		}
		$data    = [];
		$numbers = [];
		foreach ( $rows as $index => $row ) {
			foreach ( $row as $cell ) {
				if ( self::hasValue( (string) $cell ) ) {
					$data[]    = $row;
					$numbers[] = $firstNumber + $index;
					break;
				}
			}
		}
		$width = $header !== null ? count( $header ) : 0;
		foreach ( $data as $row ) {
			$width = max( $width, count( $row ) );
		}

		return [ 'header' => $header, 'rows' => $data, 'numbers' => $numbers, 'width' => $width ];
	}

	/**
	 * The wizard's read settings from an import request, cleaned, or null
	 * when there are none.
	 *
	 * @param mixed $raw
	 * @return array{encoding: string, delimiters: string, qualifier: string, consecutive: bool, start_row: int, headers: bool}|null
	 */
	public static function settings( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$encoding   = strtolower( is_string( $raw['encoding'] ?? null ) ? $raw['encoding'] : '' );
		$delimiters = is_string( $raw['delimiters'] ?? null ) ? (string) mb_substr( $raw['delimiters'], 0, 8 ) : '';
		$qualifier  = is_string( $raw['qualifier'] ?? null ) ? (string) mb_substr( $raw['qualifier'], 0, 1 ) : '';
		if ( ! isset( self::ENCODINGS[ $encoding ] ) || $delimiters === '' || ! in_array( $qualifier, [ '', '"', "'" ], true ) ) {
			return null;
		}

		return [
			'encoding'    => $encoding,
			'delimiters'  => $delimiters,
			'qualifier'   => $qualifier,
			'consecutive' => ! empty( $raw['consecutive'] ),
			'start_row'   => max( 1, (int) ( $raw['start_row'] ?? 1 ) ),
			'headers'     => ! empty( $raw['headers'] ),
		];
	}

	/**
	 * The read settings the wizard starts with for a file (its loadBuffer()):
	 * the detected encoding and delimiter, double quotes, a header row, and
	 * row 2 as the start when Excel's "sep=" line comes first.
	 *
	 * @return array{encoding: string, delimiters: string, qualifier: string, consecutive: bool, start_row: int, headers: bool}
	 */
	public static function detectSettings( string $bytes ): array {
		$encoding  = self::detectEncoding( $bytes )['encoding'];
		$delimiter = self::detectDelimiter( self::decode( $bytes, $encoding ) );

		return [
			'encoding'    => $encoding,
			'delimiters'  => $delimiter['delimiter'],
			'qualifier'   => '"',
			'consecutive' => false,
			'start_row'   => $delimiter['sepLine'] ? 2 : 1,
			'headers'     => true,
		];
	}

	/**
	 * A file read with the given settings (see settings()).
	 *
	 * @param array{encoding: string, delimiters: string, qualifier: string, consecutive: bool, start_row: int, headers: bool} $settings
	 * @return array{header: ?array<int, string>, rows: array<int, array<int, string>>, numbers: array<int, int>, width: int}
	 */
	public static function read( string $bytes, array $settings ): array {
		$text = self::decode( $bytes, $settings['encoding'] );

		return self::table( self::parse( $text, $settings['delimiters'], $settings['qualifier'], $settings['consecutive'] ), $settings['start_row'], $settings['headers'] );
	}

	/**
	 * Whether a cell has a value: something other than whitespace, as
	 * JavaScript's String.prototype.trim() counts it.
	 */
	private static function hasValue( string $cell ): bool {
		return preg_match( '/[^\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]/u', $cell ) === 1;
	}
}
