<?php

namespace RowSprout\Core\Groups;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupDeletionService {

	/**
	 * @param array<int, mixed> $ids
	 */
	public static function deleteGroupsAndPagesByIds( array $ids ): void {
		if ( empty( $ids ) ) {
			return;
		}

		$guidValues = [];
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}
			$guid = sanitize_text_field( (string) $id );
			if ( $guid !== '' ) {
				$guidValues[] = $guid;
			}
		}

		$guidValues = array_values( array_unique( $guidValues ) );
		if ( empty( $guidValues ) ) {
			return;
		}

		$rowsproutPageIds = GroupTableGateway::getRowSproutPageIdsByGuids( $guidValues );
		GroupTableGateway::deleteByGuids( $guidValues );

		if ( $rowsproutPageIds ) {
			foreach ( $rowsproutPageIds as $postId ) {
				if ( ! $postId ) {
					continue;
				}

				$childPages = get_posts( [
					'post_type'      => PostTypes::PAGE,
					'post_parent'    => $postId,
					'posts_per_page' => -1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				] );

				foreach ( $childPages as $childId ) {
					do_action( 'rowsprout_page_delete_wpml_translations', $childId, PostTypes::PAGE );
					wp_delete_post( (int) $childId, true );
				}

				do_action( 'rowsprout_page_delete_wpml_translations', $postId, PostTypes::PAGE );
				wp_delete_post( (int) $postId, true );
			}
		}
	}
}
