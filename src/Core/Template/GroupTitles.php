<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\Template\FieldTypes\HrefFieldType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every generated page needs a title, and its URL comes from the group's
 * href/slug. A group without a slug gets one from its title (the editor does
 * the same when the title loses focus); a group without a title is not
 * generated (UrlConflicts::blocking(), and PageBuilder::createById() as the
 * last check), since its page would get the template's title without the
 * group's part and a URL nobody chose.
 */
final class GroupTitles {

	/**
	 * The group's title: a child template's group carries the one it inherits
	 * from the parent group (ChildTemplateInheritance::resolveContext()), any
	 * other group its title field. Null when the template has no title
	 * property at all.
	 *
	 * @param array<string, mixed> $group
	 */
	public static function of( array $group ): ?string {
		if ( array_key_exists( 'title', $group ) ) {
			return is_scalar( $group['title'] ) ? trim( (string) $group['title'] ) : '';
		}
		foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'title' ) {
				$value = $field['value'] ?? '';

				return is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}

		return null;
	}

	/**
	 * Whether the group's page cannot be generated for lack of a title.
	 *
	 * @param array<string, mixed> $group Resolved as for building its page.
	 */
	public static function isMissing( array $group ): bool {
		return self::of( $group ) === '';
	}

	/**
	 * $groups with every empty href/slug filled from the group's own title,
	 * the way HrefFieldType stores a slug. A child template's groups store
	 * neither (they inherit both), so they are left alone.
	 *
	 * @param array<int|string, mixed> $groups
	 * @return array<int|string, mixed>
	 */
	public static function withSlugsFromTitles( array $groups ): array {
		$href = null;
		foreach ( $groups as $index => $group ) {
			if ( ! is_array( $group ) || ! is_array( $group['fields'] ?? null ) ) {
				continue;
			}
			$title = self::of( [ 'fields' => $group['fields'] ] );
			if ( $title === null || $title === '' ) {
				continue;
			}
			foreach ( $group['fields'] as $key => $field ) {
				if ( ! is_array( $field ) || ! in_array( $field['type'] ?? '', [ 'href', 'slug' ], true ) ) {
					continue;
				}
				$value = $field['value'] ?? '';
				if ( is_scalar( $value ) && trim( (string) $value ) !== '' ) {
					break;
				}
				$href = $href ?? new HrefFieldType();
				$groups[ $index ]['fields'][ $key ]['value'] = $href->sanitize( $title, [] );
				break;
			}
		}

		return $groups;
	}
}
