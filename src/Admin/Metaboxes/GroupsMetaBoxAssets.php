<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupsMetaBoxAssets {

	private const STYLE_HANDLE  = 'rowsprout-groups-metabox';
	private const SCRIPT_HANDLE = 'rowsprout-groups-metabox';

	public static function renderStylesheetLink(): void {
		$suffix       = self::useUnminifiedAssets() ? '' : '.min';
		$relativePath = 'assets/css/groups-metabox' . $suffix . '.css';
		$styleFile    = ROWSPROUT_PATH . $relativePath;
		$styleUrl     = plugins_url( $relativePath, ROWSPROUT_FILE );
		$version      = file_exists( $styleFile ) ? (string) filemtime( $styleFile ) : '1';

		// Called from the metabox render callback rather than an
		// admin_enqueue_scripts hook: postId/field-type definitions are only
		// known once the post-edit screen actually renders the metabox. This
		// still works correctly — wp_enqueue_style() only needs to run before
		// the admin_print_styles/footer hooks print the queue, which happens
		// well after metaboxes have rendered.
		wp_enqueue_style( self::STYLE_HANDLE, $styleUrl, [], $version );
	}

	public static function renderScriptLoader( int $postId, array $fieldTypeDefinitions ): void {
		$suffix       = self::useUnminifiedAssets() ? '' : '.min';
		$relativePath = 'assets/js/groups-metabox' . $suffix . '.js';
		$scriptFile   = ROWSPROUT_PATH . $relativePath;
		$scriptUrl    = plugins_url( $relativePath, ROWSPROUT_FILE );
		$version      = file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : '1';

		$config = [
			'postId'             => $postId,
			// Server-computed, reliable regardless of which editing UI is
			// rendering this: the JS's "sync from parent" auto-trigger on
			// load used to read this from WP core's native Page Attributes
			// #parent_id <select> instead — present on the classic edit
			// screen, but never rendered in Elementor's own editing canvas
			// (confirmed live: that auto-sync silently never ran there,
			// leaving inherited properties' displayed tokens/values showing
			// this template's own id instead of the parent's, and their
			// group-card inputs not locked read-only either — everything
			// that sync normally does).
			'parentId'           => (int) wp_get_post_parent_id( $postId ),
			'fieldTypes'         => $fieldTypeDefinitions,
			'uiTypeKindMap'      => FieldUiResolver::getClientTypeKindMap(),
			'uiValidationMap'    => FieldUiResolver::getClientValidationMap(),
			'overridableAllowed' => true,
			'i18n'               => [
				'group'                    => __( 'Group', 'rowsprout' ),
				'expand'                   => __( 'Expand', 'rowsprout' ),
				'collapse'                 => __( 'Collapse', 'rowsprout' ),
				'expandAll'                => __( 'Expand all', 'rowsprout' ),
				'collapseAll'              => __( 'Collapse all', 'rowsprout' ),
				'deleteGroup'              => __( 'Delete group', 'rowsprout' ),
				'select'                   => __( 'Select', 'rowsprout' ),
				'remove'                   => __( 'Remove', 'rowsprout' ),
				'yes'                      => __( 'Yes', 'rowsprout' ),
				'stateOverruled'           => __( 'Overridable', 'rowsprout' ),
				'stateFixed'               => __( 'Fixed', 'rowsprout' ),
				'deleteProperty'           => __( 'Delete', 'rowsprout' ),
				'noProperties'             => __( 'No properties added yet.', 'rowsprout' ),
				'cannotLoadParentPayload'  => __( 'Could not load parent template data.', 'rowsprout' ),
				'invalidUrl'               => __( 'Enter a valid URL (http(s)://...)', 'rowsprout' ),
				'invalidEmail'             => __( 'Enter a valid email address.', 'rowsprout' ),
				'typeSingleInstance'       => __( 'This type can only be added once.', 'rowsprout' ),
				'childGroupAddDisabled'    => __( 'Adding groups is not allowed in child templates.', 'rowsprout' ),
				'duplicatePropertyTitle'   => __( 'This property title already exists in this template.', 'rowsprout' ),
				'duplicateHref'            => __( 'This URL/slug is already used by another group in this template.', 'rowsprout' ),
				'mediaTitle'               => __( 'Select or upload an image', 'rowsprout' ),
				'mediaButton'              => __( 'Use this image', 'rowsprout' ),
				'parentChangeWarning'      => __( 'Changing the parent replaces the current groups with groups inherited from the new parent. Your own group data (titles, hrefs, fields) will be lost. Continue?', 'rowsprout' ),
				'parentRemoveWarning'      => __( 'Removing the parent resets the groups to a single empty group. Your current group data (titles, hrefs, fields) will be lost. Continue?', 'rowsprout' ),
			],
		];

		wp_enqueue_media();
		// wp-hooks: the groups table's extension points for add-on property types
		// (filter rowsprout.groupFieldInputHtml, action rowsprout.groupFieldsRendered).
		wp_enqueue_script( self::SCRIPT_HANDLE, $scriptUrl, [ 'jquery', 'wp-hooks' ], $version, true );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.rowsproutGroupsMetaBoxConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	private static function useUnminifiedAssets(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
