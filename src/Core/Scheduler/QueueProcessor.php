<?php

namespace RowSprout\Core\Scheduler;

use Exception;
use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueueProcessor {

	public static function processQueue(): void {
		GroupTableGateway::promoteDueScheduledRows();

		if ( ! self::isWithinProcessingWindow() ) {
			return;
		}

		if ( GroupTableGateway::countByStatus( GroupTableGateway::STATUS_IN_PROCESS ) > 0 ) {
			return;
		}

		$limit = (int) apply_filters( 'rowsprout_groups_queue_limit', 40 );

		// No cap on new pages: the free plugin does not limit generated pages.
		$groups = GroupTableGateway::getPendingGroupsForProcessing( $limit, true, PHP_INT_MAX );

		if ( empty( $groups ) ) {
			$groups = GroupTableGateway::getPendingGroupsForProcessing( $limit, false, PHP_INT_MAX );
		}

		foreach ( $groups as $group ) {
			GroupTableGateway::updateById( (int) $group['id'], [ 'status' => GroupTableGateway::STATUS_IN_PROCESS ] );

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'rowsprout_process_single_group',
					[ $group['guid'], (int) $group['rowsprout_template_id'] ],
					'rowsprout_page'
				);
			} else {
				self::processSingleGroup( $group['guid'], (int) $group['rowsprout_template_id'] );
			}
		}
	}

	public static function processSingleGroup( string $guid, int $postId ): void {
		$group = GroupTableGateway::getRowByGuidAndPostId( $guid, $postId );

		if ( ! $group ) {
			return;
		}

		$result    = Helpers::createById( $guid, $postId );
		$newPostId = is_array( $result ) && isset( $result['page_id'] ) ? (int) $result['page_id'] : 0;

		if ( $newPostId > 0 ) {
			$updateData = [
				'rowsprout_page_id' => $newPostId,
				'status'          => GroupTableGateway::STATUS_COMPLETED,
			];
			if ( GroupTableGateway::supportsGeneratedAt() ) {
				$updateData['generated_at'] = current_time( 'mysql' );
			}
			GroupTableGateway::updateById( (int) $group['id'], $updateData );
			return;
		}

		GroupTableGateway::updateById( (int) $group['id'], [ 'status' => GroupTableGateway::STATUS_FAILED ] );
	}

	public static function handleFailedExecution( int $actionId, Exception $exception ): void {
		unset( $exception );

		$action = \ActionScheduler::store()->fetch_action( $actionId );
		if ( ! $action || $action->get_hook() !== 'rowsprout_process_single_group' ) {
			return;
		}

		$args = $action->get_args();
		if ( empty( $args ) || count( $args ) < 2 ) {
			return;
		}

		GroupTableGateway::updateByGuidAndPostId(
			(string) $args[0],
			(int) $args[1],
			[ 'status' => GroupTableGateway::STATUS_FAILED ],
		);
	}

	private static function isWithinProcessingWindow(): bool {
		return (bool) apply_filters( 'rowsprout_is_within_processing_window', true );
	}

}
