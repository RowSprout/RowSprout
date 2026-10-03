<?php

namespace RowSprout\ThirdParty;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankMath {

	public static function register(): void {
		add_filter( 'rank_math/sitemap/exclude_post_type', [ self::class, 'excludePostType' ], 10, 2 );
		add_filter( 'rank_math/frontend/robots', [ self::class, 'setRobots' ] );
		add_filter( 'rowsprout_excluded_meta_keys', [ self::class, 'extendExcludedMetaKeys' ], 10, 4 );
		add_filter( 'rowsprout_reconciled_meta_key_prefixes', [ self::class, 'extendReconciledMetaKeyPrefixes' ] );
	}

	/**
	 * RankMath gives every Schema block added in its SEO panel its own
	 * rank_math_schema_<Type> meta key (e.g. rank_math_schema_Product,
	 * rank_math_schema_Service) — an admin removing one schema type from a
	 * template and adding another needs the old one actually dropped from
	 * every already-generated page, not just the new one added alongside
	 * it (see PageMetaReplicator::reconcilePrefixedMetaKeys() for the full
	 * reasoning and confirmed live bug this fixes).
	 *
	 * @param array<int, string> $prefixes
	 * @return array<int, string>
	 */
	public static function extendReconciledMetaKeyPrefixes( array $prefixes ): array {
		$prefixes[] = 'rank_math_schema_';

		return $prefixes;
	}

	/**
	 * @param array<int, string> $excludedKeys
	 * @return array<int, string>
	 */
	public static function extendExcludedMetaKeys( array $excludedKeys, int $sourcePostId, int $targetPostId, array $group ): array {
		unset( $sourcePostId, $targetPostId, $group );

		$excludedKeys[] = 'rank_math_internal_links_processed';
		$excludedKeys[] = 'rank_math_analytic_object_id';

		return array_values( array_unique( array_filter( $excludedKeys, 'is_string' ) ) );
	}

	/**
	 * Filter decision if post type is excluded from the XML sitemap.
	 *
	 * @param bool   $exclude
	 * @param string $type
	 * @return bool
	 */
	public static function excludePostType( bool $exclude, string $type ): bool {
		if ( PostTypes::TEMPLATE === $type ) {
			return false;
		}
		return $exclude;
	}

	/**
	 * Force noindex for rowsprout_template and index for rowsprout_page.
	 *
	 * @param array $robots
	 * @return array
	 */
	public static function setRobots( array $robots ): array {
		if ( is_singular( PostTypes::TEMPLATE ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
		}

		if ( is_singular( PostTypes::PAGE ) ) {
			$robots['index']  = 'index';
			$robots['follow'] = 'follow';
		}

		return $robots;
	}
}
