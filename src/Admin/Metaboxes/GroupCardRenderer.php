<?php

namespace RowSprout\Admin\Metaboxes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupCardRenderer {

	/**
	 * @param array<string, mixed> $group
	 * @param array<int, array<string, mixed>> $columns
	 * @param array<string, mixed>|null $parentGroup The matching group on the
	 *        parent template (looked up by $group['parent_id'] against the
	 *        parent's own group ids — see
	 *        GroupsMetaBoxRenderer::loadParentGroupsById()), or null when this
	 *        group has no parent (a top-level group on a template with no
	 *        parent template, or an orphaned parent_id).
	 */
	public static function render( int $rowIdx, array $group, array $columns, int $postId, ?array $parentGroup = null ): void {
		$id        = absint( $group['id'] ?? 0 );
		$parentId  = absint( $group['parent_id'] ?? 0 );
		$fields    = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];
		// A group with a parent_id always inherits from that parent group —
		// title/href can never be overridden per child (see
		// ColumnSchema::buildColumns()'s locked title/href columns), and any
		// other field the child leaves empty falls back to the parent's value
		// at generation time (ChildTemplateInheritance::inheritEmptyFields()).
		// This flag mirrors that here so the editor can show the same thing.
		$inherited = $parentId > 0;

		$titleCol = self::findColumnByType( $columns, 'title' );
		$hrefCol  = self::findColumnByType( $columns, 'href' );

		if ( $inherited && $parentGroup !== null ) {
			$titleValue = self::extractParentValue( $parentGroup, (string) ( $titleCol['key'] ?? 'title' ), 'title' );
			$hrefValue  = self::extractParentValue( $parentGroup, (string) ( $hrefCol['key'] ?? 'href' ), 'href' );
		} else {
			$titleValue = self::extractFieldValue( $fields, (string) ( $titleCol['key'] ?? 'title' ) );
			$hrefValue  = self::extractFieldValue( $fields, (string) ( $hrefCol['key'] ?? 'href' ) );
		}

		echo '<div class="dp-group-card" data-row-idx="' . (int) $rowIdx . '" data-group-id="' . (int) $id . '" data-parent-id="' . (int) $parentId . '" data-inherited="' . ( $inherited ? '1' : '0' ) . '">';
		echo '<div class="dp-group-head">';
		echo '<div class="dp-group-handle">&#9776;</div>';
		echo '<div class="dp-group-index">' . esc_html__( 'Group', 'rowsprout' ) . ' ' . (int) ( $rowIdx + 1 ) . '</div>';
		echo '<div><span class="label">' . esc_html__( 'Title', 'rowsprout' ) . '</span>';
		GroupFieldInputRenderer::render( $rowIdx, $titleCol ?: [ 'key' => 'title', 'type' => 'title', 'field_type' => 'text' ], $titleValue, $inherited );
		echo '</div>';
		echo '<div><span class="label">' . esc_html__( 'Href', 'rowsprout' ) . '</span>';
		GroupFieldInputRenderer::render( $rowIdx, $hrefCol ?: [ 'key' => 'href', 'type' => 'href', 'field_type' => 'text' ], $hrefValue, $inherited );
		echo '<small class="dp-href-error-msg"></small>';
		echo '</div>';
		echo '<button type="button" class="button button-small dp-group-toggle">' . esc_html__( 'Expand', 'rowsprout' ) . '</button>';
		if ( ! $inherited ) {
			echo '<button type="button" class="button-link dp-group-delete" title="' . esc_attr__( 'Delete group', 'rowsprout' ) . '">&times;</button>';
		}
		echo '</div>';

		echo '<div class="dp-group-body">';
		echo '<input type="hidden" name="dp_groups[' . (int) $rowIdx . '][id]" value="' . (int) ( $id > 0 ? $id : ColumnSchema::generateNumericId() ) . '" />';
		echo '<input type="hidden" name="dp_groups[' . (int) $rowIdx . '][parent_id]" value="' . (int) $parentId . '" />';
		echo '<div class="dp-fields-grid">';
		foreach ( $columns as $col ) {
			$type = (string) ( $col['type'] ?? '' );
			if ( in_array( $type, [ 'title', 'href', 'slug' ], true ) ) {
				continue;
			}
			$colKey = (string) ( $col['key'] ?? '' );
			$value  = self::extractFieldValue( $fields, $colKey );

			// The child left this field empty: show the parent's value as a
			// placeholder (not a stored value) so the admin can see what will
			// actually render for this page — typing something here still
			// overrides it, for this child only, exactly as before.
			//
			// Two sources, depending on which code path built $group:
			// - GroupsMetaBoxRenderer's own direct render passes $parentGroup
			//   (a separate, un-merged parent group) — derive it from there.
			// - The Parent-dropdown live-sync AJAX path (Pro:
			//   ParentSyncPayloadRenderer/InheritedGroupOverlay) already
			//   merges parent+child into a single $group and stashes the
			//   parent's original value under fields[key]['placeholder'] for
			//   exactly this — read it directly when present.
			$placeholder = '';
			if ( $inherited && $value === '' ) {
				$placeholder = self::extractFieldPlaceholder( $fields, $colKey );
				if ( $placeholder === '' && $parentGroup !== null ) {
					$parentFields = isset( $parentGroup['fields'] ) && is_array( $parentGroup['fields'] ) ? $parentGroup['fields'] : [];
					$placeholder  = self::extractFieldValue( $parentFields, $colKey );
				}
			}

			echo '<div class="dp-field dp-prop-field" data-col-key="' . esc_attr( $colKey ) . '">';
			echo '<div class="dp-field-label">' . esc_html( (string) ( $col['label'] ?? $type ) ) . '</div>';
			GroupFieldInputRenderer::render( $rowIdx, $col, $value, $inherited, $placeholder );
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 * @return array<string, mixed>|null
	 */
	private static function findColumnByType( array $columns, string $type ): ?array {
		foreach ( $columns as $col ) {
			if ( (string) ( $col['type'] ?? '' ) === $type ) {
				return is_array( $col ) ? $col : null;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private static function extractFieldValue( array $fields, string $key ): string {
		if ( $key === '' || ! isset( $fields[ $key ] ) ) {
			return '';
		}

		$raw = $fields[ $key ];
		if ( is_array( $raw ) ) {
			$value = $raw['value'] ?? '';
			return is_scalar( $value ) ? (string) $value : '';
		}

		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * @param array<string, mixed> $fields
	 */
	private static function extractFieldPlaceholder( array $fields, string $key ): string {
		if ( $key === '' || ! isset( $fields[ $key ] ) || ! is_array( $fields[ $key ] ) ) {
			return '';
		}

		$placeholder = $fields[ $key ]['placeholder'] ?? '';
		return is_scalar( $placeholder ) ? (string) $placeholder : '';
	}

	/**
	 * Title/href on a parent group are commonly stored as the group's own
	 * top-level "naam"/"href" keys rather than inside fields[] — check that
	 * first, matching
	 * ChildTemplateInheritance::extractParentTitle()/extractParentHref()'s own
	 * fallback order for the same data at generation time.
	 *
	 * @param array<string, mixed> $parentGroup
	 */
	private static function extractParentValue( array $parentGroup, string $fieldKey, string $topLevelKey ): string {
		if ( isset( $parentGroup[ $topLevelKey ] ) && is_scalar( $parentGroup[ $topLevelKey ] ) ) {
			$topLevelValue = (string) $parentGroup[ $topLevelKey ];
			if ( $topLevelValue !== '' ) {
				return $topLevelValue;
			}
		}

		$parentFields = isset( $parentGroup['fields'] ) && is_array( $parentGroup['fields'] ) ? $parentGroup['fields'] : [];
		return self::extractFieldValue( $parentFields, $fieldKey );
	}
}
