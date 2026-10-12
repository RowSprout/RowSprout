<?php

namespace RowSprout\Admin\GroupCsv;

use RowSprout\Admin\Metaboxes\ColumnSchema;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A template's groups and properties the way its template form shows and
 * posts them, for the groups CSV export and import.
 *
 * A template without a parent: its own properties and groups.
 *
 * A child template: one row per group of the parent template, in the
 * parent's order, matched to the child's group the way the Parent sync does
 * (InheritedGroupOverlay: the child group with the parent group's id, else
 * the one pointing at it; none yet gets a new one on save). Title and Href
 * are the parent's and only identify a row (match_only); the other columns
 * are the parent's overridable properties and the child's own. A value is
 * the child's own: empty means inherited (ChildTemplateInheritance).
 */
final class GroupCsvTemplate {

	/** @var int */
	public $id;

	/** @var bool */
	public $isChild = false;

	/** @var array<string, mixed> */
	public $config;

	/**
	 * The file's columns: Title, Href, then the other properties.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $columns = [];

	/**
	 * The properties the template itself declares (the form's dp_columns[]);
	 * a child's do not include its parent's.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $ownColumns = [];

	/**
	 * Every property label and type in use, also the parent's properties a
	 * child cannot fill: a new property must not repeat a label.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $reservedColumns = [];

	/**
	 * One row per group: id, parent_id and fields as the form posts them,
	 * plus export_id (the ID column), match (ids that find the row), title
	 * and href.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $rows = [];

	public static function load( int $templateId ): self {
		$template         = new self();
		$template->id     = $templateId;
		$template->config = TemplateMeta::get( $templateId );
		$parentId         = (int) wp_get_post_parent_id( $templateId );

		if ( $parentId > 0 ) {
			$template->loadChild( $parentId );
		} else {
			$template->loadStandalone();
		}

		return $template;
	}

	/**
	 * Title, Href, then the other properties of a config, built like the
	 * template form builds them (ColumnSchema), including the implicit
	 * Title/Href of a template that has not stored them.
	 *
	 * @param array<string, mixed> $config
	 * @return array<int, array<string, mixed>>
	 */
	public static function columnsOf( array $config ): array {
		$first = [];
		$rest  = [];
		foreach ( ColumnSchema::buildColumns( $config ) as $column ) {
			if ( ! is_array( $column ) ) {
				continue;
			}
			$type = (string) ( $column['type'] ?? '' );
			if ( $type === 'title' ) {
				$first[0] = $column;
			} elseif ( $type === 'href' ) {
				$first[1] = $column;
			} else {
				$rest[] = $column;
			}
		}
		ksort( $first );

		return array_merge( array_values( $first ), $rest );
	}

	public function keyOfType( string $type ): string {
		foreach ( $this->columns as $column ) {
			if ( ( $column['type'] ?? '' ) === $type ) {
				return (string) $column['key'];
			}
		}

		return '';
	}

	private function loadStandalone(): void {
		$this->columns         = self::columnsOf( $this->config );
		$this->ownColumns      = $this->columns;
		$this->reservedColumns = $this->columns;
		$titleKey              = $this->keyOfType( 'title' );
		$hrefKey               = $this->keyOfType( 'href' );

		foreach ( self::sortedGroups( $this->config ) as $group ) {
			$id     = absint( $group['id'] ?? 0 );
			$fields = [];
			foreach ( $this->columns as $column ) {
				$fields[ $column['key'] ] = GroupCsvExporter::fieldValue( $group, (string) $column['key'] );
			}
			$this->rows[] = [
				'id'        => $id,
				'parent_id' => 0,
				'fields'    => $fields,
				'export_id' => $id,
				'match'     => $id > 0 ? [ $id ] : [],
				'title'     => $fields[ $titleKey ] ?? '',
				'href'      => $fields[ $hrefKey ] ?? '',
			];
		}
	}

	private function loadChild( int $parentId ): void {
		$this->isChild = true;
		$parentConfig  = TemplateMeta::get( $parentId );
		$parentColumns = self::columnsOf( $parentConfig );
		$parentKeys    = array_column( $parentColumns, 'key' );

		// As the Parent sync shows them: the parent's Title, Href and
		// overridable properties, then the child's own.
		foreach ( $parentColumns as $column ) {
			$locked = in_array( $column['type'], [ 'title', 'href' ], true );
			if ( $locked ) {
				$column['match_only'] = true;
			}
			if ( $locked || ! empty( $column['can_be_overruled'] ) ) {
				$this->columns[] = $column;
			}
		}
		$this->reservedColumns = $parentColumns;
		foreach ( self::columnsOf( $this->config ) as $column ) {
			if ( in_array( $column['key'], $parentKeys, true ) || in_array( $column['type'], [ 'title', 'href' ], true ) ) {
				continue;
			}
			$this->columns[]         = $column;
			$this->ownColumns[]      = $column;
			$this->reservedColumns[] = $column;
		}

		$childById       = [];
		$childByParentId = [];
		foreach ( (array) ( $this->config['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$id       = absint( $group['id'] ?? 0 );
			$parentOf = absint( $group['parent_id'] ?? 0 );
			if ( $id > 0 && ! isset( $childById[ $id ] ) ) {
				$childById[ $id ] = $group;
			}
			if ( $parentOf > 0 && ! isset( $childByParentId[ $parentOf ] ) ) {
				$childByParentId[ $parentOf ] = $group;
			}
		}

		$titleKey = $this->keyOfType( 'title' );
		$hrefKey  = $this->keyOfType( 'href' );
		foreach ( self::sortedGroups( $parentConfig ) as $parentGroup ) {
			$parentGroupId = absint( $parentGroup['id'] ?? 0 );
			if ( $parentGroupId <= 0 ) {
				continue;
			}
			$child   = $childById[ $parentGroupId ] ?? ( $childByParentId[ $parentGroupId ] ?? null );
			$childId = is_array( $child ) ? absint( $child['id'] ?? 0 ) : 0;
			$fields  = [];
			foreach ( $this->columns as $column ) {
				if ( empty( $column['match_only'] ) ) {
					$fields[ $column['key'] ] = is_array( $child ) ? GroupCsvExporter::fieldValue( $child, (string) $column['key'] ) : '';
				}
			}
			$this->rows[] = [
				'id'        => $childId,
				'parent_id' => $parentGroupId,
				'fields'    => $fields,
				'export_id' => $parentGroupId,
				'match'     => array_values( array_unique( array_filter( [ $parentGroupId, $childId ] ) ) ),
				'title'     => self::parentValue( $parentGroup, $titleKey, 'title' ),
				'href'      => self::parentValue( $parentGroup, $hrefKey, 'href' ),
			];
		}
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<int, array<string, mixed>>
	 */
	private static function sortedGroups( array $config ): array {
		$groups = array_values( array_filter( (array) ( $config['groups'] ?? [] ), 'is_array' ) );
		usort( $groups, static function ( array $a, array $b ): int {
			return ( (int) ( $a['index'] ?? 0 ) ) <=> ( (int) ( $b['index'] ?? 0 ) );
		} );

		return $groups;
	}

	/**
	 * A parent group's title or URL; older data keeps them as top-level keys
	 * (the same fallback as GroupCardRenderer::extractParentValue()).
	 *
	 * @param array<string, mixed> $group
	 */
	private static function parentValue( array $group, string $key, string $topLevelKey ): string {
		if ( isset( $group[ $topLevelKey ] ) && is_scalar( $group[ $topLevelKey ] ) && (string) $group[ $topLevelKey ] !== '' ) {
			return (string) $group[ $topLevelKey ];
		}

		return GroupCsvExporter::fieldValue( $group, $key );
	}
}
