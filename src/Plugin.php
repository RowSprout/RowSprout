<?php

namespace RowSprout;

use RowSprout\Admin\Menu;
use RowSprout\Admin\Metaboxes;
use RowSprout\Admin\PageEditNotice;
use RowSprout\Admin\TemplateBlockEditor;
use RowSprout\Admin\ChildTemplateAdminUx;
use RowSprout\Admin\TemplateStatusColumn;
use RowSprout\Admin\Metaboxes\ParentPayloadAjaxHandler;
use RowSprout\Blocks\BlockRegistry;
use RowSprout\Core\AccessControl;
use RowSprout\Core\BlockBindings\PropertyBindingSource;
use RowSprout\Core\ChildTemplates\ChildTemplateInheritance;
use RowSprout\Core\Database;
use RowSprout\Core\PermalinkSettings;
use RowSprout\Core\PostTypes;
use RowSprout\Core\RemoveCptBase;
use RowSprout\Core\SavePost;
use RowSprout\Core\Scheduler;
use RowSprout\Core\TemplateDeletion;
use RowSprout\ThirdParty\Elementor\ElementorIntegration;
use RowSprout\ThirdParty\Elementor\TemplateEditorTab;
use RowSprout\ThirdParty\Elementor\TemplateSaveActionControl;
use RowSprout\ThirdParty\RankMath;
use RowSprout\ThirdParty\WPBakery\WPBakeryIntegration;
use RowSprout\ThirdParty\WpRocket;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    /**
     * @var self|null
     */
    private static $instance;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function bootstrap(): void
    {
        $this->loadActionScheduler();

        AccessControl::register();
        Menu::register();
        Metaboxes::register();
        TemplateBlockEditor::register();
        PropertyBindingSource::register();
        BlockRegistry::register();
        PageEditNotice::register();
        ChildTemplateAdminUx::register();
        TemplateStatusColumn::register();
        ParentPayloadAjaxHandler::register();
        ChildTemplateInheritance::register();
        PermalinkSettings::register();
        \RowSprout\Core\PageEditing::register();
        PostTypes::register();
        RemoveCptBase::register();
        SavePost::register();
        Scheduler::register();
        TemplateDeletion::register();

        RankMath::register();
        WPBakeryIntegration::register();
        WpRocket::register();
        ElementorIntegration::register();
        TemplateEditorTab::register();
        TemplateSaveActionControl::register();

        add_action('admin_init', [Database::class, 'maybeMigrate']);
    }

    private function loadActionScheduler(): void
    {
        if (class_exists('ActionScheduler')) {
            return;
        }

        // Composer-managed (see composer.json), pinned to the exact version
        // this plugin has always bundled — a version bump is a deliberate
        // `composer update` + regression pass, not an incidental side effect
        // of some other change. Action Scheduler itself handles the case
        // where another active plugin bundles a different version (it picks
        // the highest one found across all of them at runtime), so this
        // plugin doesn't need to coordinate with theirs.
        require_once ROWSPROUT_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
    }
}
