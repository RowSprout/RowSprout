<?php

namespace RowSprout\Core\Template\Lifecycle;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\PostTypes;
use RowSprout\Core\Scheduler\QueueManager;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateSyncMarker {

	/**
	 * WPML-completion counterpart to queueChangedGroups()/
	 * markStaleFromSmallAdjustments(): marks every EXISTING row for $postId
	 * to $targetStatus, without touching structure at all — no inserting
	 * for a group that looks new, no pruning for one that looks removed.
	 *
	 * Used specifically for a trigger whose read of the group config can't
	 * be trusted as stable between calls (see WpmlProTranslationCompleted,
	 * the only caller): confirmed live that WPML's own reconstruction of a
	 * translated template's group config doesn't reliably keep group ids
	 * stable across repeated applications of the same translation, which
	 * made diff-based syncing (matching "current" config guids against the
	 * table) alternately mass-delete or mass-duplicate that template's
	 * pages depending on how the mismatch got handled. Simplicity over
	 * precision: a completed translation just regenerates every page this
	 * template already has, rather than trying to work out what changed.
	 */
	public static function queueAllExistingUnconditionally( int $postId, string $targetStatus ): void {
		$post = get_post( $postId );
		if ( ! $post || in_array( $post->post_status, [ 'draft', 'pending' ], true ) ) {
			return;
		}

		if ( TemplateLifecycleGroupTableGateway::countGeneratedPages( $postId ) <= 0 ) {
			// Bootstrap: nothing exists yet to mark stale/pending, and the
			// row structure itself still needs creating. Inserting missing
			// rows is never destructive (only pruning is — see
			// QueueManager::syncGroupStructure()'s own docblock), so this
			// is the one place a structure sync is safe even against an
			// unstable config read.
			QueueManager::syncGroupStructure( $postId, false );
			rowsprout_queue_groups( $postId, GroupTableGateway::STATUS_PENDING, true );
			return;
		}

		TemplateLifecycleGroupTableGateway::markStaleByPostId( $postId, $targetStatus, true );
		TemplateLifecycleGroupTableGateway::cancelInProcessByPostId( $postId, $targetStatus );
	}

	/**
	 * "Save template only": marks the template's rows stale.
	 *
	 * This keeps generated pages intact, but reflects they are no longer in
	 * sync. By default every row is marked; with change detection (see
	 * syncFromChanges()) only the groups affected by what changed.
	 *
	 * @param array<string, mixed> $oldConfig The template's config as it was
	 *        before this save (already read by SavePost::handleSave() before
	 *        building the new one).
	 * @param bool $pruneOrphans See QueueManager::syncGroupStructure()'s own
	 *        docblock — false for a caller (WPML's translation-completion
	 *        signal) whose config read can't be trusted as authoritative
	 *        for "this group was removed".
	 */
	public static function markStaleFromSmallAdjustments( int $postId, array $oldConfig, bool $pruneOrphans = true ): void {
		$post = get_post( $postId );
		if ( ! $post || in_array( $post->post_status, [ 'draft', 'pending' ], true ) ) {
			return;
		}

		// Structure-only sync first: inserts rows for brand-new groups and,
		// when $pruneOrphans is true, prunes rows for removed ones, without
		// touching any existing row's status — safe regardless of what else
		// changed.
		QueueManager::syncGroupStructure( $postId, $pruneOrphans );

		if ( TemplateLifecycleGroupTableGateway::countGeneratedPages( $postId ) <= 0 ) {
			return;
		}

		self::syncFromChanges( $postId, $oldConfig, GroupTableGateway::STATUS_STALE, false, false );
	}

	/**
	 * "Create & update pages" counterpart to markStaleFromSmallAdjustments():
	 * queues the template's rows for (re)generation ('pending') — every row
	 * by default, only the affected ones with change detection (see
	 * syncFromChanges()). A brand-new template (nothing generated yet) goes
	 * through the full initial queue.
	 *
	 * @param array<string, mixed> $oldConfig Same as markStaleFromSmallAdjustments().
	 * @param bool $pruneOrphans See markStaleFromSmallAdjustments()'s own
	 *        docblock.
	 */
	public static function queueChangedGroups( int $postId, array $oldConfig, bool $pruneOrphans = true ): void {
		$post = get_post( $postId );
		if ( ! $post || in_array( $post->post_status, [ 'draft', 'pending' ], true ) ) {
			return;
		}

		QueueManager::syncGroupStructure( $postId, $pruneOrphans );

		if ( TemplateLifecycleGroupTableGateway::countGeneratedPages( $postId ) <= 0 ) {
			// preserveScheduled: a template whose groups were all planned via
			// Pro's planning tab before anything was ever generated must not
			// have that whole plan flipped to 'pending' (= generated right
			// now) by its first "Create & update pages" save.
			rowsprout_queue_groups( $postId, GroupTableGateway::STATUS_PENDING, true );
			return;
		}

		self::syncFromChanges( $postId, $oldConfig, GroupTableGateway::STATUS_PENDING, true, true );
	}

	/**
	 * Shared logic behind both public entry points above. The plugin itself
	 * doesn't track what a save changed, so by default every group of the
	 * template (and every group of its child templates) is marked
	 * $targetStatus. An add-on with change detection (RowSprout Pro's Smart
	 * Generate) narrows that down through the rowsprout_template_changed_groups
	 * and rowsprout_child_template_changed_parent_groups filters.
	 *
	 * $includeNewGroups additionally folds newly-added groups (inserted by
	 * syncGroupStructure() as 'stale') into the set promoted to
	 * $targetStatus — queueChangedGroups() needs this so a group added during
	 * an "update pages" save still gets generated, while
	 * markStaleFromSmallAdjustments() leaves new groups 'stale' (deferred).
	 * $sweepExistingStale additionally folds in every guid already sitting in
	 * 'stale' status: a prior "Save template only" save can leave a group
	 * 'stale' without this save's own changes touching it again, and a
	 * generating save must still process it. markStaleFromSmallAdjustments()
	 * passes false since its own target status IS 'stale'.
	 *
	 * @param array<string, mixed> $oldConfig
	 */
	private static function syncFromChanges( int $postId, array $oldConfig, string $targetStatus, bool $includeNewGroups, bool $sweepExistingStale ): void {
		$newConfig = TemplateMeta::get( $postId );

		/**
		 * Which of this template's groups a save actually affects.
		 *
		 * null (the default) = every group: pages are rebuilt from the
		 * template, so without change detection any save may touch all of
		 * them. An add-on that detects changes returns the group ids (guids)
		 * whose own values changed and the ids of groups added by this save.
		 *
		 * @param array{changed: array<int, string>, added: array<int, string>}|null $changes
		 * @param int                  $postId
		 * @param array<string, mixed> $oldConfig The config before this save.
		 * @param array<string, mixed> $newConfig The config after this save.
		 */
		$changes = apply_filters( 'rowsprout_template_changed_groups', null, $postId, $oldConfig, $newConfig );

		// protectScheduled on every implicit mark below (this method and
		// cascadeToChildTemplates()): see markStaleByPostId()'s docblock.
		if ( ! is_array( $changes ) ) {
			TemplateLifecycleGroupTableGateway::markStaleByPostId( $postId, $targetStatus, true );
			TemplateLifecycleGroupTableGateway::cancelInProcessByPostId( $postId, $targetStatus );
		} else {
			$affectedGuids = array_map( 'strval', (array) ( $changes['changed'] ?? [] ) );

			if ( $includeNewGroups ) {
				$affectedGuids = array_merge( $affectedGuids, array_map( 'strval', (array) ( $changes['added'] ?? [] ) ) );
			}

			if ( $sweepExistingStale ) {
				$affectedGuids = array_merge( $affectedGuids, TemplateLifecycleGroupTableGateway::getGuidsByStatus( $postId, GroupTableGateway::STATUS_STALE ) );
			}

			$affectedGuids = array_values( array_unique( $affectedGuids ) );
			if ( ! empty( $affectedGuids ) ) {
				TemplateLifecycleGroupTableGateway::markStaleByGuids( $postId, $affectedGuids, $targetStatus, true );
				TemplateLifecycleGroupTableGateway::cancelInProcessByGuids( $postId, $affectedGuids, $targetStatus );
			}
		}

		self::cascadeToChildTemplates( $postId, $oldConfig, $newConfig, $targetStatus );
	}

	/**
	 * A child template builds its pages from the groups of its parent
	 * (linked via parent_guid, see ChildTemplateInheritance), so a parent
	 * save marks the child's groups too. By default that is every group of
	 * every child; an add-on with change detection returns, per child, only
	 * the parent groups whose change actually reaches that child.
	 *
	 * A generating save ($targetStatus other than 'stale') additionally
	 * sweeps in every child row already sitting 'stale' — same reasoning as
	 * syncFromChanges()'s own $sweepExistingStale, applied to the child side.
	 *
	 * Neither can push a specific CHILD past 'stale' when that child's own
	 * save action is currently "Save template only": the child's own choice
	 * not to generate right now takes precedence. See
	 * TemplateSaveActionState.
	 *
	 * @param array<string, mixed> $oldConfig The parent's config before this save.
	 * @param array<string, mixed> $newConfig The parent's config after this save.
	 */
	private static function cascadeToChildTemplates( int $parentTemplateId, array $oldConfig, array $newConfig, string $targetStatus ): void {
		// Same shape as TemplateDeletionManager::getChildTemplateIds(), minus
		// 'trash': a trashed child template has no active workflow to
		// protect (see SavePost::shouldQueueTemplatePost(), which excludes
		// trash the same way).
		$childTemplateIds = array_map( 'intval', get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_parent'    => $parentTemplateId,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] ) );

		$sweepStale = $targetStatus !== GroupTableGateway::STATUS_STALE;

		foreach ( $childTemplateIds as $childTemplateId ) {
			$childTargetStatus = TemplateSaveActionState::isGenerating( $childTemplateId )
				? $targetStatus
				: GroupTableGateway::STATUS_STALE;

			/**
			 * Which parent groups (guids) affect this child after a parent
			 * save. null (the default) = all of them: every group of the
			 * child is marked.
			 *
			 * @param array<int, string>|null $parentGuids
			 * @param int                     $childTemplateId
			 * @param int                     $parentTemplateId
			 * @param array<string, mixed>    $oldConfig The parent's config before this save.
			 * @param array<string, mixed>    $newConfig The parent's config after this save.
			 */
			$parentGuids = apply_filters( 'rowsprout_child_template_changed_parent_groups', null, $childTemplateId, $parentTemplateId, $oldConfig, $newConfig );

			if ( ! is_array( $parentGuids ) ) {
				TemplateLifecycleGroupTableGateway::markStaleByPostId( $childTemplateId, $childTargetStatus, true );
				TemplateLifecycleGroupTableGateway::cancelInProcessByPostId( $childTemplateId, $childTargetStatus );
				continue;
			}

			$parentGuids = array_values( array_unique( array_map( 'strval', $parentGuids ) ) );
			if ( ! empty( $parentGuids ) ) {
				TemplateLifecycleGroupTableGateway::markStaleByParentGuids( [ $childTemplateId ], $parentGuids, $childTargetStatus, true );
				TemplateLifecycleGroupTableGateway::cancelInProcessByParentGuids( [ $childTemplateId ], $parentGuids, $childTargetStatus );
			}

			if ( $sweepStale && $childTargetStatus !== GroupTableGateway::STATUS_STALE ) {
				$staleGuids = TemplateLifecycleGroupTableGateway::getGuidsByStatus( $childTemplateId, GroupTableGateway::STATUS_STALE );
				if ( ! empty( $staleGuids ) ) {
					TemplateLifecycleGroupTableGateway::markStaleByGuids( $childTemplateId, $staleGuids, $childTargetStatus, true );
					TemplateLifecycleGroupTableGateway::cancelInProcessByGuids( $childTemplateId, $staleGuids, $childTargetStatus );
				}
			}
		}
	}
}
