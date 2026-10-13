<?php

namespace RowSprout\Core\Conditions;

use RowSprout\Core\TemplateMeta;
use RowSprout\ThirdParty\Elementor\PageFieldResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules of conditional content ("If / then"), the same for every editor:
 * a list of rules, each { rule_property: a property code, join: "and"|"or"
 * (with the rule above; the first rule's is not used), op_<kind>: the
 * operator for each kind of property (the one of the property's kind
 * counts), value, value2 }. "And" binds first: A and B or C = (A and B) or C.
 *
 * An editor stores the rules in this shape (the Elementor widget as its
 * repeater, a block or WPBakery element as an attribute) and hands them to
 * decide(); ConditionalOutput renders the result.
 */
final class RuleSet {

	public const JOIN_AND = 'and';
	public const JOIN_OR  = 'or';

	/**
	 * Whether a group matches the rules ("matches", with $today, default
	 * today in the site's time zone). "client" is set when the outcome
	 * depends on a rule on today: the "or" groups that today can still
	 * decide, each with only its rules on today ({ d: the property's day,
	 * o: operator, n: days }; its other rules all matched here), for the
	 * conditional-content script. "missing" lists property codes the group
	 * does not have, "changed" the rules (index) whose operator did not fit
	 * the property's kind.
	 *
	 * @param array<int, mixed>         $rules
	 * @param array<string, mixed>|null $group
	 * @return array{matches: bool, client: array<string, mixed>|null, missing: array<int, string>, changed: array<int, int>, ruleCount: int}
	 */
	public static function decide( array $rules, ?array $group, ?string $today = null ): array {
		$groups    = [];
		$missing   = [];
		$changed   = [];
		$ruleCount = 0;

		foreach ( array_values( $rules ) as $index => $rule ) {
			$rule = is_array( $rule ) ? $rule : [];
			$code = (string) ( $rule['rule_property'] ?? '' );
			if ( $code === '' ) {
				continue;
			}
			// "or" starts a new "and" group.
			if ( $ruleCount === 0 || ( $rule['join'] ?? '' ) === self::JOIN_OR ) {
				$groups[] = [ 'results' => [], 'today' => [] ];
			}
			$ruleCount++;
			$last  = count( $groups ) - 1;
			$field = $group === null ? null : PageFieldResolver::getFieldByCode( $group, $code );
			if ( $field === null ) {
				$missing[]                    = $code;
				$groups[ $last ]['results'][] = false;
				$groups[ $last ]['decided'][] = false;
				continue;
			}
			$fallback = false;
			$operator = self::operatorFor( $rule, ConditionEvaluator::kindOf( $field['type'] ), $fallback );
			if ( $fallback ) {
				$changed[] = $index;
			}
			$value  = (string) ( $rule['value'] ?? '' );
			$result = ConditionEvaluator::test( $field['value'], $field['type'], $operator, $value, (string) ( $rule['value2'] ?? '' ), $today );
			$day    = ConditionEvaluator::isRelative( $operator ) ? ConditionEvaluator::dayOf( $field['value'], $field['type'] ) : null;
			$groups[ $last ]['results'][] = $result;
			if ( $day !== null ) {
				$groups[ $last ]['today'][] = [ 'd' => $day, 'o' => $operator, 'n' => max( 0, (int) $value ) ];
			} else {
				$groups[ $last ]['decided'][] = $result;
			}
		}

		$matches = false;
		$open    = [];
		$always  = false;
		foreach ( $groups as $andGroup ) {
			$matches = $matches || ConditionEvaluator::matches( $andGroup['results'], ConditionEvaluator::MATCH_ALL );
			if ( in_array( false, $andGroup['decided'] ?? [], true ) ) {
				// Never matches, whatever the date.
				continue;
			}
			if ( $andGroup['today'] === [] ) {
				$always = true;
				continue;
			}
			$open[] = $andGroup['today'];
		}

		return [
			'matches'   => $matches,
			// Only when today can still change the outcome.
			'client'    => ! $always && $open !== [] ? [ 'offset' => (int) round( wp_timezone()->getOffset( new \DateTimeImmutable( 'now' ) ) / 60 ), 'groups' => $open ] : null,
			'missing'   => array_values( array_unique( $missing ) ),
			'changed'   => $changed,
			'ruleCount' => $ruleCount,
		];
	}

	/**
	 * A template's property codes by the kind their type compares as (only
	 * the kinds the template has), for an editor's operator lists.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function codesByKind( int $templateId ): array {
		$byKind = [];
		foreach ( (array) ( TemplateMeta::get( $templateId )['field_types'] ?? [] ) as $definition ) {
			$code = is_array( $definition ) ? (string) ( $definition['code'] ?? '' ) : '';
			if ( $code !== '' ) {
				$byKind[ ConditionEvaluator::kindOf( (string) ( $definition['type'] ?? '' ) ) ][] = $code;
			}
		}

		return $byKind;
	}

	/**
	 * The operator a rule uses for its property's kind: the one chosen in that
	 * kind's list, else the kind's default ($fallback then says so: the list
	 * had something that does not fit, or nothing while other kinds' lists
	 * had a choice, e.g. after the property's type changed).
	 *
	 * @param array<string, mixed> $rule
	 */
	private static function operatorFor( array $rule, string $kind, bool &$fallback ): string {
		$chosen   = (string) ( $rule[ 'op_' . $kind ] ?? '' );
		$fallback = false;
		if ( isset( ConditionEvaluator::operatorsFor( $kind )[ $chosen ] ) ) {
			return $chosen;
		}
		if ( $chosen !== '' ) {
			$fallback = true;
		} else {
			foreach ( ConditionEvaluator::kinds() as $other ) {
				if ( ! empty( $rule[ 'op_' . $other ] ) ) {
					$fallback = true;
					break;
				}
			}
		}

		return ConditionEvaluator::defaultOperator( $kind );
	}
}
