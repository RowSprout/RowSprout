<?php

namespace RowSprout\Admin;

use RowSprout\Admin\Metaboxes\PublishBoxRenderer;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\SavePost;
use RowSprout\Core\TemplateMeta;
use RowSprout\Core\Template\InsertableTokenFields;
use RowSprout\Core\BlockBindings\PropertyBindingSource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes templates work in the block editor without a second save flow.
 *
 * The block editor saves in two requests: first the post itself through the
 * REST API, then — for every non-autosave save, because a template always
 * has the "RowSprout template" metabox — every metabox form posted to post.php
 * (edit-post's requestMetaBoxUpdates()). That second request looks exactly
 * like a classic-screen save, so SavePost::handleSave() keeps running there,
 * unchanged:
 *
 * - The REST request carries the header X-RowSprout-Block-Editor (added by
 *   template-block-editor.js through an apiFetch middleware), and
 *   skipDuringBlockEditorRestSave() makes handleSave() skip it. Running it
 *   there too would queue against the not-yet-saved groups and with the
 *   previous save action. Other REST writers (WPML's translation save-back,
 *   any API client) don't send the header and keep the normal behaviour.
 * - The classic Publish box (and with it the save action select) doesn't
 *   exist here. The "Save action" panel in the editor sidebar writes the same
 *   field name (rowsprout_page_save_action) as a hidden input into the
 *   metabox form, so it arrives in $_POST. An add-on adds its own fields and
 *   inputs through the wp.hooks filters described in template-block-editor.js.
 * - The page never reloads after a save, so the metabox keeps the
 *   dp_config_version it was rendered with, and a second save in the same
 *   session would be refused as a stale-form conflict. After each metabox
 *   save the script calls the REST route below, which returns the fresh
 *   version (written back into the form) and the persisted save notices
 *   (shown as editor notices — admin_notices never render here). The
 *   metabox request is answered with a 302 to the edit screen, which the
 *   editor's fetch follows and discards; SavePost::renderPersistedNotices()
 *   therefore leaves notices alone on block editor screens, or that hidden
 *   page load would consume them first.
 */
final class TemplateBlockEditor {

	public const HEADER = 'X-RowSprout-Block-Editor';

	private const REST_NAMESPACE = 'rowsprout/v1';

	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue' ] );
		add_filter( 'rowsprout_skip_handle_save', [ self::class, 'skipDuringBlockEditorRestSave' ], 10, 2 );
		add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
	}

	public static function skipDuringBlockEditorRestSave( bool $skip, int $postId ): bool {
		if ( $skip || ! self::isBlockEditorRestRequest() ) {
			return $skip;
		}

		return get_post_type( $postId ) === PostTypes::TEMPLATE;
	}

	/**
	 * Whether this is the block editor's own REST save of a template (the
	 * first of its two save requests).
	 */
	public static function isBlockEditorRestRequest(): bool {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$header = 'HTTP_' . strtoupper( str_replace( '-', '_', self::HEADER ) );

		return ! empty( $_SERVER[ $header ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence check only.
	}

	public static function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/templates/(?P<id>\d+)/after-save',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'afterSave' ],
				'permission_callback' => static function ( \WP_REST_Request $request ): bool {
					$postId = (int) $request['id'];
					return get_post_type( $postId ) === PostTypes::TEMPLATE && current_user_can( 'edit_post', $postId );
				},
			]
		);
	}

	public static function afterSave( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request['id'];

		return new \WP_REST_Response(
			[
				'configVersion' => (string) ( TemplateMeta::get( $postId )['config_updated_at'] ?? '' ),
				'saveAction'    => (string) get_post_meta( $postId, PostMetaKeys::SAVE_ACTION, true ),
				'notices'       => SavePost::popPersistedNotices(),
				// Properties added in this session become insertable without a reload.
				'tokenFields'   => InsertableTokenFields::forTemplate( $postId ),
				'bindingFields' => PropertyBindingSource::editorFields( $postId ),
			]
		);
	}

	public static function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$postId = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ? (int) $GLOBALS['post']->ID : 0;
		if ( ! $screen || $screen->post_type !== PostTypes::TEMPLATE || $postId <= 0 ) {
			return;
		}

		$relativePath = 'assets/js/template-block-editor.js';
		$scriptFile   = ROWSPROUT_PATH . $relativePath;

		// wp-edit-post is only a fallback source of PluginDocumentSettingPanel
		// (it lives in wp-editor since WP 6.6).
		wp_enqueue_script(
			'rowsprout-template-block-editor',
			plugins_url( $relativePath, ROWSPROUT_FILE ),
			[ 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-notices', 'wp-i18n', 'wp-hooks' ],
			file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : ROWSPROUT_VERSION,
			true
		);

		$actions = [];
		foreach ( PublishBoxRenderer::getTemplateSaveActions() as $key => $config ) {
			$actions[] = [
				'value'       => $key,
				'label'       => $config['label'],
				'description' => $config['description'],
			];
		}

		$stored = (string) get_post_meta( $postId, PostMetaKeys::SAVE_ACTION, true );

		wp_add_inline_script(
			'rowsprout-template-block-editor',
			'window.rowsproutTemplateBlockEditor = ' . wp_json_encode(
				[
					'postId'     => $postId,
					'header'     => self::HEADER,
					'restPath'   => '/' . self::REST_NAMESPACE . '/templates/' . $postId . '/after-save',
					'actions'    => $actions,
					'saveAction' => $stored !== '' ? $stored : 'update_pages',
					'i18n'       => [
						'panelTitle'       => __( 'Save action', 'rowsprout' ),
						'saveAction'       => __( 'Save action', 'rowsprout' ),
						'afterSaveFailed'  => __( 'RowSprout could not refresh the template after saving. Reload the page before saving again.', 'rowsprout' ),
						'invalidFields'    => __( 'Saving is paused: fix the highlighted fields in the RowSprout template box first (for example a duplicate URL or an invalid email address).', 'rowsprout' ),
						'tokenButton'      => __( 'Insert RowSprout property', 'rowsprout' ),
						'tokenAsLink'      => __( 'as link', 'rowsprout' ),
						'tokenAsEmailLink' => __( 'as email link', 'rowsprout' ),
						'tokenEmpty'       => __( 'This template has no properties to insert yet. Add them in the RowSprout template box and save.', 'rowsprout' ),
						'bindingLabel'     => __( 'RowSprout property', 'rowsprout' ),
					],
					'tokenFields'   => InsertableTokenFields::forTemplate( $postId ),
					'bindingFields' => PropertyBindingSource::editorFields( $postId ),
				]
			) . ';',
			'before'
		);

		$stylePath = 'assets/css/template-block-editor.css';
		$styleFile = ROWSPROUT_PATH . $stylePath;
		wp_enqueue_style(
			'rowsprout-template-block-editor',
			plugins_url( $stylePath, ROWSPROUT_FILE ),
			[],
			file_exists( $styleFile ) ? (string) filemtime( $styleFile ) : ROWSPROUT_VERSION
		);

		$bindingsPath = 'assets/js/block-editor-bindings.js';
		$bindingsFile = ROWSPROUT_PATH . $bindingsPath;
		wp_enqueue_script(
			'rowsprout-block-editor-bindings',
			plugins_url( $bindingsPath, ROWSPROUT_FILE ),
			[ 'rowsprout-template-block-editor', 'wp-blocks' ],
			file_exists( $bindingsFile ) ? (string) filemtime( $bindingsFile ) : ROWSPROUT_VERSION,
			true
		);

		$tokenPath = 'assets/js/block-editor-token-button.js';
		$tokenFile = ROWSPROUT_PATH . $tokenPath;
		wp_enqueue_script(
			'rowsprout-block-editor-token-button',
			plugins_url( $tokenPath, ROWSPROUT_FILE ),
			[ 'rowsprout-template-block-editor', 'wp-rich-text', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-hooks' ],
			file_exists( $tokenFile ) ? (string) filemtime( $tokenFile ) : ROWSPROUT_VERSION,
			true
		);
	}
}
