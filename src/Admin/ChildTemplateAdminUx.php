<?php

namespace RowSprout\Admin;

use RowSprout\Core\PostTypes;
use RowSprout\Core\SavePost;
use RowSprout\Core\Template\Lifecycle\TemplateDeletionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-screen UX for child templates on the template edit and list
 * screens: the "Parent" dropdown only offers valid parents, the list's
 * trash/restore row actions say that the parent/child pairs go together
 * (TemplateDeletionManager trashes and restores them together), and bulk
 * actions on a selection holding both a parent and its children succeed.
 */
final class ChildTemplateAdminUx {

	public static function register(): void {
		add_filter( 'page_attributes_dropdown_pages_args', [ self::class, 'excludeChildTemplatesFromParentDropdown' ], 10, 2 );
		add_action( 'page_attributes_misc_attributes', [ self::class, 'explainMissingParentField' ] );
		// Templates are hierarchical, so their list uses page_row_actions.
		add_filter( 'page_row_actions', [ self::class, 'filterRowActions' ], 20, 2 );
		// Fires before wp-admin/edit.php runs its bulk action loop.
		add_action( 'load-edit.php', [ self::class, 'leaveBulkSelectionToCore' ] );
	}

	/**
	 * A bulk trash, delete or restore that holds a parent and its children
	 * (select all, Empty Trash, Undo) would otherwise end in a 500: the
	 * parent's cascade handles the children first, then core's loop fails
	 * on them. Core handles every selected template itself, so the cascades
	 * skip those. Mirrors how wp-admin/edit.php picks the action and ids.
	 */
	public static function leaveBulkSelectionToCore(): void {
		global $typenow, $wpdb;

		if ( $typenow !== PostTypes::TEMPLATE ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only: edit.php checks the bulk-posts nonce before it acts on these ids.
		if ( ! empty( $_REQUEST['filter_action'] ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( isset( $_REQUEST['delete_all'] ) || isset( $_REQUEST['delete_all2'] ) ) {
			$action = 'delete_all';
		}

		if ( ! in_array( $action, [ 'trash', 'untrash', 'delete', 'delete_all' ], true ) ) {
			return;
		}

		$ids = [];
		if ( $action === 'delete_all' ) {
			$status = isset( $_REQUEST['post_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_status'] ) ) : '';
			if ( $status !== '' ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the same query edit.php runs for Empty Trash.
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", PostTypes::TEMPLATE, $status ) );
			}
		} elseif ( isset( $_REQUEST['ids'] ) ) {
			$ids = explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) );
		} elseif ( ! empty( $_REQUEST['post'] ) ) {
			$ids = (array) wp_unslash( $_REQUEST['post'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ids = array_filter( array_map( 'intval', $ids ) );

		// Core skips a locked post when trashing, so the cascade must still
		// take a locked child along with its parent.
		if ( $action === 'trash' && function_exists( 'wp_check_post_lock' ) ) {
			$ids = array_filter( $ids, static function ( int $id ): bool {
				return ! wp_check_post_lock( $id );
			} );
		}

		TemplateDeletionManager::leaveToCaller( $ids );
	}

	/**
	 * Templates can only be nested 1 level deep (enforced on save by
	 * SavePost::limitPostDepth()). A template that already has a parent can
	 * therefore never itself become a valid parent, so hide it from the
	 * "Parent" dropdown; and a template that has child templates can't get a
	 * parent at all, so it gets no dropdown (an empty list makes WordPress
	 * leave the Parent field out — see explainMissingParentField()).
	 *
	 * @param array<string, mixed> $args
	 */
	public static function excludeChildTemplatesFromParentDropdown( array $args, \WP_Post $post ): array {
		if ( $post->post_type !== PostTypes::TEMPLATE ) {
			return $args;
		}

		// wp_dropdown_pages(): depth 1 lists top-level templates only.
		$args['depth'] = 1;

		if ( $post->ID > 0 && SavePost::hasChildTemplates( $post->ID ) ) {
			$args['include'] = [ -1 ];
		}

		return $args;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function filterRowActions( array $actions, \WP_Post $post ): array {
		if ( $post->post_type !== PostTypes::TEMPLATE ) {
			return $actions;
		}

		if ( isset( $actions['trash'] ) && self::hasChildTemplates( $post->ID ) ) {
			$actions['trash'] = '<a href="' . esc_url( get_delete_post_link( $post->ID ) ) . '" class="submitdelete" aria-label="' . esc_attr(
				sprintf(
					/* translators: %s: template title. */
					__( 'Move &#8220;%s&#8221; and its child pages to the Trash', 'rowsprout' ),
					$post->post_title
				)
			) . '">' . esc_html__( 'Trash with Child pages', 'rowsprout' ) . '</a>';
		}

		// Restoring a child template also restores its parent (see
		// TemplateDeletionManager::restoreParentTemplate() in the base
		// plugin) — but only when the parent is actually still trashed. If
		// the parent was already restored separately, plain "Restore" is
		// accurate again.
		$parentId = wp_get_post_parent_id( $post );
		if ( isset( $actions['untrash'] ) && $parentId && get_post_status( $parentId ) === 'trash' ) {
			$actions['untrash'] = '<a href="' . esc_url(
				wp_nonce_url( admin_url( 'post.php?post=' . $post->ID . '&action=untrash' ), 'untrash-post_' . $post->ID )
			) . '" aria-label="' . esc_attr(
				sprintf(
					/* translators: %s: template title. */
					__( 'Restore &#8220;%s&#8221; and its parent from the Trash', 'rowsprout' ),
					$post->post_title
				)
			) . '">' . esc_html__( 'Restore with Parent', 'rowsprout' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Classic screen: says why a template with child templates has no Parent
	 * field.
	 */
	public static function explainMissingParentField( \WP_Post $post ): void {
		if ( $post->post_type !== PostTypes::TEMPLATE || ! SavePost::hasChildTemplates( $post->ID ) ) {
			return;
		}

		echo '<p class="description">' . esc_html__( 'This template has child templates, so it cannot have a parent itself.', 'rowsprout' ) . '</p>';
	}

	/**
	 * Whether this template has at least one child template (post_parent).
	 */
	private static function hasChildTemplates( int $templateId ): bool {
		$children = get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_parent'    => $templateId,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		return ! empty( $children );
	}
}
