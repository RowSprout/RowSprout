<?php

namespace RowSprout\Core;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Template\HrefPatternValidator;
use RowSprout\Core\Template\HrefUniquenessValidator;
use RowSprout\Core\Template\Lifecycle\TemplateSyncMarker;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\PayloadConfigBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SavePost {

	public static function register(): void {
		add_action( 'save_post_' . PostTypes::TEMPLATE, [ self::class, 'handleSave' ], 10, 1 );
		// Before the post is written, so every editor (classic, block editor,
		// Elementor, REST) is covered and handleSave() never sees an invalid parent.
		add_filter( 'wp_insert_post_data', [ self::class, 'limitPostDepth' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'renderPersistedNotices' ] );
	}

	/**
	 * Save_post handlers run just before WordPress redirects to the edit screen,
	 * so admin_notices added during that request never get a chance to render.
	 * Persist them in a transient and show them on the next page load instead.
	 */
	public static function renderPersistedNotices(): void {
		// Never on a block editor screen: it doesn't show admin_notices, and
		// its metabox save is answered with a redirect to this very screen,
		// which the editor follows in the background and discards — popping
		// here would swallow the notices before TemplateBlockEditor's
		// after-save route can hand them to the editor.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		foreach ( self::popPersistedNotices() as $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	/**
	 * Returns and clears the current user's persisted save notices. Shared by
	 * the classic screen (renderPersistedNotices(), next page load) and the
	 * block editor, which never reloads after a save and fetches them
	 * through TemplateBlockEditor's REST route instead.
	 *
	 * @return array<int, array{type:string, message:string}> type is warning|error
	 */
	public static function popPersistedNotices(): array {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return [];
		}

		$sources = [
			[ self::hrefDuplicateNoticeTransientKey( $userId ), 'error', '' ],
			[ self::parentNotGeneratedNoticeTransientKey( $userId ), 'error', '' ],
			[ self::configConflictNoticeTransientKey( $userId ), 'warning', '' ],
			[ self::hrefTokenMissingNoticeTransientKey( $userId ), 'warning', '' ],
			[ self::parentRejectedNoticeTransientKey( $userId ), 'warning', '' ],
		];

		$notices = [];
		foreach ( $sources as [ $key, $type, $prefix ] ) {
			$message = get_transient( $key );
			if ( is_string( $message ) && $message !== '' ) {
				delete_transient( $key );
				$notices[] = [
					'type'    => $type,
					'message' => $prefix . $message,
				];
			}
		}

		return $notices;
	}

	private static function hrefDuplicateNoticeTransientKey( int $userId ): string {
		return 'rowsprout_href_duplicate_notice_' . $userId;
	}

	private static function parentNotGeneratedNoticeTransientKey( int $userId ): string {
		return 'rowsprout_parent_not_generated_notice_' . $userId;
	}

	private static function configConflictNoticeTransientKey( int $userId ): string {
		return 'rowsprout_config_conflict_notice_' . $userId;
	}

	private static function parentRejectedNoticeTransientKey( int $userId ): string {
		return 'rowsprout_parent_rejected_notice_' . $userId;
	}

	private static function hrefTokenMissingNoticeTransientKey( int $userId ): string {
		return 'rowsprout_href_token_missing_notice_' . $userId;
	}

	/**
	 * A warning, not a block: the config is saved as-is. See
	 * HrefPatternValidator for why a pattern without the href token is
	 * almost always a mistake.
	 */
	private static function persistHrefTokenMissingNotice( int $postId ): void {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return;
		}

		set_transient(
			self::hrefTokenMissingNoticeTransientKey( $userId ),
			HrefPatternValidator::missingTokenMessage( $postId ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Server-side backstop for the live JS check in groups-metabox.js — a
	 * rare last-resort path (JS disabled, a bypassed submit, a programmatic
	 * save) since the JS already blocks normal form submission on a
	 * collision. Rejects the whole save rather than persisting a config
	 * that would silently produce two generated pages with the identical
	 * post_name (WordPress's own slug-dedup safety net is deliberately
	 * removed around generated-page inserts, see PageUpserter.php).
	 */
	private static function persistHrefDuplicateNotice(): void {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return;
		}

		set_transient(
			self::hrefDuplicateNoticeTransientKey( $userId ),
			__( 'This template was not saved: two or more groups share the same URL/slug. Every group\'s URL must be unique — fix the duplicate and save again.', 'rowsprout' ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * A child template's generated pages inherit post_parent plus naam/href/
	 * thumb from the PARENT template's own generated group (see RowSprout
	 * Pro's ChildTemplateInheritance) — matched by looking up a real
	 * generated page for the parent's group. If the parent has never
	 * generated any pages (still sitting on "Save template only"), that
	 * lookup finds nothing and the child's page silently ends up orphaned
	 * (post_parent = 0, no inherited naam/href/thumb) instead of erroring.
	 * Blocking the save here — same pattern as the href-duplicate check
	 * above — surfaces that as a clear message instead of a page that's
	 * quietly wrong.
	 */
	private static function persistParentNotGeneratedNotice(): void {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return;
		}

		set_transient(
			self::parentNotGeneratedNoticeTransientKey( $userId ),
			__( 'This template was not saved: it is a child template, but its parent template has not generated any pages yet. Generate the parent\'s pages first (or set this save action to "Save template only"), then save again.', 'rowsprout' ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Properties and groups weren't saved this time — everything else on
	 * this post (title, content, status) was, since that's WordPress's own
	 * native post save and isn't gated by this check at all. A silent skip
	 * rather than a hard block: the config itself is left exactly as it
	 * already was (untouched, not partially merged), so there's nothing
	 * unsafe about letting the rest of the save through.
	 */
	private static function persistConfigConflictNotice(): void {
		$userId = get_current_user_id();
		if ( ! $userId ) {
			return;
		}

		set_transient(
			self::configConflictNoticeTransientKey( $userId ),
			__( 'Properties and groups were not saved: this template was changed elsewhere (e.g. via the API) after this page was loaded. Reload the page and reapply your property/group changes. (Title, content, and status were saved normally.)', 'rowsprout' ),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * @param array<string, array{disable_schedule:bool,handler_callback:callable|null}> $saveActions
	 */
	private static function childTemplateParentHasNoGeneratedPages( int $postId, array $saveActions, string $saveAction ): bool {
		if ( ! self::isUpdatePagesAction( $saveActions, $saveAction ) ) {
			return false;
		}

		$parentId = wp_get_post_parent_id( $postId );
		if ( ! $parentId ) {
			return false;
		}

		return GroupTableGateway::countExistingPagesByPostId( $parentId ) === 0;
	}

	/**
	 * Hidden field in the template form (TemplateTabsRenderer), shared by
	 * the classic screen and the block editor's metabox request.
	 */
	public const NONCE_ACTION = 'rowsprout_save_template';
	public const NONCE_FIELD  = 'rowsprout_template_nonce';

	/**
	 * Every request key the template form may submit. Only these are ever
	 * read from $_POST, and only after verifiedFormInput() checked the nonce
	 * and the capability.
	 */
	private const FORM_KEYS = [
		'rowsprout_page_href',
		'dp_config_version',
		'dp_columns',
		'dp_all_columns',
		'dp_groups',
		'rowsprout_page_save_action',
		'rowsprout_page_force_full_regenerate',
	];

	public static function renderNonceField(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	/**
	 * save_post_rowsprout_template callback.
	 *
	 * save_post also fires for saves that carry no template form at all
	 * (WPML's translation save-back, WPBakery's frontend editor, quick edit,
	 * programmatic wp_update_post()). Those must still run the queueing
	 * below with the stored values, so a missing or invalid nonce does not
	 * abort the save — it only means nothing from the request is used.
	 */
	public static function handleSave( int $postId ): void {
		self::saveWithInput( $postId, self::verifiedFormInput( $postId ) );
	}

	/**
	 * The template form's fields (unslashed, not yet sanitized — each value
	 * is sanitized where it is used), or [] unless the request carries a
	 * valid RowSprout nonce and the current user may edit this template.
	 *
	 * @return array<string, mixed>
	 */
	private static function verifiedFormInput( int $postId ): array {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return [];
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $postId ) ) {
			return [];
		}

		$input = [];
		foreach ( self::FORM_KEYS as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nested arrays are sanitized per field by PayloadConfigBuilder::build(), scalars where they are read.
				$input[ $key ] = wp_unslash( $_POST[ $key ] );
			}
		}

		return $input;
	}

	/**
	 * The save itself, fed by an explicit, already-verified input array
	 * instead of $_POST: handleSave() passes the verified template form,
	 * TemplateSaveActionControl passes the Elementor document settings of a
	 * save Elementor itself already authorised.
	 *
	 * @param array<string, mixed> $input Keys from FORM_KEYS; a missing key falls back to the stored value.
	 */
	public static function saveWithInput( int $postId, array $input ): void {
		global $wp_current_filter;

		$templateHref = '';

		// 'parse_request' used to be excluded here too, on the assumption that
		// it only ever meant an unrelated REST dispatch. It also turns out to
		// be active during WPML's own translation-editor save-back (writing
		// translated content into a rowsprout_template's WP post happens inside
		// a REST request), which silently blocked this whole queueing flow
		// for any language the translator only ever touches through WPML's
		// own UI — see WpmlTemplateCascade's docblock for why that's this
		// plugin's actual, expected usage pattern. Removed rather than
		// narrowed to a WPML-specific exception, since no other reason for
		// excluding 'parse_request' was found or reproduced.
		$filtersToCheck = [ 'wpml_page_builder_string_translated' ];
		if ( array_intersect( $filtersToCheck, $wp_current_filter ) ) {
			return;
		}

		// Generic escape hatch, not aware of any specific 3rd-party caller —
		// lets an integration whose own save flow already triggers this
		// method's underlying save_post_rowsprout_template hook once (e.g.
		// mid-way through its own, separate save lifecycle) defer to calling
		// handleSave() itself afterwards with fuller context, instead of this
		// running twice with the second call's context winning anyway.
		if ( apply_filters( 'rowsprout_skip_handle_save', false, $postId ) ) {
			return;
		}

		if ( isset( $input['rowsprout_page_href'] ) && is_scalar( $input['rowsprout_page_href'] ) ) {
			$templateHref = sanitize_text_field( (string) $input['rowsprout_page_href'] );
		} else {
			$templateHref = Helpers::getTemplateHref( $postId );
		}

		$existingConfig = TemplateMeta::get( $postId );

		$saveActions = self::getTemplateSaveActions();
		$saveAction  = self::readTemplateSaveAction( $postId, $saveActions, $input );
		if ( self::childTemplateParentHasNoGeneratedPages( $postId, $saveActions, $saveAction ) ) {
			self::persistParentNotGeneratedNotice();
			return;
		}

		if ( ( isset( $input['dp_columns'] ) && is_array( $input['dp_columns'] ) ) || ( isset( $input['dp_all_columns'] ) && is_array( $input['dp_all_columns'] ) ) ) {
			// Optimistic-concurrency guard: dp_config_version is whatever
			// TemplateMeta::get() returned when this form was rendered (see
			// TemplateTabsRenderer::render()); $existingConfig above is a
			// fresh read taken just now, at the start of THIS save. A
			// mismatch means the stored config changed since this form
			// loaded — most commonly an MCP API write landing while this
			// browser tab sat open — so this session's own (now stale)
			// understanding of properties/groups must not be persisted
			// over it. Blank on either side (a pre-existing template saved
			// before this check existed, or some other caller that doesn't
			// render this form at all) skips the check rather than blocking.
			//
			// A conflict here only means the PROPERTIES/GROUPS payload is
			// stale — it says nothing about the rest of this save (the
			// title/content WordPress already saved natively, and the save
			// action submitted alongside it). Falling through to
			// handlePostSaveQueue() rather than returning immediately keeps
			// those unrelated parts of the save working correctly: notably,
			// it's what persists the submitted save action into
			// _rowsprout_page_save_action. An earlier version of this method
			// returned immediately here, which — combined with Elementor's
			// own "RowSprout template" tab control resubmitting a stale,
			// long-since-invalid dp_config_version on every save once
			// staged even once (see TemplateEditorTab's own docblock) —
			// meant a template saved from Elementor could silently skip
			// this entirely, leaving _rowsprout_page_save_action pointing at
			// a stale action from a previous save even though the admin
			// had explicitly picked a different one this time.
			$submittedVersion = isset( $input['dp_config_version'] ) && is_scalar( $input['dp_config_version'] ) ? sanitize_text_field( (string) $input['dp_config_version'] ) : '';
			$currentVersion   = (string) ( $existingConfig['config_updated_at'] ?? '' );
			if ( $submittedVersion !== '' && $currentVersion !== '' && $submittedVersion !== $currentVersion ) {
				self::persistConfigConflictNotice();
				self::handlePostSaveQueue( $postId, $existingConfig, $input );
				return;
			}

			$rawCols    = isset( $input['dp_columns'] ) && is_array( $input['dp_columns'] ) ? $input['dp_columns'] : [];
			$rawAllCols = isset( $input['dp_all_columns'] ) && is_array( $input['dp_all_columns'] ) ? $input['dp_all_columns'] : [];
			$rawRows    = isset( $input['dp_groups'] ) && is_array( $input['dp_groups'] ) ? $input['dp_groups'] : [];

			$config = PayloadConfigBuilder::build( $templateHref, $rawCols, $rawAllCols, $rawRows, $existingConfig, $postId );

			if ( ! empty( HrefUniquenessValidator::findDuplicateGroups( $config ) ) ) {
				self::persistHrefDuplicateNotice();
				self::handlePostSaveQueue( $postId, $existingConfig, $input );
				return;
			}

			TemplateMeta::save( $postId, $config );
			if ( HrefPatternValidator::shouldWarnOnSave( $config, $postId ) ) {
				self::persistHrefTokenMissingNotice( $postId );
			}
			self::handlePostSaveQueue( $postId, $existingConfig, $input );
			return;
		}

		self::handlePostSaveQueue( $postId, $existingConfig, $input );
	}

	/**
	 * @param array<string, mixed> $input See saveWithInput().
	 */
	private static function handlePostSaveQueue( int $postId, array $oldConfig, array $input ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$saveActions = self::getTemplateSaveActions();
		$saveAction = self::readTemplateSaveAction( $postId, $saveActions, $input );
		update_post_meta( $postId, PostMetaKeys::SAVE_ACTION, $saveAction );

		if ( self::isUpdatePagesAction( $saveActions, $saveAction ) ) {
			$post = get_post( $postId );
			if ( self::shouldQueueTemplatePost( $post ) ) {
				// See TemplateSyncMarker::queueChangedGroups(). A save action
				// field may ask for a full regenerate instead (RowSprout Pro's
				// "Regenerate all pages"), which also overrides planned groups.
				if ( ! empty( $input['rowsprout_page_force_full_regenerate'] ) ) {
					rowsprout_queue_groups( $postId, GroupTableGateway::STATUS_PENDING );
				} else {
					TemplateSyncMarker::queueChangedGroups( $postId, $oldConfig );
				}
				// exceptScheduled: a group still planned for a specific moment
				// (Pro's planning tab) keeps its date — only rows this save
				// actually queued now have their leftover date cleared.
				GroupTableGateway::setScheduledAtByPostId( $postId, null, true );

				// Generic "this post's groups were just queued" signal, fired
				// alongside every queueing path (this one, and Pro's group
				// planning) — lets a feature react without the base plugin
				// needing to know what that feature is.
				do_action( 'rowsprout_groups_queued', $postId, GroupTableGateway::STATUS_PENDING );
			}
		} else {
			if ( self::handleCustomTemplateSaveAction( $postId, $saveAction, $saveActions ) ) {
				return;
			}

			TemplateSyncMarker::markStaleFromSmallAdjustments( $postId, $oldConfig );
		}
	}

	/**
	 * @param array<string, array{disable_schedule:bool,handler_callback:callable|null}> $saveActions
	 */
	private static function readTemplateSaveAction( int $postId, array $saveActions, array $input ): string {
		$saveAction = isset( $input['rowsprout_page_save_action'] ) && is_scalar( $input['rowsprout_page_save_action'] )
			? sanitize_key( (string) $input['rowsprout_page_save_action'] )
			: (string) get_post_meta( $postId, PostMetaKeys::SAVE_ACTION, true );

		if ( ! isset( $saveActions[ $saveAction ] ) ) {
			$saveAction = 'update_pages';
		}

		return $saveAction;
	}

	/**
	 * @param array<string, array{disable_schedule:bool,handler_callback:callable|null}> $saveActions
	 */
	private static function isUpdatePagesAction( array $saveActions, string $saveAction ): bool {
		if ( ! isset( $saveActions[ $saveAction ] ) ) {
			return true;
		}

		return empty( $saveActions[ $saveAction ]['disable_schedule'] );
	}

	/**
	 * @param array<string, array{disable_schedule:bool,handler_callback:callable|null}> $saveActions
	 */
	private static function handleCustomTemplateSaveAction( int $postId, string $saveAction, array $saveActions ): bool {
		$actionConfig = $saveActions[ $saveAction ] ?? [];

		if ( isset( $actionConfig['handler_callback'] ) && is_callable( $actionConfig['handler_callback'] ) ) {
			$handled = (bool) call_user_func( $actionConfig['handler_callback'], $postId, $saveAction, $actionConfig );
			if ( $handled ) {
				return true;
			}
		}

		$handledByFilter = apply_filters( 'rowsprout_handle_template_save_action', false, $postId, $saveAction, $actionConfig );

		return (bool) $handledByFilter;
	}

	/**
	 * @return array<string, array{disable_schedule:bool,handler_callback:callable|null}>
	 */
	private static function getTemplateSaveActions(): array {
		$actions = [
			'update_pages' => [
				'disable_schedule' => false,
				'handler_callback' => null,
			],
			'save_template' => [
				'disable_schedule' => true,
				'handler_callback' => null,
			],
		];

		$actions = apply_filters( 'rowsprout_template_save_actions', $actions );

		if ( ! is_array( $actions ) ) {
			return [
				'update_pages' => [
					'disable_schedule' => false,
					'handler_callback' => null,
				],
				'save_template' => [
					'disable_schedule' => true,
					'handler_callback' => null,
				],
			];
		}

		$normalized = [];
		foreach ( $actions as $actionKey => $actionConfig ) {
			$key = sanitize_key( (string) $actionKey );
			if ( $key === '' || ! is_array( $actionConfig ) ) {
				continue;
			}

			$normalized[ $key ] = [
				'disable_schedule' => ! empty( $actionConfig['disable_schedule'] ),
				'handler_callback' => ( isset( $actionConfig['handler_callback'] ) && is_callable( $actionConfig['handler_callback'] ) )
					? $actionConfig['handler_callback']
					: null,
			];
		}

		if ( empty( $normalized['update_pages'] ) ) {
			$normalized['update_pages'] = [
				'disable_schedule' => false,
				'handler_callback' => null,
			];
		}

		return $normalized;
	}

	private static function shouldQueueTemplatePost( $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return ! in_array( $post->post_status, [ 'draft', 'pending', 'trash', 'auto-draft', 'inherit' ], true );
	}

	/**
	 * Templates nest one level deep: a child template's parent must be a
	 * top-level template, and a template that has child templates can't get
	 * a parent itself (its children would become grandchildren). A parent
	 * that breaks either rule is dropped before the post is written, with a
	 * notice; the rest of the save goes through.
	 *
	 * @param array<string, mixed> $data    Sanitized post data about to be written.
	 * @param array<string, mixed> $postarr The raw post array, with 'ID' for an existing post.
	 * @return array<string, mixed>
	 */
	public static function limitPostDepth( array $data, array $postarr ): array {
		if ( ( $data['post_type'] ?? '' ) !== PostTypes::TEMPLATE ) {
			return $data;
		}

		$parentId = (int) ( $data['post_parent'] ?? 0 );
		if ( $parentId <= 0 ) {
			return $data;
		}

		$postId  = (int) ( $postarr['ID'] ?? 0 );
		$message = '';

		if ( wp_get_post_parent_id( $parentId ) ) {
			$message = __( 'The parent was not saved: the chosen parent is itself a child template. Templates can only be nested one level deep, so choose a template without a parent.', 'rowsprout' );
		} elseif ( $postId > 0 && self::hasChildTemplates( $postId ) ) {
			$message = __( 'The parent was not saved: this template has child templates, so it cannot have a parent itself. Templates can only be nested one level deep.', 'rowsprout' );
		}

		if ( $message === '' ) {
			return $data;
		}

		$data['post_parent'] = 0;

		$userId = get_current_user_id();
		if ( $userId ) {
			set_transient( self::parentRejectedNoticeTransientKey( $userId ), $message, MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Whether this template has at least one child template, in any status
	 * (a trashed child comes back with its parent link when restored).
	 */
	public static function hasChildTemplates( int $templateId ): bool {
		$children = get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_parent'    => $templateId,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		return ! empty( $children );
	}
}
