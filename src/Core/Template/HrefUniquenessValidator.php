<?php

namespace RowSprout\Core\Template;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side backstop for the live JS check in groups-metabox.js: two
 * groups sharing the same href/slug value would otherwise resolve to the
 * same generated page post_name with zero warning (WordPress's own
 * wp_unique_post_slug dedup is explicitly removed around generated-page
 * inserts/updates, see PageUpserter.php).
 */
final class HrefUniquenessValidator {

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, array<int, string>> Each inner array is 2+ guids
	 *         sharing the same literal href/slug value.
	 */
	public static function findDuplicateGroups( array $config ): array {
		$byValue = [];

		foreach ( (array) ( $config['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['id'] ) || ! is_scalar( $group['id'] ) ) {
				continue;
			}

			$rawHrefValue = self::extractRawHrefValue( $group );

			// A value containing '@code_' is a per-group-resolving
			// placeholder token (e.g. @code_id@), not literal text — it's
			// expected to look identical across groups since it resolves
			// differently per group later. Same exemption as
			// HrefFieldType::sanitize(), and checked BEFORE sanitizing since
			// sanitize_title() would otherwise strip the token beyond
			// recognition.
			if ( $rawHrefValue === '' || strpos( $rawHrefValue, '@code_' ) !== false ) {
				continue;
			}

			$hrefValue = sanitize_title( $rawHrefValue );
			if ( $hrefValue === '' ) {
				continue;
			}

			$byValue[ $hrefValue ][] = (string) $group['id'];
		}

		return array_values( array_filter( $byValue, static function ( $guids ) {
			return count( $guids ) > 1;
		} ) );
	}

	/**
	 * @param array<string, mixed> $group
	 */
	private static function extractRawHrefValue( array $group ): string {
		foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && in_array( $field['type'] ?? '', [ 'href', 'slug' ], true ) ) {
				$value = $field['value'] ?? '';

				return is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}

		return '';
	}
}
