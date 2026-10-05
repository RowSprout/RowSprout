<?php

namespace RowSprout\ThirdParty\Elementor;

use RowSprout\Admin\Metaboxes\PublishBoxRenderer;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\SavePost;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Save action" section to Elementor's own document-settings panel
 * (the one with Title/Status — Document::register_document_controls(),
 * elementor/core/base/document.php) for rowsprout_template documents, instead
 * of the classic screen's Publish-box <select> (post_submitbox_misc_actions
 * is a classic-editor-only hook, so it never renders inside Elementor at
 * all — this section is the Elementor-native equivalent; an add-on can
 * append its own controls through the
 * rowsprout_elementor_save_action_controls action).
 *
 * Every field here is translated into the exact same input keys
 * PublishBoxRenderer's classic-screen form fields submit, then handed to
 * SavePost::saveWithInput() — the same persistence path the classic screen
 * uses, completely unmodified. This is the single elementor/document/after_save
 * call site that does so (see afterSave()'s own docblock for why it must
 * stay the only one).
 */
final class TemplateSaveActionControl {

	public static function register(): void {
		add_action( 'elementor/documents/register_controls', [ self::class, 'registerControls' ] );
		add_action( 'elementor/document/after_save', [ self::class, 'afterSave' ], 10, 2 );
		add_filter( 'rowsprout_skip_handle_save', [ self::class, 'skipNativeHandleSaveDuringElementorSave' ], 10, 2 );

		// Priority 20: after SavePost::handleSave() (registered at the
		// default 10 on the same hook), so _rowsprout_page_save_action
		// already holds this save's final value by the time this runs —
		// regardless of whether this particular save came from Elementor
		// or the classic screen.
		add_action( 'save_post_' . PostTypes::TEMPLATE, [ self::class, 'syncIntoElementorSettings' ], 20, 1 );
	}

	/**
	 * Elementor's own document save always fires wp_update_post() (see
	 * Manager::ajax_before_save_settings(),
	 * core/settings/page/manager.php:130) between elementor/document/
	 * before_save and elementor/document/after_save — which already
	 * triggers save_post_rowsprout_template, and thus SavePost::handleSave(),
	 * once per Elementor save, before our own control's value is available.
	 * Skip that native firing precisely inside that window so only our
	 * explicit call from afterSave() (with the settings translated by
	 * toSaveInput()) actually runs handlePostSaveQueue()'s side effects.
	 */
	public static function skipNativeHandleSaveDuringElementorSave( bool $skip, int $postId ): bool {
		if ( $skip || get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return $skip;
		}

		return did_action( 'elementor/document/before_save' ) > did_action( 'elementor/document/after_save' );
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

		self::renderSaveActionSection( $document, $postId );
	}

	/**
	 * @param mixed $document \Elementor\Core\Base\Document
	 */
	private static function renderSaveActionSection( $document, int $postId ): void {
		$saveActions = PublishBoxRenderer::getTemplateSaveActions();

		$document->start_controls_section(
			'rowsprout_page_save_action_section',
			[
				'label' => __( 'Save action', 'rowsprout' ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			]
		);

		$options = [];
		foreach ( $saveActions as $key => $config ) {
			$options[ $key ] = (string) ( $config['label'] ?? $key );
		}

		$storedSaveAction = (string) get_post_meta( $postId, PostMetaKeys::SAVE_ACTION, true );
		$document->add_control(
			'rowsprout_page_save_action',
			[
				'label'   => __( 'Save action', 'rowsprout' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $options,
				'default' => $storedSaveAction !== '' ? $storedSaveAction : 'update_pages',
			]
		);

		/**
		 * Lets an add-on append controls for its own save actions to this
		 * section (e.g. Pro's hint for "Schedule page updates"); map them
		 * into the save input with rowsprout_elementor_save_input.
		 *
		 * @param mixed $document \Elementor\Core\Base\Document
		 * @param int   $postId
		 */
		do_action( 'rowsprout_elementor_save_action_controls', $document, $postId );

		$document->end_controls_section();
	}

	/**
	 * The single elementor/document/after_save handler for rowsprout_template
	 * documents — translates this class's own save-action/scheduling settings
	 * into SavePost::saveWithInput()'s input, so an Elementor
	 * Update/Publish drives the exact same save-action-driven queueing the
	 * classic screen's own form submission does. Properties/groups data is
	 * no longer part of this at all — the "RowSprout template" modal's own
	 * "Save" button persists that immediately via its own AJAX call
	 * (TemplateEditorTab::ajaxSaveProperties()), so the input never carries
	 * dp_columns/dp_all_columns/dp_groups here, and SavePost::saveWithInput()
	 * simply runs its save-action/stale-marking branch without touching
	 * TemplateMeta at all (see that method's own dp_columns branch, and
	 * TemplateEditorTab's class docblock for why a Publish still marks
	 * every group stale/pending regardless).
	 *
	 * Always calls SavePost::saveWithInput(), regardless of whether our own
	 * save-action control is actually present in $data['settings']:
	 * Elementor's own save command strips any control whose current value
	 * still equals its registered 'default' before submitting
	 * (container.settings.toJSON({remove: ['default']}),
	 * assets/js/editor/document/save/commands/internal/save.js) — since its
	 * default is "whatever's already stored", a save that doesn't touch the
	 * save-action dropdown (e.g. only editing the title, or only editing
	 * widget content) submits nothing for it at all. Bailing out in that
	 * case (an earlier version of this method did) meant
	 * SavePost::handleSave() never ran for that save, silently skipping
	 * handlePostSaveQueue() entirely. toSaveInput() leaves the key out when
	 * the setting isn't present, so SavePost's own
	 * existing fallback-to-stored-value logic (readTemplateSaveAction(),
	 * etc.) applies exactly as it already does for a classic-screen save
	 * that didn't touch this field.
	 *
	 * @param mixed $document \Elementor\Core\Base\Document
	 * @param array<string, mixed> $data
	 */
	public static function afterSave( $document, $data ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) {
			return;
		}

		$postId = (int) $document->get_main_id();
		if ( get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return;
		}

		// Elementor's own save already checked its nonce and edit_post; checked
		// again here because this is what lets the settings drive the queue.
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}

		$settings = is_array( $data ) && isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : [];

		SavePost::saveWithInput( $postId, self::toSaveInput( $settings ) );
	}

	/**
	 * Pure, independently testable: translates the Elementor settings array
	 * into the exact same input shape PublishBoxRenderer's classic-screen
	 * form fields submit (see SavePost::saveWithInput()).
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, string>
	 */
	public static function toSaveInput( array $settings ): array {
		if ( ! array_key_exists( 'rowsprout_page_save_action', $settings ) ) {
			return [];
		}

		$input = [ 'rowsprout_page_save_action' => sanitize_key( (string) $settings['rowsprout_page_save_action'] ) ];

		/**
		 * Maps an add-on's own controls (see
		 * rowsprout_elementor_save_action_controls) into the save input,
		 * using the same keys its classic-screen fields submit.
		 *
		 * @param array<string, string> $input
		 * @param array<string, mixed>  $settings The Elementor document settings.
		 */
		$input = apply_filters( 'rowsprout_elementor_save_input', $input, $settings );

		return is_array( $input ) ? $input : [];
	}

	/**
	 * Keeps Elementor's own document-settings copy of the save action in
	 * sync with our own canonical _rowsprout_page_save_action postmeta after
	 * EVERY save on this template — not just one that originated inside
	 * Elementor. Elementor's document-settings model always prefers
	 * whatever is already stored in _elementor_page_settings over this
	 * control's own registered 'default' (see registerControls() above)
	 * once any value has ever been stored there for this key — so a save
	 * made through the CLASSIC screen, which only ever touches our own
	 * postmeta, would otherwise leave the Elementor panel showing
	 * whatever save action was last picked INSIDE Elementor, no matter
	 * what the admin just chose on the classic screen. Runs at a later
	 * priority than SavePost::handleSave() (see register()) so it always
	 * reads that save's final, already-persisted value — including an
	 * Elementor-originated save, where this ends up a harmless no-op
	 * (Elementor's own native save already wrote the same value here
	 * first; the equality check below skips the redundant write).
	 */
	public static function syncIntoElementorSettings( int $postId ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! class_exists( '\Elementor\Plugin' ) || get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return;
		}

		$saveAction = (string) get_post_meta( $postId, PostMetaKeys::SAVE_ACTION, true );
		if ( $saveAction === '' ) {
			return;
		}

		$settings = get_post_meta( $postId, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		if ( ( $settings['rowsprout_page_save_action'] ?? null ) === $saveAction ) {
			return;
		}

		$settings['rowsprout_page_save_action'] = $saveAction;
		// Slashed: the page settings hold Elementor's custom CSS, whose
		// backslash escapes (content: "\f00c") update_post_meta() would strip.
		update_post_meta( $postId, '_elementor_page_settings', wp_slash( $settings ) );
	}
}
