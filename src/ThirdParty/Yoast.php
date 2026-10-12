<?php

namespace RowSprout\ThirdParty;

use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateIndexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yoast SEO. Its title, description and other per-post settings are
 * `_yoast_wpseo_*` post meta, so PageMetaReplicator copies them with each
 * page's own values. Yoast reads them from its indexables table, which it
 * rebuilds itself at the end of a request for every post whose
 * `_yoast_wpseo_*` meta was added or changed (Indexable_Post_Meta_Watcher).
 */
final class Yoast {

	/**
	 * Scores and estimates Yoast computes from a post's own text: the
	 * template's values say nothing about a generated page, and Yoast
	 * computes the page's own when it is edited.
	 */
	private const COMPUTED_META_KEYS = [
		'_yoast_wpseo_content_score',
		'_yoast_wpseo_linkdex',
		'_yoast_wpseo_inclusive_language_score',
		'_yoast_wpseo_estimated-reading-time-minutes',
	];

	public static function register(): void {
		add_filter( 'wpseo_sitemap_exclude_post_type', [ self::class, 'excludePostType' ], 10, 2 );
		add_filter( 'wpseo_robots_array', [ self::class, 'setRobots' ], 10, 2 );
		add_filter( 'rowsprout_excluded_meta_keys', [ self::class, 'extendExcludedMetaKeys' ] );
	}

	/**
	 * @param bool   $exclude
	 * @param string $postType
	 */
	public static function excludePostType( $exclude, $postType ): bool {
		return PostTypes::TEMPLATE === $postType ? true : (bool) $exclude;
	}

	/**
	 * Noindex for a template and the template archive. Yoast passes the
	 * presentation of the indexable it renders (also outside a front-end
	 * request, e.g. its REST head endpoint); without one the current request
	 * decides.
	 *
	 * @param array<string, string> $robots
	 * @param mixed                 $presentation
	 * @return array<string, string>
	 */
	public static function setRobots( $robots, $presentation = null ): array {
		$robots = (array) $robots;
		$model  = is_object( $presentation ) && isset( $presentation->model ) && is_object( $presentation->model ) ? $presentation->model : null;

		$isTemplate = $model !== null
			? in_array( $model->object_type ?? '', [ 'post', 'post-type-archive' ], true ) && ( $model->object_sub_type ?? '' ) === PostTypes::TEMPLATE
			: TemplateIndexing::isTemplateView();

		if ( $isTemplate ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
		}

		return $robots;
	}

	/**
	 * @param array<int, string> $excludedKeys
	 * @return array<int, string>
	 */
	public static function extendExcludedMetaKeys( array $excludedKeys ): array {
		return array_values( array_unique( array_merge( $excludedKeys, self::COMPUTED_META_KEYS ) ) );
	}
}
