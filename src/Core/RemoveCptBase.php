<?php

namespace RowSprout\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strips the rowsprout_page rewrite base from generated permalinks (so
 * .../rowsprout_page/foo/ becomes .../foo/) and teaches WP's request parser
 * to resolve incoming base-less URLs back to the right rowsprout_page post,
 * including nested child pages. Only active while PermalinkSettings has no
 * custom base configured — once the user sets one, PostTypes registers the
 * CPT with that slug directly and WP's native rewrite handles it, so this
 * class stays out of the way entirely.
 *
 * Ported from the "Remove CPT base" plugin (KubiQ, remove-cpt-base),
 * narrowed to the single hardcoded rowsprout_page post type.
 */
final class RemoveCptBase {

	private const POST_TYPE = PostTypes::PAGE;

	public static function register(): void {
		if ( PermalinkSettings::getBase() !== '' ) {
			return;
		}

		add_filter( 'post_type_link', [ self::class, 'removeSlug' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'redirectOldUrl' ], 1 );
		add_filter( 'request', [ self::class, 'resolveRequest' ] );
	}

	public static function activate(): void {
		PostTypes::registerPostTypes();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function removeSlug( string $permalink, \WP_Post $post ): string {
		if ( $post->post_type !== self::POST_TYPE ) {
			return $permalink;
		}

		$slug = self::getRewriteSlug();
		if ( $slug === '' ) {
			return $permalink;
		}

		return str_replace( '/' . $slug . '/', '/', $permalink );
	}

	public static function redirectOldUrl(): void {
		global $post;

		if ( is_preview() || ! is_single() || ! ( $post instanceof \WP_Post ) || $post->post_type !== self::POST_TYPE ) {
			return;
		}

		$noBaseUrl = get_permalink( $post );
		$realUrl   = self::getCurrentUrl();

		if ( strpos( $realUrl, $noBaseUrl ) !== false ) {
			return;
		}

		remove_filter( 'post_type_link', [ self::class, 'removeSlug' ], 10 );
		$withBaseUrl = get_permalink( $post );
		add_filter( 'post_type_link', [ self::class, 'removeSlug' ], 10, 2 );

		if ( $withBaseUrl === $noBaseUrl ) {
			return;
		}

		wp_safe_redirect( str_replace( $withBaseUrl, $noBaseUrl, $realUrl ), 301 );
		exit;
	}

	public static function resolveRequest( array $queryVars ): array {
		if ( is_admin() || isset( $queryVars['post_type'] ) ) {
			return $queryVars;
		}

		$looksUnresolved = ( isset( $queryVars['error'] ) && (int) $queryVars['error'] === 404 )
			|| isset( $queryVars['pagename'] )
			|| isset( $queryVars['attachment'] )
			|| isset( $queryVars['name'] )
			|| isset( $queryVars['category_name'] );

		if ( ! $looksUnresolved ) {
			return $queryVars;
		}

		$path = self::extractCandidatePath( $queryVars );
		if ( $path === '' ) {
			return $queryVars;
		}

		// Native posts/pages already own their own paths — don't steal them.
		if ( get_page_by_path( $path, OBJECT, 'post' ) instanceof \WP_Post ) {
			return $queryVars;
		}
		if ( get_page_by_path( $path ) instanceof \WP_Post ) {
			return $queryVars;
		}

		$found = get_page_by_path( $path, OBJECT, self::POST_TYPE );
		if ( $found instanceof \WP_Post ) {
			return self::buildQueryVarsForFoundPost( $queryVars, $found, $path );
		}

		return self::resolveViaRewriteRules( $queryVars, $path );
	}

	private static function extractCandidatePath( array $queryVars ): string {
		$webRoots   = array_unique( [ site_url(), home_url() ] );
		$currentUrl = self::getCurrentUrl();

		foreach ( $webRoots as $webRoot ) {
			$path = trim( str_replace( $webRoot, '', $currentUrl ), '/' );

			// Strip off any trailing rewrite endpoint segment (e.g. pagination).
			$segments = explode( '/', $path );
			foreach ( $segments as $i => $segment ) {
				if ( isset( $queryVars[ $segment ] ) ) {
					$segments = array_slice( $segments, 0, $i );
					break;
				}
			}

			$path = implode( '/', $segments );
			if ( $path !== '' ) {
				// Neither site_url() nor home_url() reflect a multilingual
				// plugin's directory-based language prefix at this point
				// (they're only rewritten inside WP's own core request
				// parsing, which already ran before this filter), so a
				// prefixed URL like /en/foo/ comes back as "en/foo" here.
				// Let a multilingual integration (see WpmlLanguagePrefix in
				// the Pro plugin) strip a recognized prefix segment before
				// it's used to look up the page.
				return apply_filters( 'rowsprout_url_candidate_path', $path );
			}
		}

		return '';
	}

	private static function buildQueryVarsForFoundPost( array $queryVars, \WP_Post $post, string $path ): array {
		$postName  = $post->post_name;
		$ancestors = get_post_ancestors( $post->ID );
		foreach ( $ancestors as $ancestorId ) {
			$postName = get_post_field( 'post_name', $ancestorId ) . '/' . $postName;
		}

		unset( $queryVars['error'], $queryVars['pagename'], $queryVars['attachment'], $queryVars['category_name'] );
		$queryVars['page']         = '';
		$queryVars['name']         = $postName !== '' ? $postName : $path;
		$queryVars['post_type']    = self::POST_TYPE;
		$queryVars[ self::POST_TYPE ] = $path;

		return $queryVars;
	}

	/**
	 * Fallback for nested child pages: the "name" WP parsed off the URL is
	 * only the last path segment, so re-test the CPT's own rewrite rules
	 * against the full path to recover the real post.
	 */
	private static function resolveViaRewriteRules( array $queryVars, string $path ): array {
		global $wp_rewrite;

		$queryVar = self::getQueryVar();
		if ( $queryVar === '' || empty( $wp_rewrite->rules ) ) {
			return $queryVars;
		}

		foreach ( $wp_rewrite->rules as $pattern => $rewrite ) {
			if ( strpos( $pattern, $queryVar ) === false ) {
				continue;
			}

			$hasCaptureGroup = strpos( $pattern, '(' . $queryVar . ')' ) !== false;
			$subject         = $hasCaptureGroup
				? $queryVar . '/' . $path
				: '/' . $queryVar . '/' . $path;

			if ( ! preg_match( '#' . $pattern . '#', $subject, $matches ) ) {
				continue;
			}

			$rewrite = str_replace( 'index.php?', '', $rewrite );
			parse_str( $rewrite, $rewrittenQuery );
			foreach ( $rewrittenQuery as $key => $value ) {
				$index = (int) str_replace( [ '$matches[', ']' ], '', $value );
				if ( isset( $matches[ $index ] ) ) {
					$rewrittenQuery[ $key ] = $matches[ $index ];
				}
			}

			if ( ! isset( $rewrittenQuery[ $queryVar ] ) ) {
				continue;
			}

			$found = get_page_by_path( '/' . $rewrittenQuery[ $queryVar ], OBJECT, self::POST_TYPE );
			if ( ! ( $found instanceof \WP_Post ) ) {
				continue;
			}

			$queryVars = self::buildQueryVarsForFoundPost( $queryVars, $found, $path );
			foreach ( $rewrittenQuery as $key => $value ) {
				if ( $key !== 'post_type' && strpos( (string) $value, '$matches' ) !== 0 ) {
					$queryVars[ $key ] = $value;
				}
			}

			return $queryVars;
		}

		return $queryVars;
	}

	private static function getRewriteSlug(): string {
		$postType = get_post_type_object( self::POST_TYPE );
		if ( ! $postType || empty( $postType->rewrite['slug'] ) ) {
			return '';
		}

		return trim( $postType->rewrite['slug'], '/' );
	}

	private static function getQueryVar(): string {
		$postType = get_post_type_object( self::POST_TYPE );

		return $postType ? (string) $postType->query_var : '';
	}

	private static function getCurrentUrl(): string {
		$isHttps = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' )
			|| ( isset( $_SERVER['SERVER_PORT'] ) && (int) $_SERVER['SERVER_PORT'] === 443 )
			|| ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' );

		$serverName = isset( $_SERVER['SERVER_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) : '';
		$host       = $serverName === 'localhost' && isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: $serverName;

		$requestUri = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';

		return ( $isHttps ? 'https://' : 'http://' ) . $host . $requestUri;
	}
}
