<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PayloadConfigBuilder {

	/**
	 * @param array<int, array<string, mixed>> $rawCols
	 * @param array<int, array<string, mixed>> $rawAllCols
	 * @param array<int, array<string, mixed>> $rawRows
	 * @param array<string, mixed> $existingConfig
	 * @param int $postId The template post being saved — needed to look up
	 *        its parent (see mergeInheritedFieldTypes()). 0 for a caller
	 *        that has no post context yet (e.g. building a fresh, not-yet-
	 *        inserted template), where there's necessarily no parent to
	 *        merge from anyway.
	 * @return array<string, mixed>
	 */
	public static function build( string $templateHref, array $rawCols, array $rawAllCols, array $rawRows, array $existingConfig, int $postId = 0 ): array {
		// Property key => stored code. A child template shares its parent's
		// properties; its own code for a shared key wins (see
		// mergeInheritedFieldTypes()), and every parent code stays taken.
		$parentId    = $postId > 0 ? (int) wp_get_post_parent_id( $postId ) : 0;
		$parentCodes = $parentId > 0 ? self::codesByKey( (array) ( TemplateMeta::get( $parentId )['field_types'] ?? [] ) ) : [];
		$storedCodes = self::codesByKey( (array) ( $existingConfig['field_types'] ?? [] ) ) + $parentCodes;
		$fieldTypes  = self::buildFieldTypes( $rawCols, $storedCodes, $parentCodes );
		$fieldTypes  = self::mergeInheritedFieldTypes( $fieldTypes, $postId, (array) ( $existingConfig['groups'] ?? [] ) );

		// The codes just given to new properties count as stored for the second
		// pass, so a property gets the same code in both.
		$storedCodes        += array_filter( array_column( $fieldTypes, 'code', 'key' ), 'is_string' );
		$aliasMap            = [];
		$effectiveFieldTypes = ! empty( $rawAllCols ) ? self::buildFieldTypes( $rawAllCols, $storedCodes, $parentCodes, $aliasMap ) : $fieldTypes;
		$groups              = self::buildGroups( $rawRows, $effectiveFieldTypes, $existingConfig, $aliasMap );

		return [
			'version'           => 1,
			'rowsprout_page_href' => $templateHref,
			'field_types'       => $fieldTypes,
			'groups'            => $groups,
			'child_extra'       => $existingConfig['child_extra'] ?? [],
		];
	}

	/**
	 * Property key => code, for every entry of a stored field_types[] that
	 * has both.
	 *
	 * @param array<int, mixed> $fieldTypes
	 * @return array<string, string>
	 */
	private static function codesByKey( array $fieldTypes ): array {
		$codes = [];
		foreach ( $fieldTypes as $fieldType ) {
			if ( ! is_array( $fieldType ) ) {
				continue;
			}
			$key  = sanitize_key( (string) ( $fieldType['key'] ?? '' ) );
			$code = sanitize_key( (string) ( $fieldType['code'] ?? '' ) );
			if ( $key !== '' && $code !== '' ) {
				$codes[ $key ] = $code;
			}
		}
		return $codes;
	}

	/**
	 * A child template's own form (classic editor and Elementor's modal
	 * alike) intentionally omits a dp_columns[] hidden input for any
	 * property whose key also exists on its parent template — see
	 * PropertyTableRenderer::renderColumnMeta()'s $inherited check, which
	 * only renders dp_all_columns[] (used for building groups[].fields
	 * below, via $effectiveFieldTypes) for such a column, not dp_columns[]
	 * (used for $fieldTypes, i.e. what actually gets PERSISTED). Since
	 * $rawCols therefore never contains those keys at all, every save of a
	 * child template was silently dropping every parent-shared property
	 * from its own stored field_types[] — confirmed live on a real
	 * template (a child of another template sharing items/text/
	 * thumbnail keys): after one save, field_types[] shrank to just the
	 * template's own genuinely-new property, even though groups[].fields
	 * kept every value intact (built from the complete $rawAllCols, not
	 * the pruned $rawCols).
	 *
	 * Restores every parent-shared key and reorders the result to match
	 * the convention every editor UI already follows when displaying a
	 * child's properties: inherited (parent-shared) properties first, in
	 * the parent's own order, followed by this template's own properties
	 * in their own order — regardless of whether a given shared key was
	 * actually present in $fieldTypes already (e.g. an older save) or had
	 * to be restored here, the position is always driven by the parent's
	 * order, not by submission order.
	 *
	 * A restored entry's "code" is corrected to match whatever this
	 * template's OWN groups already store for that key, when they have
	 * one — the parent's own code for the same key is not necessarily
	 * identical (confirmed live: a legacy-converted child template whose
	 * groups had used one spelling of a field code for years, while its parent used
	 * a slightly different spelling for the same key). Blindly taking the parent's
	 * code here would silently break every widget/token that resolves
	 * this field BY CODE (see PageFieldResolver::getFieldByCode())
	 * even though the field's own VALUE was never touched — only a
	 * template with no groups of its own yet (nothing to derive a code
	 * from) actually ends up using the parent's code, which is the
	 * correct behavior for a genuinely brand-new inherited property.
	 *
	 * @param array<int, array<string, mixed>> $fieldTypes
	 * @param array<int, mixed>                $existingGroups
	 * Public so RowSprout Pro's MCP PropertyTools::listTemplateProperties()
	 * can reuse the exact same reconciliation for a pure READ (never writes
	 * anything back) — a child template's own stored field_types[] only
	 * ever gets backfilled with a property the parent has gained since by
	 * going through this method at SAVE time (see the rest of this
	 * docblock); a child that hasn't been resaved since would otherwise
	 * report an incomplete property list to a caller reading it directly,
	 * confirmed live via an MCP client.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function mergeInheritedFieldTypes( array $fieldTypes, int $postId, array $existingGroups = [] ): array {
		if ( $postId <= 0 ) {
			return $fieldTypes;
		}

		$parentId = wp_get_post_parent_id( $postId );
		if ( ! $parentId ) {
			return $fieldTypes;
		}

		$parentFieldTypes = (array) ( TemplateMeta::get( $parentId )['field_types'] ?? [] );
		if ( empty( $parentFieldTypes ) ) {
			return $fieldTypes;
		}

		$ownByKey    = [];
		$ownCodeByKey = [];
		foreach ( $fieldTypes as $fieldType ) {
			if ( is_array( $fieldType ) && isset( $fieldType['key'] ) ) {
				$ownByKey[ (string) $fieldType['key'] ] = $fieldType;
			}
		}
		foreach ( $existingGroups as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
				continue;
			}
			foreach ( $group['fields'] as $fieldKey => $field ) {
				$code = is_array( $field ) ? (string) ( $field['code'] ?? '' ) : '';
				if ( $code !== '' && ! isset( $ownCodeByKey[ $fieldKey ] ) ) {
					$ownCodeByKey[ (string) $fieldKey ] = $code;
				}
			}
		}

		$ordered  = [];
		$usedKeys = [];

		// Parent-shared keys first, in the parent's own order. Prefer this
		// template's own submitted entry for a shared key when it exists
		// (its label/options may legitimately differ), else fall back to
		// the parent's — the "restore what the form omitted" case — but
		// always keep THIS template's own established code for that key
		// when its groups already have one.
		foreach ( $parentFieldTypes as $parentFieldType ) {
			if ( ! is_array( $parentFieldType ) || ! isset( $parentFieldType['key'] ) ) {
				continue;
			}
			$key      = (string) $parentFieldType['key'];
			$restored = $ownByKey[ $key ] ?? $parentFieldType;
			if ( isset( $ownCodeByKey[ $key ] ) ) {
				$restored['code'] = $ownCodeByKey[ $key ];
			}
			$ordered[]  = $restored;
			$usedKeys[] = $key;
		}

		// This template's own remaining, non-parent-shared properties,
		// in their own submitted order.
		foreach ( $fieldTypes as $fieldType ) {
			$key = is_array( $fieldType ) ? (string) ( $fieldType['key'] ?? '' ) : '';
			if ( $key === '' || in_array( $key, $usedKeys, true ) ) {
				continue;
			}
			$ordered[] = $fieldType;
		}

		return $ordered;
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 * @param array<string, string> $aliasMap Out parameter (by reference,
	 *        merged into rather than reset — callers that invoke this more
	 *        than once, e.g. build()'s own $rawCols/$rawAllCols pair, keep
	 *        every collision found across both calls): dropped key =>
	 *        surviving key for every label collision found below. Two
	 *        columns can legitimately end up with the same label but
	 *        different keys — most commonly a property independently added
	 *        to both a child template and its parent (each gets its own
	 *        key; the sync-from-parent JS only recognizes a column as
	 *        already-inherited by matching KEY, not label, see
	 *        groups-metabox.js's applyParentSyncPayload()) — and buildGroups()
	 *        needs this map to recover a group's existing value from
	 *        whichever key it actually happens to be stored under, instead
	 *        of silently reporting it blank just because the winning key's
	 *        own value happens to be empty (confirmed live: this silently
	 *        wiped 26 groups' worth of SEO title/description
	 *        values on a real template).
	 * @return array<int, array<string, mixed>>
	 */
	private static function buildFieldTypes( array $columns, array $storedCodes, array $parentCodes, array &$aliasMap = [] ): array {
		$fieldTypes = [];
		$seenTitles = [];
		// A property that already has a code keeps it, whatever its label says
		// now: the placeholders in the template use that code. A new property
		// keeps the code the form showed for it (groups-metabox.js derives it
		// from the label), or one derived here, made unique against the codes
		// still in use so two properties never share a placeholder. "In use"
		// means the properties in this submission plus the parent's, as in the
		// browser: a property deleted before saving frees its code there, so
		// it is free here too, or the token on screen would not be the one
		// stored.
		$usedCodes = array_fill_keys( array_values( $parentCodes ), true );
		foreach ( $columns as $col ) {
			$colKey = is_array( $col ) ? sanitize_key( (string) ( $col['key'] ?? ( $col['type'] ?? '' ) ) ) : '';
			if ( isset( $storedCodes[ $colKey ] ) ) {
				$usedCodes[ $storedCodes[ $colKey ] ] = true;
			}
		}

		foreach ( $columns as $col ) {
			if ( ! is_array( $col ) ) {
				continue;
			}
			$type       = sanitize_key( (string) ( $col['type'] ?? '' ) );
			$key        = sanitize_key( (string) ( $col['key'] ?? $type ) );
			$definition = TemplateMeta::getFieldTypeDefinition( $type );
			$label      = sanitize_text_field( (string) ( $col['label'] ?? ( $definition['label'] ?? $type ) ) );
			if ( $type === '' || $key === '' ) {
				continue;
			}
			$titleKey = mb_strtolower( trim( $label ) );
			if ( $titleKey === '' ) {
				continue;
			}
			if ( isset( $seenTitles[ $titleKey ] ) ) {
				if ( $key !== $seenTitles[ $titleKey ] ) {
					$aliasMap[ $key ] = $seenTitles[ $titleKey ];
				}
				continue;
			}
			$seenTitles[ $titleKey ] = $key;
			if ( isset( $storedCodes[ $key ] ) ) {
				$code = $storedCodes[ $key ];
			} else {
				$code = SavePayloadSanitizer::codeFromText( (string) ( $col['code'] ?? '' ) );
				if ( $code === '' ) {
					$code = SavePayloadSanitizer::generateCodeFromTitle( $label, (string) ( $definition['default_code'] ?? $type ) );
				}
				$code = SavePayloadSanitizer::uniqueCode( $code, $usedCodes );
			}
			$locked                  = in_array( $type, [ 'title', 'href', 'slug' ], true );
			$required                = (bool) ( $col['required'] ?? ( $definition['required'] ?? false ) );
			$fieldTypes[]            = [
				'key'              => $key,
				'type'             => $type,
				'label'            => $label,
				'code'             => $code,
				'field_type'       => sanitize_key( (string) ( $col['field_type'] ?? ( $definition['type'] ?? 'text' ) ) ),
				'options'          => SavePayloadSanitizer::normalizeOptions( $col['options'] ?? ( $definition['default_options'] ?? [] ) ),
				'can_be_overruled' => $locked ? false : (bool) ( $col['can_be_overruled'] ?? true ),
				'required'         => $required,

			];
		}

		return $fieldTypes;
	}

	/**
	 * @param array<int, array<string, mixed>> $rawRows
	 * @param array<int, array<string, mixed>> $effectiveFieldTypes
	 * @param array<string, mixed> $existingConfig
	 * @param array<string, string> $aliasMap See buildFieldTypes()'s own
	 *        docblock — dropped key => surviving key, for every label
	 *        collision this save's field-type submission had.
	 * @return array<int, array<string, mixed>>
	 */
	private static function buildGroups( array $rawRows, array $effectiveFieldTypes, array $existingConfig, array $aliasMap = [] ): array {
		$newGroups   = [];
		$rowNum      = 1;
		$usedGroupIds = [];

		foreach ( $rawRows as $rawRow ) {
			if ( ! is_array( $rawRow ) ) {
				continue;
			}

			$rawFields = isset( $rawRow['fields'] ) && is_array( $rawRow['fields'] ) ? $rawRow['fields'] : [];
			$parentId  = absint( $rawRow['parent_id'] ?? 0 );

			// A group linked to a parent-template group (parent_id > 0) is
			// always meaningful even when every one of its own overridable
			// fields is left blank: title/href come from the parent, so an
			// otherwise-empty inherited group is a legitimate "just use the
			// parent's values" row, not an unused placeholder to discard.
			if ( $parentId <= 0 ) {
				$hasValue = false;
				foreach ( $rawFields as $v ) {
					if ( is_string( $v ) && trim( $v ) !== '' ) {
						$hasValue = true;
						break;
					}
				}
				if ( ! $hasValue ) {
					continue;
				}
			}

			$id = absint( $rawRow['id'] ?? 0 );
			if ( $id <= 0 ) {
				$id = SavePayloadSanitizer::generateNumericId();
			}
			$id = SavePayloadSanitizer::ensureUniqueId( $id, $usedGroupIds );

			$existingGroupForId = [];
			foreach ( (array) ( $existingConfig['groups'] ?? [] ) as $existingGroup ) {
				if ( is_array( $existingGroup ) && ( $existingGroup['id'] ?? '' ) === $id ) {
					$existingGroupForId = $existingGroup;
					break;
				}
			}

			$fields       = [];
			$usedFieldIds = [];
			foreach ( $effectiveFieldTypes as $col ) {
				$colKey     = (string) $col['key'];
				$colType    = (string) $col['type'];
				$fieldType  = sanitize_key( (string) ( $col['field_type'] ?? 'text' ) );
				$colCode    = (string) ( $col['code'] ?? $colKey );
				$colOptions = SavePayloadSanitizer::normalizeOptions( $col['options'] ?? [] );

				// Deliberately NOT falling back to this group's existing stored
				// value when $colKey is missing from $rawFields — a save
				// always persists exactly what this session's form contains,
				// full stop, even if that means overwriting a value set
				// through another path (e.g. the MCP API) since this browser
				// tab last loaded/synced. See PayloadConfigBuilder's own
				// history: an earlier version tried to auto-preserve values
				// the form didn't know about, which fixed the "silently
				// dropped" case but produced its own confusing failure mode
				// (Elementor's "Toepassen" stages a full snapshot including
				// already-known-but-now-stale fields, which are PRESENT —
				// just outdated — and therefore indistinguishable from a
				// deliberate edit). Decided against trying to reconcile this
				// automatically: whichever save happens last wins, and it's
				// the editor's job to not save from a stale tab.
				$raw   = $rawFields[ $colKey ] ?? '';
				$value = SavePayloadSanitizer::sanitizeFieldValue( $colType, $fieldType, $raw, $colOptions );

				$sourceKeyForExisting = $colKey;

				// This save's own submission has nothing for $colKey (the
				// general "trust the form, full stop" rule above still
				// applies whenever it does) — but $colKey only WON this
				// spot because of a label collision (see buildFieldTypes()),
				// meaning this group's real, previously-saved value for the
				// exact same conceptual property may still be sitting under
				// whichever key got discarded. Recovering it here, rather
				// than persisting the blank the form actually submitted,
				// is what stops that collision from silently wiping data
				// the admin never saw or chose to clear.
				if ( $value === '' ) {
					foreach ( $aliasMap as $aliasKey => $survivingKey ) {
						if ( $survivingKey !== $colKey ) {
							continue;
						}
						$priorValue = $existingGroupForId['fields'][ $aliasKey ]['value'] ?? '';
						if ( is_string( $priorValue ) && $priorValue !== '' ) {
							$value                = $priorValue;
							$sourceKeyForExisting = $aliasKey;
							break;
						}
					}
				}

				$existingFieldId = (int) ( $existingGroupForId['fields'][ $sourceKeyForExisting ]['id'] ?? 0 );
				if ( $existingFieldId <= 0 ) {
					$existingFieldId = SavePayloadSanitizer::generateNumericId();
				}

				$existingFieldId = SavePayloadSanitizer::ensureUniqueId( (int) $existingFieldId, $usedFieldIds );

				$fields[ $colKey ] = [
					'type'             => $colType,
					'value'            => $value,
					'code'             => sanitize_key( $colCode ),
					'id'               => $existingFieldId,
					'can_be_overruled' => in_array( $colType, [ 'title', 'href', 'slug' ], true ) ? false : (bool) ( $col['can_be_overruled'] ?? true ),
				];
			}

			$newGroups[] = [
				'index'     => $rowNum,
				'id'        => $id,
				'parent_id' => $parentId,
				'fields'    => $fields,
			];
			$rowNum++;
		}

		return $newGroups;
	}
}
