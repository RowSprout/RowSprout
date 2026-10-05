<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupFieldInputRenderer {

	public static function render( int $rowIdx, array $col, string $value, bool $inherited = false, string $placeholder = '' ): void {
		$colKey    = (string) ( $col['key'] ?? '' );
		$colType   = (string) ( $col['type'] ?? 'textfield' );
		$fieldType = (string) ( $col['field_type'] ?? 'text' );
		$options   = ColumnSchema::normalizeOptions( $col['options'] ?? [] );
		$name      = 'dp_groups[' . $rowIdx . '][fields][' . $colKey . ']';

		$isTitleOrHref  = in_array( $colType, [ 'title', 'href', 'slug' ], true );
		$lockTitleHref  = $inherited && $isTitleOrHref;
		// Attribute name => raw value, escaped only when printed (printAttributes()).
		$nameAttr     = ( $name !== '' && ! $lockTitleHref ) ? [ 'name' => $name ] : [];
		$readonlyAttr = $lockTitleHref ? [ 'readonly' => 'readonly' ] : [];
		$disabledAttr = $lockTitleHref ? [ 'disabled' => 'disabled' ] : [];
		// Only meaningful once the child's own value is empty (see
		// GroupCardRenderer, which never passes one otherwise) — never shown
		// for title/href, those display the parent's actual value directly
		// instead of a placeholder, since they can never be typed into.
		$placeholderAttr = ( $placeholder !== '' && ! $lockTitleHref ) ? [ 'placeholder' => $placeholder ] : [];
		$kind             = sanitize_key( $fieldType !== '' ? $fieldType : ( TemplateMeta::getFieldTypeDefinition( $colType )['type'] ?? 'text' ) );
		$kind             = FieldUiResolver::resolveKind( $colType, $kind );

		/**
		 * Lets a property type an add-on registers draw its own input in the
		 * groups table. Return the input's HTML (escaped by you) to replace the
		 * built-in input, or null to keep it. The posted value must use
		 * $field['name'], so saving, reordering and sanitize() keep working.
		 * New groups and properties are drawn in the browser instead: also
		 * hook the JS filter `rowsprout.groupFieldInputHtml` (wp.hooks).
		 *
		 * @param string|null $html
		 * @param array{name:string, value:string, type:string, kind:string, key:string, row:int, options:array<int, string>, placeholder:string, locked:bool} $field
		 *        type = the property type (getType()), kind = its UI kind, locked = read-only (inherited title/URL)
		 */
		$customHtml = apply_filters( 'rowsprout_group_field_input_html', null, [
			'name'        => $lockTitleHref ? '' : $name,
			'value'       => $value,
			'type'        => $colType,
			'kind'        => $kind,
			'key'         => $colKey,
			'row'         => $rowIdx,
			'options'     => $options,
			'placeholder' => $lockTitleHref ? '' : $placeholder,
			'locked'      => $lockTitleHref,
		] );
		if ( is_string( $customHtml ) ) {
			echo $customHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the filter's provider, as documented above.
			return;
		}

		if ( $kind === 'textarea' || $kind === 'richtext' || $colType === 'textarea' || $colType === 'richtext' || $colType === 'item-list' ) {
			echo '<textarea';
			self::printAttributes( $nameAttr + $placeholderAttr + [ 'rows' => '2' ] );
			echo '>' . esc_textarea( $value ) . '</textarea>';
			return;
		}

		if ( $kind === 'thumbnail' ) {
			$targetId          = 'dp_thumb_' . $rowIdx . '_' . $colKey;
			$thumbUrl          = '';
			if ( $value !== '' && is_numeric( $value ) ) {
				$thumbUrl = wp_get_attachment_image_url( (int) $value, 'thumbnail' ) ?: '';
			}
			// The child has no thumbnail of its own but the parent does: show
			// the parent's image as a muted preview so the admin can see what
			// will actually render, without it counting as a stored override
			// (the hidden input's value stays "").
			$inheritedThumbUrl = '';
			if ( $value === '' && $placeholder !== '' && is_numeric( $placeholder ) ) {
				$inheritedThumbUrl = wp_get_attachment_image_url( (int) $placeholder, 'thumbnail' ) ?: '';
			}
			echo '<input';
			self::printAttributes( [ 'type' => 'hidden', 'class' => 'dp-thumb-field', 'id' => $targetId ] + $nameAttr + [ 'value' => $value ] );
			echo ' />';
			echo '<div class="dp-thumb-preview' . ( $inheritedThumbUrl ? ' dp-thumb-preview--inherited' : '' ) . '" id="' . esc_attr( $targetId ) . '_preview">';
			if ( $thumbUrl ) {
				echo '<img src="' . esc_url( $thumbUrl ) . '" class="dp-thumb-image" />';
			} elseif ( $inheritedThumbUrl ) {
				echo '<img src="' . esc_url( $inheritedThumbUrl ) . '" class="dp-thumb-image dp-thumb-image--inherited" />';
			}
			echo '</div>';
			if ( $inheritedThumbUrl && ! $thumbUrl ) {
				echo '<div class="dp-thumb-inherited-note">' . esc_html__( 'Inherited from parent template', 'rowsprout' ) . '</div>';
			}
			if ( ! $lockTitleHref ) {
				echo '<button type="button" class="button button-small dp-thumb-upload" data-target="' . esc_attr( $targetId ) . '">' . esc_html__( 'Select', 'rowsprout' ) . '</button> ';
				echo '<button type="button" class="button button-small dp-thumb-remove' . ( $thumbUrl ? '' : ' is-hidden' ) . '" data-target="' . esc_attr( $targetId ) . '">' . esc_html__( 'Remove', 'rowsprout' ) . '</button>';
			}
			return;
		}

		if ( $kind === 'checkbox' ) {
			$checked = $value === '1' || strtolower( $value ) === 'on' || $value === 'true';
			if ( $name !== '' && ! $lockTitleHref ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
			}
			echo '<label><input';
			self::printAttributes( [ 'type' => 'checkbox' ] + $nameAttr + [ 'value' => '1' ] + ( $checked ? [ 'checked' => 'checked' ] : [] ) + $disabledAttr );
			echo ' /> ' . esc_html__( 'Yes', 'rowsprout' ) . '</label>';
			return;
		}

		if ( $kind === 'select' ) {
			if ( empty( $options ) ) {
				$options = [ '' ];
			}
			if ( $value !== '' && ! in_array( $value, $options, true ) ) {
				$options[] = $value;
			}
			echo '<select';
			self::printAttributes( $nameAttr );
			echo '>';
			foreach ( $options as $option ) {
				$optionValue = (string) $option;
				echo '<option value="' . esc_attr( $optionValue ) . '"' . selected( $value, $optionValue, false ) . '>' . esc_html( $optionValue ) . '</option>';
			}
			echo '</select>';
			return;
		}

		if ( in_array( $kind, [ 'number', 'color', 'date', 'datetime-local', 'email', 'tel', 'url' ], true ) ) {
			echo '<input';
			self::printAttributes( [ 'type' => $kind ] + $nameAttr + [ 'value' => $value, 'data-col-type' => $colType ] + $readonlyAttr + $disabledAttr + $placeholderAttr );
			echo ' />';
			return;
		}

		echo '<input';
		self::printAttributes( [ 'type' => 'text' ] + $nameAttr + [ 'value' => $value, 'data-col-type' => $colType ] + $readonlyAttr + $disabledAttr + $placeholderAttr );
		echo ' />';
	}

	/**
	 * @param array<string, string> $attributes
	 */
	private static function printAttributes( array $attributes ): void {
		foreach ( $attributes as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
	}
}
