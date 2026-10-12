<?php

namespace RowSprout\ThirdParty;

use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateIndexing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEOPress. Its title, description, social and robots settings are
 * `_seopress_*` post meta, so PageMetaReplicator copies them with each page's
 * own values.
 */
final class SeoPress {

	/** SEOPress' sitemap settings (Options::KEY_OPTION_SITEMAP). */
	private const SITEMAP_OPTION = 'seopress_xml_sitemap_option_name';

	public static function register(): void {
		add_filter( 'option_' . self::SITEMAP_OPTION, [ self::class, 'excludeFromSitemap' ] );
		add_filter( 'seopress_titles_robots', [ self::class, 'setRobots' ] );
		add_filter( 'rowsprout_excluded_meta_keys', [ self::class, 'extendExcludedMetaKeys' ] );
	}

	/**
	 * SEOPress only puts the post types ticked in its sitemap settings in the
	 * sitemap (post, page and product by default); this keeps templates out
	 * even when they were ticked.
	 *
	 * @param mixed $options
	 * @return mixed
	 */
	public static function excludeFromSitemap( $options ) {
		if ( is_array( $options ) && isset( $options['seopress_xml_sitemap_post_types_list'][ PostTypes::TEMPLATE ] ) ) {
			unset( $options['seopress_xml_sitemap_post_types_list'][ PostTypes::TEMPLATE ] );
		}

		return $options;
	}

	/**
	 * SEOPress passes the whole robots meta tag it is about to print.
	 *
	 * @param mixed $tag
	 * @return mixed
	 */
	public static function setRobots( $tag ) {
		if ( ! TemplateIndexing::isTemplateView() ) {
			return $tag;
		}

		return '<meta name="robots" content="noindex, nofollow">' . "\n";
	}

	/**
	 * The content analysis SEOPress stores for the template is about the
	 * template's own text; a generated page gets its own when it is edited.
	 *
	 * @param array<int, string> $excludedKeys
	 * @return array<int, string>
	 */
	public static function extendExcludedMetaKeys( array $excludedKeys ): array {
		$excludedKeys[] = '_seopress_analysis_data';

		return array_values( array_unique( $excludedKeys ) );
	}
}
