<?php

namespace RowSprout\Admin\GroupCsv;

use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\HrefPatternValidator;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Import and export groups" on the template's General tab: a download of
 * the stored groups in a choice of formats, and an import wizard modelled on
 * Excel's Text Import Wizard (assets/js/group-csv.js). The classic screen,
 * the block editor's metabox and the Elementor modal all render it through
 * TemplateTabsRenderer.
 *
 * The import saves right away (GroupCsvImporter) and the editor reloads:
 * posting the groups through the template form would run into PHP's
 * max_input_vars on large files. None of the wizard's controls has a name, so
 * the template form never posts them.
 *
 * On a child template it exports and imports the child's own values of its
 * parent's groups (GroupCsvTemplate); the section hides when the Parent
 * dropdown changes, as the server works with the stored parent.
 */
final class GroupCsvSection {

	private const EXPORT_ACTION = 'rowsprout_export_groups';
	private const IMPORT_ACTION = 'rowsprout_import_groups';
	private const FETCH_ACTION  = 'rowsprout_fetch_groups_csv';
	private const NONCE_ACTION  = 'rowsprout_group_csv';

	private const SCRIPT_HANDLE = 'rowsprout-group-csv';

	/**
	 * The last URL a template's groups were fetched from, offered again
	 * next time. The _rowsprout_page_ prefix keeps it off the generated
	 * pages (PageMetaReplicator) and out of template export files.
	 */
	public const URL_META = '_rowsprout_page_groups_csv_url';

	/**
	 * Largest file fetched from a URL.
	 */
	private const MAX_URL_BYTES = 10485760;

	public static function register(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ self::class, 'handleExport' ] );
		add_action( 'wp_ajax_' . self::IMPORT_ACTION, [ self::class, 'handleImport' ] );
		add_action( 'wp_ajax_' . self::FETCH_ACTION, [ self::class, 'handleFetch' ] );
	}

	public static function render( \WP_Post $post ): void {
		$isChild = HrefPatternValidator::isChildTemplate( (int) $post->ID );

		// An auto-draft is deleted after a week unless it is saved, and its
		// screen (post-new.php) cannot be reloaded into.
		$saved     = $post->post_status !== 'auto-draft';
		$disabled  = $saved ? '' : ' disabled';
		$exportUrl = add_query_arg(
			[
				'action'   => self::EXPORT_ACTION,
				'template' => (int) $post->ID,
				'_wpnonce' => wp_create_nonce( self::NONCE_ACTION ),
			],
			admin_url( 'admin-post.php' )
		);
		?>
		<div class="dp-csv" data-dp-group-csv>
			<h3 class="dp-csv-heading"><?php esc_html_e( 'Import and export groups', 'rowsprout' ); ?></h3>
			<?php if ( $isChild ) : ?>
				<p class="description"><?php esc_html_e( 'Edit this child template\'s values in Excel, Google Sheets or another spreadsheet app: download them, change them, and import the file again. The groups come from the parent template: a row updates the group with its ID or URL, and other rows are skipped. An empty cell means the value is inherited from the parent.', 'rowsprout' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Edit the groups in Excel, Google Sheets or another spreadsheet app: download them, change or add rows, and import the file again. A row with the ID or URL of an existing group updates that group; other rows become new groups.', 'rowsprout' ); ?></p>
			<?php endif; ?>
			<p class="description dp-csv-parent-changed" hidden><em><?php esc_html_e( 'The parent template has changed. Save the template to import or export its groups.', 'rowsprout' ); ?></em></p>
			<?php if ( ! $saved ) : ?>
				<p class="description"><em><?php esc_html_e( 'Save the template first (a draft is fine) to import or export groups.', 'rowsprout' ); ?></em></p>
			<?php endif; ?>
			<div class="dp-csv-actions">
				<div class="dp-csv-action">
					<label for="dp-csv-format"><?php esc_html_e( 'Export', 'rowsprout' ); ?></label>
					<div class="dp-csv-inline">
						<select id="dp-csv-format"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?>>
							<?php foreach ( GroupCsvFormat::all() as $format => $definition ) : ?>
								<option value="<?php echo esc_attr( $format ); ?>"><?php echo esc_html( $definition['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button" id="dp-csv-export" data-export-url="<?php echo esc_url( $exportUrl ); ?>"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?>><?php esc_html_e( 'Download groups', 'rowsprout' ); ?></button>
					</div>
					<p class="description"><?php esc_html_e( 'Downloads the saved groups. Save the template first to include changes you just made.', 'rowsprout' ); ?></p>
				</div>
				<div class="dp-csv-action">
					<span class="dp-csv-label"><?php esc_html_e( 'Import', 'rowsprout' ); ?></span>
					<div class="dp-csv-inline">
						<button type="button" class="button" id="dp-csv-open"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed attribute. ?>><?php esc_html_e( 'Import groups…', 'rowsprout' ); ?></button>
					</div>
					<p class="description"><?php echo esc_html( $isChild
						? __( 'Updates this child template\'s values. Nothing is removed, and no pages are generated until you update the template.', 'rowsprout' )
						: __( 'Adds and updates groups. Nothing is removed, and no pages are generated until you update the template.', 'rowsprout' ) ); ?></p>
				</div>
			</div>
			<?php
			if ( $saved ) {
				self::renderWizard();
				self::enqueueScript( $post );
			}
			?>
		</div>
		<?php
	}

	private static function renderWizard(): void {
		$encodings = [
			'utf-8'        => __( 'Unicode (UTF-8)', 'rowsprout' ),
			'windows-1252' => __( 'Western European (Windows-1252)', 'rowsprout' ),
			'iso-8859-15'  => __( 'Western European (ISO-8859-15)', 'rowsprout' ),
			'windows-1250' => __( 'Central European (Windows-1250)', 'rowsprout' ),
			'utf-16le'     => __( 'Unicode (UTF-16 LE)', 'rowsprout' ),
			'utf-16be'     => __( 'Unicode (UTF-16 BE)', 'rowsprout' ),
		];
		$delimiters = [
			'tab'       => __( 'Tab', 'rowsprout' ),
			'semicolon' => __( 'Semicolon', 'rowsprout' ),
			'comma'     => __( 'Comma', 'rowsprout' ),
			'space'     => __( 'Space', 'rowsprout' ),
		];
		?>
		<div id="dp-csv-modal" class="dp-csv-modal" hidden>
			<div class="dp-csv-backdrop"></div>
			<div class="dp-csv-dialog" role="dialog" aria-modal="true" aria-labelledby="dp-csv-title">
				<div class="dp-csv-dialog-head">
					<div>
						<strong id="dp-csv-title"><?php esc_html_e( 'Import groups', 'rowsprout' ); ?></strong>
						<span class="dp-csv-step-label" id="dp-csv-step-label"></span>
					</div>
					<button type="button" class="button-link dp-csv-close" data-csv-close aria-label="<?php echo esc_attr__( 'Close', 'rowsprout' ); ?>">&times;</button>
				</div>
				<div class="dp-csv-dialog-body">
					<section data-csv-step="1">
						<div class="dp-csv-box" id="dp-csv-unsaved" role="alert" hidden><p><strong></strong></p></div>
						<p>
							<label for="dp-csv-source"><?php esc_html_e( 'Source', 'rowsprout' ); ?></label><br />
							<select id="dp-csv-source">
								<option value="file"><?php esc_html_e( 'A file on this computer', 'rowsprout' ); ?></option>
								<option value="url"><?php esc_html_e( 'A URL', 'rowsprout' ); ?></option>
							</select>
						</p>
						<p data-csv-source-row="file">
							<label for="dp-csv-file"><?php esc_html_e( 'File', 'rowsprout' ); ?></label><br />
							<input type="file" id="dp-csv-file" accept=".csv,.txt,.tsv,text/csv,text/plain,text/tab-separated-values" />
						</p>
						<div data-csv-source-row="url" hidden>
							<label for="dp-csv-url"><?php esc_html_e( 'URL', 'rowsprout' ); ?></label>
							<div class="dp-csv-inline">
								<input type="url" id="dp-csv-url" class="dp-csv-url" placeholder="https://" />
								<button type="button" class="button" id="dp-csv-fetch"><?php esc_html_e( 'Fetch', 'rowsprout' ); ?></button>
							</div>
							<p class="description"><?php esc_html_e( 'A CSV or text file on the web, or a Google Sheets link of a sheet shared with "Anyone with the link".', 'rowsprout' ); ?></p>
							<p class="dp-csv-fetched" id="dp-csv-fetched" hidden></p>
						</div>
						<div class="dp-csv-fields">
							<p>
								<label for="dp-csv-encoding"><?php esc_html_e( 'Character encoding', 'rowsprout' ); ?></label><br />
								<select id="dp-csv-encoding">
									<?php foreach ( $encodings as $encoding => $label ) : ?>
										<option value="<?php echo esc_attr( $encoding ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label for="dp-csv-start"><?php esc_html_e( 'Start import at row', 'rowsprout' ); ?></label><br />
								<input type="number" id="dp-csv-start" min="1" step="1" value="1" />
							</p>
						</div>
						<p><label><input type="checkbox" id="dp-csv-headers" checked="checked" /> <?php esc_html_e( 'My data has headers', 'rowsprout' ); ?></label></p>
						<p class="dp-csv-notice" id="dp-csv-encoding-notice" hidden></p>
						<p class="description dp-csv-help"><?php esc_html_e( 'The character encoding is detected automatically. Change it only if letters such as é or ü look wrong in the preview.', 'rowsprout' ); ?></p>
						<p class="dp-csv-recognised" id="dp-csv-recognised" hidden></p>
						<p><button type="button" class="button-link" id="dp-csv-preview-toggle" aria-expanded="false" aria-controls="dp-csv-file-preview" hidden><?php esc_html_e( 'Show preview', 'rowsprout' ); ?></button></p>
						<div class="dp-csv-preview" id="dp-csv-file-preview" tabindex="0" hidden></div>
					</section>

					<section data-csv-step="2" hidden>
						<p class="description"><?php esc_html_e( 'Choose the characters that separate the columns. The preview shows how the file is split.', 'rowsprout' ); ?></p>
						<fieldset class="dp-csv-delimiters">
							<legend class="dp-csv-label"><?php esc_html_e( 'Delimiters', 'rowsprout' ); ?></legend>
							<?php foreach ( $delimiters as $delimiter => $label ) : ?>
								<label><input type="checkbox" data-csv-delimiter="<?php echo esc_attr( $delimiter ); ?>" /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<label><input type="checkbox" data-csv-delimiter="other" /> <?php esc_html_e( 'Other:', 'rowsprout' ); ?></label>
							<input type="text" id="dp-csv-other" class="dp-csv-other" maxlength="1" aria-label="<?php echo esc_attr__( 'Other delimiter', 'rowsprout' ); ?>" />
						</fieldset>
						<div class="dp-csv-fields">
							<p><label><input type="checkbox" id="dp-csv-consecutive" /> <?php esc_html_e( 'Treat consecutive delimiters as one', 'rowsprout' ); ?></label></p>
							<p>
								<label for="dp-csv-qualifier"><?php esc_html_e( 'Text qualifier', 'rowsprout' ); ?></label>
								<select id="dp-csv-qualifier">
									<option value="&quot;">"</option>
									<option value="'">'</option>
									<option value=""><?php esc_html_e( '{none}', 'rowsprout' ); ?></option>
								</select>
							</p>
						</div>
						<p class="dp-csv-label"><?php esc_html_e( 'Data preview', 'rowsprout' ); ?></p>
						<div class="dp-csv-preview" id="dp-csv-preview"></div>
					</section>

					<section data-csv-step="3" hidden>
						<p class="description"><?php esc_html_e( 'Choose what each column fills. A column can also become a new property of this template. Rows are matched to existing groups by ID, else by Href.', 'rowsprout' ); ?></p>
						<div class="dp-csv-map-wrap">
							<table class="widefat striped dp-csv-map">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Column in the file', 'rowsprout' ); ?></th>
										<th><?php esc_html_e( 'Example values', 'rowsprout' ); ?></th>
										<th><?php esc_html_e( 'Import as', 'rowsprout' ); ?></th>
									</tr>
								</thead>
								<tbody id="dp-csv-map-body"></tbody>
							</table>
						</div>
					</section>

					<section data-csv-step="4" hidden>
						<div id="dp-csv-summary" aria-live="polite"></div>
					</section>

					<section data-csv-step="done" hidden>
						<div id="dp-csv-done" aria-live="polite"></div>
					</section>

					<div class="dp-csv-error" id="dp-csv-error" role="alert" hidden></div>
				</div>
				<div class="dp-csv-dialog-foot">
					<button type="button" class="button" data-csv-close data-csv-cancel><?php esc_html_e( 'Cancel', 'rowsprout' ); ?></button>
					<span class="dp-csv-foot-spacer"></span>
					<button type="button" class="button" id="dp-csv-back"><?php esc_html_e( 'Back', 'rowsprout' ); ?></button>
					<button type="button" class="button button-primary" id="dp-csv-next"><?php esc_html_e( 'Next', 'rowsprout' ); ?></button>
					<button type="button" class="button button-primary" id="dp-csv-import" hidden><?php esc_html_e( 'Import', 'rowsprout' ); ?></button>
				</div>
			</div>
			<?php self::renderPropertyDialog(); ?>
		</div>
		<?php
	}

	/**
	 * Settings of a column imported as a new property: the same fields,
	 * classes and texts as the Properties tab's "Add property" dialog
	 * (GroupsMetaBoxRenderer), on top of the wizard. group-csv.js fills the
	 * types and options.
	 */
	private static function renderPropertyDialog(): void {
		?>
		<div class="dp-csv-prop-modal" id="dp-csv-prop-modal" hidden>
			<div class="dp-add-col-backdrop" data-csv-prop-cancel></div>
			<div class="dp-add-col-dialog" role="dialog" aria-modal="true" aria-labelledby="dp-csv-prop-title">
				<div class="dp-add-col-dialog-head">
					<strong id="dp-csv-prop-title"><?php esc_html_e( 'Add new property', 'rowsprout' ); ?></strong>
					<button type="button" class="button-link dp-add-col-close" data-csv-prop-cancel aria-label="<?php echo esc_attr__( 'Close', 'rowsprout' ); ?>">&times;</button>
				</div>
				<div class="dp-add-col-content">
					<table class="dp-add-col-table">
						<tr>
							<td class="dp-add-col-label-cell"><label for="dp-csv-prop-type"><?php esc_html_e( 'Type', 'rowsprout' ); ?></label></td>
							<td class="dp-add-col-field-cell">
								<select id="dp-csv-prop-type" class="dp-add-col-input"></select>
								<div id="dp-csv-prop-type-description" class="dp-type-description"></div>
							</td>
						</tr>
						<tr id="dp-csv-prop-options-row" hidden>
							<td class="dp-add-col-label-cell"><label for="dp-csv-prop-options"><?php esc_html_e( 'Options', 'rowsprout' ); ?></label></td>
							<td class="dp-add-col-field-cell">
								<textarea id="dp-csv-prop-options" rows="4" class="dp-add-col-input" placeholder="<?php echo esc_attr__( 'One option per line', 'rowsprout' ); ?>"></textarea>
							</td>
						</tr>
						<tr>
							<td class="dp-add-col-label-cell"><label for="dp-csv-prop-label"><?php esc_html_e( 'Label', 'rowsprout' ); ?></label></td>
							<td class="dp-add-col-field-cell">
								<input type="text" id="dp-csv-prop-label" class="dp-add-col-input" />
								<div class="dp-csv-prop-error" id="dp-csv-prop-error" role="alert" hidden></div>
							</td>
						</tr>
						<tr>
							<td class="dp-add-col-label-cell"><label for="dp-csv-prop-overruled"><?php esc_html_e( 'Overridable', 'rowsprout' ); ?></label></td>
							<td class="dp-add-col-field-cell">
								<label><input type="checkbox" id="dp-csv-prop-overruled" checked="checked" /> <?php esc_html_e( 'A child template can override this property with its own value.', 'rowsprout' ); ?></label>
							</td>
						</tr>
					</table>
					<div class="dp-modal-actions">
						<button type="button" class="button" data-csv-prop-cancel><?php esc_html_e( 'Cancel', 'rowsprout' ); ?></button>
						<button type="button" class="button button-primary" id="dp-csv-prop-confirm"><?php esc_html_e( 'Add property', 'rowsprout' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	private static function enqueueScript( \WP_Post $post ): void {
		$suffix       = defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min';
		$relativePath = 'assets/js/group-csv' . $suffix . '.js';
		$scriptFile   = ROWSPROUT_PATH . $relativePath;
		$version      = file_exists( $scriptFile ) ? (string) filemtime( $scriptFile ) : ROWSPROUT_VERSION;

		$template = GroupCsvTemplate::load( (int) $post->ID );
		$columns  = [];
		foreach ( $template->columns as $column ) {
			$columns[] = [
				'key'       => (string) $column['key'],
				'type'      => (string) $column['type'],
				'label'     => (string) $column['label'],
				'code'      => (string) $column['code'],
				'matchOnly' => ! empty( $column['match_only'] ),
			];
		}
		$types = [];
		foreach ( TemplateMeta::getFieldTypeDefinitions() as $type => $definition ) {
			if ( in_array( $type, [ 'title', 'href', 'slug' ], true ) ) {
				continue;
			}
			$types[] = [
				'type'            => (string) $type,
				'label'           => (string) $definition['label'],
				'description'     => (string) ( $definition['description'] ?? '' ),
				'allowMultiple'   => ! empty( $definition['allow_multiple'] ),
				'supportsOptions' => ! empty( $definition['supports_options'] ) || ( $definition['type'] ?? '' ) === 'select',
			];
		}
		$generates = in_array( $post->post_status, [ 'publish', 'future', 'private' ], true );

		wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( $relativePath, ROWSPROUT_FILE ), [ 'jquery' ], $version, true );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.rowsproutGroupCsvConfig = ' . wp_json_encode( [
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => self::IMPORT_ACTION,
				'fetchAction' => self::FETCH_ACTION,
				'lastUrl'    => (string) get_post_meta( (int) $post->ID, self::URL_META, true ),
				'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
				'templateId' => (int) $post->ID,
				'editUrl'    => (string) get_edit_post_link( (int) $post->ID, 'raw' ),
				'idHeader'   => GroupCsvExporter::ID_HEADER,
				'isChild'    => $template->isChild,
				'parentId'   => (int) $post->post_parent,
				'columns'    => $columns,
				// Labels a new property cannot have; a child's include its
				// parent's properties it cannot fill.
				'reservedLabels' => array_values( array_map( 'strval', array_column( $template->reservedColumns, 'label' ) ) ),
				'types'      => $types,
				'i18n'       => [
					'steps'           => [
						__( 'Step 1 of 4: Choose a file or URL', 'rowsprout' ),
						__( 'Step 2 of 4: Delimiters', 'rowsprout' ),
						__( 'Step 3 of 4: Columns', 'rowsprout' ),
						__( 'Step 4 of 4: Summary', 'rowsprout' ),
					],
					'noFile'          => __( 'Choose a file first.', 'rowsprout' ),
					'readError'       => __( 'The file could not be read.', 'rowsprout' ),
					'noUrl'           => __( 'Enter a URL and click Fetch first.', 'rowsprout' ),
					'fetching'        => __( 'Fetching…', 'rowsprout' ),
					/* translators: 1: file name, 2: file size such as "12 KB". */
					'fetched'         => __( 'Fetched %1$s (%2$s).', 'rowsprout' ),
					'emptyFile'       => __( 'The file is empty.', 'rowsprout' ),
					'notUtf8'         => __( 'This file is not UTF-8, so it is read as Western European (Windows-1252), the way Excel saves "CSV (comma delimited)". Choose another character encoding if letters look wrong.', 'rowsprout' ),
					'noDelimiter'     => __( 'Choose at least one delimiter.', 'rowsprout' ),
					'showPreview'     => __( 'Show preview', 'rowsprout' ),
					'hidePreview'     => __( 'Hide preview', 'rowsprout' ),
					/* translators: 1: number of columns, 2: number of rows, 3: delimiter names such as "semicolons". */
					'recognised'      => __( 'Columns: %1$d · Rows to import: %2$d · Separated by: %3$s. Not right? Choose other delimiters in the next step.', 'rowsprout' ),
					'delimiterNames'  => [
						'tab'       => __( 'tabs', 'rowsprout' ),
						'semicolon' => __( 'semicolons', 'rowsprout' ),
						'comma'     => __( 'commas', 'rowsprout' ),
						'space'     => __( 'spaces', 'rowsprout' ),
						/* translators: %s: the delimiter character. */
						'other'     => __( '"%s"', 'rowsprout' ),
					],
					'noRows'          => __( 'There are no rows to import from the chosen start row on.', 'rowsprout' ),
					/* translators: 1: number of rows shown, 2: total number of rows. */
					'previewCount'    => __( 'The first %1$d of %2$d rows.', 'rowsprout' ),
					/* translators: %d: column number. */
					'column'          => __( 'Column %d', 'rowsprout' ),
					'doNotImport'     => __( '— Do not import —', 'rowsprout' ),
					'idTarget'        => __( 'ID (finds the existing group)', 'rowsprout' ),
					/* translators: %s: property label, such as "Title". */
					'findOnly'        => __( '%s (finds the group)', 'rowsprout' ),
					'properties'      => __( 'Properties', 'rowsprout' ),
					'newProperty'     => __( 'New property…', 'rowsprout' ),
					'newPropertiesGroup' => __( 'New properties', 'rowsprout' ),
					/* translators: %s: comma-separated column names from the file. */
					'alsoChosen'      => __( 'Also chosen for: %s', 'rowsprout' ),
					'duplicateTargets' => __( 'Two or more columns are imported into the same property. Choose one column per property.', 'rowsprout' ),
					'labelRequired'   => __( 'Give the property a label.', 'rowsprout' ),
					'duplicateLabel'  => __( 'This property title already exists in this template.', 'rowsprout' ),
					'checking'        => __( 'Checking the file…', 'rowsprout' ),
					'importing'       => __( 'Importing…', 'rowsprout' ),
					'summaryHeading'  => __( 'What the import will do', 'rowsprout' ),
					/* translators: %d: number of groups. */
					'added'           => __( 'New groups: %d', 'rowsprout' ),
					/* translators: %d: number of groups. */
					'updated'         => __( 'Groups updated: %d', 'rowsprout' ),
					/* translators: %d: number of groups. */
					'unchanged'       => __( 'Groups already up to date: %d', 'rowsprout' ),
					/* translators: %s: comma-separated property names. */
					'newProperties'   => __( 'New properties: %s', 'rowsprout' ),
					/* translators: %d: number of rows. */
					'skipped'         => __( 'Rows skipped: %d', 'rowsprout' ),
					'warnings'        => __( 'Check before you import', 'rowsprout' ),
					'nothing'         => __( 'Nothing changes: every row matches a group that already has these values.', 'rowsprout' ),
					'saveNote'        => __( 'When you click Import, the groups are saved right away and the page reloads automatically to show them.', 'rowsprout' ),
					'reloading'       => __( 'Imported. Reloading the page…', 'rowsprout' ),
					'unsaved'         => __( 'This template has changes that are not saved yet. They are lost when the page reloads after the import. Cancel and save the template first to keep them.', 'rowsprout' ),
					'generate'        => $generates
						? __( 'No pages are generated yet: after the import, update the template with "Create & update pages" to generate them.', 'rowsprout' )
						: __( 'Pages are generated when you publish the template.', 'rowsprout' ),
					'nothingSaved'    => __( 'Nothing was changed.', 'rowsprout' ),
					'requestFailed'   => __( 'The server did not answer as expected. Try again.', 'rowsprout' ),
				],
			] ) . ';',
			'before'
		);
	}

	public static function handleExport(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked right below, after the id is known.
		$templateId = isset( $_GET['template'] ) ? absint( $_GET['template'] ) : 0;
		check_admin_referer( self::NONCE_ACTION );
		$post = get_post( $templateId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE || ! current_user_can( 'edit_post', $templateId ) ) {
			wp_die( esc_html__( 'You are not allowed to export the groups of this template.', 'rowsprout' ), 403 );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : GroupCsvFormat::DEFAULT_FORMAT;
		$body   = GroupCsvFormat::encode( GroupCsvExporter::rows( $templateId ), $format );
		$type   = GroupCsvFormat::get( $format )['extension'] === 'csv' ? 'text/csv' : 'text/plain';

		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . GroupCsvExporter::fileName( $post, $format ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV file download, not HTML.
		exit;
	}

	/**
	 * A Google Sheets link as its CSV export (the sheet must be shared with
	 * "Anyone with the link"); any other URL as it is.
	 */
	public static function normalizeUrl( string $url ): string {
		$url = trim( $url );
		// A sheet's own link (…/edit, …/view); a published (/d/e/…/pub) or
		// export link already gives CSV.
		if ( preg_match( '~^https://docs\.google\.com/spreadsheets/d/(?!e/)([A-Za-z0-9_-]+)(?:/(?:edit|view|htmlview)[^?#]*|/)?(?:[?#].*)?$~', $url, $match ) === 1 ) {
			$gid = '';
			if ( preg_match( '~[?&#]gid=(\d+)~', $url, $gidMatch ) === 1 ) {
				$gid = '&gid=' . $gidMatch[1];
			}
			return 'https://docs.google.com/spreadsheets/d/' . $match[1] . '/export?format=csv' . $gid;
		}

		return $url;
	}

	/**
	 * Fetches the file at a URL through WordPress's safe HTTP API (no
	 * local-network addresses).
	 *
	 * @return array{content?:string, name?:string, size?:string, error?:string}
	 *         The file's bytes, a file name and a readable size; or an error.
	 */
	public static function fetch( string $url ): array {
		$url      = self::normalizeUrl( $url );
		$response = wp_safe_remote_get( $url, [
			'timeout'             => 20,
			'redirection'         => 5,
			'limit_response_size' => self::MAX_URL_BYTES + 1,
		] );
		if ( is_wp_error( $response ) ) {
			return [ 'error' => sprintf(
				/* translators: %s: error message. */
				__( 'The URL could not be fetched: %s', 'rowsprout' ),
				$response->get_error_message()
			) ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code !== 200 ) {
			return [ 'error' => sprintf(
				/* translators: %d: HTTP status code such as 404. */
				__( 'The URL answered with HTTP status %d instead of a file.', 'rowsprout' ),
				$code
			) ];
		}
		if ( strlen( $body ) > self::MAX_URL_BYTES ) {
			return [ 'error' => sprintf(
				/* translators: %s: maximum size such as "10 MB". */
				__( 'The file is larger than %s.', 'rowsprout' ),
				size_format( self::MAX_URL_BYTES )
			) ];
		}
		if ( trim( $body ) === '' ) {
			return [ 'error' => __( 'The URL gave an empty file.', 'rowsprout' ) ];
		}
		// A sign-in or error page instead of a file, such as a Google sheet
		// that is not shared with "Anyone with the link".
		if ( preg_match( '~^\s*<(!doctype html|html)~i', $body ) === 1 ) {
			return [ 'error' => __( 'This URL gives a web page, not a CSV file. For a Google sheet, share it with "Anyone with the link".', 'rowsprout' ) ];
		}

		$name = sanitize_file_name( (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );

		return [
			'content' => $body,
			'name'    => $name !== '' && $name !== 'export' ? $name : 'download.csv',
			'size'    => (string) size_format( strlen( $body ) ),
		];
	}

	/**
	 * The wizard's "Fetch": the file at a URL (fetch()) as base64, which
	 * the browser decodes and splits as it does an uploaded file. The URL is
	 * remembered for the template.
	 */
	public static function handleFetch(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$templateId = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$post       = get_post( $templateId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE || ! current_user_can( 'edit_post', $templateId ) ) {
			wp_send_json_error( [ 'errors' => [ __( 'You are not allowed to import groups into this template.', 'rowsprout' ) ] ], 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['url'] ) ), [ 'http', 'https' ] ) : '';
		if ( $url === '' ) {
			wp_send_json_error( [ 'errors' => [ __( 'Enter a URL that starts with http:// or https://.', 'rowsprout' ) ] ] );
		}

		$file = self::fetch( $url );
		if ( isset( $file['error'] ) ) {
			wp_send_json_error( [ 'errors' => [ $file['error'] ] ] );
		}

		update_post_meta( $templateId, self::URL_META, wp_slash( $url ) );

		wp_send_json_success( [
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the file's bytes for the browser, which detects the encoding itself.
			'content' => base64_encode( (string) $file['content'] ),
			'name'    => $file['name'],
			'size'    => $file['size'],
		] );
	}

	public static function handleImport(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$templateId = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$post       = get_post( $templateId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE || ! current_user_can( 'edit_post', $templateId ) ) {
			wp_send_json_error( [ 'errors' => [ __( 'You are not allowed to import groups into this template.', 'rowsprout' ) ] ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every value is sanitized by GroupCsvImporter.
		$payload = json_decode( isset( $_POST['payload'] ) ? (string) wp_unslash( $_POST['payload'] ) : '', true );
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$apply   = isset( $_POST['apply'] ) && $_POST['apply'] === '1';

		$result = GroupCsvImporter::run( $templateId, is_array( $payload ) ? $payload : [], $apply, $version );

		wp_send_json_success( $result->toArray() );
	}
}
