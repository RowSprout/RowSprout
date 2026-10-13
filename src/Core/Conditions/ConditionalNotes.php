<?php

namespace RowSprout\Core\Conditions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an editor tells about conditional content for its preview group:
 * whether the rules match, a rule on today, rules on properties the
 * template lacks, operators that no longer fit. Each editor shows the list
 * its own way (Elementor: at the top of the widget's Content tab).
 */
final class ConditionalNotes {

	/**
	 * @param array<string, mixed>      $decision RuleSet::decide()
	 * @param array<string, mixed>|null $context  null: no group to check
	 * @param array<string, mixed>      $settings
	 * @return array<int, string>
	 */
	public static function messages( array $decision, ?array $context, array $settings ): array {
		if ( $context === null ) {
			return [ __( 'This template has no groups yet, so the rules cannot be checked.', 'rowsprout' ) ];
		}
		if ( $decision['ruleCount'] === 0 ) {
			$notes = [ __( 'No rule has a property yet: "Else" is shown.', 'rowsprout' ) ];
		} elseif ( $decision['matches'] ) {
			$notes = [ __( 'Preview group: the rules match, so "Then" is shown.', 'rowsprout' ) ];
		} else {
			$notes = [ ( $settings['else_source'] ?? 'none' ) === 'none' ? __( 'Preview group: the rules do not match, and "Else" shows nothing.', 'rowsprout' ) : __( 'Preview group: the rules do not match, so "Else" is shown.', 'rowsprout' ) ];
		}
		if ( $decision['client'] !== null ) {
			$notes[] = __( 'A rule depends on today: the page checks it again when it is shown.', 'rowsprout' );
		}
		if ( $decision['missing'] !== [] ) {
			/* translators: %s: comma-separated property codes. */
			$notes[] = sprintf( __( 'Not in this template (these rules never match): %s', 'rowsprout' ), implode( ', ', $decision['missing'] ) );
		}
		foreach ( $decision['changed'] as $index ) {
			/* translators: %d: rule number. */
			$notes[] = sprintf( __( 'Rule %d: the operator does not fit the property\'s type (changed?), so "is not empty" / "is checked" is used. Choose the operator again.', 'rowsprout' ), (int) $index + 1 );
		}

		return $notes;
	}
}
