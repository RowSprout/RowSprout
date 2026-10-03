<?php

namespace RowSprout\Core\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupRowStore {

	/**
	 * @param array<int, array<string, mixed>> $groups
	 */
	public static function updateGroup( int $postId, array $groups ): bool {
		foreach ( $groups as $group ) {
			$guid = sanitize_text_field( (string) ( $group['id'] ?? '' ) );
			if ( $guid === '' ) {
				continue;
			}

			$parentGuid = sanitize_text_field( (string) ( $group['parent_id'] ?? '' ) );
			if ( $parentGuid === '0' ) {
				$parentGuid = '';
			}
			// Reject numeric-only parent_guid values (must be actual GUID)
			if ( $parentGuid !== '' && ctype_digit( $parentGuid ) ) {
				$parentGuid = '';
			}

			$rowsproutPageId = isset( $group['rowsprout_page_id'] ) ? (int) $group['rowsprout_page_id'] : 0;
			$status        = isset( $group['status'] ) ? sanitize_key( (string) $group['status'] ) : GroupTableGateway::STATUS_PENDING;

			$data = [
				'rowsprout_template_id' => $postId,
				'guid'                => $guid,
				'parent_guid'         => $parentGuid !== '' ? $parentGuid : null,
				'rowsprout_page_id'     => $rowsproutPageId,
				'status'              => $status !== '' ? $status : GroupTableGateway::STATUS_PENDING,
			];

			GroupTableGateway::replaceRowByPostAndGuid( $postId, $guid, $data );
		}

		return true;
	}

	/**
	 * @return array<string, array{id:string,parent_id:string,rowsprout_page_id:int,status:string}>
	 */
	public static function getGroup( int $postId ): array {
		$results = GroupTableGateway::getRowsByPostId( $postId );

		$groups = [];
		foreach ( $results as $row ) {
			$guid = isset( $row['guid'] ) ? (string) $row['guid'] : '';
			if ( $guid === '' ) {
				continue;
			}

			$groups[ $guid ] = [
				'id'              => $guid,
				'parent_id'       => isset( $row['parent_guid'] ) ? (string) $row['parent_guid'] : '',
				'rowsprout_page_id' => isset( $row['rowsprout_page_id'] ) ? (int) $row['rowsprout_page_id'] : 0,
				'status'          => isset( $row['status'] ) ? (string) $row['status'] : '',
			];
		}

		return $groups;
	}

	public static function getRowSproutPageIdByGuid( string $guid, int $templateId ): ?int {
		$sanitizedGuid = sanitize_text_field( $guid );
		if ( $sanitizedGuid === '' ) {
			return null;
		}

		return GroupTableGateway::getRowSproutPageIdByGuid( $sanitizedGuid, $templateId );
	}
}
