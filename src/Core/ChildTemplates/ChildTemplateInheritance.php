<?php

namespace RowSprout\Core\ChildTemplates;

use RowSprout\Core\Groups\GroupTableGateway;
use RowSprout\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Child templates inherit their title/href/thumb_id from the parent
 * template's linked group at page-generation time, through the
 * rowsprout_resolve_child_template_context filter that
 * Core\Page\PageBuildContextResolver fires for a template with a parent.
 * Child templates are a built-in feature of the free plugin.
 */
final class ChildTemplateInheritance {

	public static function register(): void {
		add_filter( 'rowsprout_resolve_child_template_context', [ self::class, 'resolveContext' ], 10, 4 );
	}

	/**
	 * @param array{group: array<string, mixed>, parent_id: int|null} $context
	 * @return array{group: array<string, mixed>, parent_id: int|null}
	 */
	public static function resolveContext( array $context, string $id, int $rowsproutPageId, \WP_Post $templatePost ): array {
		$group    = is_array( $context['group'] ?? null ) ? $context['group'] : [];
		$parentId = null;

		$parentGroupId = (string) ( $group['parent_id'] ?? '' );
		if ( $parentGroupId === '' ) {
			$row = GroupTableGateway::getRowByGuidAndPostId( $id, $rowsproutPageId );
			if ( is_array( $row ) && ! empty( $row['parent_guid'] ) ) {
				$parentGroupId = (string) $row['parent_guid'];
				$group['parent_id'] = $parentGroupId;
			}
		}

		$templateParentId = wp_get_post_parent_id( $templatePost );
		$parentId         = Helpers::getValidParentIdById( $parentGroupId, $templateParentId );
		$parentGroup      = [];
		$parentGroups     = Helpers::getGroups( $templateParentId, true );

		foreach ( $parentGroups as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			if ( (string) ( $candidate['id'] ?? '' ) === $parentGroupId ) {
				$parentGroup = $candidate;
				break;
			}
		}

		$group['title'] = self::extractParentTitle( $parentGroup );
		$group['href']  = self::extractParentHref( $parentGroup );

		// $parentGroup comes from Helpers::getGroups() — the parent's raw
		// stored config, where a thumbnail selection only ever lives inside
		// its 'fields' array (see PayloadConfigBuilder::buildGroups()), not
		// as a top-level 'thumb_id' key — that key is a runtime-only
		// concept PageBuildContextResolver derives while building A page,
		// never present on this cross-template read. Deliberately no
		// further fallback to the parent TEMPLATE's own featured image here
		// (unlike title/href, which always inherit from the parent): if
		// neither this child's own group nor the specific parent group it's
		// linked to has a thumbnail set, PageBuildContextResolver's own
		// last-resort fallback (this CHILD template's own featured image)
		// applies instead once this filter returns.
		if ( empty( $group['thumb_id'] ) ) {
			$parentThumbId = self::extractThumbnailFieldValue( $parentGroup );
			if ( $parentThumbId > 0 ) {
				$group['thumb_id'] = $parentThumbId;
			}
		}

		$group['fields'] = self::inheritEmptyFields(
			isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [],
			isset( $parentGroup['fields'] ) && is_array( $parentGroup['fields'] ) ? $parentGroup['fields'] : []
		);

		return [
			'group'     => $group,
			'parent_id' => $parentId,
		];
	}

	/**
	 * Generic counterpart to the thumb_id fallback above, for any other field
	 * (e.g. an "items" or "text" field): the child's own
	 * value wins whenever the admin has actually filled it in, otherwise fall
	 * back to the parent group's same field (matched by key) — the same
	 * override-or-inherit behavior the pre-rewrite plugin's child_extra had
	 * for items/text (always inherited when the child left its
	 * override empty, never overwritten when the child did set one).
	 *
	 * @param array<string, mixed> $childFields
	 * @param array<string, mixed> $parentFields
	 * @return array<string, mixed>
	 */
	private static function inheritEmptyFields( array $childFields, array $parentFields ): array {
		foreach ( $parentFields as $key => $parentFieldConfig ) {
			$parentValue = self::extractScalarFieldValue( $parentFieldConfig );
			if ( $parentValue === '' ) {
				continue;
			}

			$childFieldConfig = $childFields[ $key ] ?? null;
			$childValue       = $childFieldConfig !== null ? self::extractScalarFieldValue( $childFieldConfig ) : '';
			if ( $childValue !== '' ) {
				continue;
			}

			if ( is_array( $childFieldConfig ) ) {
				$childFieldConfig['value'] = $parentValue;
				$childFields[ $key ]       = $childFieldConfig;
			} else {
				$childFields[ $key ] = is_array( $parentFieldConfig )
					? array_merge( $parentFieldConfig, [ 'value' => $parentValue ] )
					: $parentValue;
			}
		}

		return $childFields;
	}

	/**
	 * @param array<string, mixed> $parentGroup
	 */
	private static function extractParentTitle( array $parentGroup ): string {
		$title = '';
		if ( isset( $parentGroup['title'] ) && is_scalar( $parentGroup['title'] ) ) {
			$title = (string) $parentGroup['title'];
		}

		if ( $title !== '' ) {
			return $title;
		}

		return self::extractFieldValue( $parentGroup, [ 'title' ], [ 'title' ], [ 'title' ] );
	}

	/**
	 * @param array<string, mixed> $parentGroup
	 */
	private static function extractParentHref( array $parentGroup ): string {
		$href = '';
		if ( isset( $parentGroup['href'] ) && is_scalar( $parentGroup['href'] ) ) {
			$href = (string) $parentGroup['href'];
		}

		if ( $href !== '' ) {
			return $href;
		}

		return self::extractFieldValue( $parentGroup, [ 'href', 'slug' ], [ 'href', 'slug' ], [ 'href', 'slug' ] );
	}

	/**
	 * @param array<string, mixed> $group
	 * @param array<int, string> $preferredKeys
	 * @param array<int, string> $preferredTypes
	 * @param array<int, string> $preferredCodes
	 */
	private static function extractFieldValue( array $group, array $preferredKeys, array $preferredTypes, array $preferredCodes ): string {
		$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : [];

		foreach ( $preferredKeys as $preferredKey ) {
			if ( ! isset( $fields[ $preferredKey ] ) ) {
				continue;
			}

			$value = self::extractScalarFieldValue( $fields[ $preferredKey ] );
			if ( $value !== '' ) {
				return $value;
			}
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$fieldType = sanitize_key( (string) ( $field['type'] ?? '' ) );
			$fieldCode = sanitize_key( (string) ( $field['code'] ?? '' ) );
			if ( ( $fieldType !== '' && in_array( $fieldType, $preferredTypes, true ) ) || ( $fieldCode !== '' && in_array( $fieldCode, $preferredCodes, true ) ) ) {
				$value = self::extractScalarFieldValue( $field );
				if ( $value !== '' ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * @param mixed $field
	 */
	private static function extractScalarFieldValue( $field ): string {
		if ( is_array( $field ) ) {
			$value = $field['value'] ?? '';
			return is_scalar( $value ) ? (string) $value : '';
		}

		return is_scalar( $field ) ? (string) $field : '';
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
