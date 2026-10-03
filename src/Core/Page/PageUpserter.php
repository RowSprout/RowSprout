<?php

namespace RowSprout\Core\Page;

use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageUpserter {

	/**
	 * @param array<string, mixed> $defaults
	 * @param int      $templateId
	 * @param callable $getValidIdById
	 * @return int|\WP_Error
	 */
	public static function upsertByGroupId( array $defaults, string $id, int $templateId, callable $getValidIdById ) {
		$rowsproutPageId = (int) call_user_func( $getValidIdById, $id, $templateId );

		if ( ! $rowsproutPageId ) {
			// Fallback recovery path for when the GroupTableGateway row itself
			// lost track of its rowsprout_page_id but the page still carries its
			// own source markers — scoped by BOTH markers together (not just
			// _rowsprout_page_source_group_id) for the same reason the primary
			// lookup above is scoped by template: a group id is only unique
			// within its own template (e.g. a WPML translation of a template
			// starts out with the same group ids as its source).
			$matchedPosts = get_posts( [
				'post_type'        => PostTypes::PAGE,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- must bypass WPML's language filtering: this fallback lookup is explicitly scoped by template + group id across all languages, not the current one.
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- two-key meta_query is the only reliable lookup here; see the fallback-recovery comment above.
				'meta_query'       => [
					[
						'key'   => PostMetaKeys::SOURCE_GROUP_ID,
						'value' => $id,
					],
					[
						'key'   => PostMetaKeys::SOURCE_TEMPLATE_ID,
						'value' => $templateId,
					],
				],
			] );

			if ( ! empty( $matchedPosts ) ) {
				$rowsproutPageId = (int) $matchedPosts[0];
			}
		}

		// Deliberately never re-added (see HrefUniquenessValidator's own
		// docblock for why WP's slug-dedup is disabled for generated pages
		// in the first place). Confirmed live this filter is registered
		// NOWHERE else in either plugin — every prior version of this
		// method re-added it with add_filter( 'wp_unique_post_slug',
		// 'wp_unique_post_slug', 10, 6 ), which registers WP core's OWN
		// wp_unique_post_slug() function as a filter callback on its own
		// hook. The instant anything ELSE calls wp_insert_post/
		// wp_update_post later in the same request — confirmed live via
		// WPML's Page Builders integration, which defers its own Elementor
		// string-sync update to the 'shutdown' action — that filter fires,
		// calls wp_unique_post_slug() again, which fires the filter again:
		// genuine infinite recursion, only survivable in local dev because
		// Xdebug's max-nesting-level guard aborts it — in production,
		// without Xdebug, this hangs the request or exhausts memory.
		// remove_filter() here is a harmless no-op on every call after the
		// first, since there is now nothing left to ever re-register it.
		remove_filter( 'wp_unique_post_slug', 'wp_unique_post_slug', 10, 6 );

		if ( $rowsproutPageId ) {
			// PageBuilder sets post_modified/post_modified_gmt to the
			// TEMPLATE's own timestamps (so every page generated from one
			// template reports the same "last updated" date instead of
			// drifting apart as a big batch regenerates one at a time) —
			// but that means any regeneration that runs after the template
			// was resaved (e.g. a full site-wide reconversion) touches
			// every one of its pages' post_modified even when the actual
			// rendered output hasn't changed at all. WPML (and anything
			// else watching post_modified for "did this need re-checking")
			// then flags every translation as outdated, page-wide, purely
			// as a side effect of resaving the templates. Skipping the
			// update entirely when nothing real changed avoids that false
			// signal without touching the template-mirroring behavior for
			// genuine content changes.
			if ( self::isUnchanged( $rowsproutPageId, $defaults ) ) {
				return $rowsproutPageId;
			}

			$updatePost = $defaults + [ 'ID' => $rowsproutPageId ];
			wp_update_post( wp_slash( $updatePost ), true );
			$newPostId = $rowsproutPageId;
		} else {
			$newPostId = wp_insert_post( wp_slash( $defaults ), true );
			if ( is_wp_error( $newPostId ) ) {
				return $newPostId;
			}
		}

		return $newPostId;
	}

	/**
	 * @param array<string, mixed> $defaults
	 */
	private static function isUnchanged( int $postId, array $defaults ): bool {
		$existing = get_post( $postId, ARRAY_A );
		if ( ! is_array( $existing ) ) {
			return false;
		}

		// These two mirror the TEMPLATE's timestamps, not this specific
		// page's own history — comparing them would always report
		// "changed" the moment the template is resaved, which is exactly
		// the false signal this check exists to avoid. Every other key is
		// this page's actual rendered output/identity, and is compared
		// normally.
		$ignoredKeys = [ 'post_modified', 'post_modified_gmt' ];

		// wp_insert_post()/wp_update_post() themselves cast these to (int)
		// before storing (so e.g. a null post_parent and a 0 post_parent
		// produce the exact same stored row) — comparing them as plain
		// strings would treat that as a "change" on every single top-level
		// (parentless) page, since $defaults commonly carries null here
		// while the stored row always has 0. Confirmed live: this was
		// producing a false mismatch for exactly that reason.
		$intKeys = [ 'post_parent', 'menu_order', 'post_author' ];

		foreach ( $defaults as $key => $value ) {
			if ( in_array( $key, $ignoredKeys, true ) || ! array_key_exists( $key, $existing ) ) {
				continue;
			}

			if ( in_array( $key, $intKeys, true ) ) {
				if ( (int) $existing[ $key ] !== (int) $value ) {
					return false;
				}
				continue;
			}

			// $value is the freshly regenerated, NOT-YET-SAVED string —
			// $existing[$key] already went through wp_update_post()'s own
			// db-context sanitizing (content_save_pre/title_save_pre/etc.,
			// which is what strips a bare <iframe> for a non-admin/CLI
			// request context and reformats style="..." attribute
			// whitespace) when it was last stored. Comparing the raw fresh
			// value against that already-sanitized value would report
			// "changed" on every single call regardless of whether
			// anything real changed — confirmed live: exactly this made
			// the no-op check never actually trigger. Running $value
			// through the same sanitize_post_field( ..., 'db' ) pass
			// before comparing puts both sides on equal footing.
			//
			// wp_unslash() afterward is required too: the 'db' context's
			// own KSES filter (wp_filter_post_kses) ends with addslashes()
			// on the assumption the rest of core's save pipeline will
			// unslash it again right before the DB write — get_post()'s
			// $existing values are never slashed, so without this the
			// comparison would fail on every quote in the content.
			$sanitizedValue = wp_unslash( sanitize_post_field( $key, (string) $value, $postId, 'db' ) );

			if ( (string) $existing[ $key ] !== (string) $sanitizedValue ) {
				return false;
			}
		}

		return true;
	}
}
