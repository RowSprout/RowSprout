<?php

namespace RowSprout\Admin;

use RowSprout\Core\PostTypes;
use RowSprout\Core\SavePost;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-screen UX for child templates on the template edit and list
 * screens: the "Parent" dropdown only offers valid parents, and the list's
 * trash/restore actions and bulk checkboxes follow the parent/child pairs
 * (TemplateDeletionManager trashes and restores them together).
 */
final class ChildTemplateAdminUx {

	public static function register(): void {
		add_filter( 'page_attributes_dropdown_pages_args', [ self::class, 'excludeChildTemplatesFromParentDropdown' ], 10, 2 );
		add_action( 'page_attributes_misc_attributes', [ self::class, 'explainMissingParentField' ] );
		// Templates are hierarchical, so their list uses page_row_actions.
		add_filter( 'page_row_actions', [ self::class, 'filterRowActions' ], 20, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueListScreenAssets' ] );
	}

	public static function enqueueListScreenAssets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . PostTypes::TEMPLATE ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which list view (Trash or not) is shown.
		$isTrashView = isset( $_GET['post_status'] ) && sanitize_key( wp_unslash( $_GET['post_status'] ) ) === 'trash';
		self::enqueueCascadeCheckboxScript( $isTrashView );
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

	/**
	 * Auto-select child row checkboxes when a parent row is selected (and
	 * vice versa on the Trash list). No-ops entirely if there are no child
	 * templates at all, so this is harmless to run unconditionally.
	 */
	public static function enqueueCascadeCheckboxScript( bool $isTrashView ): void {
		$childrenMap = self::buildTemplateChildrenMap();
		if ( empty( $childrenMap ) ) {
			return;
		}

		$lockedTitle         = __( 'This template is a child of a selected parent and will be trashed along with it.', 'rowsprout' );
		$parentRequiredTitle = __( 'Restoring this child template also restores its parent.', 'rowsprout' );

		wp_add_inline_script( 'jquery-core', '
			jQuery(document).ready(function($) {
				var dpTemplateChildrenMap = ' . wp_json_encode( $childrenMap ) . ';
				var dpChildToParentMap = {};
				Object.keys(dpTemplateChildrenMap).forEach(function(parentId) {
					dpTemplateChildrenMap[parentId].forEach(function(childId) {
						dpChildToParentMap[childId] = parseInt(parentId, 10);
					});
				});

				var dpIsTrashView = ' . wp_json_encode( $isTrashView ) . ';
				var dpLockedTitle = ' . wp_json_encode( $lockedTitle ) . ';
				var dpParentRequiredTitle = ' . wp_json_encode( $parentRequiredTitle ) . ';

				function dpSetChildCheckboxes(parentId, checked) {
					var children = dpTemplateChildrenMap[parentId];
					if (!children) { return; }
					children.forEach(function(childId) {
						var $cb = $("#cb-select-" + childId);
						if (!$cb.length) { return; }
						$cb.prop("checked", checked);
						$cb.attr("title", checked ? dpLockedTitle : "");
					});
				}

				if (dpIsTrashView) {
					// On the Trash list, a parent may be restored on its own, but
					// restoring a child always requires its parent too (checking a
					// child auto-checks its parent; the parent can\'t be unchecked
					// again while a child is still checked).
					$(document).on("change", "#the-list input[type=\'checkbox\'][name=\'post[]\']", function() {
						var id = parseInt($(this).val(), 10);
						if (!id) { return; }
						var parentId = dpChildToParentMap[id];
						if (!parentId || !this.checked) { return; }
						var $parentCb = $("#cb-select-" + parentId);
						if ($parentCb.length) {
							$parentCb.prop("checked", true).attr("title", dpParentRequiredTitle);
						}
					});

					$(document).on("click", "#the-list input[type=\'checkbox\'][name=\'post[]\']", function(e) {
						var id = parseInt($(this).val(), 10);
						if (!id || this.checked) { return; }
						var children = dpTemplateChildrenMap[id];
						if (!children) { return; }
						var hasCheckedChild = children.some(function(childId) {
							var $cb = $("#cb-select-" + childId);
							return $cb.length && $cb.is(":checked");
						});
						if (hasCheckedChild) {
							// Block unchecking a parent while one of its children is
							// still selected; uncheck the child(ren) first instead.
							e.preventDefault();
						}
					});
				} else {
					$(document).on("change", "#the-list input[type=\'checkbox\'][name=\'post[]\']", function() {
						var id = parseInt($(this).val(), 10);
						if (!id) { return; }
						dpSetChildCheckboxes(id, this.checked);
					});

					$(document).on("click", "#the-list input[type=\'checkbox\'][name=\'post[]\']", function(e) {
						var id = parseInt($(this).val(), 10);
						if (!id) { return; }
						var parentId = dpChildToParentMap[id];
						if (!parentId) { return; }
						var $parentCb = $("#cb-select-" + parentId);
						if ($parentCb.length && $parentCb.is(":checked") && !this.checked) {
							// Block unchecking a child while its parent is still selected;
							// uncheck the parent instead to release its children.
							e.preventDefault();
						}
					});
				}
			});
		' );
	}

	/**
	 * Map of parent template ID => array of its direct child template IDs.
	 *
	 * @return array<int, array<int, int>>
	 */
	private static function buildTemplateChildrenMap(): array {
		// 'any' excludes 'trash', but this map also needs to reflect
		// parent/child relationships on the Trash list itself, so list
		// statuses explicitly.
		$idToParent = get_posts( [
			'post_type'           => PostTypes::TEMPLATE,
			'post_status'         => [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ],
			'posts_per_page'      => -1,
			'fields'              => 'id=>parent',
			'post_parent__not_in' => [ 0 ],
			'no_found_rows'       => true,
		] );

		$childrenMap = [];
		foreach ( (array) $idToParent as $childId => $parentId ) {
			$parentId = (int) $parentId;
			if ( $parentId <= 0 ) {
				continue;
			}

			$childrenMap[ $parentId ][] = (int) $childId;
		}

		return $childrenMap;
	}
}
