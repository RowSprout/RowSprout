<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the data behind the Parent dropdown's live-sync AJAX call: when a
 * child template's parent changes, its Properties/Groups tabs are rebuilt
 * from the parent's config, overlaid with the child's own already-saved
 * overrides (see InheritedGroupOverlay).
 */
final class ParentSyncDataBuilder {

	/**
	 * @return array{field_type_definitions: array<string, mixed>, columns: array<int, array<string, mixed>>, groups: array<int, array<string, mixed>>, is_inherited_payload: bool}
	 */
	public static function build( int $parentId, int $postId ): array {
		$config = [];
		if ( $parentId > 0 ) {
			$parentPost = get_post( $parentId );
			if ( $parentPost instanceof \WP_Post && $parentPost->post_type === PostTypes::TEMPLATE ) {
				$config = TemplateMeta::get( $parentId );
			}
		}

		$childConfig = $postId > 0 ? TemplateMeta::get( $postId ) : [];

		$fieldTypeDefinitions = TemplateMeta::getFieldTypeDefinitions();
		$isInheritedPayload   = $parentId > 0;
		$columns              = self::filterColumnsForParentSync( ColumnSchema::buildColumns( $config ) );
		$columns              = array_map(
			static function( $col ) use ( $parentId, $postId, $isInheritedPayload ) {
				if ( ! is_array( $col ) ) {
					return [];
				}
				$col['source_post_id'] = $isInheritedPayload ? $parentId : $postId;
				$col['inherited']      = $isInheritedPayload;
				return $col;
			},
			$columns
		);

		$groups = isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : [];
		usort( $groups, fn( $a, $b ) => ( (int) ( $a['index'] ?? 0 ) ) <=> ( (int) ( $b['index'] ?? 0 ) ) );

		if ( $isInheritedPayload && ! empty( $groups ) ) {
			$childGroups = isset( $childConfig['groups'] ) && is_array( $childConfig['groups'] ) ? $childConfig['groups'] : [];
			$groups      = InheritedGroupOverlay::apply( $groups, $childGroups, $columns );
		}

		if ( empty( $groups ) ) {
			$groups[] = [
				'id'        => ColumnSchema::generateNumericId(),
				'parent_id' => '',
				'fields'    => [],
				'index'     => 1,
				'inherited' => $isInheritedPayload,
			];
		}

		$groups = array_map(
			static function( $group ) use ( $isInheritedPayload ) {
				if ( ! is_array( $group ) ) {
					return [];
				}
				$group['inherited'] = $isInheritedPayload;
				return $group;
			},
			$groups
		);

		return [
			'field_type_definitions' => $fieldTypeDefinitions,
			'columns'                => $columns,
			'groups'                 => $groups,
			'is_inherited_payload'   => $isInheritedPayload,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 * @return array<int, array<string, mixed>>
	 */
	private static function filterColumnsForParentSync( array $columns ): array {
		$filtered = [];

		foreach ( $columns as $column ) {
			if ( ! is_array( $column ) ) {
				continue;
			}

			$type      = (string) ( $column['type'] ?? '' );
			$isLocked  = in_array( $type, [ 'title', 'href', 'slug' ], true );
			$overruled = ! $isLocked && ! empty( $column['can_be_overruled'] );

			if ( ! $isLocked && ! $overruled ) {
				continue;
			}

			$filtered[] = $column;
		}

		if ( empty( $filtered ) ) {
			return ColumnSchema::buildColumns( [] );
		}

		$hasTitle = false;
		$hasHref  = false;
		foreach ( $filtered as $col ) {
			$type = (string) ( $col['type'] ?? '' );
			if ( $type === 'title' ) {
				$hasTitle = true;
			}
			if ( $type === 'href' || $type === 'slug' ) {
				$hasHref = true;
			}
		}

		if ( ! $hasTitle || ! $hasHref ) {
			$defaults = ColumnSchema::buildColumns( [] );
			foreach ( $defaults as $defaultCol ) {
				$type = (string) ( $defaultCol['type'] ?? '' );
				if ( $type === 'title' && ! $hasTitle ) {
					array_unshift( $filtered, $defaultCol );
					$hasTitle = true;
				}
				if ( $type === 'href' && ! $hasHref ) {
					$insertAt = 1;
					array_splice( $filtered, $insertAt, 0, [ $defaultCol ] );
					$hasHref = true;
				}
			}
		}

		return array_values( $filtered );
	}
}
