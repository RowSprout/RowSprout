<?php

namespace RowSprout\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class PostTypes
{
    // Stored in wp_posts.post_type (and WPML's element types): never change
    // these values without a matching data migration.
    public const PAGE = 'rowsprout_page';
    public const TEMPLATE = 'rowsprout_template';

    public static function register(): void
    {
        add_action('init', [self::class, 'registerPostTypes']);
    }

    public static function registerPostTypes(): void
    {
        $pageSupports = [
            'title',
            'editor',
            'thumbnail',
            'revisions',
            'post-formats',
            'page-attributes',
        ];

        // page-attributes gives templates their "Parent" field: a template
        // with a parent is a child template (Core\ChildTemplates).
        $templateSupports = [
            'title',
            'editor',
            'thumbnail',
            'revisions',
            'page-attributes',
        ];

        $pageSupports     = apply_filters( 'rowsprout_post_type_supports', $pageSupports );
        $templateSupports = apply_filters( 'rowsprout_template_post_type_supports', $templateSupports );

        // Generated pages are locked from editing and deleting unless the
        // Settings page unlocks them (PageEditing).
        $capabilities = apply_filters( 'rowsprout_post_type_capabilities', PageEditing::capabilities() );

        $permalinkBase = PermalinkSettings::getBase();

        register_post_type(self::PAGE, [
            'label' => __('RowSprout Pages', 'rowsprout'),
            'description' => __('All RowSprout Pages lives here', 'rowsprout'),
            'labels' => [
                'name' => __('RowSprout Pages', 'rowsprout'),
                'singular_name' => __('RowSprout Page', 'rowsprout'),
                'menu_name' => __('RowSprout Pages', 'rowsprout'),
                'all_items' => __('All Pages', 'rowsprout'),
                'view_item' => __('View RowSprout Page', 'rowsprout'),
                'add_new_item' => __('Add New Page', 'rowsprout'),
                'add_new' => __('Add New', 'rowsprout'),
                'edit_item' => __('Edit Page', 'rowsprout'),
                'update_item' => __('Update Page', 'rowsprout'),
                'search_items' => __('Search in RowSprout Pages', 'rowsprout'),
                'not_found' => __('Not Found', 'rowsprout'),
                'not_found_in_trash' => __('Not Found in Trash', 'rowsprout'),
            ],
            'public' => true,
            'has_archive' => false,
            'rewrite' => [
                'slug'       => $permalinkBase !== '' ? $permalinkBase : self::PAGE,
                'with_front' => true,
            ],
            'supports' => $pageSupports,
            'hierarchical' => true,
            'show_in_rest' => false,
            'show_ui' => true,
            'capability_type' => 'post',
            // Required whenever a non-empty 'capabilities' array is passed:
            // WP only auto-enables meta-cap mapping when 'capabilities' is
            // empty (see WP_Post_Type::set_props()), so without this,
            // 'edit_post'/'delete_post'/'read_post' get checked as literal
            // primitive capability names instead of being resolved against
            // the current user/post — which no role ever has, silently
            // blocking edit/delete/read for everyone, admins included.
            'map_meta_cap' => true,
            'exclude_from_search' => false,
            'show_in_menu' => false,
            'capabilities' => $capabilities,
        ]);

        register_post_type(self::TEMPLATE, [
            'label' => __('RowSprout Template Pages', 'rowsprout'),
            'description' => __('All RowSprout Template Pages lives here', 'rowsprout'),
            'labels' => [
                'name' => __('RowSprout Templates', 'rowsprout'),
                'singular_name' => __('RowSprout Template', 'rowsprout'),
                'menu_name' => __('RowSprout Templates', 'rowsprout'),
                'all_items' => __('All Template Pages', 'rowsprout'),
                'view_item' => __('View RowSprout Template Page', 'rowsprout'),
                'add_new_item' => __('Add New Template Page', 'rowsprout'),
                'add_new' => __('Add New Template', 'rowsprout'),
                'edit_item' => __('Edit Template', 'rowsprout'),
                'update_item' => __('Update Page', 'rowsprout'),
                'search_items' => __('Search in RowSprout Template Pages', 'rowsprout'),
                'not_found' => __('Not Found', 'rowsprout'),
                'not_found_in_trash' => __('Not Found in Trash', 'rowsprout'),
            ],
            'public' => true,
            'has_archive' => true,
            'rewrite' => false,
            'supports' => $templateSupports,
            // Opens templates in the block editor. Its REST save runs before
            // the classic "RowSprout template" metabox is posted; see
            // Admin\TemplateBlockEditor for how the save flow is kept in the
            // metabox request.
            'show_in_rest' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'capability_type' => 'post',
            'exclude_from_search' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
        ]);
    }
}
