<?php

namespace RowSprout\ThirdParty\Elementor;

use RowSprout\Core\PostMetaKeys;
use RowSprout\Core\PostTypes;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by the Elementor widgets that need "the value of a specific field
 * for the group behind the current rowsprout_page" — resolves against the
 * live TemplateMeta config via the group guid every generated page already
 * carries as postmeta (_rowsprout_page_source_template_id/_source_group_id,
 * see PageBuilder::createById()), rather than the per-group-instance-random
 * postmeta key GeneratedFieldMetaWriter writes (not addressable ahead of
 * time from a widget control).
 */
final class PageFieldResolver {

	/**
	 * @return array{template_id:int, group: array<string,mixed>}|null
	 */
	public static function resolveGroupContext( int $currentPostId, int $previewTemplateId = 0 ): ?array {
		if ( get_post_type( $currentPostId ) === PostTypes::PAGE ) {
			$templateId = (int) get_post_meta( $currentPostId, PostMetaKeys::SOURCE_TEMPLATE_ID, true );
			$guid       = (string) get_post_meta( $currentPostId, PostMetaKeys::SOURCE_GROUP_ID, true );

			if ( $templateId > 0 && $guid !== '' ) {
				foreach ( (array) ( TemplateMeta::get( $templateId )['groups'] ?? [] ) as $group ) {
					if ( is_array( $group ) && (string) ( $group['id'] ?? '' ) === $guid ) {
						return [ 'template_id' => $templateId, 'group' => self::applyChildInheritance( $group, $guid, $templateId ) ];
					}
				}
			}
		}

		if ( $previewTemplateId > 0 ) {
			$groups = (array) ( TemplateMeta::get( $previewTemplateId )['groups'] ?? [] );

			$overrideGuid = self::resolvePreviewGroupOverride( $previewTemplateId );
			if ( $overrideGuid !== '' ) {
				foreach ( $groups as $group ) {
					if ( is_array( $group ) && (string) ( $group['id'] ?? '' ) === $overrideGuid ) {
						return [ 'template_id' => $previewTemplateId, 'group' => self::applyChildInheritance( $group, $overrideGuid, $previewTemplateId ) ];
					}
				}
			}

			foreach ( $groups as $group ) {
				if ( is_array( $group ) ) {
					// First group only — a representative sample for
					// previewing the widget inside the Elementor editor
					// when there's no real rowsprout_page context yet, and no
					// (or no longer valid) preview-group override is set.
					$guid = (string) ( $group['id'] ?? '' );
					return [ 'template_id' => $previewTemplateId, 'group' => self::applyChildInheritance( $group, $guid, $previewTemplateId ) ];
				}
			}
		}

		return null;
	}

	/**
	 * The admin-picked "preview this specific group" override from the
	 * Elementor editor's General Settings panel (see
	 * TemplateEditorTab::PREVIEW_GROUP_SETTING_KEY / buildGroupOptions()) —
	 * purely an editor convenience for widgets/tags previewing a template
	 * directly; never consulted for a real generated rowsprout_page's render
	 * (that context is resolved entirely above this method, before
	 * $previewTemplateId is ever considered).
	 *
	 * Uses Documents_Manager::get_doc_or_auto_save() rather than plain
	 * get() — confirmed against the installed 4.2.4
	 * (core/documents-manager.php): the "Refresh preview" button forces
	 * an autosave (elementor-template-tab.js) rather than a real Update, so
	 * the just-picked group only exists on the document's AUTOSAVE revision
	 * at that point, not yet on the main post.
	 *
	 * Deliberately NOT Documents_Manager::get_doc_for_frontend() (tried
	 * first, confirmed broken for Atomic Widgets specifically): that method
	 * only swaps in the autosave when is_preview()/preview_nonce or
	 * Plugin::$instance->preview->is_preview_mode() are set — true for a
	 * classic widget's normal preview-iframe page load, but Atomic Widgets
	 * render (even inside that same iframe) through a dedicated AJAX action
	 * instead (Render_Element_Action::handle(),
	 * modules/atomic-widgets/ajax/render-element-action.php:27 — resolves
	 * the document via get_with_permissions(), never touching is_preview()
	 * at all), so get_doc_for_frontend() silently fell back to the
	 * still-published (non-autosave) document there. get_doc_or_auto_save()
	 * skips that detection entirely and always prefers the current user's
	 * autosave when one exists — correct for both render paths, and safe
	 * here specifically because this method only ever runs for the
	 * editor-only template-preview branch to begin with (never for a real
	 * rowsprout_page's render).
	 */
	private static function resolvePreviewGroupOverride( int $templateId ): string {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return '';
		}

		$document = \Elementor\Plugin::$instance->documents->get_doc_or_auto_save( $templateId, get_current_user_id() );
		if ( ! $document ) {
			return '';
		}

		$raw = sanitize_text_field( (string) $document->get_settings( TemplateEditorTab::PREVIEW_GROUP_SETTING_KEY ) );

		return TemplateEditorTab::decodePreviewGroupValue( $raw );
	}

	/**
	 * The guid every sibling template's rows carry as their own parent_guid
	 * for a given page's location — shared by RowSprout Pro's SiblingLinksWidget
	 * and PageLinkTag, both of which cross-link to a
	 * DIFFERENT template's generated page for the SAME location as the
	 * current one. Resolves correctly regardless of which level of the
	 * template hierarchy the current group came from:
	 * - Placed on/resolved from the PARENT/hub template itself (whose
	 *   groups ARE the locations) — the group's own id IS what every
	 *   sibling child template's rows store as their parent_guid.
	 * - Placed on/resolved from one of the CHILD/category templates (a
	 *   sibling of the one being linked to, both children of the same
	 *   parent/hub template) — the group's own id is useless here (it's
	 *   this child's own per-location guid, never any sibling's
	 *   parent_guid); what's needed instead is this group's own parent_id
	 *   field (every child template's group carries this back-reference to
	 *   its parent/hub template's corresponding location group), which IS
	 *   the shared value every sibling's rows carry as their own
	 *   parent_guid too. A child template's group always has a non-empty
	 *   parent_id; the parent/hub template's own top-level groups never
	 *   do, so their own id is used instead.
	 *
	 * See GroupTableGateway::getRowSproutPageIdByParentGuid() for how a
	 * sibling's page is then matched against the guid this returns.
	 *
	 * @param array<string, mixed> $group
	 */
	public static function resolveLocationGuid( array $group ): string {
		$parentId = (string) ( $group['parent_id'] ?? '' );
		if ( $parentId !== '' && $parentId !== '0' ) {
			return $parentId;
		}

		return (string) ( $group['id'] ?? '' );
	}

	/**
	 * Widgets resolve their group data straight from the stored config
	 * (see the class docblock for why), which — unlike PageBuildContextResolver,
	 * used when a page is actually generated — never went through RowSprout
	 * Pro's "inherit an empty field from the parent template's group"
	 * step (rowsprout_resolve_child_template_context, see
	 * ChildTemplateInheritance). Without this, a widget dropped on a child
	 * page would render blank for any field the admin left empty to inherit
	 * from the parent, even though the actually-generated page's own content/
	 * postmeta already has it filled in. Reuses the exact same filter so any
	 * future change to the inheritance rules only has to happen once.
	 *
	 * @param array<string, mixed> $group
	 * @return array<string, mixed>
	 */
	private static function applyChildInheritance( array $group, string $guid, int $templateId ): array {
		$templatePost = get_post( $templateId );
		if ( ! $templatePost || ! has_post_parent( $templatePost ) ) {
			return $group;
		}

		$context = apply_filters(
			'rowsprout_resolve_child_template_context',
			[ 'group' => $group, 'parent_id' => null ],
			$guid,
			$templateId,
			$templatePost
		);

		return is_array( $context['group'] ?? null ) ? $context['group'] : $group;
	}

	/**
	 * A template's groups with child-template inheritance already applied
	 * (see applyChildInheritance()) — used by
	 * TemplateEditorTab::buildGroupOptions() so the "Preview group"
	 * dropdown can show a real title/href for a CHILD template's groups
	 * too. A child's own stored group config only holds the fields the
	 * admin actually overrode there; anything left blank to inherit from
	 * the parent (title/href included) is otherwise only resolved via this
	 * same filter at render/build time, so reading TemplateMeta directly
	 * would show nothing but the raw numeric guid for those groups.
	 *
	 * @return array<int, array{guid:string, group:array<string,mixed>}>
	 */
	public static function getGroupsWithInheritance( int $templateId ): array {
		$result = [];

		foreach ( (array) ( TemplateMeta::get( $templateId )['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$guid = (string) ( $group['id'] ?? '' );
			if ( $guid === '' ) {
				continue;
			}

			$result[] = [
				'guid'  => $guid,
				'group' => self::applyChildInheritance( $group, $guid, $templateId ),
			];
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $group
	 * @return array{value:string, type:string}|null
	 */
	public static function getFieldByCode( array $group, string $fieldCode ): ?array {
		$fieldCode = sanitize_key( $fieldCode );
		if ( $fieldCode === '' ) {
			return null;
		}

		foreach ( (array) ( $group['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && sanitize_key( (string) ( $field['code'] ?? '' ) ) === $fieldCode ) {
				$value = $field['value'] ?? '';

				return [
					'value' => is_scalar( $value ) ? (string) $value : '',
					'type'  => (string) ( $field['type'] ?? '' ),
				];
			}
		}

		return null;
	}

	/**
	 * The rowsprout_template a widget's Elementor controls should read field
	 * options from, given the post currently being edited: itself, when
	 * editing a rowsprout_template directly, or its source template, when
	 * editing a generated rowsprout_page.
	 */
	public static function resolveCurrentTemplateId(): int {
		$postId = (int) get_the_ID();
		if ( $postId <= 0 ) {
			return 0;
		}

		$postType = get_post_type( $postId );
		if ( $postType === PostTypes::TEMPLATE ) {
			return $postId;
		}

		if ( $postType === PostTypes::PAGE ) {
			return (int) get_post_meta( $postId, PostMetaKeys::SOURCE_TEMPLATE_ID, true );
		}

		return 0;
	}

	/**
	 * Options for a Controls_Manager::SELECT control listing the current
	 * template's fields by code — populated at register_controls() time
	 * from resolveCurrentTemplateId(), so the dropdown reflects whichever
	 * template the widget/tag is actually placed on instead of requiring
	 * the admin to type/copy a code by hand. $fieldType filters to only
	 * that field type (e.g. 'textarea'); null lists every field regardless
	 * of type — used by RowSprout Pro's PageFieldTag, which (unlike the
	 * single-purpose text widgets below) can represent any field.
	 *
	 * A list of types (e.g. ButtonWidget's url/email/tel) matches any of them.
	 *
	 * @param string|array<int, string>|null $fieldType
	 * @return array<string, string> code => label
	 */
	public static function getFieldCodeOptions( $fieldType = null ): array {
		$fieldTypes = $fieldType === null ? null : (array) $fieldType;
		$options = [ '' => __( '-- Select --', 'rowsprout' ) ];

		$templateId = self::resolveCurrentTemplateId();
		if ( $templateId <= 0 ) {
			return $options;
		}

		foreach ( (array) ( TemplateMeta::get( $templateId )['field_types'] ?? [] ) as $fieldTypeDef ) {
			if ( ! is_array( $fieldTypeDef ) ) {
				continue;
			}
			if ( $fieldTypes !== null && ! in_array( (string) ( $fieldTypeDef['type'] ?? '' ), $fieldTypes, true ) ) {
				continue;
			}

			$code = (string) ( $fieldTypeDef['code'] ?? '' );
			if ( $code !== '' ) {
				$options[ $code ] = (string) ( $fieldTypeDef['label'] ?? $code );
			}
		}

		return $options;
	}

	/**
	 * Whether the Elementor document currently being edited is one of this
	 * plugin's own post types — used by most widgets' show_in_panel() so
	 * these widgets don't clutter the panel when editing a regular page/post.
	 * Not used by GridWidget, which is deliberately available everywhere
	 * (see its own docblock).
	 */
	public static function isRowSproutPostType(): bool {
		return in_array( get_post_type( get_the_ID() ), [ PostTypes::TEMPLATE, PostTypes::PAGE ], true );
	}

	/**
	 * @return array<int|string, string>
	 */
	public static function getTemplateOptionsForSelect(): array {
		$options = [ '' => __( '-- Select --', 'rowsprout' ) ];

		$templates = get_posts( [
			'post_type'      => PostTypes::TEMPLATE,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'orderby'        => 'title',
			'order'          => 'ASC',
			// get_posts() suppresses query filters by default (unlike a
			// plain WP_Query), which skips WPML's own language filtering and
			// would otherwise list every language's copy of each template.
			'suppress_filters' => false,
		] );

		foreach ( $templates as $template ) {
			$options[ $template->ID ] = wp_get_post_parent_id( $template->ID ) ? '___' . $template->post_title : $template->post_title;
		}

		return $options;
	}
}
