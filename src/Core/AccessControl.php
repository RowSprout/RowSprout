<?php

namespace RowSprout\Core;

// PostTypes lives in this same namespace, no `use` needed.

if (!defined('ABSPATH')) {
    exit;
}

final class AccessControl
{
    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'protectTemplates']);
    }

    public static function protectTemplates(): void
    {
        if (!is_singular(PostTypes::TEMPLATE) || is_user_logged_in()) {
            return;
        }

        wp_safe_redirect(home_url(), 301);
        exit;
    }
}
