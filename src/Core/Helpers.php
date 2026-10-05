<?php

namespace RowSprout\Core;

use RowSprout\Core\Support\IdList;
use RowSprout\Core\Groups\GroupRepository;
use RowSprout\Core\Page\PageUpserter;
use RowSprout\Core\Page\PageMetaReplicator;
use RowSprout\Core\Page\PageBuilder;
use RowSprout\Core\Template\Lookup\TemplateDataReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Helpers {

	/**
	 * Parse a comma-separated list of IDs into a unique array of positive integers.
	 *
	 * @param mixed $input
	 * @return int[]
	 */
	public static function parseIdList( $input ): array {
		return IdList::parse( $input );
	}

	/**
	 * Normalize an ID list to a canonical CSV string (e.g. "12,34,56").
	 *
	 * @param mixed $input
	 * @return string
	 */
	public static function normalizeIdListCsv( $input ): string {
		return IdList::toCsv( $input );
	}

	/**
	 * Update or insert RowSprout page groups for a post.
	 *
	 * @param int   $postId
	 * @param array $groups
	 * @return bool
	 */
	public static function updateGroup( int $postId, array $groups ): bool {
		return GroupRepository::updateGroup( $postId, $groups );
	}

	/**
	 * Retrieve all RowSprout page groups for a given post (legacy schema).
	 *
	 * @param int $postId
	 * @return array
	 */
	public static function getGroup( int $postId ): array {
		return GroupRepository::getGroup( $postId );
	}

	/**
	 * Get the rowsprout_page_id from rowsprout_page_groups based on GUID, scoped
	 * to the template the guid belongs to (a group's id is only unique
	 * within its own template's config — see GroupTableGateway::getRowSproutPageIdByGuid()).
	 *
	 * @param string $id
	 * @param int    $templateId
	 * @return int|null
	 */
	public static function getIdById( string $id, int $templateId ): ?int {
		return GroupRepository::getIdById( $id, $templateId );
	}

	/**
	 * Get the rowsprout_page_id from rowsprout_page_groups based on parent_id,
	 * scoped to the template the parent guid belongs to.
	 *
	 * @param string $parentGuid
	 * @param int    $templateId
	 * @return int|null
	 */
	public static function getIdByParentId( string $parentGuid, int $templateId ): ?int {
		return GroupRepository::getIdByParentId( $parentGuid, $templateId );
	}

	/**
	 * Remove groups with specific GUIDs from rowsprout_page_groups and remove referencing rowsprout_page posts.
	 *
	 * @param array $ids
	 * @return void
	 */
	public static function deleteGroupsAndPagesByIds( array $ids ): void {
		GroupRepository::deleteGroupsAndPagesByIds( $ids );
	}

	/**
	 * Search for a value based on GUID and field name in an array.
	 *
	 * @param string $id
	 * @param string $key
	 * @param array  $array
	 * @return mixed|null
	 */
	public static function findById( string $id, string $key, array $array ) {
		foreach ( $array as $item ) {
			if ( isset( $item[ $key ] ) && $item[ $key ] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Create or update a rowsprout_page based on GUID.
	 *
	 * @param string $id
	 * @param int    $rowsproutPageId
	 * @return array|null|\WP_Error
	 */
	public static function createById( string $id, int $rowsproutPageId ) {
		return PageBuilder::createById( $id, $rowsproutPageId );
	}

	/**
	 * Checks if a given key matches any of the provided patterns.
	 *
	 * @param string $key
	 * @param array  $patterns
	 * @return bool
	 */
	public static function keyMatches( string $key, array $patterns ): bool {
		return GroupRepository::keyMatches( $key, $patterns );
	}

	/**
	 * Get a valid rowsprout_page_id for a GUID, scoped to the template it
	 * belongs to.
	 *
	 * @param string $id
	 * @param int    $templateId
	 * @return int|null
	 */
	public static function getValidIdById( string $id, int $templateId ): ?int {
		return GroupRepository::getValidIdById( $id, $templateId );
	}

	/**
	 * Get a valid parent rowsprout_page_id via parent_id, scoped to the
	 * template the parent guid belongs to.
	 *
	 * @param string $parentGuid
	 * @param int    $templateId
	 * @return int|null
	 */
	public static function getValidParentIdById( string $parentGuid, int $templateId ): ?int {
		return GroupRepository::getValidParentIdById( $parentGuid, $templateId );
	}

	/**
	 * Update or create a rowsprout_page based on GUID, scoped to the template
	 * it belongs to.
	 *
	 * @param array  $defaults
	 * @param string $id
	 * @param int    $templateId
	 * @return int|\WP_Error
	 */
	public static function upsertById( array $defaults, string $id, int $templateId ) {
		return PageUpserter::upsertByGroupId( $defaults, $id, $templateId, [ self::class, 'getValidIdById' ] );
	}

	/**
	 * Copies and replaces post meta from source post to target post.
	 *
	 * @param int        $sourcePostId
	 * @param int        $targetPostId
	 * @param array      $group
	 * @param int|string $codeId
	 * @param int|string $parentCodeId
	 * @param array<int, int|string> $aliasCodeIds
	 * @return void
	 */
	public static function copyAndReplaceMeta( int $sourcePostId, int $targetPostId, array $group, $codeId, $parentCodeId, array $aliasCodeIds = [] ): void {
		PageMetaReplicator::copyAndReplaceMeta( $sourcePostId, $targetPostId, $group, $codeId, $parentCodeId, $aliasCodeIds );
	}

	/**
	 * Deletes specified meta keys from a post.
	 *
	 * @param int $postId
	 * @return void
	 */
	public static function deleteMetaKeys( int $postId ): void {
		$metaKeys = [];

		$metaKeys = apply_filters( 'rowsprout_page_delete_post_meta_keys', $metaKeys, $postId );

		foreach ( $metaKeys as $key ) {
			delete_post_meta( $postId, $key );
		}
	}

	/**
	 * Replaces placeholders in a string for RowSprout pages.
	 *
	 * @param string     $string
	 * @param array      $group
	 * @param int|string $codeId
	 * @param int|string $parentCodeId
	 * @param array<int, int|string> $aliasCodeIds
	 * @return string
	 */
	public static function replacePlaceholders( $string, array $group, $codeId, $parentCodeId, array $aliasCodeIds = [] ): string {
		return PageBuilder::replacePlaceholders( $string, $group, $codeId, $parentCodeId, $aliasCodeIds );
	}

	// -------------------------------------------------------------------------
	// Group / meta getters — filterable so premium add-ons can override
	// -------------------------------------------------------------------------

	/**
	 * Get the RowSprout page groups for a post.
	 * Premium plugins can inject WPML variants via the filter.
	 *
	 * @param int  $postId
	 * @param bool $single
	 * @return array
	 */
	public static function getGroups( int $postId, bool $single = false ): array {
		return TemplateDataReader::getGroups( $postId, $single );
	}

	/**
	 * Get a group based on GUID and post ID.
	 *
	 * @param string $id
	 * @param int    $rowsproutPageId
	 * @return array|null
	 */
	public static function getGroupById( string $id, int $rowsproutPageId ): ?array {
		return TemplateDataReader::getGroupById( $id, $rowsproutPageId );
	}

	/**
	 * Get the child-extra groups for a post.
	 *
	 * @param int  $postId
	 * @param bool $single
	 * @return array
	 */
	public static function getChildExtra( int $postId, bool $single = false ): array {
		return TemplateDataReader::getChildExtra( $postId, $single );
	}

	/**
	 * Get a child-extra group based on GUID and post ID.
	 *
	 * @param string $id
	 * @param int    $rowsproutPageId
	 * @return array|null
	 */
	public static function getChildExtraById( string $id, int $rowsproutPageId ): ?array {
		return TemplateDataReader::getChildExtraById( $id, $rowsproutPageId );
	}

	/**
	 * Get code_id and parent_code_id for placeholder replacement.
	 * Premium plugins can return WPML-aware values via the filter.
	 *
	 * @param int $postId
	 * @return array ['code_id' => int, 'parent_code_id' => int|string]
	 */
	public static function getCodeIds( int $postId ): array {
		return TemplateDataReader::getCodeIds( $postId );
	}

	/**
	 * Haal de template-href op uit de centrale JSON meta.
	 *
	 * @param int $postId
	 * @return string
	 */
	public static function getTemplateHref( int $postId ): string {
		return TemplateDataReader::getTemplateHref( $postId );
	}
}
