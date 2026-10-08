<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\Groups\GroupTableGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines a template's generation status ("none" / "pending" / "stale" /
 * "up_to_date" / ...) and the color/label used to display it. Shared
 * infrastructure — used by the base plugin's Home page card (see
 * Admin\Menu::renderHomePage()) and by RowSprout Pro's Templates
 * overview list screen, so it lives here rather than in either single UI.
 */
final class TemplateStatusResolver {

	public const STATUS_NO_GROUPS  = 'no_groups';
	public const STATUS_NONE       = 'none';
	public const STATUS_DELETED    = 'deleted';
	public const STATUS_UP_TO_DATE = 'up_to_date';

	/**
	 * Determine status of generated pages for a template.
	 *
	 * - no_groups: the template has no groups, so there is nothing to generate yet.
	 * - none: no generated pages exist yet.
	 * - in-process / pending / scheduled: rows are queued or being processed.
	 * - stale: at least one generated page exists, but not all rows are completed.
	 * - deleted: generated pages existed but were all removed.
	 * - up_to_date: generated pages exist and all rows are completed.
	 */
	public static function resolve( int $postId ): string {
		$rows = GroupTableGateway::getRowsByPostId( $postId );

		if ( empty( $rows ) ) {
			return self::STATUS_NO_GROUPS;
		}

		$hasGeneratedPages = false;
		$allCompleted      = true;
		$hasPendingWork    = false;
		$hasScheduledWork  = false;
		$allDeleted        = true;

		foreach ( $rows as $row ) {
			$rowsproutPageId = isset( $row['rowsprout_page_id'] ) ? (int) $row['rowsprout_page_id'] : 0;
			$status        = isset( $row['status'] ) ? (string) $row['status'] : '';
			$isDeleted     = strpos( $status, 'deleted-' ) === 0;

			if ( $rowsproutPageId > 0 ) {
				$hasGeneratedPages = true;
			}

			if ( ! $isDeleted ) {
				$allDeleted = false;
			}

			if ( ! GroupTableGateway::isCompletedOrDeletedCompleted( $status ) ) {
				$allCompleted = false;
			}

			if ( GroupTableGateway::isActiveQueueStatus( $status ) ) {
				$hasPendingWork = true;
			}

			if ( $status === GroupTableGateway::STATUS_SCHEDULED ) {
				$hasScheduledWork = true;
			}
		}

		if ( in_array( GroupTableGateway::STATUS_IN_PROCESS, array_map( static fn( $row ) => isset( $row['status'] ) ? (string) $row['status'] : '', $rows ), true ) ) {
			return GroupTableGateway::STATUS_IN_PROCESS;
		}

		if ( $hasPendingWork ) {
			return GroupTableGateway::STATUS_PENDING;
		}

		if ( $hasScheduledWork ) {
			return GroupTableGateway::STATUS_SCHEDULED;
		}

		if ( ! $hasGeneratedPages ) {
			return self::STATUS_NONE;
		}

		if ( $allDeleted ) {
			return self::STATUS_DELETED;
		}

		if ( ! $allCompleted ) {
			return GroupTableGateway::STATUS_STALE;
		}

		return self::STATUS_UP_TO_DATE;
	}

	/**
	 * Per-row breakdown behind resolve()'s single collapsed status — same
	 * bucket definitions as getColor()/getLabel() below, but counted
	 * instead of reduced to one dominant value. Used by the "Status"
	 * list-table column's segmented bar (see RowSprout Pro's
	 * StatusColumn) so a template that's mostly up to date with a couple
	 * of stale groups reads as "18/20", not as uniformly "stale" the way
	 * the single dot always did.
	 *
	 * @return array{counts: array<string, int>, total: int}
	 */
	public static function resolveBreakdown( int $postId ): array {
		$rows = GroupTableGateway::getRowsByPostId( $postId );

		$counts = [
			GroupTableGateway::STATUS_IN_PROCESS => 0,
			GroupTableGateway::STATUS_PENDING    => 0,
			GroupTableGateway::STATUS_SCHEDULED  => 0,
			GroupTableGateway::STATUS_STALE      => 0,
			self::STATUS_UP_TO_DATE              => 0,
			self::STATUS_DELETED                 => 0,
		];

		foreach ( $rows as $row ) {
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			$counts[ self::bucketForRowStatus( $status ) ]++;
		}

		return [
			'counts' => $counts,
			'total'  => count( $rows ),
		];
	}

	/**
	 * Same per-row classification resolve() itself uses inline — pulled out
	 * so resolveBreakdown() can reuse it per-row instead of only ever
	 * picking one dominant status across all rows.
	 */
	private static function bucketForRowStatus( string $status ): string {
		if ( GroupTableGateway::isCompletedOrDeletedCompleted( $status ) ) {
			return $status === GroupTableGateway::STATUS_DELETED_COMPLETED
				? self::STATUS_DELETED
				: self::STATUS_UP_TO_DATE;
		}

		if ( $status === GroupTableGateway::STATUS_IN_PROCESS ) {
			return GroupTableGateway::STATUS_IN_PROCESS;
		}

		if ( $status === GroupTableGateway::STATUS_SCHEDULED ) {
			return GroupTableGateway::STATUS_SCHEDULED;
		}

		if ( $status === GroupTableGateway::STATUS_PENDING || $status === GroupTableGateway::STATUS_WAITING ) {
			return GroupTableGateway::STATUS_PENDING;
		}

		// 'stale' and 'failed' both land here — neither resolve() nor
		// getColor()/getLabel() distinguishes a failed row from a stale
		// one today, so the breakdown doesn't invent a new bucket for it.
		return GroupTableGateway::STATUS_STALE;
	}

	public static function getColor( string $status ): string {
		$colors = [
			self::STATUS_NO_GROUPS               => '#d63638',
			self::STATUS_NONE                    => '#d63638',
			GroupTableGateway::STATUS_IN_PROCESS => '#2271b1',
			GroupTableGateway::STATUS_PENDING    => '#2271b1',
			GroupTableGateway::STATUS_SCHEDULED  => '#8e7cc3',
			GroupTableGateway::STATUS_STALE      => '#dba617',
			self::STATUS_DELETED                 => '#8c8f94',
			self::STATUS_UP_TO_DATE              => '#00a32a',
		];

		return $colors[ $status ] ?? '#8c8f94';
	}

	public static function getLabel( string $status ): string {
		$labels = [
			self::STATUS_NO_GROUPS               => __( 'No groups', 'rowsprout' ),
			self::STATUS_NONE                    => __( 'No pages generated yet', 'rowsprout' ),
			GroupTableGateway::STATUS_IN_PROCESS => __( 'Generating…', 'rowsprout' ),
			GroupTableGateway::STATUS_PENDING    => __( 'Queued', 'rowsprout' ),
			GroupTableGateway::STATUS_SCHEDULED  => __( 'Scheduled', 'rowsprout' ),
			GroupTableGateway::STATUS_STALE      => __( 'Changes not yet applied', 'rowsprout' ),
			self::STATUS_DELETED                 => __( 'Pages removed', 'rowsprout' ),
			self::STATUS_UP_TO_DATE              => __( 'Up to date', 'rowsprout' ),
		];

		return $labels[ $status ] ?? __( 'Unknown', 'rowsprout' );
	}
}
