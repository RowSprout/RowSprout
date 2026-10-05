<?php

namespace RowSprout\Core\Transfer;

use RowSprout\Core\Groups\GroupRepository;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the export file for one or more templates: each template's post,
 * its config (properties and groups), save action, featured image and the
 * rest of its post meta (page-builder data, SEO settings), so another site
 * can recreate it with TemplateImporter.
 *
 * What is NOT exported: generated pages and the group table's rows (the
 * importing site generates its own pages), and media files (the featured
 * image is matched by URL on import; images in the content keep pointing at
 * the source site).
 */
final class TemplateExporter {

	public const FORMAT         = 'rowsprout-templates';
	public const FORMAT_VERSION = 1;

	/**
	 * Post meta that is never exported (nor imported, see
	 * TemplateImporter): locks and revision bookkeeping, caches that the
	 * page builder rebuilds itself, ids and index state that only mean
	 * something on the source site, and RowSprout's own keys, which the file
	 * carries in their own fields. A trailing "*" matches a prefix
	 * (GroupRepository::keyMatches()).
	 */
	private const EXCLUDED_META_KEYS = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_*',
		'_encloseme',
		'_pingme',
		'_thumbnail_id',
		'_rowsprout_*',
		'_elementor_css',
		'_elementor_page_assets',
		'_elementor_element_cache',
		'_elementor_controls_usage',
		'_elementor_global_class_usage_*',
		'rank_math_analytic_object_id',
		'rank_math_internal_links_processed',
	];

	/**
	 * The templates an export of $templateIds contains, parents before
	 * children. A child template only works together with its parent (its
	 * groups point at the parent's groups), so a child brings its parent
	 * along, and a selected parent brings its child templates. Templates the
	 * current user cannot edit are left out.
	 *
	 * @param array<int, int|string> $templateIds
	 * @return array<int, int>
	 */
	public static function resolveTemplateIds( array $templateIds ): array {
		$parents  = [];
		$children = [];

		foreach ( array_unique( array_map( 'absint', $templateIds ) ) as $id ) {
			$post = self::exportablePost( $id );
			if ( ! $post ) {
				continue;
			}

			if ( (int) $post->post_parent > 0 ) {
				$children[ $post->ID ] = true;
				if ( self::exportablePost( (int) $post->post_parent ) ) {
					$parents[ (int) $post->post_parent ] = true;
				}
				continue;
			}

			$parents[ $post->ID ] = true;
			foreach ( self::childTemplateIds( $post->ID ) as $childId ) {
				if ( self::exportablePost( $childId ) ) {
					$children[ $childId ] = true;
				}
			}
		}

		return array_merge( array_keys( $parents ), array_keys( array_diff_key( $children, $parents ) ) );
	}

	/**
	 * The export file's contents for $templateIds (run them through
	 * resolveTemplateIds() first).
	 *
	 * @param array<int, int> $templateIds
	 * @return array<string, mixed>
	 */
	public static function build( array $templateIds ): array {
		$templates = [];
		foreach ( $templateIds as $id ) {
			$post = self::exportablePost( (int) $id );
			if ( $post ) {
				$templates[] = self::exportTemplate( $post );
			}
		}

		return [
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => defined( 'ROWSPROUT_VERSION' ) ? ROWSPROUT_VERSION : '',
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url( '/' ),
			'templates'      => $templates,
		];
	}

	/**
	 * Download file name: the template's slug for a single template, the
	 * site's host for several.
	 *
	 * @param array<string, mixed> $export
	 */
	public static function fileName( array $export ): string {
		$templates = (array) ( $export['templates'] ?? [] );
		$subject   = count( $templates ) === 1
			? sanitize_title( (string) ( $templates[0]['title'] ?? '' ) )
			: sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$prefix = count( $templates ) === 1 ? 'rowsprout-template' : 'rowsprout-templates';

		return $prefix . ( $subject !== '' ? '-' . $subject : '' ) . '-' . gmdate( 'Y-m-d' ) . '.json';
	}

	/**
	 * See EXCLUDED_META_KEYS. Shared with TemplateImporter, so a key left out
	 * of exports is also refused from a hand-edited file.
	 *
	 * @return array<int, string>
	 */
	public static function excludedMetaKeys(): array {
		/**
		 * Post meta keys (a trailing "*" matches a prefix) that template
		 * exports leave out and imports refuse. Add the keys of anything
		 * that is site-specific or rebuilt automatically.
		 *
		 * @param array<int, string> $keys
		 */
		$keys = apply_filters( 'rowsprout_template_transfer_excluded_meta_keys', self::EXCLUDED_META_KEYS );

		return array_values( array_filter( (array) $keys, 'is_string' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function exportTemplate( \WP_Post $post ): array {
		$config = TemplateMeta::get( $post->ID );

		$thumbnailId   = (int) get_post_thumbnail_id( $post );
		$thumbnailUrl  = $thumbnailId > 0 ? wp_get_attachment_url( $thumbnailId ) : false;
		$featuredImage = is_string( $thumbnailUrl ) && $thumbnailUrl !== '' ? [ 'url' => $thumbnailUrl ] : null;

		$data = [
			'id'             => $post->ID,
			'parent'         => (int) $post->post_parent,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'status'         => $post->post_status,
			'menu_order'     => (int) $post->menu_order,
			'config'         => [
				'rowsprout_page_href' => (string) ( $config['rowsprout_page_href'] ?? '' ),
				'field_types'         => array_values( (array) ( $config['field_types'] ?? [] ) ),
				'groups'              => array_values( (array) ( $config['groups'] ?? [] ) ),
			],
			'save_action'    => (string) get_post_meta( $post->ID, PostMetaKeys::SAVE_ACTION, true ),
			'featured_image' => $featuredImage,
			'meta'           => self::exportMeta( $post->ID ),
			'extra'          => [],
		];

		/**
		 * One template's entry in an export file. An add-on puts its own data
		 * under $data['extra'][<its slug>] and reads it back on import
		 * (actions rowsprout_template_imported / rowsprout_templates_imported).
		 *
		 * @param array<string, mixed> $data
		 * @param \WP_Post             $post
		 */
		$data = apply_filters( 'rowsprout_template_export_data', $data, $post );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Every meta key of the template with all its values, unserialized.
	 * A key whose value holds an object is skipped: JSON cannot carry PHP
	 * objects, and an import never unserializes anything.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	private static function exportMeta( int $postId ): array {
		$excluded = self::excludedMetaKeys();
		$meta     = [];

		foreach ( get_post_meta( $postId ) as $key => $values ) {
			$key = (string) $key;
			if ( GroupRepository::keyMatches( $key, $excluded ) ) {
				continue;
			}

			$exported = [];
			foreach ( (array) $values as $value ) {
				$value = maybe_unserialize( $value );
				if ( ! self::isPlainData( $value ) ) {
					continue 2;
				}
				$exported[] = $value;
			}

			$meta[ $key ] = $exported;
		}

		return $meta;
	}

	/**
	 * @param mixed $value
	 */
	private static function isPlainData( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::isPlainData( $item ) ) {
					return false;
				}
			}
			return true;
		}

		return $value === null || is_scalar( $value );
	}

	private static function exportablePost( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE ) {
			return null;
		}

		if ( in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * @return array<int, int>
	 */
	private static function childTemplateIds( int $parentId ): array {
		return array_map( 'intval', get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'post_parent'    => $parentId,
			'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] ) );
	}
}
