<?php

namespace RowSprout\Core\Page;

use RowSprout\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PageBuildContextResolver {

	/**
	 * @return array{group: array<string, mixed>, code_id: int|string, parent_code_id: int|string, parent_id: int|null, alias_code_ids: array<int, int|string>}
	 */
	public static function resolve( string $id, int $rowsproutPageId, \WP_Post $templatePost ): array {
		$group = self::resolveGroupData( $id, $rowsproutPageId );

		$ids          = Helpers::getCodeIds( $rowsproutPageId );
		$codeId       = $ids['code_id'];
		$parentCodeId = $ids['parent_code_id'];
		$parentId     = null;

		// Lets a feature register other post IDs whose group-placeholder
		// tokens (e.g. @code_href_<id>@) should resolve using this same
		// group's own data — e.g. RowSprout Pro's WPML integration adds
		// every sibling translation's template ID here, since WPML
		// duplicates a template's href/title pattern text verbatim into the
		// translation, tokens and all, and that text keeps referencing
		// whichever template ID the token was originally typed against.
		$aliasCodeIds = apply_filters( 'rowsprout_group_placeholder_alias_code_ids', [], $rowsproutPageId, $templatePost );
		if ( ! is_array( $aliasCodeIds ) ) {
			$aliasCodeIds = [];
		}

		if ( has_post_parent( $templatePost ) ) {
			// A child template: Core\ChildTemplates\ChildTemplateInheritance
			// fills the group's empty title/href/thumbnail from the parent
			// template's linked group through this filter.
			$context = apply_filters(
				'rowsprout_resolve_child_template_context',
				[
					'group'     => $group,
					'parent_id' => $parentId,
				],
				$id,
				$rowsproutPageId,
				$templatePost
			);

			$group    = is_array( $context['group'] ?? null ) ? $context['group'] : $group;
			$parentId = $context['parent_id'] ?? null;
		}

		// Last-resort fallback once every other source has had its chance
		// (this group's own 'thumbnail' field, set inside resolveGroupData()
		// below; for a child, everything ChildTemplateInheritance already
		// tries — its own field, the linked parent group's field, or the
		// parent TEMPLATE's featured image): fall back to $rowsproutPageId's
		// own WP featured image, so a template with no per-group thumbnail
		// config at all still gets a sensible default instead of none.
		if ( empty( $group['thumb_id'] ) ) {
			$group['thumb_id'] = get_post_thumbnail_id( $rowsproutPageId );
		}

		return [
			'group'           => $group,
			'code_id'         => $codeId,
			'parent_code_id'  => $parentCodeId,
			'parent_id'       => $parentId,
			'alias_code_ids'  => $aliasCodeIds,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function resolveGroupData( string $id, int $rowsproutPageId ): array {
		$group = Helpers::getGroupById( $id, $rowsproutPageId );
		$group = is_array( $group ) ? $group : [];

		// The 'thumbnail' field type (RowSprout Pro) lets an admin pick
		// a per-group image, but nothing previously read its stored value —
		// see PageBuilder::createById()'s set_post_thumbnail()/
		// delete_post_thumbnail() branch, which only ever looks at
		// $group['thumb_id'], a key PayloadConfigBuilder::buildGroups()
		// never writes. Wired up here so a configured 'thumbnail' field
		// actually reaches it.
		$thumbFieldId = self::extractThumbnailFieldValue( $group );
		if ( $thumbFieldId > 0 ) {
			$group['thumb_id'] = $thumbFieldId;
		}

		return $group;
	}

	/**
	 * @param array<string, mixed> $group
	 */
	private static function extractThumbnailFieldValue( array $group ): int {
		foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'thumbnail' ) {
				$value = $field['value'] ?? '';
				return is_scalar( $value ) ? absint( $value ) : 0;
			}
		}

		return 0;
	}
}
