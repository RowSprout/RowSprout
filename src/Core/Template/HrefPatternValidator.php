<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The template-level href is the slug pattern every generated page runs
 * through (PageBuilder: tokens replaced, then sanitize_title() → post_name).
 * Without a token for the group's own href/slug value, every group resolves
 * to the same slug and WordPress's own slug dedup numbers them (offer,
 * offer-2, …) in whatever order they happen to be generated; an empty
 * pattern makes PageBuilder skip the page and the row ends up `failed`.
 * HrefUniquenessValidator can't catch either, since it only compares the
 * per-group values. This only warns; it never blocks.
 *
 * A child template is exempt from the token rule: its groups map one-to-one
 * onto the parent's groups (it cannot add its own) and each page is created
 * under its parent group's page (post_parent), so a fixed href such as
 * "/contact" already gives every page its own URL. The href token would even
 * be wrong there: in a child it resolves to the parent group's href, giving
 * "/amsterdam/amsterdam". Only an empty href is still flagged.
 */
final class HrefPatternValidator {

	/**
	 * Token codes that resolve to a group's href: the built-in href/slug
	 * aliases (GroupPlaceholderTokenResolver always provides those) plus the
	 * key/code of every href/slug property on this template and its parent
	 * (a child template may reference the parent's codes).
	 *
	 * @return array<int, string>
	 */
	public static function getHrefTokenCodes( int $templateId ): array {
		$codes = [ 'href', 'slug' ];

		$templateIds = [ $templateId ];
		$parentId    = $templateId > 0 ? (int) wp_get_post_parent_id( $templateId ) : 0;
		if ( $parentId > 0 ) {
			$templateIds[] = $parentId;
		}

		foreach ( $templateIds as $id ) {
			if ( $id <= 0 ) {
				continue;
			}
			$config = TemplateMeta::get( $id );
			foreach ( (array) ( $config['field_types'] ?? [] ) as $fieldType ) {
				if ( ! is_array( $fieldType ) || ! in_array( $fieldType['type'] ?? '', [ 'href', 'slug' ], true ) ) {
					continue;
				}
				$codes[] = sanitize_key( (string) ( $fieldType['key'] ?? '' ) );
				$codes[] = sanitize_key( (string) ( $fieldType['code'] ?? '' ) );
			}
		}

		return array_values( array_unique( array_filter( $codes ) ) );
	}

	public static function isChildTemplate( int $templateId ): bool {
		return $templateId > 0 && (int) wp_get_post_parent_id( $templateId ) > 0;
	}

	/**
	 * The warning a save should show, or '' for none. Shared by the save
	 * notice (SavePost) and the Elementor modal's save response. An empty href
	 * only matters once there are groups to generate — a brand-new template
	 * starts out empty and that isn't a mistake yet.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function saveWarning( array $config, int $templateId ): string {
		$pattern = (string) ( $config['rowsprout_page_href'] ?? '' );

		if ( self::isChildTemplate( $templateId ) ) {
			if ( $pattern !== '' || empty( $config['groups'] ) ) {
				return '';
			}

			return __( 'The href of this child template is empty, so no pages are generated for it. Enter the last part of the URL, for example /contact: every child page is placed under its parent page, so that already gives each page its own URL.', 'rowsprout' );
		}

		if ( $pattern === '' ? empty( $config['groups'] ) : self::referencesGroupHref( $pattern, $templateId ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the href placeholder token, e.g. @code_href_123@. */
			__( 'The href of this template does not contain the group\'s href token (%s), so the generated pages do not get a URL of their own: WordPress numbers them instead (e.g. offer, offer-2, offer-3), and with an empty href no pages are generated at all. Add the token to the href and save again.', 'rowsprout' ),
			'@code_href_' . $templateId . '@'
		);
	}

	/**
	 * The template id inside the token is not checked: WPML sibling and
	 * parent ids resolve as well, so any id is accepted.
	 */
	public static function referencesGroupHref( string $pattern, int $templateId ): bool {
		if ( strpos( $pattern, '@code_' ) === false ) {
			return false;
		}

		$codes = array_map(
			static function ( string $code ): string {
				return preg_quote( $code, '/' );
			},
			self::getHrefTokenCodes( $templateId )
		);

		return (bool) preg_match( '/@code_(?:' . implode( '|', $codes ) . ')_\d+@/', $pattern );
	}
}
