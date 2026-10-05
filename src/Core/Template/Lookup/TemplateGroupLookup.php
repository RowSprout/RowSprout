<?php

namespace RowSprout\Core\Template\Lookup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateGroupLookup {

	/**
	 * @param array<int, mixed> $groups
	 * @return array<string, mixed>|null
	 */
	public static function findById( array $groups, string $id ): ?array {
		if ( $id === '' ) {
			return null;
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			if ( (string) ( $group['id'] ?? '' ) === $id ) {
				return $group;
			}
		}

		return null;
	}
}
