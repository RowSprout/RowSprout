<?php

namespace RowSprout\Core\Conditions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tests a property value against a condition rule ("price is not empty",
 * "date year is 2026"), for conditional content such as the Elementor
 * "If / then" widget. Editor-independent, so the block editor and WPBakery
 * can use it too.
 *
 * Values are compared by kind: text, number, date, bool or list. A property
 * type's kind comes from kindOf() (filter rowsprout_condition_kind), so an
 * add-on's types (a date, a checkbox, an item list) work with the same
 * operators. An operator that means nothing for a kind does not match (its
 * negation then does).
 */
final class ConditionEvaluator {

	public const KIND_TEXT   = 'text';
	public const KIND_NUMBER = 'number';
	public const KIND_DATE   = 'date';
	public const KIND_BOOL   = 'bool';
	public const KIND_LIST   = 'list';

	public const MATCH_ALL = 'all';
	public const MATCH_ANY = 'any';

	/** Operators that compare with today, so a cached page can go stale. */
	private const RELATIVE = [ 'is_today', 'in_past', 'in_future', 'next_days', 'last_days' ];

	/**
	 * Every operator: label and how many values it takes.
	 *
	 * @return array<string, array{label: string, args: int}>
	 */
	public static function operators(): array {
		return [
			'is_empty'       => [ 'label' => __( 'is empty', 'rowsprout' ), 'args' => 0 ],
			'is_not_empty'   => [ 'label' => __( 'is not empty', 'rowsprout' ), 'args' => 0 ],
			'equals'         => [ 'label' => __( 'is equal to', 'rowsprout' ), 'args' => 1 ],
			'not_equals'     => [ 'label' => __( 'is not equal to', 'rowsprout' ), 'args' => 1 ],
			'contains'       => [ 'label' => __( 'contains', 'rowsprout' ), 'args' => 1 ],
			'not_contains'   => [ 'label' => __( 'does not contain', 'rowsprout' ), 'args' => 1 ],
			'starts_with'    => [ 'label' => __( 'starts with', 'rowsprout' ), 'args' => 1 ],
			'gt'             => [ 'label' => __( 'is greater than / after', 'rowsprout' ), 'args' => 1 ],
			'gte'            => [ 'label' => __( 'is greater than or equal to', 'rowsprout' ), 'args' => 1 ],
			'lt'             => [ 'label' => __( 'is less than / before', 'rowsprout' ), 'args' => 1 ],
			'lte'            => [ 'label' => __( 'is less than or equal to', 'rowsprout' ), 'args' => 1 ],
			'between'        => [ 'label' => __( 'is between', 'rowsprout' ), 'args' => 2 ],
			'year_is'        => [ 'label' => __( 'date: year is', 'rowsprout' ), 'args' => 1 ],
			'is_today'       => [ 'label' => __( 'date: is today', 'rowsprout' ), 'args' => 0 ],
			'in_past'        => [ 'label' => __( 'date: is in the past', 'rowsprout' ), 'args' => 0 ],
			'in_future'      => [ 'label' => __( 'date: is in the future', 'rowsprout' ), 'args' => 0 ],
			'next_days'      => [ 'label' => __( 'date: within the next … days', 'rowsprout' ), 'args' => 1 ],
			'last_days'      => [ 'label' => __( 'date: within the last … days', 'rowsprout' ), 'args' => 1 ],
			'is_checked'     => [ 'label' => __( 'checkbox: is checked', 'rowsprout' ), 'args' => 0 ],
			'is_not_checked' => [ 'label' => __( 'checkbox: is not checked', 'rowsprout' ), 'args' => 0 ],
		];
	}

	/**
	 * The operators that fit a kind, labelled for it ("is before" for a date,
	 * "contains the item" for a list), in the order an editor offers them.
	 *
	 * @return array<string, string> operator => label
	 */
	public static function operatorsFor( string $kind ): array {
		$empty = [
			'is_empty'     => __( 'is empty', 'rowsprout' ),
			'is_not_empty' => __( 'is not empty', 'rowsprout' ),
		];
		switch ( $kind ) {
			case self::KIND_NUMBER:
				return $empty + [
					'equals'     => __( 'is equal to', 'rowsprout' ),
					'not_equals' => __( 'is not equal to', 'rowsprout' ),
					'gt'         => __( 'is greater than', 'rowsprout' ),
					'gte'        => __( 'is greater than or equal to', 'rowsprout' ),
					'lt'         => __( 'is less than', 'rowsprout' ),
					'lte'        => __( 'is less than or equal to', 'rowsprout' ),
					'between'    => __( 'is between', 'rowsprout' ),
				];
			case self::KIND_DATE:
				return $empty + [
					'equals'    => __( 'is on', 'rowsprout' ),
					'year_is'   => __( 'is in the year', 'rowsprout' ),
					'lt'        => __( 'is before', 'rowsprout' ),
					'gt'        => __( 'is after', 'rowsprout' ),
					'between'   => __( 'is between', 'rowsprout' ),
					'is_today'  => __( 'is today', 'rowsprout' ),
					'in_past'   => __( 'is in the past', 'rowsprout' ),
					'in_future' => __( 'is in the future', 'rowsprout' ),
					'next_days' => __( 'is within the next … days', 'rowsprout' ),
					'last_days' => __( 'is within the last … days', 'rowsprout' ),
				];
			case self::KIND_BOOL:
				return [
					'is_checked'     => __( 'is checked', 'rowsprout' ),
					'is_not_checked' => __( 'is not checked', 'rowsprout' ),
				];
			case self::KIND_LIST:
				return $empty + [
					'equals'   => __( 'contains the item', 'rowsprout' ),
					'contains' => __( 'has an item containing', 'rowsprout' ),
					'gt'       => __( 'has more than … items', 'rowsprout' ),
					'lt'       => __( 'has fewer than … items', 'rowsprout' ),
				];
			default:
				return $empty + [
					'equals'       => __( 'is equal to', 'rowsprout' ),
					'not_equals'   => __( 'is not equal to', 'rowsprout' ),
					'contains'     => __( 'contains', 'rowsprout' ),
					'not_contains' => __( 'does not contain', 'rowsprout' ),
					'starts_with'  => __( 'starts with', 'rowsprout' ),
				];
		}
	}

	/**
	 * The operator a new rule on a property of this kind starts with.
	 */
	public static function defaultOperator( string $kind ): string {
		return $kind === self::KIND_BOOL ? 'is_checked' : 'is_not_empty';
	}

	/**
	 * @return array<int, string>
	 */
	public static function kinds(): array {
		return [ self::KIND_TEXT, self::KIND_NUMBER, self::KIND_DATE, self::KIND_BOOL, self::KIND_LIST ];
	}

	public static function isRelative( string $operator ): bool {
		return in_array( $operator, self::RELATIVE, true );
	}

	public static function argCount( string $operator ): int {
		return self::operators()[ $operator ]['args'] ?? 0;
	}

	/**
	 * The kind a property type's values are compared as.
	 */
	public static function kindOf( string $type ): string {
		$kind = $type === 'number' ? self::KIND_NUMBER : self::KIND_TEXT;

		/**
		 * How a property type's values are compared in conditions: text,
		 * number, date (Y-m-d or Y-m-d\TH:i in the site's time zone), bool
		 * ("1", "yes", "true" or "on" = checked) or list (one item per line).
		 *
		 * @param string $kind
		 * @param string $type The property type, e.g. "date".
		 */
		$kind = (string) apply_filters( 'rowsprout_condition_kind', $kind, $type );

		return in_array( $kind, [ self::KIND_TEXT, self::KIND_NUMBER, self::KIND_DATE, self::KIND_BOOL, self::KIND_LIST ], true ) ? $kind : self::KIND_TEXT;
	}

	/**
	 * Whether a value matches a rule. $today (Y-m-d) defaults to today in
	 * the site's time zone.
	 */
	public static function test( string $value, string $type, string $operator, string $arg1 = '', string $arg2 = '', ?string $today = null ): bool {
		switch ( $operator ) {
			case 'is_not_empty':
				return ! self::test( $value, $type, 'is_empty' );
			case 'not_equals':
				return ! self::test( $value, $type, 'equals', $arg1 );
			case 'not_contains':
				return ! self::test( $value, $type, 'contains', $arg1 );
			case 'is_not_checked':
				return ! self::test( $value, $type, 'is_checked' );
		}

		$kind = self::kindOf( $type );
		if ( $kind === self::KIND_LIST ) {
			return self::testList( self::listItems( $value ), $operator, $arg1, $arg2 );
		}
		if ( $kind === self::KIND_BOOL ) {
			$checked = self::isTrue( $value );
			if ( in_array( $operator, [ 'is_empty', 'is_checked' ], true ) ) {
				return $operator === 'is_empty' ? ! $checked : $checked;
			}
			// Compared as the text a checkbox shows in a file: 1 or nothing.
			return self::testScalar( $checked ? '1' : '', self::KIND_TEXT, $operator, $arg1, $arg2, $today );
		}

		return self::testScalar( $value, $kind, $operator, $arg1, $arg2, $today );
	}

	/**
	 * Whether a case matches, given its rules' results.
	 *
	 * @param array<int, bool> $results
	 */
	public static function matches( array $results, string $match ): bool {
		if ( $results === [] ) {
			return false;
		}

		return $match === self::MATCH_ANY ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * A date property's day (Y-m-d), for rules a browser checks against
	 * today; null when the value is not a date.
	 */
	public static function dayOf( string $value, string $type ): ?string {
		if ( self::kindOf( $type ) !== self::KIND_DATE ) {
			return null;
		}
		$date = self::parseDate( $value );

		return $date === null ? null : $date['date']->format( 'Y-m-d' );
	}

	/**
	 * Today in the site's time zone.
	 */
	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	private static function testScalar( string $value, string $kind, string $operator, string $arg1, string $arg2, ?string $today ): bool {
		$text = self::plainText( $value );
		if ( $operator === 'is_empty' ) {
			return $text === '';
		}
		if ( $operator === 'is_checked' ) {
			return $text !== '';
		}
		if ( $text === '' ) {
			// Nothing to compare: only the negations (handled above) match.
			return false;
		}

		if ( $kind === self::KIND_DATE ) {
			$date = self::parseDate( $value );
			if ( $date !== null ) {
				return self::testDate( $date, $operator, $arg1, $arg2, $today ?? self::today() );
			}
			$kind = self::KIND_TEXT;
		}

		switch ( $operator ) {
			case 'equals':
				$number = self::number( $text );
				$other  = self::number( $arg1 );
				return $kind === self::KIND_NUMBER && $number !== null && $other !== null ? abs( $number - $other ) < 1e-9 : self::lower( $text ) === self::lower( self::plainText( $arg1 ) );
			case 'contains':
				$needle = self::lower( self::plainText( $arg1 ) );
				return $needle !== '' && strpos( self::lower( $text ), $needle ) !== false;
			case 'starts_with':
				$needle = self::lower( self::plainText( $arg1 ) );
				return $needle !== '' && strpos( self::lower( $text ), $needle ) === 0;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				$compared = self::compareText( $text, $arg1 );
				return $compared !== null && self::ordered( $compared, $operator );
			case 'between':
				$low  = self::compareText( $text, $arg1 );
				$high = self::compareText( $text, $arg2 );
				return $low !== null && $high !== null && $low >= 0 && $high <= 0;
		}

		// Date operators on something that is not a date.
		return false;
	}

	/**
	 * @param array{date: \DateTimeImmutable, time: bool} $date
	 */
	private static function testDate( array $date, string $operator, string $arg1, string $arg2, string $today ): bool {
		$day = $date['date']->format( 'Y-m-d' );
		switch ( $operator ) {
			case 'year_is':
				return trim( $arg1 ) !== '' && $date['date']->format( 'Y' ) === trim( $arg1 );
			case 'is_today':
				return $day === $today;
			case 'in_past':
				return $day < $today;
			case 'in_future':
				return $day > $today;
			case 'next_days':
			case 'last_days':
				$days  = max( 0, (int) $arg1 );
				$limit = ( new \DateTimeImmutable( $today, wp_timezone() ) )->modify( ( $operator === 'next_days' ? '+' : '-' ) . $days . ' days' )->format( 'Y-m-d' );
				return $operator === 'next_days' ? $day >= $today && $day <= $limit : $day <= $today && $day >= $limit;
			case 'equals':
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				$other = self::parseDate( $arg1 );
				if ( $other === null ) {
					return false;
				}
				$compared = self::compareDates( $date, $other );
				return $operator === 'equals' ? $compared === 0 : self::ordered( $compared, $operator );
			case 'between':
				$low  = self::parseDate( $arg1 );
				$high = self::parseDate( $arg2 );
				return $low !== null && $high !== null && self::compareDates( $date, $low ) >= 0 && self::compareDates( $date, $high ) <= 0;
			case 'contains':
			case 'starts_with':
				return self::testScalar( $date['date']->format( $date['time'] ? 'Y-m-d H:i' : 'Y-m-d' ), self::KIND_TEXT, $operator, $arg1, $arg2, $today );
		}

		return false;
	}

	/**
	 * A list: "is empty", an item equal to / containing / starting with the
	 * value, and greater/less/between on the number of items.
	 *
	 * @param array<int, string> $items
	 */
	private static function testList( array $items, string $operator, string $arg1, string $arg2 ): bool {
		switch ( $operator ) {
			case 'is_empty':
				return $items === [];
			case 'is_checked':
				return $items !== [];
			case 'equals':
			case 'contains':
			case 'starts_with':
				foreach ( $items as $item ) {
					if ( self::testScalar( $item, self::KIND_TEXT, $operator, $arg1, '', null ) ) {
						return true;
					}
				}
				return false;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
			case 'between':
				return self::testScalar( (string) count( $items ), self::KIND_NUMBER, $operator, $arg1, $arg2, null );
		}

		return false;
	}

	/**
	 * Compares text as numbers when both are numbers, else alphabetically
	 * (case-insensitive, "item 2" before "item 10").
	 */
	private static function compareText( string $value, string $other ): ?int {
		$other = self::plainText( $other );
		if ( $other === '' ) {
			return null;
		}
		$a = self::number( $value );
		$b = self::number( $other );
		if ( $a !== null && $b !== null ) {
			return $a <=> $b;
		}

		return strnatcasecmp( $value, $other ) <=> 0;
	}

	/**
	 * Two dates by the day, or by the minute when both have a time.
	 *
	 * @param array{date: \DateTimeImmutable, time: bool} $a
	 * @param array{date: \DateTimeImmutable, time: bool} $b
	 */
	private static function compareDates( array $a, array $b ): int {
		if ( $a['time'] && $b['time'] ) {
			return $a['date']->format( 'Y-m-d H:i' ) <=> $b['date']->format( 'Y-m-d H:i' );
		}

		return $a['date']->format( 'Y-m-d' ) <=> $b['date']->format( 'Y-m-d' );
	}

	private static function ordered( int $compared, string $operator ): bool {
		switch ( $operator ) {
			case 'gt':
				return $compared > 0;
			case 'gte':
				return $compared >= 0;
			case 'lt':
				return $compared < 0;
			default:
				return $compared <= 0;
		}
	}

	/**
	 * A date as stored (2026-10-12, 2026-10-12T14:30) or typed in a rule
	 * (also 12-10-2026 and 12/10/2026), in the site's time zone.
	 *
	 * @return array{date: \DateTimeImmutable, time: bool}|null
	 */
	private static function parseDate( string $value ): ?array {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		$formats = [ 'Y-m-d\TH:i:s' => true, 'Y-m-d\TH:i' => true, 'Y-m-d H:i:s' => true, 'Y-m-d H:i' => true, 'Y-m-d' => false, 'd-m-Y' => false, 'd/m/Y' => false, 'd-m-Y H:i' => true ];
		foreach ( $formats as $format => $hasTime ) {
			$date   = \DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date !== false && ( $errors === false || ( $errors['warning_count'] === 0 && $errors['error_count'] === 0 ) ) ) {
				return [ 'date' => $date, 'time' => $hasTime ];
			}
		}

		return null;
	}

	/**
	 * A number as people type it: 1234.5, 1234,5, 1.234,5 or 1,234.5.
	 */
	private static function number( string $value ): ?float {
		$value = str_replace( [ ' ', "\u{00A0}" ], '', trim( $value ) );
		if ( $value === '' ) {
			return null;
		}
		$comma = strrpos( $value, ',' );
		$dot   = strrpos( $value, '.' );
		if ( $comma !== false && $dot !== false ) {
			// The last separator is the decimal one.
			$value = $comma > $dot ? str_replace( [ '.', ',' ], [ '', '.' ], $value ) : str_replace( ',', '', $value );
		} elseif ( $comma !== false ) {
			$value = str_replace( ',', '.', $value );
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	private static function isTrue( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'yes', 'true', 'on' ], true );
	}

	/**
	 * @return array<int, string>
	 */
	private static function listItems( string $value ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ?: [] ), 'strlen' ) );
	}

	/**
	 * Text without markup (rich text compares by what it says).
	 */
	private static function plainText( string $value ): string {
		if ( strpos( $value, '<' ) !== false || strpos( $value, '&' ) !== false ) {
			$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
		}

		return trim( $value );
	}

	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}
}
