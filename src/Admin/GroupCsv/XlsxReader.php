<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads an Excel workbook (.xlsx: a zip of XML files) into rows of text,
 * the values as Excel shows them where that matters for an import: text
 * (also rich text and shared strings), numbers without float noise, dates
 * and times as Y-m-d / Y-m-d H:i / H:i (both the 1900 and 1904 date
 * systems), percentages, TRUE/FALSE, a formula's last calculated value and
 * error values as text. Hidden worksheets are left out. Needs PHP's zip
 * extension; XMLReader keeps memory flat for large sheets.
 */
final class XlsxReader {

	private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** Built-in number formats that show a date or time. */
	private const DATE_FORMATS = [ 14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58 ];

	/** Built-in time-only formats. */
	private const TIME_FORMATS = [ 18, 19, 20, 21, 45, 46, 47 ];

	/**
	 * Whether the bytes are a zip file that holds an Excel workbook.
	 */
	public static function isXlsx( string $bytes ): bool {
		return strncmp( $bytes, "PK\x03\x04", 4 ) === 0 && strpos( $bytes, 'xl/workbook.xml' ) !== false;
	}

	/**
	 * The workbook's visible worksheets and the rows of one of them ($sheet,
	 * by name; the first when '' or unknown is not allowed: an unknown name
	 * is an error).
	 *
	 * @return array{sheets: array<int, string>, sheet: string, rows: array<int, array<int, string>>}
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function read( string $bytes, string $sheet = '' ): array {
		if ( ! class_exists( '\ZipArchive' ) || ! class_exists( '\XMLReader' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output: GroupCsvSource::convert() catches it and returns the message as an error, which the wizard shows as text.
			throw new \RuntimeException( __( 'This server cannot read Excel files (PHP\'s zip extension is missing). Save the file as CSV instead.', 'rowsprout' ) );
		}
		$path = (string) tempnam( get_temp_dir(), 'rsxlsx' );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary copy for ZipArchive.
		$zip    = new \ZipArchive();
		$opened = false;
		try {
			$opened = $zip->open( $path ) === true;
			if ( ! $opened ) {
				throw new \RuntimeException( __( 'This Excel file could not be opened. Save it again in Excel, or as CSV.', 'rowsprout' ) );
			}

			$workbook = self::xml( $zip, 'xl/workbook.xml' );
			$sheets   = self::sheets( $zip, $workbook );
			if ( $sheets === [] ) {
				throw new \RuntimeException( __( 'This Excel file has no worksheet to import.', 'rowsprout' ) );
			}
			$names = array_keys( $sheets );
			if ( $sheet === '' ) {
				$sheet = $names[0];
			} elseif ( ! isset( $sheets[ $sheet ] ) ) {
				throw new \RuntimeException( sprintf(
					/* translators: 1: worksheet name, 2: comma-separated worksheet names. */
					__( 'The worksheet "%1$s" is not in this Excel file. Its worksheets: %2$s.', 'rowsprout' ),
					$sheet,
					implode( ', ', $names )
				) );
			}

			$props   = $workbook->children( self::NS_MAIN )->workbookPr;
			$date1904 = $props && in_array( strtolower( (string) $props->attributes()->date1904 ), [ '1', 'true' ], true );
			$strings = self::sharedStrings( $zip );
			$formats = self::styles( $zip );
			$rows    = self::sheetRows( $zip, $sheets[ $sheet ], $strings, $formats, $date1904 );
		} finally {
			if ( $opened ) {
				$zip->close();
			}
			wp_delete_file( $path );
		}

		return [ 'sheets' => $names, 'sheet' => $sheet, 'rows' => $rows ];
	}

	/**
	 * Visible worksheet name => its XML file in the zip, in workbook order.
	 *
	 * @return array<string, string>
	 */
	private static function sheets( \ZipArchive $zip, \SimpleXMLElement $workbook ): array {
		$targets = [];
		$rels    = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( is_string( $rels ) && $rels !== '' ) {
			$xml = self::parse( $rels );
			foreach ( $xml->children() as $relationship ) {
				$attributes                                = $relationship->attributes();
				$targets[ (string) $attributes->Id ] = (string) $attributes->Target;
			}
		}

		$sheets = [];
		$list   = $workbook->children( self::NS_MAIN )->sheets;
		foreach ( $list ? $list->children( self::NS_MAIN ) : [] as $node ) {
			$attributes = $node->attributes();
			$state      = (string) $attributes->state;
			if ( $state === 'hidden' || $state === 'veryHidden' ) {
				continue;
			}
			$id     = (string) $node->attributes( self::NS_REL )->id;
			$target = $targets[ $id ] ?? '';
			if ( $target === '' ) {
				continue;
			}
			$sheets[ (string) $attributes->name ] = $target[0] === '/' ? ltrim( $target, '/' ) : 'xl/' . $target;
		}

		return $sheets;
	}

	/**
	 * @return array<int, string>
	 */
	private static function sharedStrings( \ZipArchive $zip ): array {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( ! is_string( $xml ) || $xml === '' ) {
			return [];
		}
		$reader = self::reader( $xml );
		$list   = [];
		$text   = null;
		$skip   = 0;
		while ( $reader->read() ) {
			if ( $reader->nodeType === \XMLReader::ELEMENT ) {
				if ( $reader->localName === 'si' ) {
					$text = '';
				} elseif ( $reader->localName === 'rPh' ) {
					// Phonetic hints (furigana) are not part of the text.
					$skip += $reader->isEmptyElement ? 0 : 1;
				} elseif ( $reader->localName === 't' && $text !== null && $skip === 0 ) {
					$text .= $reader->readString();
				}
			} elseif ( $reader->nodeType === \XMLReader::END_ELEMENT ) {
				if ( $reader->localName === 'si' ) {
					$list[] = (string) $text;
					$text   = null;
				} elseif ( $reader->localName === 'rPh' ) {
					$skip = max( 0, $skip - 1 );
				}
			}
		}
		$reader->close();

		return $list;
	}

	/**
	 * Per cell style (the s attribute): 'date', 'datetime', 'time', 'percent'
	 * or '' for anything shown as is.
	 *
	 * @return array<int, string>
	 */
	private static function styles( \ZipArchive $zip ): array {
		$raw = $zip->getFromName( 'xl/styles.xml' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$xml     = self::parse( $raw )->children( self::NS_MAIN );
		$custom  = [];
		foreach ( $xml->numFmts ? $xml->numFmts->children( self::NS_MAIN ) : [] as $format ) {
			$attributes                              = $format->attributes();
			$custom[ (int) $attributes->numFmtId ] = (string) $attributes->formatCode;
		}
		$kinds = [];
		foreach ( $xml->cellXfs ? $xml->cellXfs->children( self::NS_MAIN ) : [] as $xf ) {
			$id      = (int) $xf->attributes()->numFmtId;
			$kinds[] = isset( $custom[ $id ] ) ? self::kindOfCode( $custom[ $id ] ) : self::kindOfBuiltin( $id );
		}

		return $kinds;
	}

	private static function kindOfBuiltin( int $id ): string {
		if ( in_array( $id, self::TIME_FORMATS, true ) ) {
			return 'time';
		}
		if ( $id === 22 ) {
			return 'datetime';
		}
		if ( in_array( $id, self::DATE_FORMATS, true ) ) {
			return 'date';
		}

		return in_array( $id, [ 9, 10 ], true ) ? 'percent' : '';
	}

	/**
	 * What a custom number format shows: quoted text, [colours] and
	 * escaped characters do not count.
	 */
	private static function kindOfCode( string $code ): string {
		$plain = (string) preg_replace( '/"[^"]*"|\\\\.|_.|\*.|\[(?!h\]|m\]|s\])[^\]]*\]/i', '', $code );
		$plain = strtolower( (string) strtok( $plain, ';' ) );
		if ( $plain === 'general' || $plain === '@' || $plain === '' ) {
			return '';
		}
		$date = preg_match( '/[dy]/', $plain ) === 1 || preg_match( '/(^|[^h:])m+([^:s]|$)/', $plain ) === 1 && preg_match( '/[hs]/', $plain ) !== 1;
		$time = preg_match( '/[hs]/', $plain ) === 1;
		if ( $date && $time ) {
			return 'datetime';
		}
		if ( $date ) {
			return 'date';
		}
		if ( $time ) {
			return 'time';
		}

		return strpos( $plain, '%' ) !== false ? 'percent' : '';
	}

	/**
	 * The sheet's rows from row 1 on (empty rows kept, so row numbers match
	 * Excel's), each cell as text.
	 *
	 * @param array<int, string> $strings
	 * @param array<int, string> $formats
	 * @return array<int, array<int, string>>
	 */
	private static function sheetRows( \ZipArchive $zip, string $file, array $strings, array $formats, bool $date1904 ): array {
		$xml = $zip->getFromName( $file );
		if ( ! is_string( $xml ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output: GroupCsvSource::convert() catches it and returns the message as an error, which the wizard shows as text.
			throw new \RuntimeException( __( 'This Excel file could not be read: a worksheet is missing.', 'rowsprout' ) );
		}
		$reader  = self::reader( $xml );
		$rows    = [];
		$rowNo   = 0;
		$cell    = null;
		while ( $reader->read() ) {
			if ( $reader->nodeType === \XMLReader::ELEMENT ) {
				switch ( $reader->localName ) {
					case 'row':
						$rowNo = (int) $reader->getAttribute( 'r' ) ?: $rowNo + 1;
						break;
					case 'c':
						$ref  = (string) $reader->getAttribute( 'r' );
						$cell = [
							'col'   => $ref !== '' ? self::columnIndex( $ref ) : ( isset( $rows[ $rowNo ] ) ? count( $rows[ $rowNo ] ) : 0 ),
							'type'  => (string) $reader->getAttribute( 't' ),
							'style' => (int) $reader->getAttribute( 's' ),
							'value' => '',
						];
						if ( $reader->isEmptyElement ) {
							$cell = null;
						}
						break;
					case 'v':
						if ( $cell !== null ) {
							$cell['value'] = $reader->readString();
						}
						break;
					case 't':
						// An inline string: <is><t>…</t></is>.
						if ( $cell !== null && $cell['type'] === 'inlineStr' ) {
							$cell['value'] .= $reader->readString();
						}
						break;
				}
			} elseif ( $reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'c' && $cell !== null ) {
				$rows[ $rowNo ][ $cell['col'] ] = self::cellText( $cell, $strings, $formats, $date1904 );
				$cell                            = null;
			}
		}
		$reader->close();

		$out = [];
		$max = $rows === [] ? 0 : max( array_keys( $rows ) );
		for ( $row = 1; $row <= $max; $row++ ) {
			$cells = $rows[ $row ] ?? [];
			$line  = [];
			if ( $cells !== [] ) {
				$width = max( array_keys( $cells ) ) + 1;
				for ( $col = 0; $col < $width; $col++ ) {
					$line[] = $cells[ $col ] ?? '';
				}
			}
			$out[] = $line;
		}

		return $out;
	}

	/**
	 * @param array{type: string, style: int, value: string} $cell
	 * @param array<int, string>                             $strings
	 * @param array<int, string>                             $formats
	 */
	private static function cellText( array $cell, array $strings, array $formats, bool $date1904 ): string {
		$value = $cell['value'];
		switch ( $cell['type'] ) {
			case 's':
				return $strings[ (int) $value ] ?? '';
			case 'inlineStr':
			case 'str':
			case 'e':
				return $value;
			case 'b':
				return $value === '1' ? 'TRUE' : 'FALSE';
		}
		if ( $value === '' || ! is_numeric( $value ) ) {
			return $value;
		}

		$kind = $formats[ $cell['style'] ] ?? '';
		if ( $kind === 'percent' ) {
			return self::number( (string) ( (float) $value * 100 ) ) . '%';
		}
		if ( $kind !== '' ) {
			return self::date( (float) $value, $kind, $date1904 );
		}

		return self::number( $value );
	}

	/**
	 * A number as Excel shows it in General format: at most 15 significant
	 * digits, no float noise (0.30000000000000004 → 0.3), integers as is.
	 */
	private static function number( string $value ): string {
		if ( preg_match( '/^-?\d+$/', $value ) === 1 ) {
			return $value;
		}
		$text = sprintf( '%.15G', (float) $value );
		if ( stripos( $text, 'E' ) === false && strpos( $text, '.' ) !== false ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}

		return $text;
	}

	/**
	 * An Excel serial date as text (no time zone: the value as shown).
	 */
	private static function date( float $serial, string $kind, bool $date1904 ): string {
		$days    = (int) floor( $serial );
		$seconds = (int) round( ( $serial - $days ) * 86400 );
		if ( $seconds >= 86400 ) {
			$days++;
			$seconds -= 86400;
		}
		// 1900 system: day 1 is 1900-01-01, and Excel counts a 1900-02-29
		// that did not exist (day 60), so from day 61 on the base is a day earlier.
		$base = $date1904 ? '1904-01-01' : ( $days < 61 ? '1899-12-31' : '1899-12-30' );
		$date = ( new \DateTimeImmutable( $base, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->modify( '+' . $seconds . ' seconds' );

		switch ( $kind ) {
			case 'time':
				return $date->format( $seconds % 60 === 0 ? 'H:i' : 'H:i:s' );
			case 'datetime':
				return $date->format( $seconds % 60 === 0 ? 'Y-m-d H:i' : 'Y-m-d H:i:s' );
			default:
				// A date format shows the day only, as Excel does.
				return $date->format( 'Y-m-d' );
		}
	}

	/**
	 * "B3" → 1, "AA10" → 26.
	 */
	private static function columnIndex( string $ref ): int {
		$letters = strtoupper( (string) preg_replace( '/\d+$/', '', $ref ) );
		$index   = 0;
		for ( $i = 0, $n = strlen( $letters ); $i < $n; $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	private static function xml( \ZipArchive $zip, string $name ): \SimpleXMLElement {
		$raw = $zip->getFromName( $name );
		if ( ! is_string( $raw ) || $raw === '' ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output: GroupCsvSource::convert() catches it and returns the message as an error, which the wizard shows as text.
			throw new \RuntimeException( __( 'This is not an Excel workbook (.xlsx). Save it in Excel as .xlsx or as CSV.', 'rowsprout' ) );
		}

		return self::parse( $raw );
	}

	private static function parse( string $xml ): \SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( $xml, \SimpleXMLElement::class, LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( ! $parsed instanceof \SimpleXMLElement ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output: GroupCsvSource::convert() catches it and returns the message as an error, which the wizard shows as text.
			throw new \RuntimeException( __( 'This Excel file could not be read: it is damaged.', 'rowsprout' ) );
		}

		return $parsed;
	}

	private static function reader( string $xml ): \XMLReader {
		$reader = new \XMLReader();
		$reader->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );

		return $reader;
	}
}
