<?php

namespace RowSprout\ThirdParty;

use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateIndexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The SEO Framework. Its title, description, social and robots settings are
 * post meta (`_genesis_*`, `_open_graph_*`, `_twitter_*`, `_social_*`), so
 * PageMetaReplicator copies them with each page's own values.
 */
final class SeoFramework {

	public static function register(): void {
		add_filter( 'the_seo_framework_sitemap_supported_post_types', [ self::class, 'excludeFromSitemap' ] );
		add_filter( 'the_seo_framework_robots_meta_array', [ self::class, 'setRobots' ], 10, 2 );
	}

	/**
	 * @param array<int, string> $postTypes
	 * @return array<int, string>
	 */
	public static function excludeFromSitemap( $postTypes ): array {
		return array_values( array_diff( (array) $postTypes, [ PostTypes::TEMPLATE ] ) );
	}

	/**
	 * Noindex for a template and the template archive. $args names the post
	 * (`id`) or post type archive (`pta`) TSF generates for, e.g. for its
	 * sitemap; it is null when TSF works from the current request.
	 *
	 * @param array<string, string> $meta
	 * @param array<string, mixed>|null $args
	 * @return array<string, string>
	 */
	public static function setRobots( $meta, $args = null ): array {
		$meta = (array) $meta;

		if ( is_array( $args ) ) {
			$isTemplate = ( $args['pta'] ?? '' ) === PostTypes::TEMPLATE
				|| ( empty( $args['tax'] ) && ! empty( $args['id'] ) && get_post_type( (int) $args['id'] ) === PostTypes::TEMPLATE );
		} else {
			$isTemplate = TemplateIndexing::isTemplateView();
		}

		if ( $isTemplate ) {
			$meta['noindex']  = 'noindex';
			$meta['nofollow'] = 'nofollow';
		}

		return $meta;
	}
}
