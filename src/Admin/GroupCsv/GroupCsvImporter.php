<?php

namespace RowSprout\Admin\GroupCsv;

use RowSprout\Admin\Metaboxes\GroupsMetaBoxAssets;
use RowSprout\Core\Template\HrefUniquenessValidator;
use RowSprout\Core\Template\Lifecycle\TemplateSyncMarker;
use RowSprout\Core\Template\PayloadConfigBuilder;
use RowSprout\Core\Template\SavePayloadSanitizer;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds and updates a template's groups from the rows of a spreadsheet file.
 *
 * The browser reads and splits the file (assets/js/group-csv.js) and sends
 * the rows with the column mapping; everything is decided here, once for the
 * wizard's summary (dry run) and once more for real.
 *
 * A row updates the group whose ID it has, else the group with its Href
 * value (or, without one, the URL its title gives, as a new group would get),
 * else it becomes a new group (which needs a title). Only mapped
 * columns are written and nothing is generated: the
 * template is stored like "Save template only". The new config is built the
 * way a save from the template form builds it (PayloadConfigBuilder), from
 * the stored groups with the file's values filled in, so codes, sanitizing
 * and field ids follow the same rules.
 *
 * With delete_missing, the stored groups no row is for are deleted too, as
 * removing them in the editor and saving would (also their child templates'
 * groups); never when no row matches a stored group.
 *
 * A child template (GroupCsvTemplate) gets no new groups and loses none: a row that finds
 * none of its parent's groups is skipped. Its Title and Href only find the
 * row, and an empty cell means the value is inherited.
 */
final class GroupCsvImporter {

	public const TARGET_ID       = 'id';
	public const TARGET_PROPERTY = 'prop:';
	public const TARGET_NEW      = 'new:';

	private const LOCKED_TYPES = [ 'title', 'href', 'slug' ];

	/**
	 * A new property of a type with options gets the column's distinct
	 * values, up to this many.
	 */
	private const MAX_OPTIONS = 200;

	/**
	 * Values a property type empties, listed by row; the rest is counted.
	 */
	private const MAX_LISTED_EMPTIED = 10;

	/**
	 * @param array<string, mixed> $payload {
	 *     @type array<int, string>             $mapping        Per file column: '' (skip), 'id', 'prop:<key>' or 'new:<n>'.
	 *     @type array<int, array<string, mixed>>  $new_properties Per n: label, type, options and can_be_overruled.
	 *     @type array<int, array<int, string>> $rows           The data rows (no header).
	 *     @type array<int, int>                $row_numbers    Each row's number in the file, for messages.
	 *     @type bool                           $delete_missing Delete the groups no row is for, with their pages.
	 * }
	 * @param bool   $apply           False for a dry run.
	 * @param string $expectedVersion The config_updated_at the editor was rendered with.
	 */
	public static function run( int $templateId, array $payload, bool $apply, string $expectedVersion = '' ): GroupCsvImportResult {
		$result = new GroupCsvImportResult();

		if ( TemplateMeta::hasChangedSince( $templateId, $expectedVersion ) ) {
			$result->addError( self::changedMessage() );
			return $result;
		}

		$template  = GroupCsvTemplate::load( $templateId );
		$oldConfig = $template->config;
		$version   = (string) ( $oldConfig['config_updated_at'] ?? '' );
		$columns   = $template->columns;
		$byKey     = array_column( $columns, null, 'key' );

		$rows       = self::readRows( $payload, $result );
		$rawNew     = array_values( array_filter( (array) ( $payload['new_properties'] ?? [] ), 'is_array' ) );
		$mapping    = self::readMapping( $payload['mapping'] ?? [], $byKey, count( $rawNew ), $result );
		$newColumns = self::readNewProperties( $rawNew, $template->reservedColumns, $mapping, $rows, $result );
		if ( $result->hasErrors() ) {
			return $result;
		}

		foreach ( $mapping as $index => $target ) {
			if ( strpos( $target, self::TARGET_NEW ) === 0 ) {
				$mapping[ $index ] = (string) $newColumns[ (int) substr( $target, strlen( self::TARGET_NEW ) ) ]['key'];
			}
		}
		$allColumns = array_merge( $columns, array_values( $newColumns ) );
		$byKey      = array_column( $allColumns, null, 'key' );
		$titleKey   = $template->keyOfType( 'title' );
		$hrefKey    = $template->keyOfType( 'href' );

		if ( ! array_intersect( [ self::TARGET_ID, $titleKey, $hrefKey ], $mapping ) ) {
			$result->addError( $template->isChild
				? __( 'Import a column as Title, ID or Href, so each row can find its group.', 'rowsprout' )
				: __( 'Import a column as Title, ID or Href. Without one, a row can neither become a new group nor update an existing one.', 'rowsprout' )
			);
			return $result;
		}

		// The groups as the template form would post them; a new property
		// starts empty in every group.
		$rawRows = [];
		$byId    = [];
		$byHref  = [];
		foreach ( $template->rows as $position => $row ) {
			foreach ( $newColumns as $column ) {
				$row['fields'][ $column['key'] ] = '';
			}
			$rawRows[ $position ] = $row;
			foreach ( $row['match'] as $id ) {
				if ( ! isset( $byId[ (string) $id ] ) ) {
					$byId[ (string) $id ] = $position;
				}
			}
			self::indexHref( $byHref, (string) $row['href'], $position );
		}
		$storedCount = count( $rawRows );

		$touchedBy = [];
		$changed   = [];
		$emptied   = [];
		foreach ( $rows as $i => $cells ) {
			$rowNumber = absint( $payload['row_numbers'][ $i ] ?? 0 ) ?: $i + 1;
			$id        = '';
			$values    = [];
			$hasValue  = false;
			foreach ( $mapping as $index => $target ) {
				$cell     = GroupCsvFormat::unguardFormula( $cells[ $index ] ?? '' );
				$hasValue = $hasValue || trim( $cell ) !== '';
				if ( $target === self::TARGET_ID ) {
					$id = trim( $cell );
				} else {
					$values[ $target ] = GroupCsvValue::fromCell( $byKey[ $target ], $cell );
				}
			}
			// Empty lines, such as the ";;;;" rows Excel leaves at the end.
			if ( ! $hasValue ) {
				continue;
			}

			$position = null;
			if ( ctype_digit( $id ) && isset( $byId[ (string) absint( $id ) ] ) ) {
				$position = $byId[ (string) absint( $id ) ];
			}
			// Without a Href value, the URL a new group would get from its
			// title: importing the same file twice must not add a copy.
			$hrefValue = trim( $values[ $hrefKey ] ?? '' ) !== '' ? $values[ $hrefKey ] : ( $values[ $titleKey ] ?? '' );
			if ( $position === null && trim( $hrefValue ) !== '' ) {
				$position = $byHref[ self::hrefIdentity( $hrefValue ) ] ?? null;
			}

			if ( $position === null && $template->isChild ) {
				$result->addSkipped( sprintf(
					/* translators: %d: row number in the file. */
					__( 'Row %d: no group of the parent template has this ID or URL. The groups of a child template come from its parent template; add the group there.', 'rowsprout' ),
					$rowNumber
				) );
				continue;
			}

			if ( $position !== null ) {
				if ( isset( $touchedBy[ $position ] ) ) {
					$result->addWarning( sprintf(
						/* translators: 1: row number, 2: a later row number. */
						__( 'Rows %1$d and %2$d are for the same group; the values of row %2$d are used.', 'rowsprout' ),
						$touchedBy[ $position ],
						$rowNumber
					) );
				}
				foreach ( $values as $key => $value ) {
					$column = $byKey[ $key ];
					// The parent's Title and Href only find a child's row.
					if ( ! empty( $column['match_only'] ) ) {
						continue;
					}
					// An empty Title or Href keeps the stored one: a group
					// needs a title, and an empty URL falls back to it.
					if ( ( $key === $titleKey || $key === $hrefKey ) && trim( $value ) === '' ) {
						continue;
					}
					$stored = (string) $rawRows[ $position ]['fields'][ $key ];
					if ( GroupCsvValue::equivalent( $column, $stored, $value ) ) {
						continue;
					}
					$rawRows[ $position ]['fields'][ $key ] = $value;
					if ( self::sanitize( $column, $value ) !== self::sanitize( $column, $stored ) ) {
						$changed[ $position ] = true;
					}
				}
			} else {
				$title = trim( $values[ $titleKey ] ?? '' );
				if ( $title === '' ) {
					$result->addSkipped( sprintf(
						/* translators: %d: row number in the file. */
						__( 'Row %d: no title, so no group was added.', 'rowsprout' ),
						$rowNumber
					) );
					continue;
				}
				$fields = array_merge( array_fill_keys( array_keys( $byKey ), '' ), $values );
				// As in the editor, a new group without a URL gets its title's.
				if ( trim( $fields[ $hrefKey ] ) === '' ) {
					$fields[ $hrefKey ] = $title;
				}
				$position             = count( $rawRows );
				$rawRows[ $position ] = [ 'id' => 0, 'parent_id' => 0, 'fields' => $fields ];
				self::indexHref( $byHref, $fields[ $hrefKey ], $position );
				$result->added++;
			}
			$touchedBy[ $position ] = $rowNumber;

			foreach ( $values as $key => $value ) {
				$column = $byKey[ $key ];
				if ( empty( $column['match_only'] ) && trim( $value ) !== '' && self::sanitize( $column, $value ) === '' ) {
					$emptied[] = sprintf(
						/* translators: 1: property label, 2: row number in the file. */
						__( '%1$s in row %2$d', 'rowsprout' ),
						(string) $column['label'],
						$rowNumber
					);
				}
			}
		}

		foreach ( array_keys( $touchedBy ) as $position ) {
			if ( $position < $storedCount ) {
				if ( isset( $changed[ $position ] ) ) {
					$result->updated++;
				} else {
					$result->unchanged++;
				}
			}
		}

		// The stored groups no row is for. A child template cannot lose
		// groups (they are its parent's), and a file that matches none of
		// the groups is most likely the wrong file: it must not empty the
		// template.
		if ( ! $template->isChild ) {
			$missing = [];
			for ( $position = 0; $position < $storedCount; $position++ ) {
				if ( ! isset( $touchedBy[ $position ] ) ) {
					$missing[] = $position;
				}
			}
			$childGroupCounts = $missing !== [] ? GroupsMetaBoxAssets::childGroupCounts( $templateId ) : [];
			foreach ( $missing as $position ) {
				$id = (string) $rawRows[ $position ]['id'];
				$result->addMissing( trim( (string) $rawRows[ $position ]['title'] ) !== '' ? (string) $rawRows[ $position ]['title'] : $id, $childGroupCounts[ $id ] ?? 0 );
			}
			$result->deleteBlocked = $missing !== [] && ( $result->updated + $result->unchanged ) === 0;

			if ( ! empty( $payload['delete_missing'] ) && $missing !== [] ) {
				if ( $result->deleteBlocked ) {
					$result->addError( __( 'No row of the file matches a group of this template, so no groups were deleted: this may be the wrong file.', 'rowsprout' ) );
					return $result;
				}
				foreach ( $missing as $position ) {
					unset( $rawRows[ $position ] );
				}
				$result->deleted = count( $missing );
			}
		} elseif ( ! empty( $payload['delete_missing'] ) ) {
			$result->addError( __( 'Groups cannot be deleted from a child template: its groups come from its parent template.', 'rowsprout' ) );
			return $result;
		}

		$formRows = [];
		foreach ( $rawRows as $row ) {
			$formRows[] = [ 'id' => $row['id'], 'parent_id' => $row['parent_id'], 'fields' => $row['fields'] ];
		}
		// dp_columns[]: what the template declares itself; dp_all_columns[]:
		// every column of its groups (a child's include its parent's Title,
		// Href and overridable properties).
		$config = PayloadConfigBuilder::build(
			(string) ( $oldConfig['rowsprout_page_href'] ?? '' ),
			array_map( [ self::class, 'formColumn' ], array_merge( $template->ownColumns, array_values( $newColumns ) ) ),
			array_map( [ self::class, 'formColumn' ], $allColumns ),
			$formRows,
			$oldConfig,
			$templateId
		);

		foreach ( $newColumns as $column ) {
			$result->addNewProperty( (string) $column['label'] );
		}
		if ( $emptied !== [] ) {
			$listed = array_slice( $emptied, 0, self::MAX_LISTED_EMPTIED );
			$more   = count( $emptied ) - count( $listed );
			$result->addWarning( sprintf(
				/* translators: %s: list such as "Email in row 4, Website in row 9". */
				__( 'These values do not fit their property type and are left empty: %s.', 'rowsprout' ),
				implode( ', ', $listed ) . ( $more > 0 ? ' ' . sprintf(
					/* translators: %d: number of further values. */
					_n( 'and %d more', 'and %d more', $more, 'rowsprout' ),
					$more
				) : '' )
			) );
		}
		if ( ! $template->isChild ) {
			self::warnDuplicateUrls( $result, $config );
		}

		if ( ! $apply ) {
			return $result;
		}

		if ( $result->added === 0 && $result->updated === 0 && $result->deleted === 0 && $newColumns === [] ) {
			return $result;
		}

		// A save that landed while this request ran.
		if ( TemplateMeta::hasChangedSince( $templateId, $version ) ) {
			$result->addError( self::changedMessage() );
			return $result;
		}

		TemplateMeta::save( $templateId, $config );
		// Like "Save template only": rows for the new groups, changed groups
		// outdated, nothing generated. Deleted groups go the way a save from
		// the editor removes them (QueueManager pruning): their rows and
		// pages, and the groups their child templates have for them.
		TemplateSyncMarker::markStaleFromSmallAdjustments( $templateId, $oldConfig, $result->deleted > 0 );
		$result->saved = true;

		return $result;
	}

	private static function changedMessage(): string {
		return __( 'This template was saved somewhere else after you opened it. Reload the page and import the file again.', 'rowsprout' );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<int, array<int, string>>
	 */
	private static function readRows( array $payload, GroupCsvImportResult $result ): array {
		if ( ! isset( $payload['rows'] ) || ! is_array( $payload['rows'] ) ) {
			$result->addError( __( 'The file could not be read. Choose it again.', 'rowsprout' ) );
			return [];
		}

		$rows = [];
		foreach ( $payload['rows'] as $row ) {
			$cells = [];
			foreach ( is_array( $row ) ? array_values( $row ) : [] as $cell ) {
				$cells[] = is_scalar( $cell ) ? (string) $cell : '';
			}
			$rows[] = $cells;
		}

		if ( $rows === [] ) {
			$result->addError( __( 'The file contains no rows to import.', 'rowsprout' ) );
		}

		return $rows;
	}

	/**
	 * @param mixed                               $raw
	 * @param array<string, array<string, mixed>> $byKey
	 * @return array<int, string> File column index => 'id', a property key or 'new:<n>'.
	 */
	private static function readMapping( $raw, array $byKey, int $newCount, GroupCsvImportResult $result ): array {
		$mapping = [];
		$seen    = [];

		foreach ( is_array( $raw ) ? $raw : [] as $index => $target ) {
			$target = is_string( $target ) ? $target : '';
			if ( $target === '' ) {
				continue;
			}

			if ( $target === self::TARGET_ID ) {
				$key   = self::TARGET_ID;
				$label = GroupCsvExporter::ID_HEADER;
			} elseif ( strpos( $target, self::TARGET_PROPERTY ) === 0 ) {
				$key = sanitize_key( substr( $target, strlen( self::TARGET_PROPERTY ) ) );
				if ( ! isset( $byKey[ $key ] ) ) {
					$result->addError( __( 'A column is imported into a property this template no longer has. Reload the page and try again.', 'rowsprout' ) );
					continue;
				}
				$label = (string) $byKey[ $key ]['label'];
			} elseif ( strpos( $target, self::TARGET_NEW ) === 0 && ctype_digit( substr( $target, strlen( self::TARGET_NEW ) ) ) && (int) substr( $target, strlen( self::TARGET_NEW ) ) < $newCount ) {
				$key   = $target;
				$label = '';
			} else {
				$result->addError( __( 'The column choices could not be read. Reload the page and try again.', 'rowsprout' ) );
				continue;
			}

			if ( isset( $seen[ $key ] ) ) {
				$result->addError( sprintf(
					/* translators: %s: property label. */
					__( 'Two columns are imported into "%s". Choose one of them.', 'rowsprout' ),
					$label
				) );
				continue;
			}
			$seen[ $key ]              = true;
			$mapping[ absint( $index ) ] = $key;
		}

		if ( $mapping === [] && ! $result->hasErrors() ) {
			$result->addError( __( 'Choose at least one column to import.', 'rowsprout' ) );
		}

		return $mapping;
	}

	/**
	 * The new properties a column is mapped to, as columns (unused ones are
	 * dropped). Checked like the "Add property" dialog checks them.
	 *
	 * @param array<int, array<string, mixed>> $rawNew
	 * @param array<int, array<string, mixed>> $columns
	 * @param array<int, string>               $mapping
	 * @param array<int, array<int, string>>   $rows
	 * @return array<int, array<string, mixed>> n => column.
	 */
	private static function readNewProperties( array $rawNew, array $columns, array $mapping, array $rows, GroupCsvImportResult $result ): array {
		$definitions = TemplateMeta::getFieldTypeDefinitions();
		$labels      = [];
		$usedTypes   = [];
		foreach ( $columns as $column ) {
			$labels[ mb_strtolower( trim( (string) $column['label'] ) ) ] = true;
			$usedTypes[ (string) $column['type'] ]                        = true;
		}
		$stamp = (string) (int) round( microtime( true ) * 1000 );

		$new = [];
		foreach ( $rawNew as $n => $property ) {
			$index = array_search( self::TARGET_NEW . $n, $mapping, true );
			if ( $index === false ) {
				continue;
			}

			$label = trim( sanitize_text_field( (string) ( $property['label'] ?? '' ) ) );
			$type  = sanitize_key( (string) ( $property['type'] ?? '' ) );
			if ( $label === '' ) {
				$result->addError( __( 'Give every new property a name.', 'rowsprout' ) );
				continue;
			}
			$normalized = mb_strtolower( $label );
			if ( isset( $labels[ $normalized ] ) ) {
				$result->addError( sprintf(
					/* translators: %s: property label. */
					__( 'There already is a property called "%s". Choose another name, or import the column into that property.', 'rowsprout' ),
					$label
				) );
				continue;
			}
			$definition = $definitions[ $type ] ?? null;
			if ( ! is_array( $definition ) || in_array( $type, self::LOCKED_TYPES, true ) ) {
				$result->addError( sprintf(
					/* translators: %s: property label. */
					__( 'Choose a type for the new property "%s".', 'rowsprout' ),
					$label
				) );
				continue;
			}
			if ( empty( $definition['allow_multiple'] ) && isset( $usedTypes[ $type ] ) ) {
				$result->addError( sprintf(
					/* translators: 1: property label, 2: property type label. */
					__( 'The new property "%1$s" cannot be of type %2$s: a template can have only one property of that type.', 'rowsprout' ),
					$label,
					(string) $definition['label']
				) );
				continue;
			}
			$labels[ $normalized ] = true;
			$usedTypes[ $type ]    = true;

			// The options from the wizard, else the column's distinct values.
			$options = SavePayloadSanitizer::normalizeOptions( $property['options'] ?? [] );
			if ( $options === [] && ( ! empty( $definition['supports_options'] ) || ( $definition['type'] ?? '' ) === 'select' ) ) {
				$values  = array_filter( array_map( 'trim', array_column( $rows, (int) $index ) ), 'strlen' );
				$options = array_slice( array_values( array_unique( $values ) ), 0, self::MAX_OPTIONS );
			}
			if ( $options === [] ) {
				$options = (array) ( $definition['default_options'] ?? [] );
			}

			$new[ $n ] = [
				'key'              => 'col_' . $stamp . '_' . $n,
				'type'             => $type,
				'label'            => $label,
				// Derived from the label on save, unique among the template's codes.
				'code'             => '',
				'field_type'       => (string) ( $definition['type'] ?? 'text' ),
				'options'          => SavePayloadSanitizer::normalizeOptions( $options ),
				'can_be_overruled' => ! array_key_exists( 'can_be_overruled', $property ) || ! empty( $property['can_be_overruled'] ),
				'required'         => false,
			];
		}

		return $new;
	}

	/**
	 * A column in the shape the template form posts it (dp_columns[]).
	 *
	 * @param array<string, mixed> $column
	 * @return array<string, mixed>
	 */
	private static function formColumn( array $column ): array {
		return [
			'key'              => (string) $column['key'],
			'type'             => (string) $column['type'],
			'label'            => (string) $column['label'],
			'code'             => (string) ( $column['code'] ?? '' ),
			'field_type'       => (string) ( $column['field_type'] ?? 'text' ),
			'options'          => (array) ( $column['options'] ?? [] ),
			'can_be_overruled' => ! empty( $column['can_be_overruled'] ),
			'required'         => ! empty( $column['required'] ),
		];
	}

	/**
	 * @param array<string, mixed> $column
	 */
	private static function sanitize( array $column, string $value ): string {
		return SavePayloadSanitizer::sanitizeFieldValue(
			(string) $column['type'],
			(string) ( $column['field_type'] ?? 'text' ),
			$value,
			(array) ( $column['options'] ?? [] )
		);
	}

	/**
	 * The URL a Href value gives, to recognise a group by; '' for an empty
	 * value or a placeholder token (the same for every group).
	 */
	private static function hrefIdentity( string $value ): string {
		$slug = SavePayloadSanitizer::sanitizeFieldValue( 'href', 'text', $value, [] );

		return strpos( $slug, '@code_' ) === false ? $slug : '';
	}

	/**
	 * @param array<string, int> $byHref
	 */
	private static function indexHref( array &$byHref, string $value, int $position ): void {
		$identity = self::hrefIdentity( $value );
		if ( $identity !== '' && ! isset( $byHref[ $identity ] ) ) {
			$byHref[ $identity ] = $position;
		}
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function warnDuplicateUrls( GroupCsvImportResult $result, array $config ): void {
		$duplicates = HrefUniquenessValidator::findDuplicateGroups( $config );
		if ( $duplicates === [] ) {
			return;
		}

		$hrefKey = '';
		foreach ( (array) ( $config['field_types'] ?? [] ) as $fieldType ) {
			if ( is_array( $fieldType ) && in_array( $fieldType['type'] ?? '', [ 'href', 'slug' ], true ) ) {
				$hrefKey = (string) $fieldType['key'];
				break;
			}
		}
		$hrefById = [];
		foreach ( (array) ( $config['groups'] ?? [] ) as $group ) {
			if ( is_array( $group ) ) {
				$hrefById[ (string) ( $group['id'] ?? '' ) ] = GroupCsvExporter::fieldValue( $group, $hrefKey );
			}
		}

		$urls = [];
		foreach ( $duplicates as $guids ) {
			$urls[] = $hrefById[ (string) $guids[0] ] ?? '';
		}

		$result->addWarning( sprintf(
			/* translators: %s: comma-separated URL values. */
			__( 'These URLs are used by more than one group: %s. Those groups get no page until each has a URL of its own.', 'rowsprout' ),
			implode( ', ', array_filter( $urls, 'strlen' ) )
		) );
	}
}
