<?php

namespace RowSprout\ThirdParty\Elementor;

use RowSprout\Admin\Metaboxes\TemplateTabsRenderer;
use RowSprout\Core\Helpers;
use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\HrefPatternValidator;
use RowSprout\Core\Template\HrefUniquenessValidator;
use RowSprout\Core\Template\UrlConflicts;
use RowSprout\Core\Template\Lifecycle\TemplateSyncMarker;
use RowSprout\Core\Template\PayloadConfigBuilder;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "RowSprout template" tab next to Elementor's own "Global" tab in the
 * editor's top panel navigation, opening a modal that reuses the exact same
 * General/Properties/Groups rendering as the classic-editor metabox
 * (TemplateTabsRenderer::render()) — same approach RankMath uses for its own
 * The modal is printed on elementor/editor/footer; the nav button is added
 * client-side (assets/js/elementor-template-tab.js) whenever Elementor
 * renders that navigation bar, since Elementor has no public API for adding
 * a tab to it. (It used to be spliced into an output-buffered copy of
 * Elementor's whole footer, RankMath-style; that re-echoed third-party
 * markup unescaped.)
 *
 * The modal's "Save" button persists the properties/groups payload
 * immediately via its own AJAX call (ajaxSaveProperties()) — a direct
 * TemplateMeta::save() plus TemplateSyncMarker::markStaleFromSmallAdjustments()
 * (the same path the classic screen's own save action "Save template only"
 * uses), so a change made here
 * is visible immediately to any other admin screen reading the template
 * (e.g. the classic editor open in a second tab) without waiting for
 * Elementor's own Update/Publish.
 *
 * Elementor's own Update/Publish no longer touches properties/groups data
 * AT ALL as a result — this modal is now the only place that ever writes
 * dp_columns/dp_all_columns/dp_groups/rowsprout_page_href. What a Publish
 * still does, unaffected, is its existing save-action-driven stale/pending
 * marking of every group through
 * TemplateSyncMarker::queueChangedGroups()/markStaleFromSmallAdjustments()
 * (with RowSprout Pro's change detection, Elementor rewriting post_content
 * on every save counts as a template-wide change, so that still marks every
 * group) — nothing about that needed changing here.
 *
 * (An earlier version of this modal instead staged the edit as an Elementor
 * document setting — a HIDDEN control, via elementor.settings.page.model.set(...)
 * — only actually persisting it once Elementor's own Update/Publish ran,
 * bridged into $_POST by TemplateSaveActionControl::afterSave(). Reversed
 * (first to an immediate AJAX save that still kept that HIDDEN control
 * registered as a safety net, then — once the save-action-driven stale
 * marking above was confirmed to need it not at all — dropped entirely)
 * after it turned out to mean a second browser tab editing the same
 * template never saw a change made here until the admin also published the
 * whole Elementor page.)
 */
final class TemplateEditorTab {

	/**
	 * Read by PageFieldResolver::resolveGroupContext() — public
	 * because that class needs the exact same setting-key string, and a
	 * single shared definition avoids two literal copies drifting apart.
	 */
	public const PREVIEW_GROUP_SETTING_KEY = 'rowsprout_page_preview_group';

	/**
	 * Group guids are plain integers (ColumnSchema::generateNumericId()),
	 * not real GUIDs — used as-is, buildGroupOptions()'s array keys would be
	 * canonical-numeric strings. Elementor's SELECT control renders its
	 * options client-side via Underscore (`_.each(data.options, ...)`,
	 * includes/controls/select.php:70) over the JSON-decoded options object,
	 * and per the ECMAScript spec a plain JS object always iterates
	 * integer-index-like string keys in ascending numeric order first,
	 * regardless of insertion order — silently discarding any PHP-side
	 * asort() by the time it reaches the browser. Prefixing every key here
	 * (and stripping it back off in
	 * PageFieldResolver::resolvePreviewGroupOverride()) keeps them as
	 * ordinary string keys, so the browser preserves our sorted order.
	 */
	public const PREVIEW_GROUP_OPTION_PREFIX = 'grp_';

	/**
	 * Undoes buildGroupOptions()'s PREVIEW_GROUP_OPTION_PREFIX so callers get
	 * back the raw guid stored on the group itself. See
	 * PREVIEW_GROUP_OPTION_PREFIX's docblock for why the prefix exists.
	 */
	public static function decodePreviewGroupValue( string $raw ): string {
		if ( $raw === '' || strpos( $raw, self::PREVIEW_GROUP_OPTION_PREFIX ) !== 0 ) {
			return '';
		}

		return substr( $raw, strlen( self::PREVIEW_GROUP_OPTION_PREFIX ) );
	}

	private const SAVE_PROPERTIES_ACTION = 'rowsprout_save_template_properties';
	private const SAVE_PROPERTIES_NONCE_ACTION = 'dp_save_template_properties_nonce';

	public static function register(): void {
		// Registered unconditionally, unlike everything else below: this
		// fires on its own later admin-ajax.php request (see
		// ajaxSaveProperties()), which never carries the $_GET['post'] this
		// class's other hooks gate on — isEditingRowSproutTemplate() would
		// always read false there and silently block the endpoint.
		add_action( 'wp_ajax_' . self::SAVE_PROPERTIES_ACTION, [ self::class, 'ajaxSaveProperties' ] );

		if ( ! self::isEditingRowSproutTemplate() ) {
			return;
		}

		add_action( 'elementor/editor/before_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'elementor/editor/footer', [ self::class, 'renderFooter' ] );
		add_action( 'elementor/documents/register_controls', [ self::class, 'registerControls' ] );
	}

	private static function isEditingRowSproutTemplate(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check (which post is open in the editor), nothing is processed or mutated; absint() already sanitizes.
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		return $postId > 0 && get_post_type( $postId ) === PostTypes::TEMPLATE;
	}

	public static function enqueue(): void {
		$relativePath = 'assets/js/elementor-template-tab.js';
		$scriptFile   = ROWSPROUT_PATH . $relativePath;
		$scriptUrl    = plugins_url( $relativePath, ROWSPROUT_FILE );
		$version      = file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : '1';

		wp_enqueue_script( 'rowsprout-elementor-template-tab', $scriptUrl, [ 'jquery' ], $version, true );

		$styleRelativePath = 'assets/css/elementor-template-tab.css';
		$styleFile          = ROWSPROUT_PATH . $styleRelativePath;
		$styleUrl           = plugins_url( $styleRelativePath, ROWSPROUT_FILE );
		$styleVersion       = file_exists( $styleFile ) ? (string) filemtime( $styleFile ) : '1';

		wp_enqueue_style( 'rowsprout-elementor-template-tab', $styleUrl, [], $styleVersion );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check (which post is open in the editor), nothing is processed or mutated; absint() already sanitizes.
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

		wp_localize_script(
			'rowsprout-elementor-template-tab',
			'dpElementorTemplateTab',
			[
				'ajaxAction' => self::SAVE_PROPERTIES_ACTION,
				'postId'     => $postId,
				'saveNonce'  => wp_create_nonce( self::SAVE_PROPERTIES_NONCE_ACTION ),
				'tabLabel'   => __( 'RowSprout template', 'rowsprout' ),
			]
		);
	}

	/**
	 * AJAX handler behind the modal's "Save" button — persists the
	 * submitted properties/groups payload immediately (see this class's own
	 * docblock for why), through the exact same building blocks
	 * SavePost::handleSave() uses for the classic screen's own
	 * dp_columns/dp_all_columns/dp_groups/rowsprout_page_href fields:
	 * PayloadConfigBuilder to build the new config, HrefUniquenessValidator
	 * to block a duplicate group URL/slug, and — once saved —
	 * TemplateSyncMarker::markStaleFromSmallAdjustments() to mark the
	 * affected groups stale, the same path the classic screen's own "Save
	 * template only" action uses. Deliberately never queues page
	 * regeneration itself; see the class docblock.
	 *
	 * No optimistic-concurrency/version check here (deliberately, unlike
	 * SavePost::handleSave()'s own copy of this concern for the classic
	 * screen) — whichever save happens last simply wins, full stop.
	 */
	public static function ajaxSaveProperties(): void {
		check_ajax_referer( self::SAVE_PROPERTIES_NONCE_ACTION, 'nonce' );

		$postId = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $postId <= 0 || get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			wp_send_json_error( [ 'message' => __( 'Invalid template.', 'rowsprout' ) ], 400 );
		}

		if ( ! current_user_can( 'edit_post', $postId ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit this template.', 'rowsprout' ) ], 403 );
		}

		$templateHref = isset( $_POST['rowsprout_page_href'] )
			? sanitize_text_field( wp_unslash( $_POST['rowsprout_page_href'] ) )
			: Helpers::getTemplateHref( $postId );

		$existingConfig = TemplateMeta::get( $postId );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- structured nested payload, sanitized per-field by PayloadConfigBuilder::build() below against the template's known field schema; a blanket sanitize_text_field() would corrupt array structure. Nonce already verified via check_ajax_referer() above.
		$rawCols    = isset( $_POST['dp_columns'] ) && is_array( $_POST['dp_columns'] ) ? (array) wp_unslash( $_POST['dp_columns'] ) : [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see $rawCols above.
		$rawAllCols = isset( $_POST['dp_all_columns'] ) && is_array( $_POST['dp_all_columns'] ) ? (array) wp_unslash( $_POST['dp_all_columns'] ) : [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see $rawCols above.
		$rawRows    = isset( $_POST['dp_groups'] ) && is_array( $_POST['dp_groups'] ) ? (array) wp_unslash( $_POST['dp_groups'] ) : [];

		$config = PayloadConfigBuilder::build( $templateHref, $rawCols, $rawAllCols, $rawRows, $existingConfig, $postId );

		if ( ! empty( HrefUniquenessValidator::findDuplicateGroups( $config ) ) ) {
			wp_send_json_error( [
				'code'    => 'duplicate_href',
				'message' => __( 'Two or more groups share the same URL/slug. Every group\'s URL must be unique.', 'rowsprout' ),
			], 422 );
		}

		$urlConflicts = UrlConflicts::find( $postId, $config );
		if ( $urlConflicts !== [] ) {
			wp_send_json_error( [
				'code'    => 'url_conflict',
				'message' => UrlConflicts::message( $urlConflicts ),
			], 422 );
		}

		TemplateMeta::save( $postId, $config );
		TemplateSyncMarker::markStaleFromSmallAdjustments( $postId, $existingConfig );

		$saved = TemplateMeta::get( $postId );

		wp_send_json_success( [
			'config_updated_at' => (string) ( $saved['config_updated_at'] ?? '' ),
			// Not an error: the config is saved; the modal stays open and shows it.
			'warning'           => HrefPatternValidator::saveWarning( $saved, $postId ),
		] );
	}

	public static function renderFooter(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check (which post is open in the editor), nothing is processed or mutated; absint() already sanitizes.
		$postId = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$post   = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		?>
		<div id="dp-elementor-tab-modal" class="dp-elementor-tab-modal" aria-hidden="true">
			<div class="dp-elementor-tab-backdrop"></div>
			<div class="dp-elementor-tab-dialog" role="dialog" aria-modal="true" aria-labelledby="dp-elementor-tab-title">
				<div class="dp-elementor-tab-head">
					<strong id="dp-elementor-tab-title"><?php esc_html_e( 'RowSprout template', 'rowsprout' ); ?></strong>
					<button type="button" class="dp-elementor-tab-close" aria-label="<?php echo esc_attr__( 'Close', 'rowsprout' ); ?>">&times;</button>
				</div>
				<div class="dp-elementor-tab-body">
					<?php TemplateTabsRenderer::render( $post ); ?>
				</div>
				<div class="dp-elementor-tab-foot">
					<button type="button" class="button button-primary" id="dp-elementor-tab-save"><?php esc_html_e( 'Save', 'rowsprout' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param mixed $document \Elementor\Core\Base\Document
	 */
	public static function registerControls( $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}

		$postId = (int) $document->get_main_id();
		if ( get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return;
		}

		// Injected into Elementor's own built-in "General Settings" section
		// (document_settings — confirmed present on every document type,
		// core\base\document.php) rather than a dedicated section of our
		// own: a section containing only a HIDDEN control still renders its
		// (otherwise empty-looking) title in the panel, which is exactly
		// what this used to do before switching to injection.
		$document->start_injection( [
			'type' => 'section',
			'of'   => 'document_settings',
		] );

		$groupOptions = self::buildGroupOptions( $postId );

		$document->add_control(
			self::PREVIEW_GROUP_SETTING_KEY,
			[
				'label'       => __( 'Preview group', 'rowsprout' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $groupOptions,
				// No blank "-- default --" placeholder: the first
				// (alphabetically sorted) group is the default selection
				// itself, so there's always a real group being previewed.
				'default'     => $groupOptions !== [] ? (string) array_key_first( $groupOptions ) : '',
				'export'      => false,
				'description' => __( 'Determines which group the widgets and tags show in this editor. After choosing, click "Refresh preview" to update the preview. Has no effect on the actual pages.', 'rowsprout' ),
			]
		);

		// Elementor doesn't re-render PHP-rendered widgets just because a
		// document setting changed (confirmed against the installed 4.2.4:
		// only element-level setting changes trigger their own AJAX
		// re-render) — a plain SELECT here would silently do nothing until
		// the next unrelated save. Same fix Elementor Pro's own Theme
		// Builder uses for its near-identical "Preview Settings" (see
		// elementor-pro/modules/theme-builder/documents/theme-document.php:
		// a BUTTON control's 'event' is dispatched on elementor.channels.editor
		// — core Elementor behavior, not Pro-only, confirmed in
		// elementor/assets/js/editor.js — picked up in
		// assets/js/elementor-template-tab.js to run a forced
		// 'document/save/auto' and then elementor.reloadPreview(). Verified
		// safe: SavePost::handlePostSaveQueue() already bails on
		// DOING_AUTOSAVE (which Elementor's own Document::save() sets for
		// this kind of save), and rowsprout_page_save_action is excluded from
		// the autosave's settings diff when untouched (Elementor strips any
		// control still equal to its own default before submitting), so
		// this never reaches SavePost::handleSave()'s queueing side effects.
		$document->add_control(
			'rowsprout_page_apply_preview_group',
			[
				'type'        => \Elementor\Controls_Manager::BUTTON,
				'label_block' => true,
				'show_label'  => false,
				'text'        => __( 'Refresh preview', 'rowsprout' ),
				'event'       => 'rowsproutPageEditor:ApplyPreviewGroup',
				'export'      => false,
			]
		);

		$document->end_injection();
	}

	/**
	 * Options for PREVIEW_GROUP_SETTING_KEY, alphabetically sorted by label:
	 * every current group of this template, labelled by its own naam/title
	 * field (falling back to its href, then its raw guid, so no option ever
	 * renders blank). No blank/"default" placeholder entry — registerControls()
	 * defaults the control to the first (alphabetically first) group itself.
	 * Purely an editor-preview convenience — see
	 * PageFieldResolver::resolveGroupContext(), the only place this
	 * setting is ever read; never consulted for an actual generated
	 * rowsprout_page's render.
	 *
	 * @return array<string, string>
	 */
	private static function buildGroupOptions( int $postId ): array {
		$groupOptions = [];

		// Goes through PageFieldResolver::getGroupsWithInheritance()
		// rather than reading TemplateMeta directly, so a CHILD template's
		// groups (which only store the fields the admin actually overrode
		// there) still show their inherited title/href instead of a bare
		// numeric guid.
		foreach ( PageFieldResolver::getGroupsWithInheritance( $postId ) as $entry ) {
			$guid  = $entry['guid'];
			$group = $entry['group'];

			$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];
			$name   = self::fieldValue( $fields, 'title' );
			if ( $name === '' ) {
				$name = self::fieldValue( $fields, 'href' );
			}

			// See PREVIEW_GROUP_OPTION_PREFIX's docblock: the guid itself is a
			// plain integer, which the browser would otherwise silently
			// re-sort ascending regardless of our own ordering below.
			$groupOptions[ self::PREVIEW_GROUP_OPTION_PREFIX . $guid ] = $name !== '' ? $name : $guid;
		}

		// Alphabetical on label, not insertion order — registerControls()
		// picks the first entry here (array_key_first()) as the control's
		// own default, so no separate blank placeholder option is needed.
		asort( $groupOptions, SORT_STRING | SORT_FLAG_CASE );

		return $groupOptions;
	}

	private static function fieldValue( array $fields, string $key ): string {
		$field = $fields[ $key ] ?? null;
		if ( is_array( $field ) ) {
			return is_scalar( $field['value'] ?? '' ) ? (string) $field['value'] : '';
		}

		return is_scalar( $field ) ? (string) $field : '';
	}

}
