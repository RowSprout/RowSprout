<?php

namespace RowSprout\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class IdList {

	/**
	 * Parse a comma-separated list of IDs into a unique array of positive integers.
	 *
	 * @param mixed $input
	 * @return int[]
	 */
	public static function parse( $input ): array {
		if ( is_array( $input ) ) {
			$input = implode( ',', $input );
		}

		$input = (string) $input;
		if ( $input === '' ) {
			return [];
		}

		$parts = preg_split( '/\s*,\s*/', $input, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return [];
		}

		$ids = [];
		foreach ( $parts as $part ) {
			$id = absint( $part );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Normalize an ID list to a canonical CSV string (e.g. "12,34,56").
	 *
	 * @param mixed $input
	 * @return string
	 */
	public static function toCsv( $input ): string {
		return implode( ',', self::parse( $input ) );
	}
}
