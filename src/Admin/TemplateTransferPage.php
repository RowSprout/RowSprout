<?php

namespace RowSprout\Admin;

use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;
use RowSprout\Core\Transfer\ImportResult;
use RowSprout\Core\Transfer\TemplateExporter;
use RowSprout\Core\Transfer\TemplateImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RowSprout → Import / Export: download templates as a JSON file and add
 * the templates of such a file to this site (Core\Transfer). The Templates
 * list gets an "Export" row action and bulk action too.
 *
 * Same capabilities as WordPress's own Tools → Export/Import: "export" to
 * download, "import" to upload. The page opens for either; each card shows
 * only for its own capability.
 */
final class TemplateTransferPage {

	public const PAGE_SLUG = 'rowsprout_import_export';

	private const EXPORT_ACTION = 'rowsprout_export_templates';
	private const IMPORT_ACTION = 'rowsprout_import_templates';
	private const BULK_ACTION   = 'rowsprout_export';
	private const FILE_FIELD    = 'rowsprout_import_file';
	private const SAMPLE_ACTION = 'rowsprout_import_sample_data';

	/**
	 * Two example templates (city breaks and food tours in European cities,
	 * with accented names and typographic quotes) in the export format,
	 * relative to the plugin folder.
	 */
	public const SAMPLE_FILE = 'sample-data/rowsprout-sample-templates.json';

	public static function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ self::class, 'handleExport' ] );
		add_action( 'admin_post_' . self::IMPORT_ACTION, [ self::class, 'handleImport' ] );
		add_action( 'admin_post_' . self::SAMPLE_ACTION, [ self::class, 'handleImportSample' ] );
		add_filter( 'bulk_actions-edit-' . PostTypes::TEMPLATE, [ self::class, 'addBulkAction' ] );
		add_filter( 'handle_bulk_actions-edit-' . PostTypes::TEMPLATE, [ self::class, 'handleBulkAction' ], 10, 3 );
		// Templates are hierarchical, so their list uses page_row_actions.
		add_filter( 'page_row_actions', [ self::class, 'addRowAction' ], 30, 2 );
	}

	/**
	 * The capability the menu item needs: a single one, so whichever of
	 * export/import this user has (export when neither, which hides the
	 * page).
	 */
	public static function requiredCapability(): string {
		return ! current_user_can( 'export' ) && current_user_can( 'import' ) ? 'import' : 'export';
	}

	public static function pageUrl(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function addBulkAction( array $actions ): array {
		if ( current_user_can( 'export' ) ) {
			$actions[ self::BULK_ACTION ] = __( 'Export', 'rowsprout' );
		}

		return $actions;
	}

	/**
	 * WordPress checked the list's own bulk nonce before calling this.
	 *
	 * @param array<int, int|string> $postIds
	 */
	public static function handleBulkAction( string $redirectTo, string $action, array $postIds ): string {
		if ( $action !== self::BULK_ACTION || ! current_user_can( 'export' ) ) {
			return $redirectTo;
		}

		$ids = TemplateExporter::resolveTemplateIds( $postIds );
		if ( $ids === [] ) {
			return $redirectTo;
		}

		self::sendDownload( TemplateExporter::build( $ids ) );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function addRowAction( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== PostTypes::TEMPLATE || $post->post_status === 'trash' || ! current_user_can( 'export' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				[
					'action'       => self::EXPORT_ACTION,
					'template_ids' => $post->ID,
				],
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);

		$label = (int) $post->post_parent > 0
			? __( 'Export with parent', 'rowsprout' )
			: __( 'Export', 'rowsprout' );

		$actions[ self::BULK_ACTION ] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';

		return $actions;
	}

	public static function handleExport(): void {
		if ( ! current_user_can( 'export' ) ) {
			wp_die( esc_html__( 'You are not allowed to export templates.', 'rowsprout' ), 403 );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$raw       = isset( $_REQUEST['template_ids'] ) ? wp_unslash( $_REQUEST['template_ids'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every id goes through absint() in resolveTemplateIds().
		$requested = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		/**
		 * Options for this export, from fields an add-on adds to the export
		 * form. Runs after the nonce and capability checks, so a callback may
		 * read its own fields from the request. The options reach
		 * rowsprout_template_export_ids and rowsprout_template_export_data.
		 *
		 * @param array<string, mixed> $options
		 */
		$options = (array) apply_filters( 'rowsprout_template_export_options', [] );

		$ids = TemplateExporter::resolveTemplateIds( $requested, $options );
		if ( $ids === [] ) {
			$result = new ImportResult();
			$result->addError( __( 'Select at least one template to export.', 'rowsprout' ) );
			self::redirectWithResult( $result );
		}

		self::sendDownload( TemplateExporter::build( $ids, $options ) );
	}

	public static function handleImport(): void {
		if ( ! current_user_can( 'import' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to import templates.', 'rowsprout' ), 403 );
		}
		check_admin_referer( self::IMPORT_ACTION );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- an upload: only its error code and temporary path are read, and the path is checked with is_uploaded_file().
		$file = isset( $_FILES[ self::FILE_FIELD ] ) && is_array( $_FILES[ self::FILE_FIELD ] ) ? $_FILES[ self::FILE_FIELD ] : null;

		$error   = is_array( $file ) ? (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE;
		$tmpName = is_array( $file ) ? (string) ( $file['tmp_name'] ?? '' ) : '';

		if ( $error !== UPLOAD_ERR_OK || $tmpName === '' || ! is_uploaded_file( $tmpName ) ) {
			$result = new ImportResult();
			$result->addError( in_array( $error, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true )
				? sprintf(
					/* translators: %s: maximum upload size, e.g. "8 MB". */
					__( 'The file is larger than this server accepts (%s).', 'rowsprout' ),
					size_format( wp_max_upload_size() )
				)
				: __( 'Choose an export file to import.', 'rowsprout' )
			);
			self::redirectWithResult( $result );
		}

		$json = file_get_contents( $tmpName ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading PHP's own temporary upload file.

		/**
		 * Options for this import, from fields an add-on printed with the
		 * rowsprout_template_import_form_fields action. Runs after the nonce
		 * and capability checks, so a callback may read its own fields from
		 * the request. The options reach every import hook.
		 *
		 * @param array<string, mixed> $options
		 */
		$options = (array) apply_filters( 'rowsprout_template_import_options', [] );

		self::redirectWithResult( TemplateImporter::importJson( is_string( $json ) ? $json : '', $options ) );
	}

	public static function renderPage(): void {
		if ( ! current_user_can( 'export' ) && ! current_user_can( 'import' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import / Export templates', 'rowsprout' ) . '</h1>';

		self::renderResult();

		echo '<div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">';
		if ( current_user_can( 'export' ) ) {
			self::renderExportCard();
		}
		if ( current_user_can( 'import' ) ) {
			echo '<div style="flex: 1 1 320px; max-width: 480px; display: flex; flex-direction: column; gap: 20px;">';
			self::renderImportCard();
			self::renderSampleCard();
			echo '</div>';
		}

		/**
		 * Prints extra cards on the Import / Export page (each a
		 * <div class="card">).
		 */
		do_action( 'rowsprout_import_export_page_cards' );
		echo '</div>';
		echo '</div>';
	}

	private static function renderExportCard(): void {
		$templates = self::templatesForList();

		echo '<div class="card" style="flex: 2 1 420px; max-width: 760px; margin-top: 0;">';
		echo '<h2>' . esc_html__( 'Export', 'rowsprout' ) . '</h2>';
		echo '<p>' . esc_html__( 'Download templates as a file, with their properties, groups and page-builder layout, to add them to another site. A child template is always exported with its parent.', 'rowsprout' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Not included: the generated pages (the other site generates its own) and media files. Images keep pointing at this site.', 'rowsprout' ) . '</p>';

		if ( $templates === [] ) {
			echo '<p><em>' . esc_html__( 'There are no templates to export yet.', 'rowsprout' ) . '</em></p>';
			echo '</div>';
			return;
		}

		echo '<form method="post" id="rowsprout-export-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
		wp_nonce_field( self::EXPORT_ACTION );

		// List-table markup: WordPress's own common.js makes the header
		// checkbox select every row (shift-click selects a range), as on the
		// Templates list.
		echo '<table class="wp-list-table widefat striped" id="rowsprout-export-templates" style="margin: 12px 0;">';
		echo '<thead><tr>';
		echo '<td class="manage-column column-cb check-column"><label class="screen-reader-text" for="rowsprout-export-select-all">' . esc_html__( 'Select all', 'rowsprout' ) . '</label><input type="checkbox" id="rowsprout-export-select-all"></td>';
		echo '<th scope="col">' . esc_html__( 'Template', 'rowsprout' ) . '</th><th scope="col">' . esc_html__( 'Status', 'rowsprout' ) . '</th><th scope="col">' . esc_html__( 'Groups', 'rowsprout' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $templates as $template ) {
			$post        = $template['post'];
			$inputId     = 'rowsprout-export-' . $post->ID;
			$statusLabel = get_post_status_object( $post->post_status );
			$title       = get_the_title( $post ) !== '' ? get_the_title( $post ) : __( '(no title)', 'rowsprout' );

			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="template_ids[]" value="' . esc_attr( (string) $post->ID ) . '" id="' . esc_attr( $inputId ) . '"';
			if ( (int) $post->post_parent > 0 ) {
				echo ' data-parent="' . esc_attr( (string) $post->post_parent ) . '"';
			}
			echo '></th>';
			echo '<td><label for="' . esc_attr( $inputId ) . '">' . ( $template['child'] ? '&#8212; ' : '' ) . esc_html( $title ) . '</label></td>';
			echo '<td>' . esc_html( $statusLabel ? (string) $statusLabel->label : $post->post_status ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( count( (array) ( TemplateMeta::get( $post->ID )['groups'] ?? [] ) ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Download selected', 'rowsprout' ), 'primary', 'export_selected', true );
		echo '</form>';
		echo '</div>';

		self::enqueueExportTableScript();
	}

	/**
	 * The export always includes a chosen child's parent, so the table shows
	 * it: ticking a child ticks its parent, and unticking a parent unticks
	 * its children. A parent does not tick its children (it is exported on
	 * its own).
	 */
	private static function enqueueExportTableScript(): void {
		wp_register_script( 'rowsprout-template-transfer', false, [], ROWSPROUT_VERSION, true );
		wp_enqueue_script( 'rowsprout-template-transfer' );
		wp_add_inline_script( 'rowsprout-template-transfer', "
		(function () {
			var table = document.getElementById('rowsprout-export-templates');
			if (!table) {
				return;
			}
			table.addEventListener('change', function (event) {
				var box = event.target;
				if (!box.matches || !box.matches('input[name=\"template_ids[]\"]')) {
					return;
				}
				var parentId = box.getAttribute('data-parent');
				if (parentId && box.checked) {
					var parent = table.querySelector('input[name=\"template_ids[]\"][value=\"' + parentId + '\"]');
					if (parent) {
						parent.checked = true;
					}
				}
				if (!parentId && !box.checked) {
					table.querySelectorAll('input[data-parent=\"' + box.value + '\"]').forEach(function (child) {
						child.checked = false;
					});
				}
			});
		})();
		" );
	}

	private static function renderImportCard(): void {
		echo '<div class="card" style="max-width: none; margin-top: 0;">';
		echo '<h2>' . esc_html__( 'Import', 'rowsprout' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose a RowSprout export file. Every template in it is added as a new draft; existing templates are never changed.', 'rowsprout' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Check an imported template (above all its URL pattern), then publish it with "Create & update pages" to generate its pages.', 'rowsprout' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT_ACTION ) . '">';
		wp_nonce_field( self::IMPORT_ACTION );
		echo '<p><label for="' . esc_attr( self::FILE_FIELD ) . '" class="screen-reader-text">' . esc_html__( 'Export file', 'rowsprout' ) . '</label>';
		echo '<input type="file" name="' . esc_attr( self::FILE_FIELD ) . '" id="' . esc_attr( self::FILE_FIELD ) . '" accept=".json,application/json" required></p>';
		echo '<p class="description">' . esc_html( sprintf(
			/* translators: %s: maximum upload size, e.g. "8 MB". */
			__( 'Maximum file size: %s.', 'rowsprout' ),
			size_format( wp_max_upload_size() )
		) ) . '</p>';

		/**
		 * Prints extra fields in the import form; read them back through the
		 * rowsprout_template_import_options filter.
		 */
		do_action( 'rowsprout_template_import_form_fields' );

		submit_button( __( 'Import', 'rowsprout' ), 'primary', 'submit', true );
		echo '</form>';
		echo '</div>';
	}

	private static function renderSampleCard(): void {
		echo '<div class="card" style="max-width: none; margin-top: 0;">';
		echo '<h2>' . esc_html__( 'Sample data', 'rowsprout' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add two example templates to see how RowSprout works: city breaks in 12 European cities and food tours in 6, with their properties and groups. They are added as drafts; publish one with "Create & update pages" to generate its pages.', 'rowsprout' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAMPLE_ACTION ) . '">';
		wp_nonce_field( self::SAMPLE_ACTION );
		submit_button( __( 'Import sample data', 'rowsprout' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Imports the bundled sample file (SAMPLE_FILE) like an uploaded one.
	 */
	public static function handleImportSample(): void {
		if ( ! current_user_can( 'import' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to import templates.', 'rowsprout' ), 403 );
		}
		check_admin_referer( self::SAMPLE_ACTION );

		$path = ROWSPROUT_PATH . self::SAMPLE_FILE;
		$json = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file that ships with the plugin.
		if ( ! is_string( $json ) || $json === '' ) {
			$result = new ImportResult();
			$result->addError( __( 'The sample data file is missing. Reinstall RowSprout and try again.', 'rowsprout' ) );
			self::redirectWithResult( $result );
		}

		/** This filter is documented in handleImport(). */
		$options = (array) apply_filters( 'rowsprout_template_import_options', [] );

		self::redirectWithResult( TemplateImporter::importJson( $json, $options ) );
	}

	private static function renderResult(): void {
		$userId = get_current_user_id();
		$stored = $userId ? get_transient( self::resultTransientKey( $userId ) ) : false;
		if ( ! is_array( $stored ) ) {
			return;
		}
		delete_transient( self::resultTransientKey( $userId ) );

		$result = ImportResult::fromArray( $stored );

		foreach ( $result->errors() as $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$created = $result->created();
		if ( $created !== [] ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
				/* translators: %d: number of templates. */
				_n( '%d template was imported as a draft:', '%d templates were imported as drafts:', count( $created ), 'rowsprout' ),
				count( $created )
			) ) . '</p><ul style="list-style: disc; margin-left: 20px;">';
			foreach ( $created as $item ) {
				$editUrl = get_edit_post_link( $item['id'] );
				$title   = get_the_title( $item['id'] );
				echo '<li>' . ( $editUrl ? '<a href="' . esc_url( $editUrl ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		$updated = $result->updated();
		if ( $updated !== [] ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf(
				/* translators: %d: number of templates. */
				_n( '%d existing template was updated. Nothing was generated: its changed pages are marked as outdated until you save it with "Create & update pages".', '%d existing templates were updated. Nothing was generated: their changed pages are marked as outdated until you save them with "Create & update pages".', count( $updated ), 'rowsprout' ),
				count( $updated )
			) ) . '</p><ul style="list-style: disc; margin-left: 20px;">';
			foreach ( $updated as $item ) {
				$editUrl = get_edit_post_link( $item['id'] );
				$title   = get_the_title( $item['id'] );
				echo '<li>' . ( $editUrl ? '<a href="' . esc_url( $editUrl ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $result->warnings() !== [] ) {
			echo '<div class="notice notice-warning"><ul style="list-style: disc; margin-left: 20px;">';
			foreach ( $result->warnings() as $warning ) {
				echo '<li>' . esc_html( $warning ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * @param array<string, mixed> $export
	 * @return never
	 */
	private static function sendDownload( array $export ) {
		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'The export file could not be created.', 'rowsprout' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . TemplateExporter::fileName( $export ) . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file download, not HTML.
		exit;
	}

	/**
	 * The page shows the outcome after the redirect (a POST handler cannot
	 * render it itself).
	 *
	 * @return never
	 */
	private static function redirectWithResult( ImportResult $result ) {
		$userId = get_current_user_id();
		if ( $userId ) {
			set_transient( self::resultTransientKey( $userId ), $result->toArray(), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( self::pageUrl() );
		exit;
	}

	private static function resultTransientKey( int $userId ): string {
		return 'rowsprout_transfer_result_' . $userId;
	}

	/**
	 * Every template the user can export, each parent followed by its
	 * child templates.
	 *
	 * suppress_filters off, so the list follows the same query filters as
	 * the Templates list, such as a multilingual plugin's admin language
	 * switcher (get_posts() bypasses them by default and would list every
	 * translation as a template of its own).
	 *
	 * @return array<int, array{post: \WP_Post, child: bool}>
	 */
	private static function templatesForList(): array {
		$posts = get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'suppress_filters' => false,
		] );

		$byParent = [];
		$ids      = [];
		foreach ( $posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$byParent[ (int) $post->post_parent ][] = $post;
				$ids[ $post->ID ]                        = true;
			}
		}

		$list = [];
		foreach ( $byParent[0] ?? [] as $parent ) {
			$list[] = [ 'post' => $parent, 'child' => false ];
			foreach ( $byParent[ $parent->ID ] ?? [] as $child ) {
				$list[] = [ 'post' => $child, 'child' => true ];
			}
		}

		// Children whose parent is not listed (trashed, or not editable).
		foreach ( $byParent as $parentId => $children ) {
			if ( $parentId !== 0 && ! isset( $ids[ $parentId ] ) ) {
				foreach ( $children as $child ) {
					$list[] = [ 'post' => $child, 'child' => true ];
				}
			}
		}

		return $list;
	}
}
