<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps every group on one line of the file. A list property (Item-list:
 * one item per line) is written as "item 1 | item 2"; line breaks in other
 * multi-line text (Textarea, Pro's Rich text) are written as \n. A backslash
 * escapes, so both come back exactly on import: \\ is a backslash, and \|
 * a "|" inside a list item.
 *
 * Single-line properties are written as they are.
 */
final class GroupCsvValue {

	public const LIST_SEPARATOR = ' | ';

	/**
	 * @param array<string, mixed> $column
	 */
	public static function isList( array $column ): bool {
		/**
		 * Property types whose value is a list with one item per line,
		 * written to a groups CSV file as "item 1 | item 2".
		 *
		 * @param array<int, string> $types
		 */
		$types = (array) apply_filters( 'rowsprout_group_csv_list_types', [ 'item-list' ] );

		return in_array( (string) ( $column['type'] ?? '' ), $types, true );
	}

	/**
	 * @param array<string, mixed> $column
	 */
	public static function isMultiline( array $column ): bool {
		return in_array( (string) ( $column['field_type'] ?? '' ), [ 'textarea', 'richtext' ], true )
			|| in_array( (string) ( $column['type'] ?? '' ), [ 'textarea', 'richtext' ], true );
	}

	/**
	 * @param array<string, mixed> $column
	 */
	public static function toCell( array $column, string $value ): string {
		if ( self::isList( $column ) ) {
			$items = array_map(
				static function ( string $item ): string {
					return str_replace( [ '\\', '|' ], [ '\\\\', '\\|' ], $item );
				},
				self::items( $value )
			);

			return implode( self::LIST_SEPARATOR, $items );
		}

		if ( self::isMultiline( $column ) ) {
			// The backslash first, so the \n written after it is not doubled.
			return str_replace( [ '\\', "\r\n", "\r", "\n" ], [ '\\\\', '\n', '\n', '\n' ], $value );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $column
	 */
	public static function fromCell( array $column, string $cell ): string {
		if ( self::isList( $column ) ) {
			// Already one item per line (a file made elsewhere).
			if ( preg_match( '/[\r\n]/', $cell ) === 1 ) {
				return $cell;
			}

			$items   = [];
			$current = '';
			$length  = strlen( $cell );
			for ( $i = 0; $i < $length; $i++ ) {
				$char = $cell[ $i ];
				if ( $char === '\\' && $i + 1 < $length && ( $cell[ $i + 1 ] === '\\' || $cell[ $i + 1 ] === '|' ) ) {
					$current .= $cell[ ++$i ];
				} elseif ( $char === '|' ) {
					$items[] = $current;
					$current = '';
				} else {
					$current .= $char;
				}
			}
			$items[] = $current;

			return implode( "\n", array_values( array_filter( array_map( 'trim', $items ), 'strlen' ) ) );
		}

		if ( self::isMultiline( $column ) ) {
			// Only a cell in which every backslash belongs to an escape (as
			// in an exported file) is decoded: a path typed in a spreadsheet,
			// such as C:\temp\new, is kept as it is.
			if ( strpos( (string) preg_replace( '/\\\\[\\\\n]/', '', $cell ), '\\' ) !== false ) {
				return $cell;
			}
			return (string) preg_replace_callback(
				'/\\\\([\\\\n])/',
				static function ( array $match ): string {
					return $match[1] === 'n' ? "\n" : '\\';
				},
				$cell
			);
		}

		return self::normalizeDate( $column, $cell );
	}

	/**
	 * A spreadsheet app writes a date and time as "2026-10-11 14:30" or
	 * "2026-01-02 9:05", while the Date and time type stores (and its input
	 * needs) "2026-10-11T14:30"; a date can come with a time ("2026-10-11
	 * 0:00:00"). Only these year-first forms are converted: they cannot be
	 * read two ways, unlike 10/11/2026.
	 *
	 * @param array<string, mixed> $column
	 */
	private static function normalizeDate( array $column, string $cell ): string {
		$kind  = (string) ( $column['field_type'] ?? '' );
		$value = trim( $cell );
		if ( $kind === 'datetime-local' && preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $match ) === 1 ) {
			return sprintf( '%sT%02d:%s', $match[1], (int) $match[2], $match[3] );
		}
		if ( $kind === 'date' && preg_match( '/^(\d{4}-\d{2}-\d{2})[ T]\d{1,2}:\d{2}(?::\d{2})?$/', $value, $match ) === 1 ) {
			return $match[1];
		}

		return $cell;
	}

	/**
	 * Whether an imported value is the stored one as a spreadsheet app
	 * rewrites it when it converts text to numbers (Google Sheets' and
	 * Excel's default): leading zeros dropped ("0612345678" to "612345678",
	 * "007" to "7"), or a number in the app's own notation ("1e5" to
	 * "1,00E+05"). The import keeps the stored value and says so: the lost
	 * digits are no edit anyone made.
	 */
	public static function spreadsheetEquivalent( string $stored, string $imported ): bool {
		$stored   = trim( $stored );
		$imported = trim( $imported );
		if ( $stored === '' || $imported === '' || $stored === $imported ) {
			return false;
		}
		if ( ctype_digit( $stored ) && ctype_digit( $imported ) && $stored[0] === '0' ) {
			return ltrim( $stored, '0' ) === ltrim( $imported, '0' );
		}
		if ( is_numeric( $stored ) && preg_match( '/^-?\d+(?:[.,]\d+)?E[+-]?\d+$/i', $imported ) === 1 ) {
			return (float) $stored === (float) str_replace( ',', '.', $imported );
		}

		return false;
	}

	/**
	 * Whether an imported value says the same as the stored one, so an
	 * updated group keeps the stored value: a list with the same items, or
	 * text that only differs in its line endings. Otherwise importing a file
	 * that was just exported would mark groups as changed.
	 *
	 * @param array<string, mixed> $column
	 */
	public static function equivalent( array $column, string $stored, string $imported ): bool {
		if ( $stored === $imported ) {
			return true;
		}
		if ( self::isList( $column ) ) {
			return array_map( 'trim', self::items( $stored ) ) === array_map( 'trim', self::items( $imported ) );
		}
		if ( self::isMultiline( $column ) ) {
			return str_replace( [ "\r\n", "\r" ], "\n", $stored ) === str_replace( [ "\r\n", "\r" ], "\n", $imported );
		}

		return false;
	}

	/**
	 * The non-empty lines of a list value.
	 *
	 * @return array<int, string>
	 */
	private static function items( string $value ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $value ) ?: [];

		return array_values( array_filter( $lines, static function ( string $line ): bool {
			return trim( $line ) !== '';
		} ) );
	}
}
