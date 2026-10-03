<?php

namespace RowSprout\Core;

use Exception;
use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Scheduler\CleanupManager;
use RowSprout\Core\Scheduler\QueueManager;
use RowSprout\Core\Scheduler\QueueProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'scheduleQueueTask' ] );
		add_action( 'admin_init', [ self::class, 'scheduleCleanupTask' ] );

		add_action( 'rowsprout_process_groups_queue', [ self::class, 'processQueue' ] );
		add_action( 'rowsprout_cleanup_hook', [ self::class, 'cleanup' ] );
		add_action( 'rowsprout_process_single_group', [ self::class, 'processSingleGroup' ], 10, 2 );
		add_action( 'action_scheduler_failed_execution', [ self::class, 'handleFailedExecution' ], 10, 2 );
	}

	public static function scheduleQueueTask(): void {
		QueueManager::scheduleQueueTask();
	}

	public static function scheduleCleanupTask(): void {
		QueueManager::scheduleCleanupTask();
	}

	public static function queue( int $postId, string $status = GroupTableGateway::STATUS_PENDING, bool $preserveScheduled = false ): void {
		QueueManager::queue( $postId, $status, $preserveScheduled );
	}

	public static function processQueue(): void {
		QueueProcessor::processQueue();
	}

	public static function processSingleGroup( string $guid, int $postId ): void {
		QueueProcessor::processSingleGroup( $guid, $postId );
	}

	public static function handleFailedExecution( int $actionId, Exception $exception ): void {
		QueueProcessor::handleFailedExecution( $actionId, $exception );
	}

	public static function cleanup(): void {
		CleanupManager::cleanup();
	}
}
