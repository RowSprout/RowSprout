<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Admin\Metaboxes\ColumnSchema;
use RowSprout\Admin\Metaboxes\FieldUiResolver;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RowRenderer {

	/**
	 * @param int   $colIdx
	 * @param array $col
	 * @param int   $postId
	 */
	public static function renderColumnHeader( int $colIdx, array $col, int $postId ): void {
		$key        = (string) ( $col['key'] ?? '' );
		$type       = (string) ( $col['type'] ?? '' );
		$label      = (string) ( $col['label'] ?? $type );
		$code       = sanitize_key( (string) ( $col['code'] ?? '' ) );
		if ( $code === '' ) {
			$code = ColumnSchema::codeForLabel( $label, $type );
		}
		$fieldType  = sanitize_key( (string) ( $col['field_type'] ?? 'text' ) );
		$options    = ColumnSchema::normalizeOptions( $col['options'] ?? [] );
		$locked     = (bool) ( $col['locked'] ?? false );
		$overruled  = $locked ? false : (bool) ( $col['can_be_overruled'] ?? true );
		$required   = (bool) ( $col['required'] ?? false );
		$tokenCode  = $code !== '' ? $code : $key;

		echo "<th class='dp-col-header' data-col-key='" . esc_attr( $key ) . "' data-col-type='" . esc_attr( $type ) . "' data-col-field-type='" . esc_attr( $fieldType ) . "' data-col-options='" . esc_attr( wp_json_encode( $options ) ?: '[]' ) . "' data-col-code='" . esc_attr( $tokenCode ) . "' style='padding:4px; border:1px solid #dcdcde; min-width:120px; vertical-align:top;'>";

		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][key]' value='" . esc_attr( $key ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][type]' value='" . esc_attr( $type ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][label]' value='" . esc_attr( $label ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][code]' value='" . esc_attr( $tokenCode ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][field_type]' value='" . esc_attr( $fieldType ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][options]' value='" . esc_attr( wp_json_encode( $options ) ?: '[]' ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][can_be_overruled]' value='" . ( $overruled ? '1' : '0' ) . "' />";
		echo "<input type='hidden' name='dp_columns[" . (int) $colIdx . "][required]' value='" . ( $required ? '1' : '0' ) . "' />";
		echo '<div><strong>' . esc_html( $label ) . '</strong></div>';
		echo "<div style='font-size:11px; opacity:.7;'>" . esc_html( $type ) . '</div>';
		echo '<div style="font-size:11px;">' . esc_html__( 'Code:', 'rowsprout' ) . ' <code>@code_' . esc_html( $tokenCode ) . '_' . (int) $postId . '@</code></div>';
		echo "<div style='font-size:11px;'><label><input type='checkbox' " . ( $overruled ? 'checked' : '' ) . " style='pointer-events:none;' disabled /> " . esc_html__( 'Can be overridden', 'rowsprout' ) . '</label></div>';
		echo "<div style='font-size:11px;'><label><input type='checkbox' " . ( $required ? 'checked' : '' ) . " style='pointer-events:none;' disabled /> " . esc_html__( 'Required', 'rowsprout' ) . '</label></div>';
		if ( ! $locked ) {
			echo "<div style='margin-top:4px;'><button type='button' class='button-link dp-col-delete' data-col-key='" . esc_attr( $key ) . "' style='color:#a00;'>&times; " . esc_html__( 'Remove property', 'rowsprout' ) . '</button></div>';
		}
		echo '</th>';
	}

	/**
	 * @param int   $rowIdx
	 * @param array $group
	 * @param array $columns
	 */
	public static function renderGroupRow( int $rowIdx, array $group, array $columns ): void {
		$id       = absint( $group['id'] ?? 0 );
		$parentId = absint( $group['parent_id'] ?? 0 );
		$fields   = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];

		echo "<tr class='dp-group-row' data-row-idx='" . (int) $rowIdx . "'>";
		echo "<td class='dp-drag-handle' style='cursor:move; padding:4px; border:1px solid #dcdcde; text-align:center; vertical-align:top;'>☰";
		echo "<input type='hidden' name='dp_groups[" . (int) $rowIdx . "][id]' value='" . (int) $id . "' />";
		echo "<input type='hidden' name='dp_groups[" . (int) $rowIdx . "][parent_id]' value='" . (int) $parentId . "' />";
		echo '</td>';
		echo "<td class='dp-row-num' style='padding:4px; border:1px solid #dcdcde; text-align:center; vertical-align:top;'>" . esc_html__( 'Group', 'rowsprout' ) . ' ' . (int) ( $rowIdx + 1 ) . '</td>';

		foreach ( $columns as $col ) {
			$colKey    = (string) ( $col['key'] ?? '' );
			$colType   = (string) ( $col['type'] ?? 'textfield' );
			$fieldType = (string) ( $col['field_type'] ?? 'text' );
			$options   = ColumnSchema::normalizeOptions( $col['options'] ?? [] );
			$value     = '';
			if ( isset( $fields[ $colKey ] ) ) {
				$raw   = $fields[ $colKey ];
				$value = is_array( $raw ) ? (string) ( $raw['value'] ?? '' ) : (string) $raw;
			}

			echo "<td class='dp-cell' data-col-key='" . esc_attr( $colKey ) . "' style='padding:4px; border:1px solid #dcdcde; vertical-align:top;'>";
			self::renderCellInput( $rowIdx, $colKey, $colType, $value, $fieldType, $options );
			echo '</td>';
		}

		echo "<td style='padding:4px; border:1px solid #dcdcde;'></td>";
		echo "<td style='padding:4px; border:1px solid #dcdcde; vertical-align:top;'><button type='button' class='button-link dp-row-delete' style='color:#a00;' title='" . esc_attr__( 'Delete group', 'rowsprout' ) . "'>&times;</button></td>";
		echo '</tr>';
	}

	/**
	 * @param int    $rowIdx
	 * @param string $colKey
	 * @param string $colType
	 * @param string $value
	 */
	private static function renderCellInput( int $rowIdx, string $colKey, string $colType, string $value, string $fieldType = 'text', array $options = [] ): void {
		$name = 'dp_groups[' . $rowIdx . '][fields][' . $colKey . ']';
		$kind = sanitize_key( $fieldType !== '' ? $fieldType : ( TemplateMeta::getFieldTypeDefinition( $colType )['type'] ?? 'text' ) );
		$kind = FieldUiResolver::resolveKind( $colType, $kind );

		if ( $kind === 'textarea' || $kind === 'richtext' || $colType === 'textarea' || $colType === 'richtext' || $colType === 'item-list' ) {
			echo "<textarea name='" . esc_attr( $name ) . "' rows='2' style='width:100%;'>" . esc_textarea( $value ) . '</textarea>';
		} elseif ( $kind === 'thumbnail' ) {
			$targetId = 'dp_thumb_' . $rowIdx . '_' . $colKey;
			$thumbUrl = '';
			if ( $value !== '' && is_numeric( $value ) ) {
				$thumbUrl = wp_get_attachment_image_url( (int) $value, 'thumbnail' ) ?: '';
			}
			echo "<input type='hidden' class='dp-thumb-field' id='" . esc_attr( $targetId ) . "' name='" . esc_attr( $name ) . "' value='" . esc_attr( $value ) . "' />";
			echo "<div class='dp-thumb-preview' id='" . esc_attr( $targetId ) . "_preview' style='margin-bottom:4px;'>";
			if ( $thumbUrl ) {
				echo "<img src='" . esc_url( $thumbUrl ) . "' style='max-width:50px;max-height:50px;display:block;' />";
			}
			echo '</div>';
			echo "<button type='button' class='button button-small dp-thumb-upload' data-target='" . esc_attr( $targetId ) . "'>" . esc_html__( 'Select', 'rowsprout' ) . '</button> ';
			echo "<button type='button' class='button button-small dp-thumb-remove' data-target='" . esc_attr( $targetId ) . "' style='" . ( $thumbUrl ? '' : 'display:none;' ) . "'>" . esc_html__( 'Remove', 'rowsprout' ) . '</button>';
		} elseif ( $kind === 'checkbox' ) {
			$checked = $value === '1' || strtolower( $value ) === 'on' || $value === 'true';
			echo "<input type='hidden' name='" . esc_attr( $name ) . "' value='0' />";
			echo "<label style='display:inline-flex; align-items:center; gap:6px;'><input type='checkbox' name='" . esc_attr( $name ) . "' value='1'" . ( $checked ? ' checked' : '' ) . ' /> ' . esc_html__( 'Yes', 'rowsprout' ) . '</label>';
		} elseif ( $kind === 'select' ) {
			if ( empty( $options ) ) {
				$options = [ '' ];
			}
			if ( $value !== '' && ! in_array( $value, $options, true ) ) {
				$options[] = $value;
			}
			echo "<select name='" . esc_attr( $name ) . "' style='width:100%;'>";
			foreach ( $options as $option ) {
				$optionValue = (string) $option;
				echo "<option value='" . esc_attr( $optionValue ) . "'" . selected( $value, $optionValue, false ) . '>' . esc_html( $optionValue ) . '</option>';
			}
			echo '</select>';
		} elseif ( in_array( $kind, [ 'number', 'color', 'date', 'datetime-local', 'email', 'tel', 'url' ], true ) ) {
			echo "<input type='" . esc_attr( $kind ) . "' name='" . esc_attr( $name ) . "' value='" . esc_attr( $value ) . "' style='width:100%;' data-col-type='" . esc_attr( $colType ) . "' />";
		} else {
			echo "<input type='text' name='" . esc_attr( $name ) . "' value='" . esc_attr( $value ) . "' style='width:100%;' data-col-type='" . esc_attr( $colType ) . "' />";
		}
	}
}
