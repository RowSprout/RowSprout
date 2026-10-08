<?php

namespace RowSprout\Core\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs whatever queues a template's groups (a save, a generate action) while
 * keeping some groups out of it: afterwards each held group is put back the
 * way it was. A planned group gets its plan back; one that was queued
 * already, or had no row yet, becomes outdated. One transaction, so a queue
 * run never picks such a group up in between; rolled back when anything in
 * it fails.
 *
 * Used for groups whose URL is in use (Template\UrlConflicts::queueWithout())
 * and for a child template whose parent has no pages yet (SavePost).
 */
final class QueueHold {

	/**
	 * Template id => guid => the group's row before queueing (null: no row).
	 *
	 * @param array<int, array<string, array<string, mixed>|null>> $held
	 */
	public static function run( array $held, callable $queue ): void {
		global $wpdb;

		if ( $held === [] ) {
			$queue();
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a transaction around the queueing, nothing to cache.
		$wpdb->query( 'START TRANSACTION' );
		try {
			$queue();

			foreach ( $held as $templateId => $rows ) {
				foreach ( $rows as $guid => $previous ) {
					$guid = (string) $guid;
					$row  = GroupTableGateway::getRowByGuidAndPostId( $guid, (int) $templateId );
					if ( $row === null || ( $row['status'] ?? '' ) !== GroupTableGateway::STATUS_PENDING ) {
						continue;
					}
					$status = is_array( $previous ) ? (string) ( $previous['status'] ?? '' ) : GroupTableGateway::STATUS_STALE;
					if ( $status === '' || GroupTableGateway::isActiveQueueStatus( $status ) ) {
						$status = GroupTableGateway::STATUS_STALE;
					}
					$data = [ 'status' => $status ];
					if ( GroupTableGateway::supportsScheduling() ) {
						$data['scheduled_at'] = is_array( $previous ) ? ( $previous['scheduled_at'] ?? null ) : null;
					}
					GroupTableGateway::updateByGuidAndPostId( $guid, (int) $templateId, $data );
				}
			}
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see START TRANSACTION.
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see START TRANSACTION.
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * The current rows of these groups, for run() (whole rows: getRowsByPostId()
	 * leaves scheduled_at out).
	 *
	 * @param array<int, string> $guids
	 * @return array<string, array<string, mixed>|null>
	 */
	public static function rowsOf( int $templateId, array $guids ): array {
		$rows = [];
		foreach ( array_unique( array_map( 'strval', $guids ) ) as $guid ) {
			$rows[ $guid ] = GroupTableGateway::getRowByGuidAndPostId( $guid, $templateId );
		}

		return $rows;
	}
}
