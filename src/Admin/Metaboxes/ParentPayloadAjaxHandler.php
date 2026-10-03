<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handler behind the "Parent" dropdown's live sync — when a template's
 * parent selection changes, the Properties/Groups tabs are rebuilt from the
 * new parent's config without a full page reload (assets/js/groups-metabox.js).
 */
final class ParentPayloadAjaxHandler {

	public static function register(): void {
		add_action( 'wp_ajax_dp_rowsprout_template_parent_payload', [ self::class, 'handle' ] );
	}

	public static function handle(): void {
		check_ajax_referer( 'rowsprout_page_groups_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit templates.', 'rowsprout' ) ], 403 );
		}

		$postId   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$parentId = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;

		if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to edit this template.', 'rowsprout' ) ], 403 );
		}

		if ( $parentId > 0 ) {
			$parent = get_post( $parentId );
			if ( ! $parent instanceof \WP_Post || $parent->post_type !== PostTypes::TEMPLATE ) {
				wp_send_json_error( [ 'message' => __( 'Invalid parent template.', 'rowsprout' ) ], 400 );
			}
		}

		if ( $postId > 0 && $parentId === $postId ) {
			$parentId = 0;
		}

		$payload = ParentSyncPayloadRenderer::build( $parentId, $postId );
		wp_send_json_success( $payload );
	}
}
