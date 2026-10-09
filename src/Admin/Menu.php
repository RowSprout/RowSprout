<?php

namespace RowSprout\Admin;

use RowSprout\Core\PermalinkSettings;
use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\TemplateStatusResolver;

if (!defined('ABSPATH')) {
    exit;
}

final class Menu
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminMenu']);
        add_action('admin_menu', [MenuRegistrar::class, 'reorderAdminSubmenu'], 999);
    }

    public static function registerAdminMenu(): void
    {
        \RowSprout\Admin\MenuRegistrar::registerAdminMenu();
    }

    public static function renderHomePage(): void
    {
        echo '<div class="wrap">';
        echo '<img src="' . esc_url(plugins_url('assets/images/rowsprout-logo.svg', ROWSPROUT_FILE)) . '" alt="' . esc_attr__('RowSprout', 'rowsprout') . '" width="229" height="50" style="display: block; margin: 10px 0 4px;">';
        echo '<h1>' . esc_html__('Welcome to RowSprout', 'rowsprout') . '</h1>';
        echo '<p style="max-width: 760px; font-size: 14px;">' . esc_html__('RowSprout turns one template and a table of rows into as many pages as you need: for example a page for every service in every city you work in. Each page gets its own title, URL, texts and links, and stays a normal WordPress page.', 'rowsprout') . '</p>';
        echo '<div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">';
        self::renderHowItWorksCard();
        echo '<div style="flex: 1 1 320px; max-width: 480px;">';
        self::renderTemplatesCard();
        self::renderGoodToKnowCard();
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * The four steps from template to generated pages.
     */
    private static function renderHowItWorksCard(): void
    {
        $steps = [
            [
                __('Build a template', 'rowsprout'),
                __('Design the page once in your favorite editor, there are widgets for the block editor, Elementor and WPBakery. This is the layout every generated page shares.', 'rowsprout'),
            ],
            [
                __('Add properties', 'rowsprout'),
                __('Properties are the parts that differ per page: a title, a URL, a text, a link, an email address. Place a property in your design with its code (shown on the Properties tab, for example @code_city_123@) or with one of the widgets.', 'rowsprout'),
            ],
            [
                __('Add groups', 'rowsprout'),
                __('A group is one row of values for those properties, and every group becomes one page. Ten groups give you ten pages.', 'rowsprout'),
            ],
            [
                __('Save with "Create & update pages"', 'rowsprout'),
                __('The pages are created in the background, usually within a minute. Change the template or a group later and save again to update them.', 'rowsprout'),
            ],
        ];

        echo '<div class="card" style="flex: 2 1 420px; max-width: 760px; margin-top: 0;">';
        echo '<h2>' . esc_html__('How it works', 'rowsprout') . '</h2>';
        echo '<ol style="margin-left: 20px;">';
        foreach ($steps as [$title, $text]) {
            echo '<li style="margin-bottom: 12px;"><strong>' . esc_html($title) . '</strong><br>' . esc_html($text) . '</li>';
        }
        echo '</ol>';
        echo '<p><a href="' . esc_url(admin_url('post-new.php?post_type=' . PostTypes::TEMPLATE)) . '" class="button button-primary">' . esc_html__('Create your first template', 'rowsprout') . '</a></p>';
        echo '</div>';
    }

    /**
     * Short notes on the parts of the plugin that are easy to miss.
     */
    private static function renderGoodToKnowCard(): void
    {
        $notes = [
            __('Save action: "Save template only" keeps the existing pages as they are and marks them as outdated; "Create & update pages" regenerates them.', 'rowsprout'),
            __('Child templates: give a template a parent and it reuses the parent\'s groups, so you get a second set of pages (for example "Roof repair Amsterdam" next to "Roofer Amsterdam") without entering the rows again.', 'rowsprout'),
            __('The Status column under Templates shows how many pages of each template are up to date.', 'rowsprout'),
            __('Under Settings you choose whether the pages get a URL prefix (e.g. /pages/…) or live directly at the site root.', 'rowsprout'),
        ];

        echo '<div class="card" style="max-width: none; margin-top: 20px;">';
        echo '<h2>' . esc_html__('Good to know', 'rowsprout') . '</h2>';
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        foreach ($notes as $note) {
            echo '<li style="margin-bottom: 8px;">' . esc_html($note) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    /**
     * The most recently changed templates with their status, plus links to
     * the full Templates list and to a new template.
     */
    private static function renderTemplatesCard(): void
    {
        $recent = get_posts([
            'post_type'      => PostTypes::TEMPLATE,
            'post_status'    => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => 5,
            'no_found_rows'  => true,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ]);

        echo '<div class="card" style="max-width: none; margin-top: 0;">';
        echo '<h2>' . esc_html__('Your templates', 'rowsprout') . '</h2>';

        if (empty($recent)) {
            echo '<p>' . esc_html__("You haven't created a template yet.", 'rowsprout') . '</p>';
        } else {
            echo '<ul style="margin: 0 0 16px;">';
            foreach ($recent as $template) {
                $status = TemplateStatusResolver::resolve((int) $template->ID);
                echo '<li style="margin-bottom: 6px;">';
                echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr(TemplateStatusResolver::getColor($status)) . ';vertical-align:middle;margin-right:8px;" title="' . esc_attr(TemplateStatusResolver::getLabel($status)) . '"></span>';
                echo '<a href="' . esc_url((string) get_edit_post_link($template->ID)) . '">' . esc_html(get_the_title($template) !== '' ? get_the_title($template) : __('(no title)', 'rowsprout')) . '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<a href="' . esc_url(admin_url('post-new.php?post_type=' . PostTypes::TEMPLATE)) . '" class="button button-primary">' . esc_html__('Add new template', 'rowsprout') . '</a> ';
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=' . PostTypes::TEMPLATE)) . '" class="button">' . esc_html__('All templates', 'rowsprout') . '</a>';
        echo '</div>';
    }

    public static function renderSettingsPage(): void
    {
        $currentBase = PermalinkSettings::getBase();
        $mode        = $currentBase === '' ? 'none' : 'custom';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('RowSprout Settings', 'rowsprout'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('rowsprout_permalink_action', 'rowsprout_permalink_nonce'); ?>
                <h2><?php esc_html_e('Page URL base', 'rowsprout'); ?></h2>
                <p class="description"><?php esc_html_e('Choose whether generated RowSprout Pages get a URL prefix.', 'rowsprout'); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('URL structure', 'rowsprout'); ?></th>
                        <td>
                            <p>
                                <label>
                                    <input type="radio" name="rowsprout_permalink_mode" value="none" id="rowsprout_permalink_mode_none" <?php checked($mode, 'none'); ?>>
                                    <?php esc_html_e('No base — pages live directly at the site root (e.g. https://example.com/)', 'rowsprout'); ?>
                                </label>
                            </p>
                            <p>
                                <label>
                                    <input type="radio" name="rowsprout_permalink_mode" value="custom" id="rowsprout_permalink_mode_custom" <?php checked($mode, 'custom'); ?>>
                                    <?php esc_html_e('Custom base:', 'rowsprout'); ?>
                                </label>
                                <input type="text" name="rowsprout_permalink_base" id="rowsprout_permalink_base" value="<?php echo esc_attr($currentBase); ?>" placeholder="<?php esc_attr_e('e.g. pages', 'rowsprout'); ?>" class="regular-text" <?php disabled($mode, 'none'); ?>>
                            </p>
                            <p class="description"><?php esc_html_e('Example: with base "pages", a generated page lives at https://example.com/pages/.', 'rowsprout'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php \RowSprout\Core\PageEditing::renderSettingsFields(); ?>

                <?php submit_button(); ?>
            </form>

            <?php

            /**
             * Lets an add-on such as RowSprout Pro append its own settings
             * sections below these, without the base plugin knowing it exists.
             */
            do_action('rowsprout_settings_page_after_permalink');
            ?>
        </div>
        <?php
        wp_register_script('rowsprout-settings', false, [], ROWSPROUT_VERSION, true);
        wp_enqueue_script('rowsprout-settings');
        wp_add_inline_script('rowsprout-settings', "
        (function () {
            var customRadio = document.getElementById('rowsprout_permalink_mode_custom');
            var noneRadio   = document.getElementById('rowsprout_permalink_mode_none');
            var baseInput   = document.getElementById('rowsprout_permalink_base');

            function syncBaseInput() {
                baseInput.disabled = !customRadio.checked;
            }

            customRadio.addEventListener('change', syncBaseInput);
            noneRadio.addEventListener('change', syncBaseInput);
            baseInput.addEventListener('focus', function () {
                customRadio.checked = true;
                syncBaseInput();
            });
        })();
        ");
    }
}
