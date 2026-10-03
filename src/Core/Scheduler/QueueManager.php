<?php

namespace RowSprout\Core\Scheduler;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Helpers;
use RowSprout\Core\Template\Lifecycle\TemplateGroupActionUnscheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueueManager {

	public static function scheduleQueueTask(): void {
		$interval = apply_filters( 'rowsprout_groups_queue_interval', MINUTE_IN_SECONDS );

		self::deduplicateRecurringAction( 'rowsprout_process_groups_queue' );

		if ( as_next_scheduled_action( 'rowsprout_process_groups_queue' ) ) {
			return;
		}

		as_schedule_recurring_action(
			time(),
			$interval,
			'rowsprout_process_groups_queue',
			[],
			'rowsprout_page'
		);
	}

	public static function scheduleCleanupTask(): void {
		self::deduplicateRecurringAction( 'rowsprout_cleanup_hook' );

		if ( as_next_scheduled_action( 'rowsprout_cleanup_hook' ) ) {
			return;
		}

		as_schedule_recurring_action(
			time(),
			HOUR_IN_SECONDS,
			'rowsprout_cleanup_hook',
			[],
			'rowsprout_page'
		);
	}

	/**
	 * Self-healing guard against duplicate recurring chains for one of our
	 * own hooks: as_next_scheduled_action() (used by scheduleQueueTask() /
	 * scheduleCleanupTask() above) only prevents CREATING a second chain
	 * at the moment it's called — it doesn't merge two chains that already
	 * exist. Two independent recurring chains for the same hook can end up
	 * coexisting (and each keep perpetuating itself forever, since AS
	 * chains forward on every natural completion regardless of any
	 * sibling) if a brief gap ever left as_next_scheduled_action() seeing
	 * nothing pending — confirmed live: a plugin activation that's part of
	 * a retried conversion (fail, rollback, retry, succeed) can do exactly
	 * that, since a rollback deactivates the plugin without canceling any
	 * Action Scheduler action a brief earlier activation already created.
	 * Runs on every admin_init (via scheduleQueueTask()/scheduleCleanupTask()
	 * above), so this keeps healing itself regardless of how a duplicate
	 * chain came to exist, not just during a conversion.
	 */
	private static function deduplicateRecurringAction( string $hook ): void {
		if ( ! class_exists( 'ActionScheduler_Store' ) ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler's own table has no public API for "find duplicate pending actions for this hook"; $wpdb->prefix is not request input.
		$pendingIds = $wpdb->get_col( $wpdb->prepare(
			"SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'pending' ORDER BY action_id ASC",
			$hook
		) );

		if ( count( $pendingIds ) <= 1 ) {
			return;
		}

		array_shift( $pendingIds );

		$store = \ActionScheduler_Store::instance();
		foreach ( $pendingIds as $id ) {
			$store->cancel_action( (int) $id );
		}
	}

	/**
	 * @param bool $preserveScheduled Leave rows already 'scheduled' (a group
	 *        deliberately planned for a specific moment) untouched instead of
	 *        overwriting their status/scheduled_at — for implicit "first time
	 *        this template gets queued" paths. An explicit "regenerate
	 *        everything" caller leaves this false and does override a plan.
	 */
	public static function queue( int $postId, string $status = GroupTableGateway::STATUS_PENDING, bool $preserveScheduled = false ): void {
		$status = in_array( $status, GroupTableGateway::queueableStatuses(), true ) ? $status : GroupTableGateway::STATUS_PENDING;

		$groups = self::getQueueGroups( $postId );
		$supportsScheduling = GroupTableGateway::supportsScheduling();
		$currentGuids = [];

		foreach ( $groups as $group ) {
			[ $groupGuid, $parentGuid ] = self::resolveGroupIdentifiers( $group );

			if ( $groupGuid === '' ) {
				continue;
			}

			$currentGuids[ $groupGuid ] = true;

			if ( $preserveScheduled ) {
				$existingRow = GroupTableGateway::getRowByGuidAndPostId( $groupGuid, $postId );
				if ( $existingRow && ( $existingRow['status'] ?? '' ) === GroupTableGateway::STATUS_SCHEDULED ) {
					continue;
				}
			}

			$existingId = GroupTableGateway::findRowIdByGuidAndPostId( $groupGuid, $postId );

			$rowData = [
				'status' => $status,
			];
			if ( $supportsScheduling ) {
				// A row queued here is due now: any earlier plan date is cleared.
				$rowData['scheduled_at'] = null;
			}

			if ( $existingId ) {
				   GroupTableGateway::updateById( (int) $existingId, $rowData );
				   continue;
			}

			GroupTableGateway::insertRow( array_merge(
				[
					'rowsprout_template_id' => $postId,
					'guid'                => $groupGuid,
					'parent_guid'         => $parentGuid,
					'rowsprout_page_id'     => 0,
				],
				$rowData
			) );
		}

		// Scoped to THIS template's own rows only — as_unschedule_all_actions()
		// with no $args/$group has no such scoping and removes every action
		// registered under this hook name SITE-WIDE, regardless of which
		// template or guid it belongs to. resetStatuses()/setStatusByPostId()
		// right below are correctly scoped to $postId, so any other
		// template's in-flight, already-scheduled action wiped out by the
		// unscoped call would silently lose its Action Scheduler task while
		// its own DB row kept reporting 'in-process' forever — confirmed
		// live: a WPML translation's freshly-scheduled actions got canceled
		// this way the moment an unrelated template's own first save (or
		// its own second queueing pass, e.g. via WpmlProTranslationCompleted
		// firing alongside the plain save_post_rowsprout_template hook) also
		// happened to hit this same queue() call.
		TemplateGroupActionUnscheduler::unscheduleTemplateGroupActions( $postId, GroupTableGateway::getRowsByPostId( $postId ) );
		GroupTableGateway::resetStatuses( $status, $postId );
		GroupTableGateway::setStatusByPostId( $postId, $status, $preserveScheduled );

		// Runs last: setStatusByPostId() above has no guid filter, so it would
		// otherwise flip orphaned rows (guids no longer in the current group
		// data, e.g. removed via the UI or dropped by a parent change) back to
		// $status, causing them to be reprocessed with empty group data and
		// have their title/content silently blanked. Delete them instead:
		// there is no restore path for an individual orphaned group (unlike
		// a trashed template, nothing ever reconnects a dropped group to a
		// "deleted-*" row again), so soft-deleting it would only accumulate
		// permanently unused rows and pages.
		self::pruneOrphanedGroups( $postId, $currentGuids );
	}

	/**
	 * Structure-only sync: ensures every group currently in $postId's
	 * config has a corresponding row (inserting missing ones) and, when
	 * $pruneOrphans is true, prunes rows for groups no longer in config —
	 * but unlike queue(), never touches the status of a row that already
	 * exists — so a caller that only needs the rows to exist (saving a
	 * template without generating, Pro's group planning and MCP generation)
	 * can't resurrect already-completed pages by accident.
	 *
	 * $pruneOrphans defaults to true for the normal admin-save path, where
	 * the config being read is whatever the user just explicitly submitted
	 * via the groups metabox — a genuinely authoritative "this is the
	 * current set of groups" snapshot. Pass false for a caller reacting to
	 * an external, asynchronous event (see Pro's WpmlProTranslationCompleted)
	 * whose read of the config can't be trusted as authoritative for "this
	 * group was removed" the same way — confirmed live: WPML's own
	 * translation-completion signal can fire while its write of the
	 * translated group config is still incomplete, making every existing
	 * group look orphaned and get force-deleted. Missing/new groups still
	 * get inserted either way; only the destructive removal branch is
	 * skipped.
	 */
	public static function syncGroupStructure( int $postId, bool $pruneOrphans = true ): void {
		$groups = self::getQueueGroups( $postId );
		$currentGuids = [];

		foreach ( $groups as $group ) {
			[ $groupGuid, $parentGuid ] = self::resolveGroupIdentifiers( $group );

			if ( $groupGuid === '' ) {
				continue;
			}

			$currentGuids[ $groupGuid ] = true;

			$existingRow = GroupTableGateway::getRowByGuidAndPostId( $groupGuid, $postId );
			if ( $existingRow ) {
				// Existing row's own status/rowsprout_page_id are left alone here
				// (this is a structure sync, not a queueing action) — but
				// parent_guid still needs to track the config's current
				// parent_id, which can legitimately change after this row was
				// first inserted: e.g. WPML regenerating a PARENT template's
				// own group guids leaves every CHILD's config correctly
				// re-pointed at the new guid, but — before this check existed
				// — left already-inserted child rows permanently referencing
				// the old, now-orphaned guid, since this loop otherwise never
				// touches a row that already exists. Confirmed live: this had
				// silently broken cross-template guid lookups (e.g. a "link to
				// this location's page on another template" resolver) for
				// every WPML-translated child template on this site.
				$storedParentGuid = isset( $existingRow['parent_guid'] ) && $existingRow['parent_guid'] !== null
					? (string) $existingRow['parent_guid']
					: null;

				if ( $storedParentGuid !== $parentGuid ) {
					GroupTableGateway::updateByGuidAndPostId( $groupGuid, $postId, [ 'parent_guid' => $parentGuid ] );
				}

				continue;
			}

			GroupTableGateway::insertRow( [
				'rowsprout_template_id' => $postId,
				'guid'                => $groupGuid,
				'parent_guid'         => $parentGuid,
				'rowsprout_page_id'     => 0,
				'status'              => GroupTableGateway::STATUS_STALE,
			] );
		}

		if ( $pruneOrphans ) {
			self::pruneOrphanedGroups( $postId, $currentGuids );
		}
	}

	/**
	 * @param array<string, mixed> $group
	 * @return array{0: string, 1: ?string} [ guid, parent_guid ]
	 */
	private static function resolveGroupIdentifiers( array $group ): array {
		$groupGuid = '';
		if ( isset( $group['guid'] ) && is_scalar( $group['guid'] ) ) {
			$groupGuid = sanitize_text_field( (string) $group['guid'] );
		} elseif ( isset( $group['id'] ) && is_scalar( $group['id'] ) ) {
			$groupGuid = sanitize_text_field( (string) $group['id'] );
		}

		$parentGuid = null;
		if ( isset( $group['parent_guid'] ) && is_scalar( $group['parent_guid'] ) ) {
			$rawParentGuid = sanitize_text_field( (string) $group['parent_guid'] );
			if ( $rawParentGuid !== '' && $rawParentGuid !== '0' ) {
				$parentGuid = $rawParentGuid;
			}
		} elseif ( isset( $group['parent_id'] ) && is_scalar( $group['parent_id'] ) ) {
			$rawParentGuid = sanitize_text_field( (string) $group['parent_id'] );
			if ( $rawParentGuid !== '' && $rawParentGuid !== '0' ) {
				$parentGuid = $rawParentGuid;
			}
		}

		return [ $groupGuid, $parentGuid ];
	}

	/**
	 * @param array<string, bool> $currentGuids
	 */
	private static function pruneOrphanedGroups( int $postId, array $currentGuids ): void {
		$orphanedGuids = [];

		foreach ( GroupTableGateway::getRowsByPostId( $postId ) as $row ) {
			$guid = isset( $row['guid'] ) ? (string) $row['guid'] : '';
			if ( $guid === '' || isset( $currentGuids[ $guid ] ) ) {
				continue;
			}

			$orphanedGuids[] = $guid;

			$pageId = isset( $row['rowsprout_page_id'] ) ? (int) $row['rowsprout_page_id'] : 0;
			if ( $pageId <= 0 ) {
				continue;
			}

			if ( get_post_status( $pageId ) !== false ) {
				wp_delete_post( $pageId, true );
			}
		}

		if ( ! empty( $orphanedGuids ) ) {
			GroupTableGateway::deleteByGuids( $orphanedGuids );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function getQueueGroups( int $postId ): array {
		$parentGroups = Helpers::getGroups( $postId, true );

		if ( ! is_array( $parentGroups ) ) {
			$parentGroups = [];
		}

		$unique = [];
		$seen   = [];

		foreach ( $parentGroups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$guid = '';
			if ( isset( $group['guid'] ) && is_scalar( $group['guid'] ) ) {
				$guid = sanitize_text_field( (string) $group['guid'] );
			} elseif ( isset( $group['id'] ) && is_scalar( $group['id'] ) ) {
				$guid = sanitize_text_field( (string) $group['id'] );
			}

			if ( $guid === '' || isset( $seen[ $guid ] ) ) {
				continue;
			}

			$seen[ $guid ] = true;
			$unique[]      = $group;
		}

		return $unique;
	}

}
