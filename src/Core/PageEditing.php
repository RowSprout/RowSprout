<?php

namespace RowSprout\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generated rowsprout_page posts are locked from direct editing and deletion by
 * default: the next regeneration overwrites them and the next queue run
 * recreates a deleted one, so direct changes never stick. The Settings page
 * lets a site owner switch that off (option rowsprout_unlock_pages).
 *
 * A default, not a paywall: the unlock must live in this plugin, since an
 * unlock in RowSprout Pro would read as a built-in restriction lifted by a
 * paid plugin (WordPress.org Guideline 5). Pro migrates its old option
 * rowsprout_pro_unlock_pages into this one.
 */
final class PageEditing {

	public const OPTION = 'rowsprout_unlock_pages';

	private const NONCE_ACTION = 'rowsprout_page_editing_action';
	private const NONCE_FIELD  = 'rowsprout_page_editing_nonce';

	/**
	 * Capabilities that are denied while pages are locked. With map_meta_cap,
	 * 'edit_post'/'delete_post' resolve to these depending on author/status
	 * (wp-includes/capabilities.php). Plain 'edit_posts'/'delete_posts' stay
	 * untouched: they also decide whether the post type's list table and menu
	 * are usable at all, and generated pages are always published, so they
	 * never decide an edit/delete of one.
	 */
	private const LOCKED_CAPABILITIES = [
		'edit_others_posts',
		'edit_published_posts',
		'edit_private_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_private_posts',
	];

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'handleSave' ] );
	}

	public static function isUnlocked(): bool {
		return (bool) get_option( self::OPTION, false );
	}

	/**
	 * The capability overrides for the rowsprout_page post type; a dropped key
	 * falls back to the capability_type default, i.e. normal post permissions.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		$capabilities = [
			'read_post'    => 'read_rowsprout_page',
			'create_posts' => 'do_not_allow',
		];

		if ( ! self::isUnlocked() ) {
			foreach ( self::LOCKED_CAPABILITIES as $capability ) {
				$capabilities[ $capability ] = 'do_not_allow';
			}
		}

		return $capabilities;
	}

	/**
	 * Fields only: rendered inside the Settings page's single form
	 * (Admin\Menu::renderSettingsPage()), which has the one Save button.
	 */
	public static function renderSettingsFields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<h2><?php esc_html_e( 'Generated page editing', 'rowsprout' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Generated pages are locked by default: they are overwritten the next time their template is saved and its pages are regenerated, so direct edits and deletions do not stick.', 'rowsprout' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Editing', 'rowsprout' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>" value="1" <?php checked( self::isUnlocked() ); ?> />
						<?php esc_html_e( 'Allow generated pages to be edited and deleted directly.', 'rowsprout' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function handleSave(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'rowsprout' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change this setting.', 'rowsprout' ) );
		}

		$unlock = ! empty( $_POST[ self::OPTION ] );
		if ( $unlock === self::isUnlocked() ) {
			return;
		}

		update_option( self::OPTION, $unlock );

		// The post type was registered on init with the old value; the
		// capabilities take effect from the next request.
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Setting saved.', 'rowsprout' ) . '</p></div>';
		} );
	}
}
