<?php

namespace RowSprout\Core\Transfer;

use RowSprout\Core\Groups\GroupRepository;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\FieldTypes\FieldTypeManager;
use RowSprout\Core\Template\HrefUniquenessValidator;
use RowSprout\Core\Template\Lifecycle\TemplateSyncMarker;
use RowSprout\Core\Template\SavePayloadSanitizer;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recreates the templates of a TemplateExporter file on this site.
 *
 * Every template becomes a NEW draft: nothing is generated until someone
 * publishes it, which leaves room to check the URL pattern first (importing
 * on the site the file came from gives two templates that produce the same
 * URLs). An add-on can point an entry at an existing template instead
 * (filter rowsprout_template_import_target); that template is updated in
 * place, keeps its status and save action, generates nothing, and only has
 * its affected pages marked outdated. Ids change on the way
 * in, so placeholder tokens and child→parent links are rewritten to the
 * new ids; group ids stay, which keeps a child's groups linked to its
 * parent's.
 *
 * The file is untrusted input: values go through the same sanitizing as a
 * save from the template form, nothing is unserialized, and post meta
 * (page-builder data, which is not filtered the way post content is) is
 * only imported for users who may post unfiltered HTML.
 */
final class TemplateImporter {

	/**
	 * Locked types: their value is the page's title/slug, never overridden
	 * by a child (same list as PayloadConfigBuilder).
	 */
	private const LOCKED_TYPES = [ 'title', 'href', 'slug' ];

	/**
	 * @var array<string, bool> Property type => whether this site knows it.
	 */
	private static $knownTypes = [];

	/**
	 * @param array<string, mixed> $options Import options (see the
	 *        rowsprout_template_import_options filter); passed on to every
	 *        import hook.
	 */
	public static function importJson( string $json, array $options = [] ): ImportResult {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$result = new ImportResult();
			$result->addError( __( 'This file is not a RowSprout export file: it does not contain valid JSON.', 'rowsprout' ) );
			return $result;
		}

		return self::import( $data, $options );
	}

	/**
	 * @param array<string, mixed> $data    A decoded export file.
	 * @param array<string, mixed> $options See importJson().
	 */
	public static function import( array $data, array $options = [] ): ImportResult {
		$result = new ImportResult();

		if ( ( $data['format'] ?? '' ) !== TemplateExporter::FORMAT || ! isset( $data['templates'] ) || ! is_array( $data['templates'] ) ) {
			$result->addError( __( 'This file is not a RowSprout export file.', 'rowsprout' ) );
			return $result;
		}

		$formatVersion = is_numeric( $data['format_version'] ?? null ) ? (int) $data['format_version'] : 0;
		if ( $formatVersion > TemplateExporter::FORMAT_VERSION ) {
			$result->addError( __( 'This export file was made by a newer version of RowSprout. Update RowSprout on this site and try again.', 'rowsprout' ) );
			return $result;
		}
		if ( $formatVersion < 1 ) {
			$result->addError( __( 'This export file is damaged: it has no valid format version.', 'rowsprout' ) );
			return $result;
		}

		$entries = self::readEntries( $data['templates'], $result );
		if ( $entries === [] ) {
			$result->addError( __( 'The export file contains no templates.', 'rowsprout' ) );
			return $result;
		}

		self::$knownTypes = [];
		$unknownTypes     = [];

		// The import writes the templates itself; the template save flow must
		// not queue, mark or refuse anything halfway (an updated template is
		// marked once below, against its config from before the import). By
		// post id is not enough: save_post fires inside wp_insert_post(),
		// before the new id is known here.
		add_filter( 'rowsprout_skip_handle_save', '__return_true' );

		// Two passes: a template's own content holds tokens with its own id,
		// which is only known once the post exists.
		$oldConfigs = [];
		$idMap      = self::createPosts( $entries, $result, $options, $oldConfigs );

		foreach ( $idMap as $sourceId => $newId ) {
			$entry    = $entries[ $sourceId ];
			$isUpdate = isset( $oldConfigs[ $newId ] );
			self::fillTemplate( $newId, $entry, $idMap, $result, $unknownTypes, $options, $isUpdate );
			if ( $isUpdate ) {
				$result->addUpdated( $newId, $sourceId );
			} else {
				$result->addCreated( $newId, $sourceId );
			}

			/**
			 * A template was imported: created as a draft, or an existing
			 * template updated (ImportResult::wasUpdated()). For an add-on that
			 * put data under $entry['extra'] at export time.
			 *
			 * @param int                  $newId
			 * @param array<string, mixed> $entry   The template's entry in the file.
			 * @param array<int, int>      $idMap   Source template id => this site's id, for every template of this import.
			 * @param ImportResult         $result  Add warnings here.
			 * @param array<string, mixed> $options The import options.
			 */
			do_action( 'rowsprout_template_imported', $newId, $entry, $idMap, $result, $options );
		}

		remove_filter( 'rowsprout_skip_handle_save', '__return_true' );

		// Like "Save template only": rows for new groups, changed groups
		// outdated, and nothing deleted (no pruning: a group missing from the
		// file keeps its page until the template is saved).
		foreach ( $oldConfigs as $templateId => $oldConfig ) {
			TemplateSyncMarker::markStaleFromSmallAdjustments( $templateId, $oldConfig, false );
		}

		if ( $unknownTypes !== [] ) {
			$result->addWarning( sprintf(
				current_user_can( 'unfiltered_html' )
					/* translators: %s: comma-separated property types, e.g. "richtext, date". */
					? __( 'These property types are not available on this site: %s. Their values were imported unchanged; activate the plugin that adds them (such as RowSprout Pro) to edit and use them.', 'rowsprout' )
					/* translators: %s: comma-separated property types, e.g. "richtext, date". */
					: __( 'These property types are not available on this site: %s. Their values were imported with only safe HTML kept (your account may not add other HTML), so some markup may be gone; activate the plugin that adds them (such as RowSprout Pro) to edit and use them.', 'rowsprout' ),
				implode( ', ', array_keys( $unknownTypes ) )
			) );
		}

		self::warnAboutSharedUrlPatterns( $idMap, $result );

		/**
		 * The whole import is done.
		 *
		 * @param array<int, int>      $idMap   Source template id => this site's id.
		 * @param array<string, mixed> $data    The decoded export file.
		 * @param ImportResult         $result
		 * @param array<string, mixed> $options The import options.
		 */
		do_action( 'rowsprout_templates_imported', $idMap, $data, $result, $options );

		return $result;
	}

	/**
	 * The file's template entries by source id; malformed ones are skipped.
	 *
	 * @param array<int|string, mixed> $templates
	 * @return array<int, array<string, mixed>>
	 */
	private static function readEntries( array $templates, ImportResult $result ): array {
		$entries = [];
		$skipped = 0;

		foreach ( $templates as $entry ) {
			$sourceId = is_array( $entry ) ? absint( $entry['id'] ?? 0 ) : 0;
			if ( $sourceId <= 0 || isset( $entries[ $sourceId ] ) ) {
				$skipped++;
				continue;
			}
			$entries[ $sourceId ] = $entry;
		}

		if ( $skipped > 0 ) {
			$result->addWarning( sprintf(
				/* translators: %d: number of skipped entries. */
				_n( '%d entry in the file was skipped because it is incomplete.', '%d entries in the file were skipped because they are incomplete.', $skipped, 'rowsprout' ),
				$skipped
			) );
		}

		return $entries;
	}

	/**
	 * Inserts a draft per entry (or picks the existing template an add-on
	 * points it at), parents first so a child can be linked to its parent's
	 * new id.
	 *
	 * @param array<int, array<string, mixed>> $entries
	 * @param array<string, mixed>             $options
	 * @param array<int, array<string, mixed>> $oldConfigs Out: updated template id => its config before the import.
	 * @return array<int, int> Source id => this site's id.
	 */
	private static function createPosts( array $entries, ImportResult $result, array $options, array &$oldConfigs ): array {
		$roots    = [];
		$children = [];
		foreach ( $entries as $sourceId => $entry ) {
			$parent = absint( $entry['parent'] ?? 0 );
			if ( $parent > 0 && isset( $entries[ $parent ] ) && absint( $entries[ $parent ]['parent'] ?? 0 ) === 0 ) {
				$children[ $sourceId ] = $parent;
			} else {
				$roots[ $sourceId ] = $parent;
			}
		}

		$idMap = [];
		foreach ( $roots + $children as $sourceId => $parent ) {
			$entry = $entries[ $sourceId ];
			$title = self::title( $entry );

			$targetId = self::existingTarget( $entry, $options );
			if ( $targetId > 0 ) {
				$oldConfigs[ $targetId ] = TemplateMeta::get( $targetId );
				$idMap[ $sourceId ]      = $targetId;
				continue;
			}

			$newParent = isset( $children[ $sourceId ] ) ? ( $idMap[ $parent ] ?? 0 ) : 0;
			if ( $parent > 0 && $newParent === 0 ) {
				$result->addWarning( sprintf(
					/* translators: %s: template title. */
					__( '"%s" is a child template, but its parent template could not be imported with it. It was imported as a template without a parent.', 'rowsprout' ),
					$title
				) );
			}

			$newId = wp_insert_post( wp_slash( [
				'post_type'   => PostTypes::TEMPLATE,
				'post_title'  => $title,
				'post_status' => 'draft',
				'post_parent' => $newParent,
				'menu_order'  => (int) ( $entry['menu_order'] ?? 0 ),
			] ), true );

			if ( is_wp_error( $newId ) || (int) $newId <= 0 ) {
				$result->addError( sprintf(
					/* translators: 1: template title, 2: error message. */
					__( '"%1$s" could not be imported: %2$s', 'rowsprout' ),
					$title,
					is_wp_error( $newId ) ? $newId->get_error_message() : __( 'unknown error', 'rowsprout' )
				) );
				continue;
			}

			$idMap[ $sourceId ] = (int) $newId;
		}

		return $idMap;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $options
	 */
	private static function existingTarget( array $entry, array $options ): int {
		/**
		 * An existing template to update with this entry instead of creating
		 * a new one (0 = create). It must be a template the user can edit;
		 * anything else is ignored.
		 *
		 * @param int                  $templateId
		 * @param array<string, mixed> $entry   The template's entry in the file.
		 * @param array<string, mixed> $options The import options.
		 */
		$targetId = (int) apply_filters( 'rowsprout_template_import_target', 0, $entry, $options );
		if ( $targetId <= 0 ) {
			return 0;
		}

		$post = get_post( $targetId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) || ! current_user_can( 'edit_post', $targetId ) ) {
			return 0;
		}

		return $targetId;
	}

	/**
	 * The attachment on this site for a media URL from an import file, or 0.
	 * Media is not part of the file; by default only a file that is already
	 * in this site's media library is found (as on the site the export came
	 * from).
	 *
	 * @param array<string, mixed> $options
	 */
	public static function findAttachment( string $url, int $templateId, array $options ): int {
		$url = esc_url_raw( $url );
		if ( $url === '' ) {
			return 0;
		}

		/**
		 * The attachment for a media URL from an import file. An add-on can
		 * download a missing file here.
		 *
		 * @param int                  $attachmentId 0 when this site has no such file.
		 * @param string               $url
		 * @param int                  $templateId   The template being imported.
		 * @param array<string, mixed> $options      The import options.
		 */
		return (int) apply_filters( 'rowsprout_template_import_attachment_id', (int) attachment_url_to_postid( $url ), $url, $templateId, $options );
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<int, int>      $idMap
	 * @param array<string, bool>  $unknownTypes
	 * @param array<string, mixed> $options
	 */
	private static function fillTemplate( int $newId, array $entry, array $idMap, ImportResult $result, array &$unknownTypes, array $options, bool $isUpdate ): void {
		/**
		 * The template ids whose placeholder tokens are rewritten in this
		 * template (old id => new id). By default every template of the
		 * import; an add-on can map ids that only it knows, such as the ids
		 * of translations that resolve as aliases.
		 *
		 * @param array<int, int>      $idMap
		 * @param array<string, mixed> $entry
		 * @param int                  $newId
		 */
		$map = (array) apply_filters( 'rowsprout_template_import_id_map', $idMap, $entry, $newId );

		$postUpdate = [
			'ID'           => $newId,
			'post_title'   => PlaceholderTokenIds::remap( self::title( $entry ), $map ),
			'post_content' => PlaceholderTokenIds::remap( self::text( $entry['content'] ?? '' ), $map ),
			'post_excerpt' => PlaceholderTokenIds::remap( self::text( $entry['excerpt'] ?? '' ), $map ),
		];
		// An updated template follows its parent when that came along in the
		// file, and keeps its own parent otherwise.
		$sourceParent = absint( $entry['parent'] ?? 0 );
		if ( $isUpdate && $sourceParent > 0 && isset( $idMap[ $sourceParent ] ) ) {
			$postUpdate['post_parent'] = $idMap[ $sourceParent ];
		}

		$updated = wp_update_post( wp_slash( $postUpdate ), true );
		if ( is_wp_error( $updated ) ) {
			$result->addWarning( sprintf(
				/* translators: 1: template title, 2: error message. */
				__( 'The content of "%1$s" could not be imported: %2$s', 'rowsprout' ),
				self::title( $entry ),
				$updated->get_error_message()
			) );
		}

		$config = self::sanitizeConfig( PlaceholderTokenIds::remapRecursive( $entry['config'] ?? [], $map ), $unknownTypes );

		/**
		 * The config about to be stored for an imported template (already
		 * sanitized and with token ids rewritten). For an updated template the
		 * stored config is still the one from before the import here, so an
		 * add-on can, for example, merge a partial export into it.
		 *
		 * @param array<string, mixed> $config
		 * @param array<string, mixed> $entry    The template's entry in the file.
		 * @param int                  $newId    The template on this site.
		 * @param bool                 $isUpdate Whether an existing template is updated.
		 * @param array<string, mixed> $options  The import options.
		 */
		$filtered = apply_filters( 'rowsprout_template_import_config', $config, $entry, $newId, $isUpdate, $options );
		$config   = is_array( $filtered ) ? $filtered : $config;
		if ( $isUpdate ) {
			$config = self::keepEquivalentValues( $config, TemplateMeta::get( $newId ) );
		}
		TemplateMeta::save( $newId, $config );

		if ( ! empty( HrefUniquenessValidator::findDuplicateGroups( TemplateMeta::get( $newId ) ) ) ) {
			$result->addWarning( sprintf(
				/* translators: %s: template title. */
				__( 'In "%s" two or more groups share the same URL. Make every URL unique before you publish it.', 'rowsprout' ),
				self::title( $entry )
			) );
		}

		self::importMeta( $newId, $entry, $map, $result );
		self::importFeaturedImage( $newId, $entry, $result, $options );

		// An updated template keeps its own save action. For a new one, an
		// action this site doesn't offer falls back to the default when the
		// template is saved (SavePost::readTemplateSaveAction()).
		$saveAction = sanitize_key( self::text( $entry['save_action'] ?? '' ) );
		if ( ! $isUpdate && $saveAction !== '' ) {
			update_post_meta( $newId, PostMetaKeys::SAVE_ACTION, $saveAction );
		}
	}

	/**
	 * The template's config, cleaned the way a save from the template form
	 * cleans it: every group value through its property type's sanitize().
	 * A value of a type this site doesn't have (an add-on that isn't active
	 * here) is kept rather than flattened to plain text, so activating the
	 * add-on later finds it intact.
	 *
	 * @param mixed               $raw
	 * @param array<string, bool> $unknownTypes
	 * @return array<string, mixed>
	 */
	private static function sanitizeConfig( $raw, array &$unknownTypes ): array {
		$raw        = is_array( $raw ) ? $raw : [];
		$fieldTypes = array_values( array_filter( (array) ( $raw['field_types'] ?? [] ), 'is_array' ) );

		$properties = [];
		foreach ( $fieldTypes as $fieldType ) {
			$type = sanitize_key( self::text( $fieldType['type'] ?? '' ) );
			$key  = sanitize_key( self::text( $fieldType['key'] ?? $type ) );
			if ( $type === '' || $key === '' ) {
				continue;
			}
			if ( ! self::isKnownType( $type ) ) {
				$unknownTypes[ $type ] = true;
			}
			$properties[ $key ] = [
				'type'       => $type,
				'field_type' => sanitize_key( self::text( $fieldType['field_type'] ?? '' ) ),
				'code'       => sanitize_key( self::text( $fieldType['code'] ?? '' ) ),
				'options'    => SavePayloadSanitizer::normalizeOptions( $fieldType['options'] ?? [] ),
			];
		}

		return [
			'rowsprout_page_href' => self::text( $raw['rowsprout_page_href'] ?? '' ),
			'field_types'         => $fieldTypes,
			'groups'              => self::sanitizeGroups( (array) ( $raw['groups'] ?? [] ), $properties, $unknownTypes ),
			// Legacy data of the pre-rewrite plugin, never exported.
			'child_extra'         => [],
		];
	}

	/**
	 * @param array<int|string, mixed>            $rawGroups
	 * @param array<string, array<string, mixed>> $properties Property key => type/field_type/code/options.
	 * @param array<string, bool>                 $unknownTypes
	 * @return array<int, array<string, mixed>>
	 */
	private static function sanitizeGroups( array $rawGroups, array $properties, array &$unknownTypes ): array {
		$groups       = [];
		$usedGroupIds = [];

		foreach ( $rawGroups as $rawGroup ) {
			if ( ! is_array( $rawGroup ) ) {
				continue;
			}

			// Group ids are kept: a child template's groups point at its
			// parent's groups by id (parent_id).
			$id = absint( $rawGroup['id'] ?? 0 );
			$id = SavePayloadSanitizer::ensureUniqueId( $id > 0 ? $id : SavePayloadSanitizer::generateNumericId(), $usedGroupIds );

			$fields       = [];
			$usedFieldIds = [];
			foreach ( (array) ( $rawGroup['fields'] ?? [] ) as $rawKey => $rawField ) {
				$key = sanitize_key( (string) $rawKey );
				if ( $key === '' || ! is_array( $rawField ) ) {
					continue;
				}

				// A declared property decides the field's type and code, as in a
				// save from the template form; the field's own entry only counts
				// for a key without a property.
				$property = $properties[ $key ] ?? [];
				$type     = (string) ( $property['type'] ?? '' );
				if ( $type === '' ) {
					$type = sanitize_key( self::text( $rawField['type'] ?? '' ) );
				}
				if ( $type === '' ) {
					$type = 'textfield';
				}
				if ( ! self::isKnownType( $type ) ) {
					$unknownTypes[ $type ] = true;
				}

				$fieldId = absint( $rawField['id'] ?? 0 );

				$fields[ $key ] = [
					'type'             => $type,
					'value'            => self::sanitizeValue( $type, (string) ( $property['field_type'] ?? '' ), $rawField['value'] ?? '', (array) ( $property['options'] ?? [] ) ),
					'code'             => ( $property['code'] ?? '' ) !== '' ? $property['code'] : sanitize_key( self::text( $rawField['code'] ?? $key ) ),
					'id'               => SavePayloadSanitizer::ensureUniqueId( $fieldId > 0 ? $fieldId : SavePayloadSanitizer::generateNumericId(), $usedFieldIds ),
					'can_be_overruled' => in_array( $type, self::LOCKED_TYPES, true ) ? false : (bool) ( $rawField['can_be_overruled'] ?? true ),
				];
			}

			$groups[] = [
				'index'     => count( $groups ) + 1,
				'id'        => $id,
				'parent_id' => absint( $rawGroup['parent_id'] ?? 0 ),
				'fields'    => $fields,
			];
		}

		return $groups;
	}

	/**
	 * An updated template keeps a stored value that the import's sanitizing
	 * turns into the value from the file (a trailing space, line endings):
	 * otherwise the change detection sees a change nobody made and outdates
	 * the group.
	 *
	 * @param array<string, mixed> $config  The config about to be stored.
	 * @param array<string, mixed> $current The template's config before the import.
	 * @return array<string, mixed>
	 */
	private static function keepEquivalentValues( array $config, array $current ): array {
		$properties = [];
		foreach ( (array) ( $config['field_types'] ?? [] ) as $fieldType ) {
			if ( is_array( $fieldType ) && isset( $fieldType['key'] ) ) {
				$properties[ (string) $fieldType['key'] ] = $fieldType;
			}
		}
		$currentGroups = [];
		foreach ( (array) ( $current['groups'] ?? [] ) as $group ) {
			if ( is_array( $group ) && isset( $group['id'] ) ) {
				$currentGroups[ (string) $group['id'] ] = $group;
			}
		}

		foreach ( (array) ( $config['groups'] ?? [] ) as $g => $group ) {
			$old = $currentGroups[ (string) ( $group['id'] ?? '' ) ] ?? null;
			if ( ! is_array( $group ) || $old === null ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? [] ) as $key => $field ) {
				$oldField = $old['fields'][ $key ] ?? null;
				if ( ! is_array( $field ) || ! is_array( $oldField ) || ( $oldField['type'] ?? '' ) !== ( $field['type'] ?? '' ) || ! is_scalar( $oldField['value'] ?? null ) ) {
					continue;
				}
				$oldValue = (string) $oldField['value'];
				$newValue = (string) ( $field['value'] ?? '' );
				if ( $oldValue === $newValue ) {
					continue;
				}
				$property  = $properties[ (string) $key ] ?? [];
				$sanitized = self::sanitizeValue(
					(string) $field['type'],
					sanitize_key( self::text( $property['field_type'] ?? '' ) ),
					$oldValue,
					SavePayloadSanitizer::normalizeOptions( $property['options'] ?? [] )
				);
				if ( $sanitized === $newValue ) {
					$config['groups'][ $g ]['fields'][ $key ]['value'] = $oldValue;
				}
			}
		}

		return $config;
	}

	/**
	 * @param mixed              $raw
	 * @param array<int, string> $options
	 */
	private static function sanitizeValue( string $type, string $fieldType, $raw, array $options ): string {
		if ( self::isKnownType( $type ) ) {
			return FieldTypeManager::sanitize( $type, $fieldType, $raw, $options );
		}

		$value = is_scalar( $raw ) ? (string) $raw : '';

		return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
	}

	private static function isKnownType( string $type ): bool {
		if ( ! isset( self::$knownTypes[ $type ] ) ) {
			self::$knownTypes[ $type ] = FieldTypeManager::find( $type ) !== null;
		}

		return self::$knownTypes[ $type ];
	}

	/**
	 * Post meta holds page-builder data (Elementor's _elementor_data and the
	 * like), which WordPress does not filter the way it filters post
	 * content. Importing it is therefore the same trust decision as letting
	 * the user write raw HTML: users without unfiltered_html get the
	 * template without it.
	 *
	 * @param array<string, mixed> $entry
	 * @param array<int, int>      $map
	 */
	private static function importMeta( int $newId, array $entry, array $map, ImportResult $result ): void {
		$meta = isset( $entry['meta'] ) && is_array( $entry['meta'] ) ? $entry['meta'] : [];
		if ( $meta === [] ) {
			return;
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$result->addWarning( sprintf(
				/* translators: %s: template title. */
				__( 'The page-builder data and other settings of "%s" were not imported: your account is not allowed to add unfiltered HTML. Ask an administrator to import this file.', 'rowsprout' ),
				self::title( $entry )
			) );
			return;
		}

		$excluded = TemplateExporter::excludedMetaKeys();
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( $key === '' || strlen( $key ) > 255 || GroupRepository::keyMatches( $key, $excluded ) ) {
				continue;
			}

			$values = array_values( array_map(
				static function ( $value ) use ( $map ) {
					return PlaceholderTokenIds::remapRecursive( $value, $map );
				},
				is_array( $values ) ? $values : [ $values ]
			) );

			// An updated template: a key that already holds these values is
			// left alone. Rewriting it anyway reads as a change to change
			// tracking (RowSprout Pro's Smart Generate), which would then mark
			// every page outdated.
			if ( self::comparable( get_post_meta( $newId, $key ) ) === self::comparable( $values ) ) {
				continue;
			}

			delete_post_meta( $newId, $key );
			foreach ( $values as $value ) {
				add_post_meta( $newId, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Meta values as stored would read them back: scalars as strings (the
	 * file has real numbers and booleans, the database only strings).
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function comparable( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'comparable' ], $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * See findAttachment(). A featured image that cannot be found leaves the
	 * template's current one (an updated template) or none.
	 *
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $options
	 */
	private static function importFeaturedImage( int $newId, array $entry, ImportResult $result, array $options ): void {
		$url = is_array( $entry['featured_image'] ?? null ) ? esc_url_raw( self::text( $entry['featured_image']['url'] ?? '' ) ) : '';
		if ( $url === '' ) {
			return;
		}

		$attachmentId = self::findAttachment( $url, $newId, $options );
		if ( $attachmentId > 0 ) {
			set_post_thumbnail( $newId, $attachmentId );
			return;
		}

		$result->addWarning( sprintf(
			/* translators: 1: template title, 2: image URL. */
			__( 'The featured image of "%1$s" is not in this site\'s media library (%2$s). Upload it and set it again.', 'rowsprout' ),
			self::title( $entry ),
			$url
		) );
	}

	/**
	 * Two templates with the same URL pattern generate pages with the same
	 * URLs. Patterns are compared without the template ids in their tokens.
	 *
	 * @param array<int, int> $idMap
	 */
	private static function warnAboutSharedUrlPatterns( array $idMap, ImportResult $result ): void {
		$existing = [];
		$others   = get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );
		foreach ( $others as $otherId ) {
			if ( in_array( (int) $otherId, $idMap, true ) ) {
				continue;
			}
			$pattern = PlaceholderTokenIds::strip( TemplateMeta::getHref( (int) $otherId ) );
			if ( $pattern !== '' ) {
				$existing[ $pattern ] = (int) $otherId;
			}
		}

		foreach ( $idMap as $newId ) {
			$pattern = PlaceholderTokenIds::strip( TemplateMeta::getHref( $newId ) );
			if ( $pattern === '' || ! isset( $existing[ $pattern ] ) ) {
				continue;
			}

			$result->addWarning( sprintf(
				/* translators: 1: imported template title, 2: existing template title. */
				__( '"%1$s" has the same URL pattern as the existing template "%2$s". Change one of them before publishing, or their pages get the same URLs.', 'rowsprout' ),
				get_the_title( $newId ),
				get_the_title( $existing[ $pattern ] )
			) );
		}
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function title( array $entry ): string {
		$title = sanitize_text_field( self::text( $entry['title'] ?? '' ) );

		return $title !== '' ? $title : __( 'Imported template', 'rowsprout' );
	}

	/**
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
