<?php

namespace RowSprout\Core\Groups;

use RowSprout\Infrastructure\Database\GroupsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This class exists to talk to the plugin's own custom table
 * (GroupsTable), so every query in it is necessarily a direct,
 * uncached $wpdb call — that's the entire point of a custom-table data
 * layer, not an oversight. $table/tableName() is always computed from
 * $wpdb->prefix, never from request input, and every IN (...) placeholder
 * list is built with array_fill()/implode() sized to match the values
 * spread into prepare() right after it — both patterns PHPCS's static
 * placeholder counter can't trace, hence the sniffs disabled below.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
final class GroupTableGateway {

	public const STATUS_PENDING           = 'pending';
	public const STATUS_SCHEDULED         = 'scheduled';
	public const STATUS_WAITING           = 'waiting';
	public const STATUS_IN_PROCESS        = 'in-process';
	public const STATUS_COMPLETED         = 'completed';
	public const STATUS_FAILED            = 'failed';
	public const STATUS_STALE             = 'stale';
	public const STATUS_DELETED_COMPLETED = 'deleted-completed';

	/**
	 * @return array<int, string>
	 */
	public static function queueableStatuses(): array {
		return [ self::STATUS_PENDING, self::STATUS_SCHEDULED, self::STATUS_WAITING ];
	}

	/**
	 * @return array<int, string>
	 */
	public static function activeQueueStatuses(): array {
		return [ self::STATUS_PENDING, self::STATUS_IN_PROCESS, self::STATUS_WAITING ];
	}

	public static function isActiveQueueStatus( string $status ): bool {
		return in_array( $status, self::activeQueueStatuses(), true );
	}

	public static function isCompletedOrDeletedCompleted( string $status ): bool {
		return $status === self::STATUS_COMPLETED || $status === self::STATUS_DELETED_COMPLETED;
	}

	private static function tableName(): string {
		return GroupsTable::name();
	}

	/** @var bool|null */
	private static $hasScheduledAtColumn = null;

	/**
	 * The "scheduled_at" column is not part of the base plugin's own table
	 * definition (see Database::activate()) — it's added by RowSprout
	 * Pro's own migration when that add-on is active, since only Pro's
	 * scheduling features ever populate it with a real value. Every base
	 * codepath that touches the column must check this first so the base
	 * plugin keeps working correctly on sites without Pro installed.
	 */
	public static function supportsScheduling(): bool {
		if ( self::$hasScheduledAtColumn === null ) {
			global $wpdb;
			$table  = self::tableName();
			$column = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'scheduled_at' ) );
			self::$hasScheduledAtColumn = ! empty( $column );
		}

		return self::$hasScheduledAtColumn;
	}

	/**
	 * Used by RowSprout Pro after it adds the "scheduled_at" column, so
	 * the cached result above reflects the change within the same request.
	 */
	public static function resetSchedulingSupportCache(): void {
		self::$hasScheduledAtColumn = null;
	}

	/** @var bool|null */
	private static $hasGeneratedAtColumn = null;

	/**
	 * "generated_at" (schema v10+) is part of the base table definition
	 * (see Database::activate()), but dbDelta() only runs on admin_init —
	 * so a request that never hits wp-admin right after this plugin
	 * version deploys (e.g. an MCP call) could still see the pre-migration
	 * table. Same defensive pattern as supportsScheduling() above.
	 */
	public static function supportsGeneratedAt(): bool {
		if ( self::$hasGeneratedAtColumn === null ) {
			global $wpdb;
			$table  = self::tableName();
			$column = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'generated_at' ) );
			self::$hasGeneratedAtColumn = ! empty( $column );
		}

		return self::$hasGeneratedAtColumn;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function replaceRowByPostAndGuid( int $postId, string $guid, array $data ): void {
		global $wpdb;
		$table = self::tableName();

		$wpdb->delete( $table, [ 'rowsprout_template_id' => $postId, 'guid' => $guid ], [ '%d', '%s' ] );
		$wpdb->insert( $table, $data );
	}

	/**
	 * Repoints an existing row at a different guid, leaving its
	 * rowsprout_page_id/status/scheduled_at untouched. Used when a group's id
	 * has drifted out from under an already-built page (confirmed live for
	 * WPML translations — see WpmlProTranslationCompleted's own docblock),
	 * so the existing page can be re-linked to its current config group
	 * instead of being left orphaned or duplicated.
	 */
	public static function renameGuid( int $postId, string $oldGuid, string $newGuid ): void {
		global $wpdb;
		$wpdb->update(
			self::tableName(),
			[ 'guid' => $newGuid ],
			[ 'rowsprout_template_id' => $postId, 'guid' => $oldGuid ]
		);
	}

	/**
	 * @return array<int, array{guid:string,parent_guid:?string,rowsprout_page_id:int,status:string}>
	 */
	public static function getRowsByPostId( int $postId ): array {
		global $wpdb;
		$table = self::tableName();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT guid, parent_guid, rowsprout_page_id, status FROM {$table} WHERE rowsprout_template_id = %d",
				$postId
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Scoped by rowsprout_template_id as well as guid: a group's numeric id is
	 * only guaranteed unique within its own template's config. Two templates
	 * can legitimately end up with matching group ids — most notably a WPML
	 * translation, which starts as a duplicate of the source template's
	 * config (see RowSprout Pro's WpmlPageLanguage) — and without this
	 * scoping, whichever template's group got processed second would silently
	 * overwrite the first one's already-generated page instead of creating
	 * its own.
	 */
	public static function getRowSproutPageIdByGuid( string $guid, int $templateId ): ?int {
		global $wpdb;
		$table = self::tableName();

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rowsprout_page_id FROM {$table} WHERE guid = %s AND rowsprout_template_id = %d LIMIT 1",
				$guid,
				$templateId
			)
		);

		return $found ? (int) $found : null;
	}

	/**
	 * Same shape as getRowSproutPageIdByGuid() above, but matches a CHILD
	 * template's row via its parent_guid (set from that group's own
	 * parent_id — see GroupRowStore::sync()) instead of its own guid. Used
	 * to find "template X's page for the same location as the group
	 * identified by $parentGuid" — e.g. a widget cross-linking sibling
	 * templates' pages for the current location. $found being the string
	 * "0" (a row exists but was never actually generated) is falsy in PHP,
	 * so this already returns null for that case without a separate
	 * rowsprout_page_id > 0 clause — same reliance as getRowSproutPageIdByGuid().
	 */
	public static function getRowSproutPageIdByParentGuid( string $parentGuid, int $templateId ): ?int {
		global $wpdb;
		$table = self::tableName();

		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT rowsprout_page_id FROM {$table} WHERE parent_guid = %s AND rowsprout_template_id = %d LIMIT 1",
				$parentGuid,
				$templateId
			)
		);

		return $found ? (int) $found : null;
	}

	/**
	 * @param array<int, string> $guids
	 * @return array<int, int>
	 */
	public static function getRowSproutPageIdsByGuids( array $guids ): array {
		global $wpdb;

		$guids = self::sanitizeGuids( $guids );
		if ( empty( $guids ) ) {
			return [];
		}

		$table        = self::tableName();
		$placeholders = implode( ',', array_fill( 0, count( $guids ), '%s' ) );
		$results      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT rowsprout_page_id FROM {$table} WHERE guid IN ({$placeholders})",
				...$guids
			)
		);

		if ( ! is_array( $results ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'intval', $results ) ) );
	}

	/**
	 * @param array<int, string> $guids
	 */
	public static function deleteByGuids( array $guids ): void {
		global $wpdb;

		$guids = self::sanitizeGuids( $guids );
		if ( empty( $guids ) ) {
			return;
		}

		$table        = self::tableName();
		$placeholders = implode( ',', array_fill( 0, count( $guids ), '%s' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE guid IN ({$placeholders})",
				...$guids
			)
		);
	}

	public static function findRowIdByGuidAndPostId( string $guid, int $postId ): ?int {
		global $wpdb;
		$table = self::tableName();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE guid = %s AND rowsprout_template_id = %d",
				$guid,
				$postId
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function updateById( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( self::tableName(), $data, [ 'id' => $id ] );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function insertRow( array $data ): void {
		global $wpdb;
		$wpdb->insert( self::tableName(), $data );
	}

	public static function resetStatuses( string $status, ?int $postId = null ): void {
		global $wpdb;
		$table = self::tableName();
		$failedStatus = self::STATUS_FAILED;
		$inProcessStatus = self::STATUS_IN_PROCESS;

		if ( $postId !== null ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s WHERE rowsprout_template_id = %d AND status IN (%s, %s)",
					$status,
					$postId,
					$failedStatus,
					$inProcessStatus
				)
			);
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE status IN (%s, %s)",
				$status,
				$failedStatus,
				$inProcessStatus
			)
		);
	}

	public static function setStatusByPostId( int $postId, string $status, bool $exceptScheduled = false ): void {
		global $wpdb;

		if ( $exceptScheduled ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::tableName() . " SET status = %s WHERE rowsprout_template_id = %d AND status <> %s",
					$status,
					$postId,
					self::STATUS_SCHEDULED
				)
			);
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::tableName() . " SET status = %s WHERE rowsprout_template_id = %d",
				$status,
				$postId
			)
		);
	}

	/**
	 * @param bool $exceptScheduled Leave rows still 'scheduled' alone, so a
	 *        group planned for a specific moment keeps its date through a
	 *        save that only just queued OTHER groups (see SavePost).
	 */
	public static function setScheduledAtByPostId( int $postId, ?string $scheduledAt, bool $exceptScheduled = false ): void {
		if ( ! self::supportsScheduling() ) {
			return;
		}

		global $wpdb;

		$exceptClause = $exceptScheduled ? $wpdb->prepare( ' AND status <> %s', self::STATUS_SCHEDULED ) : '';

		if ( $scheduledAt === null || $scheduledAt === '' ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE " . self::tableName() . " SET scheduled_at = NULL WHERE rowsprout_template_id = %d",
					$postId
				) . $exceptClause
			);
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::tableName() . " SET scheduled_at = %s WHERE rowsprout_template_id = %d",
				$scheduledAt,
				$postId
			) . $exceptClause
		);
	}

	public static function promoteDueScheduledRows(): void {
		if ( ! self::supportsScheduling() ) {
			return;
		}

		global $wpdb;

		$table = self::tableName();
		$now = current_time( 'mysql' );
		$scheduledStatus = self::STATUS_SCHEDULED;
		$pendingStatus = self::STATUS_PENDING;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE status = %s AND (scheduled_at IS NULL OR scheduled_at <= %s)",
				$pendingStatus,
				$scheduledStatus,
				$now
			)
		);
	}

	public static function countByStatus( ?string $status ): int {
		global $wpdb;
		$table = self::tableName();

		if ( null === $status ) {
			// Count all active rows (not failed, stale, completed, or deleted-completed)
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status NOT IN ( %s, %s, %s, %s )",
					self::STATUS_FAILED,
					self::STATUS_STALE,
					self::STATUS_COMPLETED,
					self::STATUS_DELETED_COMPLETED
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					$status
				)
			);
		}

		return (int) $count;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function getPendingGroups( int $limit, bool $rootsOnly ): array {
		global $wpdb;
		$table = self::tableName();
		$pendingStatus = self::STATUS_PENDING;

		if ( $rootsOnly ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s AND (parent_guid IS NULL OR parent_guid = '') LIMIT %d",
					$pendingStatus,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s LIMIT %d",
					$pendingStatus,
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Like getPendingGroups(), but license-aware: rows that already have a
	 * page (rowsprout_page_id > 0) are a content refresh of a page that
	 * already exists, so they never count against the license and are
	 * always processed first; rows with no page yet are new pages, capped
	 * at $maxNewPages so processing never creates more pages than the
	 * license allows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function getPendingGroupsForProcessing( int $limit, bool $rootsOnly, int $maxNewPages ): array {
		global $wpdb;
		$table = self::tableName();
		$pendingStatus = self::STATUS_PENDING;
		$parentClause = $rootsOnly
			? "AND (g.parent_guid IS NULL OR g.parent_guid = '')"
			: self::childParentReadyClause( $table );
		$orderClause = self::processingOrderClause();

		$existing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.* FROM {$table} g WHERE g.status = %s AND g.rowsprout_page_id > 0 {$parentClause} {$orderClause} LIMIT %d",
				$pendingStatus,
				$limit
			),
			ARRAY_A
		);
		$existing = is_array( $existing ) ? $existing : [];

		$newCap = min( max( 0, $limit - count( $existing ) ), max( 0, $maxNewPages ) );
		if ( $newCap <= 0 ) {
			return $existing;
		}

		$new = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.* FROM {$table} g WHERE g.status = %s AND g.rowsprout_page_id = 0 {$parentClause} {$orderClause} LIMIT %d",
				$pendingStatus,
				$newCap
			),
			ARRAY_A
		);
		$new = is_array( $new ) ? $new : [];

		return array_merge( $existing, $new );
	}

	/**
	 * A child group (one with a parent_guid, i.e. a group of a CHILD template
	 * linked to a group of its parent template) is built as a child of its
	 * parent group's page, so it can't be processed until that page exists.
	 * Excludes exactly the children whose parent group's row is KNOWN and
	 * still has no page yet — a parent row that isn't found at all (a
	 * legacy/odd link, e.g. a WPML translation whose parent template wasn't
	 * translated yet) is deliberately NOT blocked, so such a child keeps
	 * being processed the way it always was instead of waiting forever.
	 *
	 * The parent group row is looked up inside the child template's own
	 * PARENT TEMPLATE (post_parent): a group id is only unique within one
	 * template, and a WPML translation starts out with the same ids as its
	 * source.
	 */
	private static function childParentReadyClause( string $table ): string {
		global $wpdb;

		return "AND NOT EXISTS (
			SELECT 1 FROM {$table} pg
			INNER JOIN {$wpdb->posts} ct ON ct.ID = g.rowsprout_template_id
			WHERE g.parent_guid IS NOT NULL AND g.parent_guid <> ''
			  AND ct.post_parent > 0
			  AND pg.rowsprout_template_id = ct.post_parent
			  AND pg.guid = g.parent_guid
			  AND pg.rowsprout_page_id = 0
		)";
	}

	/**
	 * Never-generated rows first, then longest since last generated, so a
	 * limited batch works through the whole backlog fairly instead of
	 * re-picking whatever the table happens to return first.
	 */
	private static function processingOrderClause(): string {
		if ( self::supportsGeneratedAt() ) {
			return 'ORDER BY (g.generated_at IS NULL) DESC, g.generated_at ASC, g.id ASC';
		}

		return 'ORDER BY g.id ASC';
	}

	/**
	 * Count groups that already have a real page (used by
	 * RowSprout Pro's own License\Manager as the site's current page
	 * count). A page counts once created regardless
	 * of its current queue status — re-queueing it for a content refresh
	 * doesn't create a new page — except "deleted-completed", which means
	 * the page was removed via this plugin's own cascade-delete and no
	 * longer exists.
	 */
	public static function countExistingPages(): int {
		global $wpdb;
		$table = self::tableName();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE rowsprout_page_id > 0 AND status != %s",
				self::STATUS_DELETED_COMPLETED
			)
		);
	}

	/**
	 * Count pending groups with no page yet (new pages waiting to be
	 * created), across all templates. Used by the rowsprout-support doctor.
	 */
	public static function countPendingNewPages(): int {
		global $wpdb;
		$table = self::tableName();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND rowsprout_page_id = 0",
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Same scope as countExistingPages(), but for one template only — used to
	 * check whether a PARENT template has generated any real pages yet before
	 * letting a child template (which inherits post_parent plus naam/href/
	 * thumb from the parent's own generated group, see RowSprout Pro's
	 * ChildTemplateInheritance) generate its own.
	 */
	public static function countExistingPagesByPostId( int $postId ): int {
		global $wpdb;
		$table = self::tableName();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE rowsprout_template_id = %d AND rowsprout_page_id > 0 AND status != %s",
				$postId,
				self::STATUS_DELETED_COMPLETED
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function getRowByGuidAndPostId( string $guid, int $postId ): ?array {
		global $wpdb;
		$table = self::tableName();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE guid = %s AND rowsprout_template_id = %d",
				$guid,
				$postId
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function updateByGuidAndPostId( string $guid, int $postId, array $data ): void {
		global $wpdb;
		$wpdb->update( self::tableName(), $data, [ 'guid' => $guid, 'rowsprout_template_id' => $postId ] );
	}

	/**
	 * @param array<int, string> $guids
	 * @return array<int, string>
	 */
	private static function sanitizeGuids( array $guids ): array {
		$sanitized = [];
		foreach ( $guids as $guid ) {
			$value = sanitize_text_field( (string) $guid );
			if ( $value !== '' ) {
				$sanitized[] = $value;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}
