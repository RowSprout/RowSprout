<?php

namespace RowSprout\Core\Page;

use RowSprout\Core\Helpers;
use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageBuilder {

	/**
	 * @param string $id
	 * @param int    $rowsproutPageId
	 * @return array|null|\WP_Error
	 */
	public static function createById( string $id, int $rowsproutPageId ) {
		$pageHref = Helpers::getTemplateHref( $rowsproutPageId );
		if ( ! $pageHref ) {
			return null;
		}

		$templatePost = get_post( $rowsproutPageId );
		if ( ! $templatePost ) {
			return new \WP_Error( 'invalid_post', 'Template post niet gevonden' );
		}

		$context      = PageBuildContextResolver::resolve( $id, $rowsproutPageId, $templatePost );
		$group        = $context['group'];
		$codeId       = $context['code_id'];
		$parentCodeId = $context['parent_code_id'];
		$parentId     = $context['parent_id'];
		$aliasCodeIds = $context['alias_code_ids'] ?? [];

		$newHref    = self::replacePlaceholders( $pageHref, $group, $codeId, $parentCodeId, $aliasCodeIds );
		$newTitle   = self::replacePlaceholders( $templatePost->post_title, $group, $codeId, $parentCodeId, $aliasCodeIds );
		$newContent = self::applyGroupPlaceholderTokensToMarkup(
			(string) $templatePost->post_content,
			$group,
			$codeId,
			$parentCodeId,
			is_numeric( $codeId ) ? (int) $codeId : 0,
			$aliasCodeIds
		);

		$defaults = [
			'post_title'        => $newTitle,
			'post_content'      => $newContent,
			'post_status'       => $templatePost->post_status,
			'post_password'     => $templatePost->post_password,
			'post_name'         => sanitize_title( $newHref ),
			'post_parent'       => $parentId,
			'post_author'       => $templatePost->post_author,
			'post_date'         => $templatePost->post_date,
			'post_date_gmt'     => $templatePost->post_date_gmt,
			'post_excerpt'      => $templatePost->post_excerpt,
			'post_modified'     => $templatePost->post_modified,
			'post_modified_gmt' => $templatePost->post_modified_gmt,
			'menu_order'        => $templatePost->menu_order,
			'post_type'         => PostTypes::PAGE,
		];

		$defaults = apply_filters( 'rowsprout_page_defaults_before_upsert', $defaults, $group, $rowsproutPageId );

		do_action( 'rowsprout_page_before_upsert', $defaults, $group, $rowsproutPageId );

		$newPostId = Helpers::upsertById( $defaults, $id, $rowsproutPageId );

		if ( is_wp_error( $newPostId ) ) {
			return $newPostId;
		}

		Helpers::copyAndReplaceMeta( $rowsproutPageId, $newPostId, $group, $codeId, $parentCodeId, $aliasCodeIds );
		GeneratedFieldMetaWriter::apply( $newPostId, $group, $rowsproutPageId );
		update_post_meta( $newPostId, PostMetaKeys::SOURCE_GROUP_ID, $id );
		update_post_meta( $newPostId, PostMetaKeys::SOURCE_TEMPLATE_ID, $rowsproutPageId );
		Helpers::deleteMetaKeys( $newPostId );

		if ( ! empty( $group['thumb_id'] ) ) {
			set_post_thumbnail( $newPostId, $group['thumb_id'] );
		} else {
			delete_post_thumbnail( $newPostId );
		}

		do_action( 'rowsprout_page_after_upsert', $newPostId, $group, $rowsproutPageId );

		return [
			'id'      => $id,
			'page_id' => $newPostId,
			'success' => true,
		];
	}

	/**
	 * @param string          $string
	 * @param array           $group
	 * @param int|string      $codeId
	 * @param int|string      $parentCodeId
	 * @param array<int, int|string> $aliasCodeIds
	 */
	public static function replacePlaceholders( $string, array $group, $codeId, $parentCodeId, array $aliasCodeIds = [] ): string {
		if ( ! is_string( $string ) ) {
			return (string) $string;
		}

		$templatePostId = is_numeric( $codeId ) ? (int) $codeId : 0;
		return self::applyGroupPlaceholderTokens( $string, $group, $codeId, $parentCodeId, $templatePostId, $aliasCodeIds );
	}

	/**
	 * @param array<int, int|string> $aliasCodeIds
	 */
	public static function applyGroupPlaceholderTokens( string $string, array $group, $codeId, $parentCodeId, int $templatePostId = 0, array $aliasCodeIds = [] ): string {
		if ( strpos( $string, '@code_' ) === false ) {
			return $string;
		}

		return MarkupTokenReplacer::replacePlain( $string, GroupPlaceholderTokenResolver::resolve( $group, $codeId, $parentCodeId, $templatePostId, $aliasCodeIds ) );
	}

	/**
	 * For values that may be markup (post content, HTML inside meta): tokens in
	 * HTML attributes and block attributes are replaced escaped — see
	 * MarkupTokenReplacer. Plain-text values (title, URL pattern) keep using
	 * applyGroupPlaceholderTokens().
	 *
	 * @param array<int, int|string> $aliasCodeIds
	 */
	public static function applyGroupPlaceholderTokensToMarkup( string $string, array $group, $codeId, $parentCodeId, int $templatePostId = 0, array $aliasCodeIds = [] ): string {
		// %40code_: a token url-encoded inside a page builder's link field.
		if ( strpos( $string, '@code_' ) === false && strpos( $string, '%40code_' ) === false ) {
			return $string;
		}

		return MarkupTokenReplacer::replace( $string, GroupPlaceholderTokenResolver::resolve( $group, $codeId, $parentCodeId, $templatePostId, $aliasCodeIds ) );
	}

}
