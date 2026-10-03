<?php

namespace RowSprout\Admin\Metaboxes;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Merges a parent template's groups with a child template's already-saved
 * group overrides — the "inherit groups from parent, keep my own overridden
 * field values" mechanism behind the Parent dropdown's live-sync AJAX call.
 */
final class InheritedGroupOverlay {

	/**
	 * @param array<int, array<string, mixed>> $groups
	 * @param array<int, array<string, mixed>> $childGroups
	 * @param array<int, array<string, mixed>> $columns
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply( array $groups, array $childGroups, array $columns ): array {
		if ( empty( $groups ) ) {
			return $groups;
		}

		$childById       = [];
		$childByParentId = [];

		foreach ( $childGroups as $childGroup ) {
			if ( ! is_array( $childGroup ) ) {
				continue;
			}
			$childId = absint( $childGroup['id'] ?? 0 );
			if ( $childId > 0 ) {
				$childById[ $childId ] = $childGroup;
			}
			$childParentId = absint( $childGroup['parent_id'] ?? 0 );
			if ( $childParentId > 0 && ! isset( $childByParentId[ $childParentId ] ) ) {
				$childByParentId[ $childParentId ] = $childGroup;
			}
		}

		foreach ( $groups as &$group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$groupId    = absint( $group['id'] ?? 0 );
			$childMatch = $childById[ $groupId ] ?? null;
			if ( ! is_array( $childMatch ) && $groupId > 0 ) {
				$childMatch = $childByParentId[ $groupId ] ?? null;
			}

			if ( is_array( $childMatch ) ) {
				$childId = absint( $childMatch['id'] ?? 0 );
				if ( $childId > 0 ) {
					$group['id'] = $childId;
				}

				$childParentId = absint( $childMatch['parent_id'] ?? 0 );
				if ( $childParentId > 0 ) {
					$group['parent_id'] = $childParentId;
				} elseif ( $groupId > 0 ) {
					$group['parent_id'] = $groupId;
				}
			} else {
				if ( $groupId > 0 ) {
					$group['parent_id'] = $groupId;
				}
				$group['id'] = ColumnSchema::generateNumericId();
				continue;
			}

			$childFields = isset( $childMatch['fields'] ) && is_array( $childMatch['fields'] ) ? $childMatch['fields'] : [];
			if ( empty( $childFields ) ) {
				continue;
			}

			if ( ! isset( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
				$group['fields'] = [];
			}

			foreach ( $columns as $column ) {
				if ( ! is_array( $column ) ) {
					continue;
				}
				$colKey  = (string) ( $column['key'] ?? '' );
				$colType = (string) ( $column['type'] ?? '' );
				if ( $colKey === '' ) {
					continue;
				}
				if ( in_array( $colType, [ 'title', 'href', 'slug' ], true ) ) {
					continue;
				}
				if ( empty( $column['can_be_overruled'] ) ) {
					continue;
				}
				if ( ! isset( $childFields[ $colKey ] ) || ! is_array( $childFields[ $colKey ] ) || ! array_key_exists( 'value', $childFields[ $colKey ] ) ) {
					continue;
				}

				$childValue = (string) $childFields[ $colKey ]['value'];

				// An empty child value is NOT an override — the migration
				// (and the native "add group" flow) always stores a
				// fields[key]['value'] entry even when the admin never typed
				// anything into it, so "the key exists" alone can't mean
				// "this field is overridden." Confirmed live: without this
				// check, every blank child field (the common case — e.g. a
				// converted child_extra row, which only ever had an
				// text/thumbnail override slot) was silently
				// stomping the parent's real value with an empty string here,
				// exactly the "the child left it empty" case
				// ChildTemplateInheritance::inheritEmptyFields() correctly
				// treats as "inherit" at generation time — this overlay
				// (the admin-editor equivalent, used by the Parent dropdown's
				// live-sync) needs the same rule.
				if ( $childValue === '' ) {
					// Preserve the parent's own (real) value as a
					// placeholder instead of leaving it as the visible,
					// editable value — GroupCardRenderer reads
					// fields[key]['placeholder'] for exactly this, so the
					// admin sees "this is inherited, type here to override"
					// rather than a value that would silently get saved as
					// an explicit override the next time this form submits.
					if ( isset( $group['fields'][ $colKey ] ) && is_array( $group['fields'][ $colKey ] ) ) {
						$parentValue = isset( $group['fields'][ $colKey ]['value'] ) ? (string) $group['fields'][ $colKey ]['value'] : '';
						$group['fields'][ $colKey ]['placeholder'] = $parentValue;
						$group['fields'][ $colKey ]['value']       = '';
					}
					continue;
				}

				if ( ! isset( $group['fields'][ $colKey ] ) || ! is_array( $group['fields'][ $colKey ] ) ) {
					$group['fields'][ $colKey ] = [
						'type'  => $colType,
						'value' => $childValue,
					];
				} else {
					$group['fields'][ $colKey ]['value'] = $childValue;
				}
			}
		}
		unset( $group );

		return $groups;
	}
}
