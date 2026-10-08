<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Groups\QueueHold;
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
 * template generated, or a regular WordPress page or post at the same
 * address (under the URL base, if there is one). Two generated pages at one address make one of
 * them unreachable without any warning (generated slugs skip WordPress's own
 * uniqueness check, see PageUpserter), so such a group is not generated
 * (queueWithout()), as a group whose URL another group of the same template
 * has (HrefUniquenessValidator). The template itself is always saved.
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
			// Every owner of the path, so a filter that drops one (another
			// language) still sees the others.
			foreach ( $taken[ $path ] ?? [] as $owner ) {
				$conflicts[] = [ 'guid' => (string) $guid, 'path' => $path ] + $owner;
			}
		}

		/**
		 * The URL conflicts found for a template about to be saved: one per
		 * group and thing that already has its URL (post_id, kind 'template',
		 * 'page' or a post type). A multilingual add-on removes the ones in
		 * another language. One conflict per group is kept afterwards.
		 *
		 * @param array<int, array<string, mixed>> $conflicts
		 * @param int                              $templateId
		 */
		$conflicts = apply_filters( 'rowsprout_url_conflicts', $conflicts, $templateId );

		$perGroup = [];
		foreach ( (array) $conflicts as $conflict ) {
			if ( is_array( $conflict ) && ! isset( $perGroup[ (string) ( $conflict['guid'] ?? '' ) ] ) ) {
				$perGroup[ (string) ( $conflict['guid'] ?? '' ) ] = $conflict;
			}
		}

		return array_values( $perGroup );
	}

	/**
	 * Everything that keeps a group's page from being generated: its URL is
	 * in use elsewhere (find()), or another group of the same template has
	 * the same URL (kind 'duplicate').
	 *
	 * @param array<string, mixed> $config
	 * @return array<int, array<string, mixed>>
	 */
	public static function blocking( int $templateId, array $config ): array {
		$conflicts = self::find( $templateId, $config );
		$post      = get_post( $templateId );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, self::GENERATING_STATUSES, true ) ) {
			return $conflicts;
		}

		$listed = array_fill_keys( array_map( 'strval', array_column( $conflicts, 'guid' ) ), true );
		$paths  = null;
		foreach ( HrefUniquenessValidator::findDuplicateGroups( $config ) as $guids ) {
			$paths = $paths ?? self::paths( $post, $config );
			foreach ( $guids as $guid ) {
				if ( isset( $listed[ (string) $guid ] ) ) {
					continue;
				}
				$listed[ (string) $guid ] = true;
				$conflicts[]              = [
					'guid'    => (string) $guid,
					'path'    => $paths[ (string) $guid ] ?? '',
					'post_id' => $templateId,
					'kind'    => 'duplicate',
					'title'   => get_the_title( $post ),
				];
			}
		}

		return $conflicts;
	}

	/**
	 * Runs $queue (whatever queues the template's groups: a save, a generate
	 * action) while keeping every blocked group (blocking()) out of it, see
	 * QueueHold. Covers the template and its child templates, whose groups a
	 * template-wide save or generate queues too.
	 *
	 * @param array<int, string>|null $guids Only these groups of the template
	 *                                       (a call that queues a subset): no
	 *                                       other group, and no child
	 *                                       template, is held or reported.
	 * @return array<int, array<string, mixed>> The groups that were held
	 *                                          back, each with its template_id.
	 */
	public static function queueWithout( int $templateId, callable $queue, ?array $guids = null ): array {
		global $wpdb;

		$templateIds = [ $templateId ];
		if ( $guids === null ) {
			$templateIds = array_merge( $templateIds, array_map( 'intval', get_children( [
				'post_parent' => $templateId,
				'post_type'   => PostTypes::TEMPLATE,
				'post_status' => 'any',
				'fields'      => 'ids',
			] ) ) );
		}
		$scope = $guids === null ? null : array_fill_keys( array_map( 'strval', $guids ), true );

		$conflicts = [];
		$before    = [];
		foreach ( $templateIds as $id ) {
			$found = self::blocking( $id, TemplateMeta::get( $id ) );
			if ( $scope !== null ) {
				$found = array_values( array_filter( $found, static function ( array $conflict ) use ( $scope ): bool {
					return isset( $scope[ (string) ( $conflict['guid'] ?? '' ) ] );
				} ) );
			}
			if ( $found === [] ) {
				continue;
			}
			$before[ $id ] = QueueHold::rowsOf( $id, array_column( $found, 'guid' ) );
			foreach ( $found as $conflict ) {
				$conflicts[] = [ 'template_id' => $id ] + $conflict;
			}
		}

		QueueHold::run( $before, $queue );

		return $conflicts;
	}

	/**
	 * Group guid => the path its page would get ("slug", or
	 * "parent-path/slug" for a child template), for $config of $post. A
	 * pattern that comes out empty gets the slug WordPress then derives from
	 * the page title.
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

		$paths = [];
		foreach ( (array) ( $config['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['id'] ) ) {
				continue;
			}
			$guid                   = (string) $group['id'];
			[ $group, $parentPath ] = self::resolveGroup( $post, $group );

			$slug = sanitize_title( PageBuilder::replacePlaceholders( $pattern, $group, $ids['code_id'], $ids['parent_code_id'], $aliasCodeIds ) );
			if ( $slug === '' ) {
				$slug = sanitize_title( PageBuilder::replacePlaceholders( $post->post_title, $group, $ids['code_id'], $ids['parent_code_id'], $aliasCodeIds ) );
			}
			if ( $slug === '' ) {
				continue;
			}

			$paths[ $guid ] = $parentPath !== '' ? $parentPath . '/' . $slug : $slug;
		}

		return $paths;
	}

	/**
	 * A free href/slug value for each colliding group, the way WordPress makes
	 * a slug unique ("amsterdam" → "amsterdam-2"), checked against the same
	 * URLs (and the same rowsprout_url_conflicts filter) as find() and against
	 * this template's own other groups. A group whose URL does not come from
	 * its href value (a pattern without that placeholder) gets none.
	 *
	 * @param array<string, mixed>             $config
	 * @param array<int, array<string, mixed>> $conflicts From find().
	 * @return array<string, string> Group guid => suggested href value.
	 */
	public static function suggestions( int $templateId, array $config, array $conflicts ): array {
		$post = get_post( $templateId );
		if ( ! $post instanceof \WP_Post || $conflicts === [] ) {
			return [];
		}

		$own    = self::paths( $post, $config );
		$groups = [];
		foreach ( (array) ( $config['groups'] ?? [] ) as $group ) {
			if ( is_array( $group ) && isset( $group['id'] ) ) {
				$groups[ (string) $group['id'] ] = $group;
			}
		}

		// Group guid => [ candidate value => its path ].
		$candidates = [];
		foreach ( array_unique( array_map( 'strval', array_column( $conflicts, 'guid' ) ) ) as $guid ) {
			$group = $groups[ $guid ] ?? null;
			if ( $group === null || ! isset( $own[ $guid ] ) ) {
				continue;
			}
			$hrefKey = self::hrefKey( $group );
			$base    = $hrefKey !== '' ? (string) ( self::resolveGroup( $post, $group )[0]['fields'][ $hrefKey ]['value'] ?? '' ) : '';
			if ( $base === '' ) {
				continue;
			}
			for ( $n = 2; $n <= 20; $n++ ) {
				$candidate                                = $group;
				$candidate['fields'][ $hrefKey ]          = (array) ( $candidate['fields'][ $hrefKey ] ?? [] );
				$candidate['fields'][ $hrefKey ]['type']  = 'href';
				$candidate['fields'][ $hrefKey ]['value'] = $base . '-' . $n;
				$path = self::paths( $post, [ 'rowsprout_page_href' => $config['rowsprout_page_href'] ?? '', 'groups' => [ $candidate ] ] )[ $guid ] ?? '';
				if ( $path === '' || $path === $own[ $guid ] ) {
					continue 2;
				}
				$candidates[ $guid ][ $base . '-' . $n ] = $path;
			}
		}
		if ( $candidates === [] ) {
			return [];
		}

		$wanted = [];
		foreach ( $candidates as $paths ) {
			$wanted += array_fill_keys( array_values( $paths ), true );
		}
		$taken = [];
		foreach ( self::takenPaths( $templateId, $wanted ) as $path => $owners ) {
			foreach ( $owners as $owner ) {
				$taken[] = [ 'guid' => '', 'path' => $path ] + $owner;
			}
		}
		/** This filter is documented in find(). */
		$taken = apply_filters( 'rowsprout_url_conflicts', $taken, $templateId );
		$used  = array_fill_keys( array_values( $own ), true ) + array_fill_keys( array_column( array_filter( (array) $taken, 'is_array' ), 'path' ), true );

		$suggestions = [];
		foreach ( $candidates as $guid => $paths ) {
			foreach ( $paths as $value => $path ) {
				if ( ! isset( $used[ $path ] ) ) {
					$suggestions[ $guid ] = (string) $value;
					$used[ $path ]        = true;
					break;
				}
			}
		}

		return $suggestions;
	}

	/**
	 * The group as its page is built from it (a child template's group with
	 * its parent group's values filled in) and the path of the page it goes
	 * under ('' for a top-level template). While the parent group has no page
	 * yet, that is the path the parent group's page will get: a child group
	 * is only generated after its parent's (QueueProcessor).
	 *
	 * @param array<string, mixed> $group
	 * @return array{0: array<string, mixed>, 1: string}
	 */
	private static function resolveGroup( \WP_Post $post, array $group ): array {
		if ( (int) $post->post_parent <= 0 ) {
			return [ $group, '' ];
		}

		/** This filter is documented in PageBuildContextResolver::resolve(). */
		$context  = apply_filters( 'rowsprout_resolve_child_template_context', [ 'group' => $group, 'parent_id' => null ], (string) $group['id'], $post->ID, $post );
		$resolved = is_array( $context['group'] ?? null ) ? $context['group'] : $group;
		$pageId   = (int) ( $context['parent_id'] ?? 0 );
		if ( $pageId > 0 ) {
			return [ $resolved, trim( (string) get_page_uri( $pageId ), '/' ) ];
		}

		$parentGuid = (string) ( $group['parent_id'] ?? '' );
		$parent     = get_post( (int) $post->post_parent );
		if ( $parentGuid === '' || $parentGuid === '0' || ! $parent instanceof \WP_Post || (int) $parent->post_parent > 0 ) {
			return [ $resolved, '' ];
		}

		static $parentPaths = [];
		$parentConfig = TemplateMeta::get( $parent->ID );
		$cacheKey     = $parent->ID . '|' . md5( (string) wp_json_encode( $parentConfig ) );
		if ( ! isset( $parentPaths[ $cacheKey ] ) ) {
			$parentPaths[ $cacheKey ] = self::paths( $parent, $parentConfig );
		}

		return [ $resolved, $parentPaths[ $cacheKey ][ $parentGuid ] ?? '' ];
	}

	/**
	 * The key of the group's href/slug field ('' when it has none).
	 *
	 * @param array<string, mixed> $group
	 */
	private static function hrefKey( array $group ): string {
		foreach ( (array) ( $group['fields'] ?? [] ) as $key => $field ) {
			if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'href' ) {
				return (string) $key;
			}
		}

		return isset( $group['fields']['href'] ) ? 'href' : '';
	}

	/**
	 * The notice after a save that held groups back (queueWithout()): the
	 * template is saved, only those pages are not generated.
	 *
	 * @param array<int, array<string, mixed>> $conflicts
	 */
	public static function message( array $conflicts ): string {
		return sprintf(
			/* translators: %s: list of URLs with what already uses them. */
			__( 'The template is saved, but these pages are not generated because their URL is already in use: %s. Every URL must be unique on the site; change these groups (or the URL pattern) and save again to generate them.', 'rowsprout' ),
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
			$kind = (string) ( $conflict['kind'] ?? '' );
			if ( $kind === 'duplicate' ) {
				$owner = __( 'another group of this template', 'rowsprout' );
			} elseif ( $kind === 'template' ) {
				/* translators: %s: template title. */
				$owner = sprintf( __( 'template "%s"', 'rowsprout' ), (string) ( $conflict['title'] ?? '' ) );
			} else {
				/* translators: 1: post type name, e.g. "Page", 2: post title. */
				$owner = sprintf( __( '%1$s "%2$s"', 'rowsprout' ), $kind, (string) ( $conflict['title'] ?? '' ) );
			}
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

		return '/' . implode( '/', array_filter( [ self::front(), trim( $base, '/' ), $path ], 'strlen' ) ) . '/';
	}

	/**
	 * The fixed start of the permalink structure ("blog" for
	 * /blog/%postname%/), which generated pages get too (their rewrite rule has
	 * with_front, see PostTypes); '' when there is none.
	 */
	private static function front(): string {
		global $wp_rewrite;

		return $wp_rewrite instanceof \WP_Rewrite ? trim( (string) $wp_rewrite->front, '/' ) : '';
	}

	/**
	 * Of the paths in $wanted, those something else already has, with every
	 * owner of each.
	 *
	 * @param array<string, bool> $wanted
	 * @return array<string, array<int, array{post_id:int, kind:string, title:string}>>
	 */
	private static function takenPaths( int $templateId, array $wanted ): array {
		$slugs = [];
		foreach ( array_keys( $wanted ) as $path ) {
			$segments                      = explode( '/', $path );
			$slugs[ end( $segments ) ] = true;
		}
		$slugs = array_keys( $slugs );

		$taken = [];
		$add   = static function ( string $path, int $postId, string $kind, string $title ) use ( &$taken ): void {
			$taken[ $path ][ $kind . '#' . $postId ] = [ 'post_id' => $postId, 'kind' => $kind, 'title' => $title ];
		};

		// Pages other templates already generated.
		foreach ( self::postsWithSlugs( [ PostTypes::PAGE ], $slugs ) as $page ) {
			$sourceTemplate = (int) get_post_meta( $page->ID, PostMetaKeys::SOURCE_TEMPLATE_ID, true );
			$path           = trim( (string) get_page_uri( $page ), '/' );
			if ( $sourceTemplate !== $templateId && isset( $wanted[ $path ] ) ) {
				$owner = $sourceTemplate > 0 ? $sourceTemplate : $page->ID;
				$add( $path, $owner, 'template', get_the_title( $owner ) );
			}
		}

		// Groups of other templates without an up-to-date page: new, outdated,
		// planned or failed ones, or one whose page is gone (deleted or trashed
		// once pages were unlocked). A group whose page is generated, current
		// and still there has exactly that page's URL, which the query above
		// already covers; computing every group of every template on each save
		// is far too slow.
		$others = get_posts( [
			'post_type'        => PostTypes::TEMPLATE,
			'post_status'      => self::GENERATING_STATUSES,
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- every language: a multilingual add-on decides through rowsprout_url_conflicts which ones count, not the current admin language.
			'suppress_filters' => true,
		] );
		$completed = [];
		$pageIds   = [];
		foreach ( $others as $other ) {
			if ( $other->ID === $templateId ) {
				continue;
			}
			$completed[ $other->ID ] = [];
			foreach ( GroupTableGateway::getRowsByPostId( $other->ID ) as $row ) {
				$pageId = (int) ( $row['rowsprout_page_id'] ?? 0 );
				if ( $pageId > 0 && ( $row['status'] ?? '' ) === GroupTableGateway::STATUS_COMPLETED ) {
					$completed[ $other->ID ][ (string) $row['guid'] ] = $pageId;
					$pageIds[]                                         = $pageId;
				}
			}
		}
		$existing = self::existingPages( $pageIds );

		foreach ( $others as $other ) {
			if ( ! isset( $completed[ $other->ID ] ) ) {
				continue;
			}
			$current          = $completed[ $other->ID ];
			$config           = TemplateMeta::get( $other->ID );
			$config['groups'] = array_values( array_filter( (array) ( $config['groups'] ?? [] ), static function ( $group ) use ( $current, $existing ): bool {
				$pageId = is_array( $group ) ? ( $current[ (string) ( $group['id'] ?? '' ) ] ?? 0 ) : 0;
				return is_array( $group ) && ! isset( $existing[ $pageId ] );
			} ) );
			if ( $config['groups'] === [] ) {
				continue;
			}

			foreach ( self::paths( $other, $config ) as $path ) {
				if ( isset( $wanted[ $path ] ) ) {
					$add( $path, $other->ID, 'template', get_the_title( $other ) );
				}
			}
		}

		// Generated pages share their addresses with everything else on the
		// site: pages, posts and the content of other plugins. What counts is
		// the address such a post really has (its permalink), so a post type
		// with a base of its own (/product/amsterdam/) does not clash, and one
		// without does. Generated pages sit under the fixed start of the
		// permalink structure (/blog/%postname%/) and the URL base, so only a
		// post under that same prefix can have one of their addresses: with
		// the base "locations", a regular page "amsterdam" under a page
		// "locations" (/locations/amsterdam/) does, a post at /amsterdam/ does
		// not.
		$prefix    = implode( '/', array_filter( [ self::front(), trim( PermalinkSettings::getBase(), '/' ) ], 'strlen' ) );
		$postTypes = array_values( array_diff(
			get_post_types( [ 'public' => true ] ),
			[ PostTypes::PAGE, PostTypes::TEMPLATE, 'attachment' ]
		) );
		foreach ( self::postsWithSlugs( $postTypes, $slugs ) as $post ) {
			$path = self::sitePath( (string) get_permalink( $post ) );
			if ( $prefix !== '' ) {
				if ( strpos( $path . '/', $prefix . '/' ) !== 0 ) {
					continue;
				}
				$path = trim( (string) substr( $path, strlen( $prefix ) ), '/' );
			}
			if ( $path !== '' && isset( $wanted[ $path ] ) ) {
				$typeObject = get_post_type_object( $post->post_type );
				$add( $path, $post->ID, $typeObject ? (string) $typeObject->labels->singular_name : $post->post_type, get_the_title( $post ) );
			}
		}

		return array_map( 'array_values', $taken );
	}

	/**
	 * Of $pageIds, the generated pages that still exist with a status that
	 * is visible (not trashed or deleted), as a set.
	 *
	 * @param array<int, int> $pageIds
	 * @return array<int, bool>
	 */
	private static function existingPages( array $pageIds ): array {
		global $wpdb;

		$pageIds = array_values( array_unique( array_filter( array_map( 'intval', $pageIds ) ) ) );
		if ( $pageIds === [] ) {
			return [];
		}

		$found = [];
		$statusPlaceholders = implode( ', ', array_fill( 0, count( self::GENERATING_STATUSES ), '%s' ) );
		foreach ( array_chunk( $pageIds, 1000 ) as $chunk ) {
			$idPlaceholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one query for every page of every template; the IN lists are placeholders.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ({$idPlaceholders}) AND post_status IN ({$statusPlaceholders})", array_merge( [ PostTypes::PAGE ], $chunk, self::GENERATING_STATUSES ) ) );
			foreach ( $ids as $id ) {
				$found[ (int) $id ] = true;
			}
		}

		return $found;
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
