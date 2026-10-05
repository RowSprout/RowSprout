<?php

namespace RowSprout\Core\Template\Lifecycle;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateDeletionManager {

	public static function restoreGeneratedPagesAndStatuses( int $templateId ): void {
		// Validate that template post exists
		if ( get_post_status( $templateId ) === false ) {
			return;
		}

		$rows = TemplateLifecycleGroupTableGateway::getRowsForRestore( $templateId );

		if ( is_array( $rows ) && ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$rowId  = isset( $row['id'] ) ? (int) $row['id'] : 0;
				$pageId = isset( $row['rowsprout_page_id'] ) ? (int) $row['rowsprout_page_id'] : 0;
				if ( $rowId <= 0 ) {
					continue;
				}

				$storedStatus = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
				$status       = TemplateDeletionStatus::restoreStatusFromDeletedMarker( $storedStatus );
				if ( $status === '' ) {
					$status = GroupTableGateway::STATUS_PENDING;
				}

				if ( $pageId > 0 ) {
					$pageStatus = get_post_status( $pageId );
					if ( $pageStatus === 'trash' ) {
						wp_untrash_post( $pageId );
						$pageStatus = get_post_status( $pageId );
					}

					if ( $pageStatus === false ) {
						$status = GroupTableGateway::STATUS_PENDING;
					}
				}

				TemplateLifecycleGroupTableGateway::updateStatusById( $rowId, $status );
			}
		}

		// Restoring a parent does NOT cascade down to its children — a parent
		// may be restored on its own. Restoring a child DOES cascade up to
		// its parent (see restoreParentTemplate()): a live child template
		// pointing at a still-trashed parent would be a broken state.
		self::restoreParentTemplate( $templateId );
	}

	public static function trashGeneratedPagesAndMarkDeleted( int $templateId ): void {
		$rows = TemplateLifecycleGroupTableGateway::getRowsForTrash( $templateId );

		if ( is_array( $rows ) && ! empty( $rows ) ) {
			TemplateGroupActionUnscheduler::unscheduleTemplateGroupActions( $templateId, $rows );

			foreach ( $rows as $row ) {
				$rowId  = isset( $row['id'] ) ? (int) $row['id'] : 0;
				$pageId = isset( $row['rowsprout_page_id'] ) ? (int) $row['rowsprout_page_id'] : 0;
				$status = isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : '';
				if ( $status === '' ) {
					$status = GroupTableGateway::STATUS_PENDING;
				}

				if ( $rowId > 0 ) {
					TemplateLifecycleGroupTableGateway::updateStatusById( $rowId, TemplateDeletionStatus::toDeletedStatus( $status ) );
				}

				if ( $pageId <= 0 ) {
					continue;
				}

				$status = get_post_status( $pageId );
				if ( $status === false || $status === 'trash' ) {
					continue;
				}

				wp_trash_post( $pageId );
			}
		}

		self::trashChildTemplates( $templateId );
	}

	public static function deleteGeneratedPagesAndRows( int $templateId ): void {
		$rows = TemplateLifecycleGroupTableGateway::getRowsForDelete( $templateId );

		if ( is_array( $rows ) && ! empty( $rows ) ) {
			TemplateGroupActionUnscheduler::unscheduleTemplateGroupActions( $templateId, $rows );

			foreach ( $rows as $row ) {
				$pageId = isset( $row['rowsprout_page_id'] ) ? (int) $row['rowsprout_page_id'] : 0;
				if ( $pageId <= 0 ) {
					continue;
				}

				if ( get_post_status( $pageId ) === false ) {
					continue;
				}

				wp_delete_post( $pageId, true );
			}

			TemplateLifecycleGroupTableGateway::deleteRowsByTemplateId( $templateId );
		}

		self::deleteChildTemplates( $templateId );
	}

	/**
	 * Trashing a parent template cascades to its child template(s), which in
	 * turn re-fires wp_trash_post for each child and lets this same handler
	 * trash the child's own generated pages. Templates only nest 1 level deep
	 * (see SavePost::limitPostDepth()), so this never recurses further.
	 */
	private static function trashChildTemplates( int $templateId ): void {
		foreach ( self::getChildTemplateIds( $templateId ) as $childId ) {
			if ( get_post_status( $childId ) !== 'trash' ) {
				wp_trash_post( $childId );
			}
		}
	}

	/**
	 * @see trashChildTemplates() for why this doesn't need to recurse further.
	 */
	private static function deleteChildTemplates( int $templateId ): void {
		foreach ( self::getChildTemplateIds( $templateId ) as $childId ) {
			if ( get_post_status( $childId ) !== false ) {
				wp_delete_post( $childId, true );
			}
		}
	}

	/**
	 * A live child template pointing at a still-trashed parent would be a
	 * broken state, so restoring a child restores its parent too. Safe
	 * against infinite recursion: this only ever walks up one level, since
	 * templates only nest 1 level deep (see SavePost::limitPostDepth()), and
	 * restoring the parent no longer cascades back down to siblings.
	 */
	private static function restoreParentTemplate( int $templateId ): void {
		$parentId = wp_get_post_parent_id( $templateId );
		if ( $parentId && get_post_status( $parentId ) === 'trash' ) {
			wp_untrash_post( $parentId );
		}
	}

	/**
	 * @return array<int, int>
	 */
	private static function getChildTemplateIds( int $templateId ): array {
		// 'any' excludes 'trash' and 'auto-draft', but child templates can
		// legitimately be trashed (e.g. cascaded along with this template
		// earlier), so the trash/delete/restore cascades would silently miss
		// them without listing statuses explicitly.
		$childIds = get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_parent'    => $templateId,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		return array_map( 'intval', $childIds );
	}
}
