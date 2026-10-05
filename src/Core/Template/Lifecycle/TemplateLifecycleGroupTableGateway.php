<?php

namespace RowSprout\Core\Template\Lifecycle;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Infrastructure\Database\GroupsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class exists to talk to the plugin's own custom table
 * (GroupsTable), so every query in it is necessarily a direct,
 * uncached $wpdb call — that's the entire point of a custom-table data
 * layer, not an oversight. tableName() is always computed from
 * $wpdb->prefix, never from request input, and every IN (...) placeholder
 * list is built with array_fill()/implode() sized to match the values
 * spread into prepare() right after it — both patterns PHPCS's static
 * placeholder counter can't trace, hence the sniffs disabled below.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
final class TemplateLifecycleGroupTableGateway {

	private static function tableName(): string {
		return GroupsTable::name();
	}

	/**
	 * @return array<int, array{id:int,rowsprout_page_id:int,status:string}>
	 */
	public static function getRowsForRestore( int $templateId ): array {
		global $wpdb;
		$table = self::tableName();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, rowsprout_page_id, status FROM {$table} WHERE rowsprout_template_id = %d",
				$templateId
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @return array<int, array{id:int,guid:string,rowsprout_page_id:int,status:string}>
	 */
	public static function getRowsForTrash( int $templateId ): array {
		global $wpdb;
		$table = self::tableName();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, guid, rowsprout_page_id, status FROM {$table} WHERE rowsprout_template_id = %d",
				$templateId
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * @return array<int, array{guid:string,rowsprout_page_id:int}>
	 */
	public static function getRowsForDelete( int $templateId ): array {
		global $wpdb;
		$table = self::tableName();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT guid, rowsprout_page_id FROM {$table} WHERE rowsprout_template_id = %d",
				$templateId
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	public static function updateStatusById( int $rowId, string $status ): void {
		global $wpdb;
		$wpdb->update(
			self::tableName(),
			[ 'status' => $status ],
			[ 'id' => $rowId ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	public static function deleteRowsByTemplateId( int $templateId ): void {
		global $wpdb;
		$wpdb->delete(
			self::tableName(),
			[ 'rowsprout_template_id' => $templateId ],
			[ '%d' ]
		);
	}

	/**
	 * Guids currently sitting in $status for $postId — used to sweep
	 * leftover 'stale' rows (left there by an earlier "Save template only"
	 * save that never got promoted) into a later "Create & update
	 * pages"/"Schedule page updates" save's own affected-guids set, even
	 * when that later save's own diff finds nothing newly changed.
	 *
	 * @return array<int, string>
	 */
	public static function getGuidsByStatus( int $postId, string $status ): array {
		global $wpdb;
		$tableName = self::tableName();

		$guids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT guid FROM {$tableName} WHERE rowsprout_template_id = %d AND status = %s",
				$postId,
				$status
			)
		);

		return is_array( $guids ) ? array_map( 'strval', $guids ) : [];
	}

	public static function countGeneratedPages( int $postId ): int {
		global $wpdb;
		$tableName = self::tableName();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tableName} WHERE rowsprout_template_id = %d AND rowsprout_page_id > 0",
				$postId
			)
		);

		return (int) $count;
	}

	/**
	 * Cancels every currently 'in-process' row for $postId instead of leaving
	 * it to complete a regeneration a follow-up 'save_template' ("save only")
	 * save has said isn't wanted after all — see markStaleByPostId()'s
	 * docblock for why that one still leaves 'in-process' alone on its own.
	 *
	 * A row only sits 'in-process' because QueueProcessor::processQueue()
	 * scheduled a rowsprout_process_single_group Action Scheduler action
	 * for it, typically at least a minute in the future (see that class) —
	 * so there's normally a real window to unschedule it before it actually
	 * runs. If it's already running or just finished by the time this runs
	 * (a narrow, unavoidable race), unscheduling is a harmless no-op and this
	 * still marks the row 'stale' — a rare, cosmetic mislabel of an already-
	 * completed row, not a correctness problem: nothing here touches
	 * rowsprout_page_id, so the page it just generated stays exactly as-is.
	 *
	 * @param string $targetStatus Status to leave the row in after
	 *        unscheduling — defaults to 'stale' (the "save only" caller);
	 *        TemplateSyncMarker::queueChangedGroups() passes 'pending'
	 *        instead so the row gets picked up for immediate reprocessing.
	 */
	public static function cancelInProcessByPostId( int $postId, string $targetStatus = GroupTableGateway::STATUS_STALE ): void {
		global $wpdb;
		$tableName = self::tableName();
		$inProcessStatus = GroupTableGateway::STATUS_IN_PROCESS;

		$guids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT guid FROM {$tableName} WHERE rowsprout_template_id = %d AND status = %s",
				$postId,
				$inProcessStatus
			)
		);

		if ( empty( $guids ) ) {
			return;
		}

		if ( function_exists( 'as_unschedule_action' ) ) {
			foreach ( $guids as $guid ) {
				as_unschedule_action( 'rowsprout_process_single_group', [ $guid, $postId ], 'rowsprout_page' );
			}
		}

		$placeholders = implode( ', ', array_fill( 0, count( $guids ), '%s' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tableName} SET status = %s WHERE rowsprout_template_id = %d AND guid IN ({$placeholders})",
				array_merge( [ $targetStatus, $postId ], $guids )
			)
		);
	}

	/**
	 * 'pending' is deliberately NOT excluded here (unlike 'in-process' and
	 * 'waiting'): a pending row hasn't started processing yet, so it's safe
	 * to downgrade to 'stale' instead — cancelling a not-yet-run regeneration
	 * a previous 'update_pages'/'schedule_pages' save queued, now that a
	 * follow-up 'save_template' ("save only") save has said no reprocessing
	 * is wanted after all. 'in-process' is excluded here specifically because
	 * unscheduling its Action Scheduler action takes more than a status
	 * change — see cancelInProcessByPostId(), called alongside this one from
	 * TemplateSyncMarker::markStaleFromSmallAdjustments(). 'waiting' rows are
	 * still left alone; no cancellation path exists for that status.
	 *
	 * @param string $targetStatus See cancelInProcessByPostId()'s docblock.
	 * @param bool $protectScheduled Also leave 'scheduled' rows alone — a group
	 *        deliberately planned for a specific moment (Pro's group planning
	 *        tab) keeps that plan through an implicit template save / WPML
	 *        update / parent cascade, since it reads the then-current config
	 *        when its date arrives anyway. Explicit "generate these now"
	 *        callers leave this false and still override a plan.
	 */
	public static function markStaleByPostId( int $postId, string $targetStatus = GroupTableGateway::STATUS_STALE, bool $protectScheduled = false ): void {
		global $wpdb;
		$tableName = self::tableName();
		$excluded = self::excludedStatuses( $protectScheduled );
		$placeholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tableName} SET status = %s WHERE rowsprout_template_id = %d AND status NOT IN ({$placeholders})",
				array_merge( [ $targetStatus, $postId ], $excluded )
			)
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function excludedStatuses( bool $protectScheduled ): array {
		$excluded = [ GroupTableGateway::STATUS_IN_PROCESS, GroupTableGateway::STATUS_WAITING ];
		if ( $protectScheduled ) {
			$excluded[] = GroupTableGateway::STATUS_SCHEDULED;
		}

		return $excluded;
	}

	/**
	 * Guid-scoped counterpart to markStaleByPostId() — marks only the given
	 * groups stale instead of every row for $postId, so an edit to one
	 * group's own value doesn't disturb unrelated, already up-to-date
	 * groups. Same status-exclusion contract as markStaleByPostId().
	 *
	 * @param array<int, string> $guids
	 * @param string $targetStatus See cancelInProcessByPostId()'s docblock.
	 * @param bool $protectScheduled See markStaleByPostId()'s docblock.
	 */
	public static function markStaleByGuids( int $postId, array $guids, string $targetStatus = GroupTableGateway::STATUS_STALE, bool $protectScheduled = false ): void {
		global $wpdb;
		$tableName = self::tableName();
		$guids = self::sanitizeGuidList( $guids );

		if ( empty( $guids ) ) {
			return;
		}

		$excluded = self::excludedStatuses( $protectScheduled );
		$statusPlaceholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
		$placeholders = implode( ', ', array_fill( 0, count( $guids ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tableName} SET status = %s WHERE rowsprout_template_id = %d AND status NOT IN ({$statusPlaceholders}) AND guid IN ({$placeholders})",
				array_merge( [ $targetStatus, $postId ], $excluded, $guids )
			)
		);
	}

	/**
	 * Guid-scoped counterpart to cancelInProcessByPostId() — only
	 * unschedules/staleifies in-process rows among the given guids, so
	 * editing one group never disturbs an in-flight regeneration for a
	 * different, untouched group.
	 *
	 * @param array<int, string> $guids
	 * @param string $targetStatus See cancelInProcessByPostId()'s docblock.
	 */
	public static function cancelInProcessByGuids( int $postId, array $guids, string $targetStatus = GroupTableGateway::STATUS_STALE ): void {
		global $wpdb;
		$tableName = self::tableName();
		$guids = self::sanitizeGuidList( $guids );

		if ( empty( $guids ) ) {
			return;
		}

		$inProcessStatus = GroupTableGateway::STATUS_IN_PROCESS;

		$placeholders = implode( ', ', array_fill( 0, count( $guids ), '%s' ) );

		$inProcessGuids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT guid FROM {$tableName} WHERE rowsprout_template_id = %d AND status = %s AND guid IN ({$placeholders})",
				array_merge( [ $postId, $inProcessStatus ], $guids )
			)
		);

		if ( empty( $inProcessGuids ) ) {
			return;
		}

		if ( function_exists( 'as_unschedule_action' ) ) {
			foreach ( $inProcessGuids as $guid ) {
				as_unschedule_action( 'rowsprout_process_single_group', [ $guid, $postId ], 'rowsprout_page' );
			}
		}

		$inProcessPlaceholders = implode( ', ', array_fill( 0, count( $inProcessGuids ), '%s' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tableName} SET status = %s WHERE rowsprout_template_id = %d AND guid IN ({$inProcessPlaceholders})",
				array_merge( [ $targetStatus, $postId ], $inProcessGuids )
			)
		);
	}

	/**
	 * Cross-template counterpart to markStaleByGuids(), for the parent→child
	 * template cascade: a parent template's group guid is only unique
	 * within its own template (see the table's UNIQUE KEY
	 * template_guid(rowsprout_template_id, guid)), so matching parent_guid
	 * alone — without also scoping to the specific child template IDs that
	 * actually descend from the edited parent — would risk marking an
	 * unrelated child under a different parent stale by coincidence.
	 *
	 * @param array<int, int>    $childTemplateIds
	 * @param array<int, string> $parentGuids
	 * @param string             $targetStatus See cancelInProcessByPostId()'s
	 *        docblock — 'stale' for a parent "Save template only" save,
	 *        'pending' for "Create & update pages"/"Schedule page updates"
	 *        so the affected children actually get queued for real
	 *        regeneration instead of just being flagged out of date.
	 */
	public static function markStaleByParentGuids( array $childTemplateIds, array $parentGuids, string $targetStatus = GroupTableGateway::STATUS_STALE, bool $protectScheduled = false ): void {
		global $wpdb;
		$tableName = self::tableName();
		$childTemplateIds = array_values( array_unique( array_map( 'intval', $childTemplateIds ) ) );
		$parentGuids = self::sanitizeGuidList( $parentGuids );

		if ( empty( $childTemplateIds ) || empty( $parentGuids ) ) {
			return;
		}

		$excluded = self::excludedStatuses( $protectScheduled );
		$statusPlaceholders = implode( ', ', array_fill( 0, count( $excluded ), '%s' ) );
		$templatePlaceholders = implode( ', ', array_fill( 0, count( $childTemplateIds ), '%d' ) );
		$guidPlaceholders = implode( ', ', array_fill( 0, count( $parentGuids ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tableName} SET status = %s WHERE rowsprout_template_id IN ({$templatePlaceholders}) AND status NOT IN ({$statusPlaceholders}) AND parent_guid IN ({$guidPlaceholders})",
				array_merge( [ $targetStatus ], $childTemplateIds, $excluded, $parentGuids )
			)
		);
	}

	/**
	 * Cross-template counterpart to cancelInProcessByGuids() — same
	 * template-ID + parent-guid scoping reasoning as markStaleByParentGuids().
	 * Updates matching rows individually via updateStatusById() rather than
	 * a compound (guid, rowsprout_template_id) WHERE clause, since rows are
	 * only unique per that pair, not by guid alone across templates.
	 *
	 * @param array<int, int>    $childTemplateIds
	 * @param array<int, string> $parentGuids
	 * @param string             $targetStatus See markStaleByParentGuids().
	 */
	public static function cancelInProcessByParentGuids( array $childTemplateIds, array $parentGuids, string $targetStatus = GroupTableGateway::STATUS_STALE ): void {
		global $wpdb;
		$tableName = self::tableName();
		$childTemplateIds = array_values( array_unique( array_map( 'intval', $childTemplateIds ) ) );
		$parentGuids = self::sanitizeGuidList( $parentGuids );

		if ( empty( $childTemplateIds ) || empty( $parentGuids ) ) {
			return;
		}

		$inProcessStatus = GroupTableGateway::STATUS_IN_PROCESS;

		$templatePlaceholders = implode( ', ', array_fill( 0, count( $childTemplateIds ), '%d' ) );
		$guidPlaceholders = implode( ', ', array_fill( 0, count( $parentGuids ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, guid, rowsprout_template_id FROM {$tableName} WHERE rowsprout_template_id IN ({$templatePlaceholders}) AND status = %s AND parent_guid IN ({$guidPlaceholders})",
				array_merge( $childTemplateIds, [ $inProcessStatus ], $parentGuids )
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		if ( function_exists( 'as_unschedule_action' ) ) {
			foreach ( $rows as $row ) {
				as_unschedule_action( 'rowsprout_process_single_group', [ $row['guid'], (int) $row['rowsprout_template_id'] ], 'rowsprout_page' );
			}
		}

		foreach ( $rows as $row ) {
			self::updateStatusById( (int) $row['id'], $targetStatus );
		}
	}

	/**
	 * @param array<int, mixed> $guids
	 * @return array<int, string>
	 */
	private static function sanitizeGuidList( array $guids ): array {
		$sanitized = [];
		foreach ( $guids as $guid ) {
			if ( is_scalar( $guid ) ) {
				$value = (string) $guid;
				if ( $value !== '' ) {
					$sanitized[ $value ] = true;
				}
			}
		}

		return array_keys( $sanitized );
	}
}
