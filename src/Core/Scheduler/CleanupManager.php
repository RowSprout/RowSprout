<?php

namespace RowSprout\Core\Scheduler;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CleanupManager {

	public static function cleanup(): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}

		$store  = \ActionScheduler_Store::instance();
		$cutoff = as_get_datetime_object( HOUR_IN_SECONDS . ' seconds ago' );

		$statuses = [
			\ActionScheduler_Store::STATUS_COMPLETE,
			\ActionScheduler_Store::STATUS_CANCELED,
			\ActionScheduler_Store::STATUS_FAILED,
		];

		foreach ( $statuses as $status ) {
			$actions = $store->query_actions( [
				'group'            => 'rowsprout_page',
				'status'           => $status,
				'modified'         => $cutoff,
				'modified_compare' => '<=',
				'per_page'         => 50,
				'orderby'          => 'none',
			] );

			foreach ( $actions as $actionId ) {
				try {
					$store->delete_action( $actionId );
				} catch ( Exception $e ) {
					// Background task without an admin surface: the next hourly
					// run retries, so a failure is only worth logging while debugging.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- only with WP_DEBUG on, see above.
						error_log( 'Could not delete Action Scheduler task: ' . $e->getMessage() );
					}
				}
			}
		}
	}
}
