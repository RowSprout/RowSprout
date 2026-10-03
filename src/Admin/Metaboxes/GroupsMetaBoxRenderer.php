<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\Helpers;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

final class GroupsMetaBoxRenderer {

public static function render( \WP_Post $post ): void {
self::renderPropertiesTab( $post );
self::renderGroupsTab( $post );
self::renderScripts( $post );
}

public static function renderScripts( \WP_Post $post ): void {
	$fieldTypeDefinitions = TemplateMeta::getFieldTypeDefinitions();
	GroupsMetaBoxAssets::renderScriptLoader( (int) $post->ID, $fieldTypeDefinitions );
}

public static function renderPropertiesTab( \WP_Post $post ): void {
$config               = TemplateMeta::get( (int) $post->ID );
$columns              = ColumnSchema::buildColumns( $config );
$parentColumnKeys     = self::loadParentColumnKeys( (int) $post->ID );
$columns              = array_map(
	static function( $col ) use ( $post, $parentColumnKeys ) {
		if ( ! is_array( $col ) ) {
			return [];
		}
		$col['source_post_id'] = (int) $post->ID;
		// A column whose key also exists on the parent template is the
		// SAME shared property, not something this child added on its
		// own — the Parent dropdown's live-sync JS (applyParentSyncPayload
		// in groups-metabox.js) uses this flag to decide which columns to
		// re-append after it replaces #dp-columns-meta with the parent's
		// own list; getting this wrong duplicated every shared property
		// (confirmed live: Items/Text/Thumbnail each showing
		// twice on a converted child template, since every migrated
		// template's field set is identical by key to its parent's).
		$col['inherited'] = in_array( (string) ( $col['key'] ?? '' ), $parentColumnKeys, true );
		return $col;
	},
	$columns
);
$fieldTypeDefinitions = TemplateMeta::getFieldTypeDefinitions();
$usedTypes            = array_column( $columns, 'type' );
$addableFieldTypes    = array_filter(
	$fieldTypeDefinitions,
	static function ( $definition, $typeKey ) use ( $usedTypes ) {
		$allowMultiple = ! array_key_exists( 'allow_multiple', $definition ) || (bool) $definition['allow_multiple'];
		return $allowMultiple || ! in_array( $typeKey, $usedTypes, true );
	},
	ARRAY_FILTER_USE_BOTH
);

wp_nonce_field( 'rowsprout_page_groups_nonce', 'rowsprout_page_groups_nonce_field' );
GroupsMetaBoxAssets::renderStylesheetLink();
?>

<div id="dp-columns-meta">
<?php foreach ( $columns as $colIdx => $col ) : ?>
<?php self::renderColumnMeta( $colIdx, $col ); ?>
<?php endforeach; ?>
</div>

<table class="widefat striped" id="dp-props-table">
<thead>
<tr>
<th><?php esc_html_e( 'Label', 'rowsprout' ); ?></th>
<th><?php esc_html_e( 'Type', 'rowsprout' ); ?></th>
<th><?php esc_html_e( 'Token', 'rowsprout' ); ?></th>
<th><?php esc_html_e( 'Status', 'rowsprout' ); ?></th>
<th></th>
</tr>
</thead>
<tbody id="dp-props-tbody">
<?php if ( empty( $columns ) ) : ?>
<tr><td colspan="5" class="dp-empty-state"><?php esc_html_e( 'No properties added yet.', 'rowsprout' ); ?></td></tr>
<?php else : ?>
<?php foreach ( $columns as $col ) : ?>
<?php self::renderPropertyRow( $col, (int) $post->ID, $fieldTypeDefinitions ); ?>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>

<div class="dp-section-top-sm">
<button type="button" id="dp-add-col-btn" class="button button-primary">+ <?php esc_html_e( 'Add property', 'rowsprout' ); ?></button>
</div>

<div id="dp-add-col-modal" class="dp-add-col-modal" aria-hidden="true">
<div class="dp-add-col-backdrop"></div>
<div class="dp-add-col-dialog" role="dialog" aria-modal="true" aria-labelledby="dp-add-col-title">
<div class="dp-add-col-dialog-head">
<strong id="dp-add-col-title"><?php esc_html_e( 'Add new property', 'rowsprout' ); ?></strong>
<button type="button" id="dp-col-close" class="button-link dp-add-col-close" aria-label="<?php echo esc_attr__( 'Close', 'rowsprout' ); ?>">&times;</button>
</div>
<div class="dp-add-col-content">
<table class="dp-add-col-table">
<tr>
<td class="dp-add-col-label-cell"><label for="dp-new-type"><?php esc_html_e( 'Type', 'rowsprout' ); ?></label></td>
<td class="dp-add-col-field-cell">
<select id="dp-new-type" class="dp-add-col-input">
<?php foreach ( $addableFieldTypes as $typeKey => $typeDefinition ) : ?>
<option value="<?php echo esc_attr( $typeKey ); ?>"><?php echo esc_html( (string) ( $typeDefinition['label'] ?? $typeKey ) ); ?></option>
<?php endforeach; ?>
</select>
<div id="dp-type-description" class="dp-type-description"></div>
</td>
</tr>
<tr id="dp-new-options-row" class="dp-new-options-row">
<td class="dp-add-col-label-cell"><label for="dp-new-options"><?php esc_html_e( 'Options', 'rowsprout' ); ?></label></td>
<td class="dp-add-col-field-cell">
<textarea id="dp-new-options" rows="4" class="dp-add-col-input" placeholder="<?php echo esc_attr__( 'One option per line', 'rowsprout' ); ?>"></textarea>
</td>
</tr>
<tr>
<td class="dp-add-col-label-cell"><label for="dp-new-label"><?php esc_html_e( 'Label', 'rowsprout' ); ?></label></td>
<td class="dp-add-col-field-cell"><input type="text" id="dp-new-label" class="dp-add-col-input" /></td>
</tr>
<tr>
<td class="dp-add-col-label-cell"><label for="dp-new-overruled"><?php esc_html_e( 'Overridable', 'rowsprout' ); ?></label></td>
<td class="dp-add-col-field-cell">
<label><input type="checkbox" id="dp-new-overruled" checked="checked" /> <?php esc_html_e( 'A child template can override this property with its own value.', 'rowsprout' ); ?></label>
</td>
</tr>
</table>
<div class="dp-modal-actions">
<button type="button" id="dp-col-cancel" class="button"><?php esc_html_e( 'Cancel', 'rowsprout' ); ?></button>
<button type="button" id="dp-col-confirm" class="button button-primary"><?php esc_html_e( 'Add property', 'rowsprout' ); ?></button>
</div>
</div>
</div>
</div>
<?php
}

public static function renderGroupsTab( \WP_Post $post ): void {
$config  = TemplateMeta::get( (int) $post->ID );
$columns = ColumnSchema::buildColumns( $config );
$groups  = isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : [];
usort( $groups, fn( $a, $b ) => ( (int) ( $a['index'] ?? 0 ) ) <=> ( (int) ( $b['index'] ?? 0 ) ) );

if ( empty( $groups ) ) {
$groups[] = [
'id'        => ColumnSchema::generateNumericId(),
'parent_id' => '',
'fields'    => [],
'index'     => 1,
];
}

$parentGroupsById = self::loadParentGroupsById( (int) $post->ID );
?>
<div class="dp-groups-wrap">
<div class="dp-groups-toolbar">
<button type="button" id="dp-toggle-all-groups" class="button"><?php esc_html_e( 'Expand all', 'rowsprout' ); ?></button>
</div>
<div id="dp-groups-sortable" class="dp-groups-list">
<?php foreach ( $groups as $rowIdx => $group ) : ?>
<?php
$group       = (array) $group;
$parentId    = absint( $group['parent_id'] ?? 0 );
$parentGroup = $parentId > 0 ? ( $parentGroupsById[ $parentId ] ?? null ) : null;
self::renderGroupCard( $rowIdx, $group, $columns, (int) $post->ID, $parentGroup );
?>
<?php endforeach; ?>
</div>
<div class="dp-section-top">
<button type="button" id="dp-add-group-btn" class="button button-primary">+ <?php esc_html_e( 'Add group', 'rowsprout' ); ?></button>
</div>
</div>
<?php
}

/**
 * A group's own "parent_id" points at the matching group's numeric "id"
 * on the PARENT template (see ChildTemplateInheritance, which resolves this
 * same link at page-generation time) — load and index those once per render
 * instead of looking them up per group.
 *
 * @return array<int, array<string, mixed>>
 */
private static function loadParentGroupsById( int $postId ): array {
	$parentTemplateId = wp_get_post_parent_id( $postId );
	if ( ! $parentTemplateId ) {
		return [];
	}

	$parentGroups = Helpers::getGroups( $parentTemplateId, true );
	$byId         = [];
	foreach ( $parentGroups as $parentGroup ) {
		if ( ! is_array( $parentGroup ) ) {
			continue;
		}
		$id = absint( $parentGroup['id'] ?? 0 );
		if ( $id > 0 ) {
			$byId[ $id ] = $parentGroup;
		}
	}

	return $byId;
}

/**
 * @return array<int, string>
 */
private static function loadParentColumnKeys( int $postId ): array {
	$parentTemplateId = wp_get_post_parent_id( $postId );
	if ( ! $parentTemplateId ) {
		return [];
	}

	$parentConfig  = TemplateMeta::get( $parentTemplateId );
	$parentColumns = ColumnSchema::buildColumns( $parentConfig );

	$keys = [];
	foreach ( $parentColumns as $parentColumn ) {
		if ( is_array( $parentColumn ) && isset( $parentColumn['key'] ) ) {
			$keys[] = (string) $parentColumn['key'];
		}
	}

	return $keys;
}

private static function renderPropertyRow( array $col, int $postId, array $fieldTypeDefinitions ): void {
	PropertyTableRenderer::renderPropertyRow( $col, $postId, $fieldTypeDefinitions );
}

private static function renderColumnMeta( int $colIdx, array $col ): void {
	PropertyTableRenderer::renderColumnMeta( $colIdx, $col );
}

private static function renderGroupCard( int $rowIdx, array $group, array $columns, int $postId, ?array $parentGroup = null ): void {
	GroupCardRenderer::render( $rowIdx, $group, $columns, $postId, $parentGroup );
}

}