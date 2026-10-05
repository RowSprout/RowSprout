<?php

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Not a back-compat shim like the rest of this directory used to be — this
 * one is live, current internal API: RowSprout Pro calls it directly
 * (Admin\Overview\Actions, the MCP GenerationTools) instead of going through
 * RowSprout\Core\Scheduler, so it stays.
 */
if ( ! function_exists( 'rowsprout_queue_groups' ) ) {
	function rowsprout_queue_groups( $post_id, $status = GroupTableGateway::STATUS_PENDING, $preserve_scheduled = false ): void {
		Scheduler::queue( (int) $post_id, (string) $status, (bool) $preserve_scheduled );
	}
}
