<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Helpers;
use RowSprout\Core\Page\PageBuilder;
use RowSprout\Core\PermalinkSettings;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The URLs a template's groups would get that something else already has:
 * a group of another template (generated or not yet), a page another
 * template generated, or, when generated pages have no URL base, a regular
 * WordPress page or post. Two generated pages at one address make one of
 * them unreachable without any warning (generated slugs skip WordPress's own
 * uniqueness check, see PageUpserter), so a save is refused instead, as for
 * two groups of one template (HrefUniquenessValidator).
 *
 * A URL is computed the way PageBuilder::createById() builds the page:
 * the template's URL pattern with the group's placeholders filled in
 * (a child template's group inherits from its parent group), through
 * sanitize_title(), under the parent template's page for a child.
 *
 * Only templates that generate pages (published, scheduled, private) are
 * checked and compared. Language: the plugin itself knows none; an add-on
 * removes the conflicts that only exist across languages through the
 * rowsprout_url_conflicts filter.
 */
final class UrlConflicts {

	private const GENERATING_STATUSES = [ 'publish', 'future', 'private' ];

	/**
	 * @param array<string, mixed> $config The config about to be saved.
	 * @param string|null          $status The status the template is about to
	 *                                     get, for a check before publishing
	 *                                     it; else its own.
	 * @return array<int, array{guid:string, path:string, post_id:int, kind:string, title:string}>
	 */
	public static function find( int $templateId, array $config, ?string $status = null ): array {
		$post = get_post( $templateId );
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE || ! in_array( $status ?? $post->post_status, self::GENERATING_STATUSES, true ) ) {
			return [];
		}

		$own = self::paths( $post, $config );
		if ( $own === [] ) {
			return [];
		}

		$wanted    = array_fill_keys( array_values( $own ), true );
		$taken     = self::takenPaths( $templateId, $wanted );
		$conflicts = [];
		foreach ( $own as $guid => $path ) {
			if ( isset( $taken[ $path ] ) ) {
				$conflicts[] = [ 'guid' => (string) $guid, 'path' => $path ] + $taken[ $path ];
			}
		}

		/**
		 * The URL conflicts found for a template about to be saved. Each names
		 * what already has the URL (post_id, kind 'template', 'page' or a post
		 * type). A multilingual add-on removes the ones in another language.
		 *
		 * @param array<int, array<string, mixed>> $conflicts
		 * @param int                              $templateId
		 */
		$conflicts = apply_filters( 'rowsprout_url_conflicts', $conflicts, $templateId );

		return array_values( array_filter( (array) $conflicts, 'is_array' ) );
	}

	/**
	 * Group guid => the path its page would get ("slug", or
	 * "parent-path/slug" for a child template), for $config of $post.
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, string>
	 */
	public static function paths( \WP_Post $post, array $config ): array {
		$pattern = (string) ( $config['rowsprout_page_href'] ?? '' );
		if ( $pattern === '' ) {
			return [];
		}

		$ids          = Helpers::getCodeIds( $post->ID );
		$aliasCodeIds = apply_filters( 'rowsprout_group_placeholder_alias_code_ids', [], $post->ID, $post );
		$aliasCodeIds = is_array( $aliasCodeIds ) ? $aliasCodeIds : [];
		$isChild      = (int) $post->post_parent > 0;

		$paths = [];
		foreach ( (array) ( $config['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['id'] ) ) {
				continue;
			}
			$guid         = (string) $group['id'];
			$parentPageId = 0;

			if ( $isChild ) {
				/** This filter is documented in PageBuildContextResolver::resolve(). */
				$context      = apply_filters( 'rowsprout_resolve_child_template_context', [ 'group' => $group, 'parent_id' => null ], $guid, $post->ID, $post );
				$group        = is_array( $context['group'] ?? null ) ? $context['group'] : $group;
				$parentPageId = (int) ( $context['parent_id'] ?? 0 );
			}

			$slug = sanitize_title( PageBuilder::replacePlaceholders( $pattern, $group, $ids['code_id'], $ids['parent_code_id'], $aliasCodeIds ) );
			if ( $slug === '' ) {
				continue;
			}

			$parentPath     = $parentPageId > 0 ? trim( (string) get_page_uri( $parentPageId ), '/' ) : '';
			$paths[ $guid ] = $parentPath !== '' ? $parentPath . '/' . $slug : $slug;
		}

		return $paths;
	}

	/**
	 * The notice for a refused save.
	 *
	 * @param array<int, array<string, mixed>> $conflicts
	 */
	public static function message( array $conflicts ): string {
		return sprintf(
			/* translators: %s: list of URLs with what already uses them. */
			__( 'This template was not saved and no pages were generated: some of its URLs are already in use: %s. Every URL must be unique on the site; change these groups (or the URL pattern) and save again.', 'rowsprout' ),
			self::describe( $conflicts )
		);
	}

	/**
	 * The colliding URLs with what already has them, as one line (at most
	 * five, then a count).
	 *
	 * @param array<int, array<string, mixed>> $conflicts
	 */
	public static function describe( array $conflicts ): string {
		$items = [];
		foreach ( array_slice( $conflicts, 0, 5 ) as $conflict ) {
			$owner = (string) ( $conflict['kind'] ?? '' ) === 'template'
				/* translators: %s: template title. */
				? sprintf( __( 'template "%s"', 'rowsprout' ), (string) ( $conflict['title'] ?? '' ) )
				/* translators: 1: post type name, e.g. "Page", 2: post title. */
				: sprintf( __( '%1$s "%2$s"', 'rowsprout' ), (string) ( $conflict['kind'] ?? '' ), (string) ( $conflict['title'] ?? '' ) );
			$items[] = self::displayPath( (string) ( $conflict['path'] ?? '' ) ) . ' (' . $owner . ')';
		}

		$list = implode( ', ', $items );
		if ( count( $conflicts ) > 5 ) {
			/* translators: 1: list of URLs, 2: number of further URLs. */
			$list = sprintf( __( '%1$s and %2$d more', 'rowsprout' ), $list, count( $conflicts ) - 5 );
		}

		return $list;
	}

	/**
	 * The URL as a visitor sees it, for messages.
	 */
	public static function displayPath( string $path ): string {
		$base = PermalinkSettings::getBase();

		return '/' . ( $base !== '' ? trim( $base, '/' ) . '/' : '' ) . $path . '/';
	}

	/**
	 * Of the paths in $wanted, those something else already has.
	 *
	 * @param array<string, bool> $wanted
	 * @return array<string, array{post_id:int, kind:string, title:string}>
	 */
	private static function takenPaths( int $templateId, array $wanted ): array {
		$slugs = [];
		foreach ( array_keys( $wanted ) as $path ) {
			$segments                      = explode( '/', $path );
			$slugs[ end( $segments ) ] = true;
		}
		$slugs = array_keys( $slugs );

		$taken = [];

		// Pages other templates already generated.
		foreach ( self::postsWithSlugs( [ PostTypes::PAGE ], $slugs ) as $page ) {
			$sourceTemplate = (int) get_post_meta( $page->ID, PostMetaKeys::SOURCE_TEMPLATE_ID, true );
			$path           = trim( (string) get_page_uri( $page ), '/' );
			if ( $sourceTemplate !== $templateId && isset( $wanted[ $path ] ) && ! isset( $taken[ $path ] ) ) {
				$taken[ $path ] = [
					'post_id' => $sourceTemplate > 0 ? $sourceTemplate : $page->ID,
					'kind'    => 'template',
					'title'   => get_the_title( $sourceTemplate > 0 ? $sourceTemplate : $page->ID ),
				];
			}
		}

		// Groups of other templates without an up-to-date page: new, outdated,
		// planned or failed ones. A group whose page is generated and current
		// has exactly that page's URL, which the query above already covers;
		// computing every group of every template on each save is far too slow.
		$others = get_posts( [
			'post_type'        => PostTypes::TEMPLATE,
			'post_status'      => self::GENERATING_STATUSES,
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- every language: a multilingual add-on decides through rowsprout_url_conflicts which ones count, not the current admin language.
			'suppress_filters' => true,
		] );
		foreach ( $others as $other ) {
			if ( $other->ID === $templateId ) {
				continue;
			}

			$current = [];
			foreach ( GroupTableGateway::getRowsByPostId( $other->ID ) as $row ) {
				if ( (int) ( $row['rowsprout_page_id'] ?? 0 ) > 0 && ( $row['status'] ?? '' ) === GroupTableGateway::STATUS_COMPLETED ) {
					$current[ (string) $row['guid'] ] = true;
				}
			}
			$config           = TemplateMeta::get( $other->ID );
			$config['groups'] = array_values( array_filter( (array) ( $config['groups'] ?? [] ), static function ( $group ) use ( $current ): bool {
				return is_array( $group ) && ! isset( $current[ (string) ( $group['id'] ?? '' ) ] );
			} ) );
			if ( $config['groups'] === [] ) {
				continue;
			}

			foreach ( self::paths( $other, $config ) as $path ) {
				if ( isset( $wanted[ $path ] ) && ! isset( $taken[ $path ] ) ) {
					$taken[ $path ] = [
						'post_id' => $other->ID,
						'kind'    => 'template',
						'title'   => get_the_title( $other ),
					];
				}
			}
		}

		// Without a URL base, generated pages share the site root with
		// everything else: pages, posts and the content of other plugins. What
		// counts is the address such a post really has (its permalink), so a
		// post type with a base of its own (/product/amsterdam/) does not
		// clash, and one without does.
		if ( PermalinkSettings::getBase() === '' ) {
			$postTypes = array_values( array_diff(
				get_post_types( [ 'public' => true ] ),
				[ PostTypes::PAGE, PostTypes::TEMPLATE, 'attachment' ]
			) );
			foreach ( self::postsWithSlugs( $postTypes, $slugs ) as $post ) {
				$path = self::sitePath( (string) get_permalink( $post ) );
				if ( $path !== '' && isset( $wanted[ $path ] ) && ! isset( $taken[ $path ] ) ) {
					$typeObject     = get_post_type_object( $post->post_type );
					$taken[ $path ] = [
						'post_id' => $post->ID,
						'kind'    => $typeObject ? (string) $typeObject->labels->singular_name : $post->post_type,
						'title'   => get_the_title( $post ),
					];
				}
			}
		}

		return $taken;
	}

	/**
	 * A URL's path below the site's home ("amsterdam" for
	 * https://example.com/amsterdam/), or '' for a URL elsewhere. A language
	 * prefix (/en/amsterdam/) is stripped the way RemoveCptBase strips it from
	 * a request, since a generated page's own path never carries one.
	 */
	private static function sitePath( string $url ): string {
		$home = trailingslashit( home_url() );
		if ( strpos( $url, $home ) !== 0 ) {
			return '';
		}

		$path = trim( (string) wp_parse_url( substr( $url, strlen( $home ) ), PHP_URL_PATH ), '/' );

		/** This filter is documented in RemoveCptBase. */
		return trim( (string) apply_filters( 'rowsprout_url_candidate_path', $path ), '/' );
	}

	/**
	 * @param array<int, string> $postTypes
	 * @param array<int, string> $slugs
	 * @return array<int, \WP_Post>
	 */
	private static function postsWithSlugs( array $postTypes, array $slugs ): array {
		if ( $slugs === [] ) {
			return [];
		}

		return get_posts( [
			'post_type'        => $postTypes,
			'post_status'      => self::GENERATING_STATUSES,
			'post_name__in'    => $slugs,
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- every language, as above.
			'suppress_filters' => true,
		] );
	}
}
