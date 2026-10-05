<?php

namespace RowSprout\Core\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupRepository {

	public static function updateGroup( int $postId, array $groups ): bool {
		return GroupRowStore::updateGroup( $postId, $groups );
	}

	public static function getGroup( int $postId ): array {
		return GroupRowStore::getGroup( $postId );
	}

	public static function getIdById( string $id, int $templateId ): ?int {
		return GroupRowStore::getRowSproutPageIdByGuid( $id, $templateId );
	}

	public static function getIdByParentId( string $parentGuid, int $templateId ): ?int {
		return GroupRowStore::getRowSproutPageIdByGuid( $parentGuid, $templateId );
	}

	public static function deleteGroupsAndPagesByIds( array $ids ): void {
		GroupDeletionService::deleteGroupsAndPagesByIds( $ids );
	}

	public static function keyMatches( string $key, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( substr( $pattern, -1 ) === '*' ) {
				$prefix = substr( $pattern, 0, -1 );
				if ( strpos( $key, $prefix ) === 0 ) {
					return true;
				}
			} elseif ( $key === $pattern ) {
				return true;
			}
		}
		return false;
	}

	public static function getValidIdById( string $id, int $templateId ): ?int {
		$found = self::getIdById( $id, $templateId );
		if ( $found && get_post_status( $found ) === false ) {
			return null;
		}
		return $found;
	}

	public static function getValidParentIdById( string $parentGuid, int $templateId ): ?int {
		$found = self::getIdByParentId( $parentGuid, $templateId );
		if ( $found && get_post_status( $found ) === false ) {
			return null;
		}
		return $found ?: null;
	}
}
