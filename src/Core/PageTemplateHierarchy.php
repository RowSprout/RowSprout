<?php

namespace RowSprout\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets generated pages and templates fall back to the theme's page template
 * instead of its blog-post template.
 *
 * Both post types are custom post types, so WordPress resolves them through
 * the single hierarchy (single-{type}-{slug}, single-{type}, single, singular,
 * index). A theme's single template is written for blog posts: Twenty
 * Twenty-Five's prints "Written by [author] in [categories]", and since these
 * posts have no author support and no categories, visitors saw a bare
 * "Written by in" above every landing page. 'page' is inserted right before
 * 'single', so a template the admin picked, a theme's own single-{type}
 * template, or a theme without a page template (e.g. Hello Elementor, which
 * routes everything through index.php) are unaffected.
 *
 * The same filter serves the front end (get_single_template(), entries end in
 * .php) and the block editor's default-template lookup
 * (get_template_hierarchy(), bare slugs), so the editor shows the template
 * the pages will really use.
 */
final class PageTemplateHierarchy {

	public static function register(): void {
		add_filter( 'single_template_hierarchy', [ self::class, 'preferPageTemplate' ] );
	}

	/**
	 * @param string[] $templates
	 * @return string[]
	 */
	public static function preferPageTemplate( $templates ): array {
		$templates = is_array( $templates ) ? array_values( $templates ) : [];
		if ( ! self::isOwnPostType( $templates ) ) {
			return $templates;
		}

		foreach ( $templates as $index => $template ) {
			if ( $template === 'single.php' || $template === 'single' ) {
				$page = $template === 'single.php' ? 'page.php' : 'page';
				if ( ! in_array( $page, $templates, true ) ) {
					array_splice( $templates, $index, 0, [ $page ] );
				}
				break;
			}
		}

		return $templates;
	}

	/**
	 * The hierarchy always names the post type ("single-rowsprout_page" or
	 * "single-rowsprout_page.php", possibly followed by "-<slug>"), on the
	 * front end and in the editor lookup alike, while the queried object is
	 * not available in the latter.
	 *
	 * @param string[] $templates
	 */
	private static function isOwnPostType( array $templates ): bool {
		foreach ( [ PostTypes::PAGE, PostTypes::TEMPLATE ] as $postType ) {
			$prefix = 'single-' . $postType;
			foreach ( $templates as $template ) {
				if ( ! is_string( $template ) ) {
					continue;
				}
				$name = preg_replace( '/\.php$/', '', $template );
				if ( $name === $prefix || strpos( $name, $prefix . '-' ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}
}
