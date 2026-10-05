<?php

namespace RowSprout\Admin\Metaboxes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PropertyTableRenderer {

	/**
	 * @param array<string, mixed> $col
	 * @param array<string, array<string, mixed>> $fieldTypeDefinitions
	 */
	public static function renderPropertyRow( array $col, int $postId, array $fieldTypeDefinitions ): void {
		$type           = (string) ( $col['type'] ?? '' );
		$label          = (string) ( $col['label'] ?? $type );
		$code           = sanitize_key( (string) ( $col['code'] ?? '' ) );
		$key            = (string) ( $col['key'] ?? '' );
		$locked         = (bool) ( $col['locked'] ?? false );
		$isInherited    = ! empty( $col['inherited'] );
		$canBeOverruled = ! $locked && ! empty( $col['can_be_overruled'] );

		if ( $code === '' ) {
			$code = ColumnSchema::generateCodeFromTitle( $label, $type );
		}

		$typeLabel   = (string) ( $fieldTypeDefinitions[ $type ]['label'] ?? $type );
		$tokenPostId = absint( $col['source_post_id'] ?? $postId );
		if ( $tokenPostId <= 0 ) {
			$tokenPostId = $postId;
		}
		$token            = '@code_' . $code . '_' . $tokenPostId . '@';
		$stateClass       = $canBeOverruled ? 'is-overruled' : 'is-fixed';
		$stateLabel       = $canBeOverruled ? __( 'Overridable', 'rowsprout' ) : __( 'Fixed', 'rowsprout' );
		$isFixedOrderType = in_array( $type, [ 'title', 'href', 'slug' ], true );
		$canReorder       = ! $isInherited && ! $isFixedOrderType;
		$rowClass         = $canReorder ? 'dp-prop-row is-reorderable' : 'dp-prop-row';
		$dragClass        = $canReorder ? 'dp-prop-drag' : 'dp-prop-drag is-disabled';
		$dragTitle        = $canReorder ? __( 'Drag to reorder', 'rowsprout' ) : '';
		// draggable="true" lives on the handle icon itself, not the <tr> —
		// a draggable ancestor intercepts mousedown-drag anywhere inside it
		// (blocking normal text selection in every other cell), so only the
		// handle initiates the drag; groups-metabox.js's dragstart handler
		// walks up to the row via closest('tr') to actually reorder it.
		echo '<tr class="' . esc_attr( $rowClass ) . '" data-col-key="' . esc_attr( $key ) . '" data-col-type="' . esc_attr( $type ) . '">';
		echo '<td><span class="dp-prop-label-wrap">';
		echo '<span class="' . esc_attr( $dragClass ) . '"' . ( $canReorder ? ' draggable="true"' : '' ) . ( $dragTitle !== '' ? ' title="' . esc_attr( $dragTitle ) . '"' : '' ) . '>☰</span>';
		echo '<strong>' . esc_html( $label ) . '</strong></span></td>';
		echo '<td>' . esc_html( $typeLabel ) . '</td>';
		echo '<td><code>' . esc_html( $token ) . '</code></td>';
		echo '<td><span class="dp-state-badge ' . esc_attr( $stateClass ) . '">' . esc_html( $stateLabel ) . '</span></td>';
		if ( $locked || $isInherited ) {
			echo '<td></td>';
		} else {
			echo '<td><div class="dp-prop-actions"><button type="button" class="button-link dp-col-delete" data-col-key="' . esc_attr( $key ) . '">' . esc_html__( 'Delete', 'rowsprout' ) . '</button></div></td>';
		}
		echo '</tr>';
	}

	/**
	 * @param array<string, mixed> $col
	 */
	public static function renderColumnMeta( int $colIdx, array $col ): void {
		$key          = (string) ( $col['key'] ?? '' );
		$type         = (string) ( $col['type'] ?? '' );
		$label        = (string) ( $col['label'] ?? $type );
		$code         = sanitize_key( (string) ( $col['code'] ?? '' ) );
		$fieldType    = sanitize_key( (string) ( $col['field_type'] ?? 'text' ) );
		$options      = ColumnSchema::normalizeOptions( $col['options'] ?? [] );
		$locked       = (bool) ( $col['locked'] ?? false );
		$inherited    = ! empty( $col['inherited'] );
		$sourcePostId = absint( $col['source_post_id'] ?? 0 );

		if ( $code === '' ) {
			$code = ColumnSchema::generateCodeFromTitle( $label, $type );
		}

		echo '<div class="dp-prop-meta"'
			. ' data-col-key="' . esc_attr( $key ) . '"'
			. ' data-col-type="' . esc_attr( $type ) . '"'
			. ' data-col-label="' . esc_attr( $label ) . '"'
			. ' data-col-code="' . esc_attr( $code ) . '"'
			. ' data-col-field-type="' . esc_attr( $fieldType ) . '"'
			. ' data-col-options="' . esc_attr( wp_json_encode( $options ) ?: '[]' ) . '"'
			. ' data-col-overruled="' . ( ( ! $locked && ! empty( $col['can_be_overruled'] ) ) ? '1' : '0' ) . '"'
			. ' data-col-locked="' . ( $locked ? '1' : '0' ) . '"'
			. ' data-col-source-post-id="' . (int) $sourcePostId . '"'
			. ' data-col-inherited="' . ( $inherited ? '1' : '0' ) . '">';

		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][key]" value="' . esc_attr( $key ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][type]" value="' . esc_attr( $type ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][label]" value="' . esc_attr( $label ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][code]" value="' . esc_attr( $code ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][field_type]" value="' . esc_attr( $fieldType ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][options]" value="' . esc_attr( wp_json_encode( $options ) ?: '[]' ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][can_be_overruled]" value="' . ( ( ! $locked && ! empty( $col['can_be_overruled'] ) ) ? '1' : '0' ) . '" />';
		echo '<input type="hidden" name="dp_all_columns[' . (int) $colIdx . '][required]" value="' . ( ! empty( $col['required'] ) ? '1' : '0' ) . '" />';

		if ( ! $inherited ) {
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][key]" value="' . esc_attr( $key ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][type]" value="' . esc_attr( $type ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][label]" value="' . esc_attr( $label ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][code]" value="' . esc_attr( $code ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][field_type]" value="' . esc_attr( $fieldType ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][options]" value="' . esc_attr( wp_json_encode( $options ) ?: '[]' ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][can_be_overruled]" value="' . ( ( ! $locked && ! empty( $col['can_be_overruled'] ) ) ? '1' : '0' ) . '" />';
			echo '<input type="hidden" name="dp_columns[' . (int) $colIdx . '][required]" value="' . ( ! empty( $col['required'] ) ? '1' : '0' ) . '" />';
		}
		echo '</div>';
	}
}
