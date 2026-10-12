<?php

namespace RowSprout\Core\Transfer;

use RowSprout\Core\Template\ConfigNormalizer;
use RowSprout\Core\Template\GroupTitles;
use RowSprout\Core\Template\HrefUniquenessValidator;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an import of a TemplateExporter file would do, without writing
 * anything: per template whether it is added as a new draft or updates an
 * existing template (filter rowsprout_template_import_target), and which
 * properties and groups it adds, changes or removes. It runs the import's
 * own steps (TemplateImporter::readFile(), existingTarget(), plannedConfig()),
 * so an add-on's filters apply as they will on import; the actions that
 * write (rowsprout_template_imported, rowsprout_templates_imported) do not
 * run.
 */
final class TemplateImportPreview {

	/**
	 * @param array<string, mixed> $data    A decoded export file.
	 * @param array<string, mixed> $options The import options.
	 * @return array{templates: array<int, array<string, mixed>>, warnings: array<int, string>, errors: array<int, string>}
	 */
	public static function build( array $data, array $options = [] ): array {
		$result  = new ImportResult();
		$entries = TemplateImporter::readFile( $data, $result );
		if ( $entries === [] ) {
			return [ 'templates' => [], 'warnings' => $result->warnings(), 'errors' => $result->errors() ];
		}

		$order   = TemplateImporter::inParentOrder( $entries );
		$targets = [];
		foreach ( array_keys( $order ) as $sourceId ) {
			$targets[ $sourceId ] = TemplateImporter::existingTarget( $entries[ $sourceId ], $options );
		}
		// As on import: an updated template's tokens point at it; a new one's
		// id is not known yet, so its source id stands in.
		$idMap = [];
		foreach ( $targets as $sourceId => $targetId ) {
			$idMap[ $sourceId ] = $targetId > 0 ? $targetId : $sourceId;
		}

		$unknownTypes = [];
		$titlesBySource = [];
		$templates    = [];
		foreach ( $order as $sourceId => $fileParent ) {
			$entry    = $entries[ $sourceId ];
			$targetId = $targets[ $sourceId ];
			$isUpdate = $targetId > 0;
			/** This filter is documented in TemplateImporter::fillTemplate(). */
			$map     = $isUpdate ? (array) apply_filters( 'rowsprout_template_import_id_map', $idMap, $entry, $targetId ) : $idMap;
			$config  = ConfigNormalizer::normalizeConfig( TemplateImporter::plannedConfig( $entry, $map, $targetId, $isUpdate, $options, $unknownTypes ) );
			$current = $isUpdate ? TemplateMeta::get( $targetId ) : [ 'field_types' => [], 'groups' => [] ];

			// A child template's groups take their title from the parent group.
			$parentTitles = [];
			if ( $fileParent > 0 ) {
				$parentTitles = $titlesBySource[ $fileParent ] ?? [];
			} elseif ( $isUpdate && wp_get_post_parent_id( $targetId ) > 0 ) {
				$parentTitles = self::groupTitles( (array) ( TemplateMeta::get( (int) wp_get_post_parent_id( $targetId ) )['groups'] ?? [] ), [] );
			}
			$newTitles                   = self::groupTitles( (array) ( $config['groups'] ?? [] ), $parentTitles );
			$titlesBySource[ $sourceId ] = $newTitles;

			$item = [
				'title'           => TemplateImporter::title( $entry ),
				'action'          => $isUpdate ? 'update' : 'create',
				'target'          => null,
				'parent'          => $fileParent > 0 ? TemplateImporter::title( $entries[ $fileParent ] ) : '',
				'parent_missing'  => ! $isUpdate && $fileParent === 0 && absint( $entry['parent'] ?? 0 ) > 0,
				'content_changed' => false,
				'properties'      => self::diffProperties( (array) ( $current['field_types'] ?? [] ), (array) ( $config['field_types'] ?? [] ) ),
				'groups'          => self::diffGroups( (array) ( $current['groups'] ?? [] ), (array) ( $config['groups'] ?? [] ), self::groupTitles( (array) ( $current['groups'] ?? [] ), $parentTitles ), $newTitles ),
				'duplicate_urls'  => HrefUniquenessValidator::findDuplicateGroups( $config ) !== [],
			];
			if ( $isUpdate ) {
				$post           = get_post( $targetId );
				$status         = $post instanceof \WP_Post ? get_post_status_object( $post->post_status ) : null;
				$item['target'] = [
					'id'     => $targetId,
					'title'  => get_the_title( $targetId ),
					'url'    => (string) get_edit_post_link( $targetId, 'raw' ),
					'status' => $status ? (string) $status->label : '',
				];
				$item['content_changed'] = $post instanceof \WP_Post && (
					PlaceholderTokenIds::remap( TemplateImporter::title( $entry ), $map ) !== $post->post_title
					|| PlaceholderTokenIds::remap( self::text( $entry['content'] ?? '' ), $map ) !== $post->post_content
					|| PlaceholderTokenIds::remap( self::text( $entry['excerpt'] ?? '' ), $map ) !== $post->post_excerpt
				);
			}
			$templates[] = $item;
		}

		$warnings = $result->warnings();
		if ( $unknownTypes !== [] ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated property types, e.g. "richtext, date". */
				__( 'These property types are not available on this site: %s. Their values are imported unchanged; activate the plugin that adds them (such as RowSprout Pro) to edit and use them.', 'rowsprout' ),
				implode( ', ', array_keys( $unknownTypes ) )
			);
		}

		return [ 'templates' => $templates, 'warnings' => $warnings, 'errors' => [] ];
	}

	/**
	 * Properties by key: the labels of those added, changed and removed, and
	 * how many stay as they are.
	 *
	 * @param array<int, mixed> $current
	 * @param array<int, mixed> $new
	 * @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>, unchanged: int}
	 */
	private static function diffProperties( array $current, array $new ): array {
		$before = self::byKey( $current, 'key' );
		$diff   = [ 'added' => [], 'changed' => [], 'removed' => [], 'unchanged' => 0 ];
		foreach ( self::byKey( $new, 'key' ) as $key => $property ) {
			$label = (string) ( $property['label'] ?? $key );
			if ( ! isset( $before[ $key ] ) ) {
				$diff['added'][] = $label;
			} elseif ( self::comparable( $before[ $key ] ) !== self::comparable( $property ) ) {
				$diff['changed'][] = $label;
			} else {
				$diff['unchanged']++;
			}
			unset( $before[ $key ] );
		}
		foreach ( $before as $key => $property ) {
			$diff['removed'][] = (string) ( $property['label'] ?? $key );
		}

		return $diff;
	}

	/**
	 * Groups by id: the names of those added, changed (a value or the parent
	 * group) and removed, and how many stay as they are.
	 *
	 * @param array<int, mixed>     $current
	 * @param array<int, mixed>     $new
	 * @param array<string, string> $currentTitles Group id => name, from groupTitles().
	 * @param array<string, string> $newTitles
	 * @return array{added: array<int, string>, changed: array<int, string>, removed: array<int, string>, unchanged: int}
	 */
	private static function diffGroups( array $current, array $new, array $currentTitles, array $newTitles ): array {
		$before = self::byKey( $current, 'id' );
		$diff   = [ 'added' => [], 'changed' => [], 'removed' => [], 'unchanged' => 0 ];
		foreach ( self::byKey( $new, 'id' ) as $id => $group ) {
			$name = $newTitles[ $id ] ?? $id;
			if ( ! isset( $before[ $id ] ) ) {
				$diff['added'][] = $name;
			} elseif ( self::groupValues( $before[ $id ] ) !== self::groupValues( $group ) ) {
				$diff['changed'][] = $name;
			} else {
				$diff['unchanged']++;
			}
			unset( $before[ $id ] );
		}
		foreach ( array_keys( $before ) as $id ) {
			$diff['removed'][] = $currentTitles[ $id ] ?? (string) $id;
		}

		return $diff;
	}

	/**
	 * Group id => the name to show: its title, a child template's group the
	 * title of its parent group, else "Group N" by position.
	 *
	 * @param array<int, mixed>     $groups
	 * @param array<string, string> $parentTitles
	 * @return array<string, string>
	 */
	private static function groupTitles( array $groups, array $parentTitles ): array {
		$titles   = [];
		$position = 0;
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['id'] ) ) {
				continue;
			}
			$position++;
			$id    = (string) $group['id'];
			$title = (string) GroupTitles::of( $group );
			if ( $title === '' ) {
				$title = $parentTitles[ (string) ( $group['parent_id'] ?? '' ) ] ?? '';
			}
			/* translators: %d: position of the group in the template. */
			$titles[ $id ] = $title !== '' ? $title : sprintf( __( 'Group %d', 'rowsprout' ), $position );
		}

		return $titles;
	}

	/**
	 * What decides whether a group changed: its parent group and each value.
	 *
	 * @param array<string, mixed> $group
	 * @return array<string, mixed>
	 */
	private static function groupValues( array $group ): array {
		$values = [ '@parent' => (string) ( $group['parent_id'] ?? '' ) ];
		foreach ( (array) ( $group['fields'] ?? [] ) as $key => $field ) {
			$values[ (string) $key ] = is_array( $field ) && is_scalar( $field['value'] ?? null ) ? (string) $field['value'] : '';
		}
		ksort( $values );

		return $values;
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<string, array<string, mixed>>
	 */
	private static function byKey( array $items, string $field ): array {
		$byKey = [];
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item[ $field ] ) && is_scalar( $item[ $field ] ) ) {
				$byKey[ (string) $item[ $field ] ] = $item;
			}
		}

		return $byKey;
	}

	/**
	 * @param array<string, mixed> $property
	 * @return array<string, mixed>
	 */
	private static function comparable( array $property ): array {
		ksort( $property );

		return $property;
	}

	/**
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
