<?php

namespace RowSprout\Admin\Metaboxes;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the three HTML fragments (columns meta / properties rows / group
 * cards) the Parent dropdown's live-sync AJAX call replaces the metabox
 * with — see ParentPayloadAjaxHandler.
 */
final class ParentSyncPayloadRenderer {

	/**
	 * @return array{columns_meta_html: string, properties_rows_html: string, groups_html: string}
	 */
	public static function build( int $parentId, int $postId ): array {
		$payloadData          = ParentSyncDataBuilder::build( $parentId, $postId );
		$fieldTypeDefinitions = $payloadData['field_type_definitions'];
		$columns              = $payloadData['columns'];
		$groups               = $payloadData['groups'];

		ob_start();
		foreach ( $columns as $colIdx => $col ) {
			PropertyTableRenderer::renderColumnMeta( (int) $colIdx, (array) $col );
		}
		$columnsMetaHtml = (string) ob_get_clean();

		ob_start();
		if ( empty( $columns ) ) {
			echo '<tr><td colspan="5" class="dp-empty-state">' . esc_html__( 'No properties added yet.', 'rowsprout' ) . '</td></tr>';
		} else {
			foreach ( $columns as $col ) {
				PropertyTableRenderer::renderPropertyRow( (array) $col, $postId, $fieldTypeDefinitions );
			}
		}
		$propertiesRowsHtml = (string) ob_get_clean();

		ob_start();
		foreach ( $groups as $rowIdx => $group ) {
			GroupCardRenderer::render( (int) $rowIdx, (array) $group, $columns, $postId );
		}
		$groupsHtml = (string) ob_get_clean();

		return [
			'columns_meta_html'    => $columnsMetaHtml,
			'properties_rows_html' => $propertiesRowsHtml,
			'groups_html'          => $groupsHtml,
		];
	}
}
