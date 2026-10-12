<?php

namespace RowSprout\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps templates out of search engines on every site, with or without an SEO
 * plugin. The template post type has to stay public with an archive (page
 * builders such as Elementor did not open it otherwise, see PostTypes), which
 * also put it in WordPress' own XML sitemap and left it indexable. The SEO
 * plugin integrations in ThirdParty/ do the same for their own sitemaps and
 * robots output, using isTemplateView().
 */
final class TemplateIndexing {

	public static function register(): void {
		add_filter( 'wp_sitemaps_post_types', [ self::class, 'excludeFromCoreSitemap' ] );
		add_filter( 'wp_robots', [ self::class, 'setRobots' ] );
	}

	/**
	 * Whether the current front-end request shows a template or the template
	 * archive.
	 */
	public static function isTemplateView(): bool {
		return is_singular( PostTypes::TEMPLATE ) || is_post_type_archive( PostTypes::TEMPLATE );
	}

	/**
	 * @param array<string, \WP_Post_Type> $postTypes
	 * @return array<string, \WP_Post_Type>
	 */
	public static function excludeFromCoreSitemap( array $postTypes ): array {
		unset( $postTypes[ PostTypes::TEMPLATE ] );

		return $postTypes;
	}

	/**
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public static function setRobots( array $robots ): array {
		if ( ! self::isTemplateView() ) {
			return $robots;
		}

		// Not wp_robots_no_robots(): on a public site that keeps "follow".
		unset( $robots['follow'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}
}
