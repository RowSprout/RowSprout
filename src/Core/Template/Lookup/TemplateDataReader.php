<?php

namespace RowSprout\Core\Template\Lookup;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateDataReader {

	/**
	 * @param int  $postId
	 * @param bool $single
	 * @return array
	 */
	public static function getGroups( int $postId, bool $single = false ): array {
		$result = apply_filters( 'rowsprout_get_groups', null, $postId, $single );
		if ( $result !== null ) {
			return is_array( $result ) ? $result : [];
		}

		// Fallback to TemplateMeta if filter does not provide groups
		$config = TemplateMeta::get( $postId );
		return isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : [];
	}

	/**
	 * @param string $id
	 * @param int    $rowsproutPageId
	 * @return array|null
	 */
	public static function getGroupById( string $id, int $rowsproutPageId ): ?array {
		$result = apply_filters( 'rowsprout_get_group_by_id', null, $id, $rowsproutPageId );
		if ( $result !== null ) {
			return $result;
		}

		// Prefer JSON-config groups so dynamic field tokens (code_*) keep working.
		$config = TemplateMeta::get( $rowsproutPageId );
		$groups = isset( $config['groups'] ) && is_array( $config['groups'] ) ? $config['groups'] : [];
		$group  = TemplateGroupLookup::findById( $groups, $id );
		if ( is_array( $group ) ) {
			return $group;
		}

		return null;
	}

	/**
	 * @param int  $postId
	 * @param bool $single
	 * @return array
	 */
	public static function getChildExtra( int $postId, bool $single = false ): array {
		$result = apply_filters( 'rowsprout_get_child_extra', null, $postId, $single );
		if ( $result !== null ) {
			return is_array( $result ) ? $result : [];
		}

		// Fallback to TemplateMeta if filter does not provide child_extra
		$config = TemplateMeta::get( $postId );
		return isset( $config['child_extra'] ) && is_array( $config['child_extra'] ) ? $config['child_extra'] : [];
	}

	/**
	 * @param string $id
	 * @param int    $rowsproutPageId
	 * @return array|null
	 */
	public static function getChildExtraById( string $id, int $rowsproutPageId ): ?array {
		$result = apply_filters( 'rowsprout_get_child_extra_by_id', null, $id, $rowsproutPageId );
		if ( $result !== null ) {
			return $result;
		}

		$config = TemplateMeta::get( $rowsproutPageId );
		$groups = isset( $config['child_extra'] ) && is_array( $config['child_extra'] ) ? $config['child_extra'] : [];
		$group  = TemplateGroupLookup::findById( $groups, $id );
		if ( is_array( $group ) ) {
			return $group;
		}

		return null;
	}

	/**
	 * @param int $postId
	 * @return array ['code_id' => int, 'parent_code_id' => int|string]
	 */
	public static function getCodeIds( int $postId ): array {
		$result = apply_filters( 'rowsprout_get_code_ids', null, $postId );
		if ( is_array( $result ) ) {
			return $result;
		}

		return [
			'code_id'        => $postId,
			'parent_code_id' => wp_get_post_parent_id( $postId ) ?: '',
		];
	}

	public static function getTemplateHref( int $postId ): string {
		return TemplateMeta::getHref( $postId );
	}
}
