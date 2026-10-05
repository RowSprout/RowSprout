<?php

namespace RowSprout\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * This class exists to talk to the plugin's own custom table
 * (`{$wpdb->prefix}rowsprout_page_groups`), so every query in it is
 * necessarily a direct, uncached $wpdb call — that's the entire point of a
 * custom-table data layer, not an oversight. $table itself is always
 * computed from $wpdb->prefix, never from request input, so the
 * "unescaped/not prepared table name" sniffs below are false positives for
 * this specific, well-understood pattern (the same one WordPress core's own
 * custom-table plugins, and most WP.org-hosted ones with a custom table,
 * carry a blanket phpcs:disable for).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter
final class Database
{
    public const SCHEMA_VERSION = '11';
    public const SCHEMA_VERSION_OPTION = 'rowsprout_db_schema_version';

    public static function activate(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rowsprout_page_groups';
        $charsetCollate = $wpdb->get_charset_collate();

        // "scheduled_at" is intentionally NOT part of the base schema: it's
        // only ever populated by RowSprout Pro's scheduling features,
        // which add the column themselves on activation. Existing sites that
        // already have the column (from before this change, or because Pro
        // added it) keep it as-is — dbDelta() only ever adds columns, never
        // drops them, so this is safe either way. See
        // GroupTableGateway::supportsScheduling() for how the base plugin
        // stays functional whether or not the column is present.
        //
        // "generated_at" IS part of the base schema (added in schema v10):
        // set by QueueProcessor::processSingleGroup() every time a group's
        // page is actually (re)built, so callers (notably the MCP server's
        // get_generation_status) can tell how stale a specific generated
        // page's content is instead of only its queue status. Guarded by
        // GroupTableGateway::supportsGeneratedAt() the same way
        // supportsScheduling() guards scheduled_at, since dbDelta() only
        // runs on admin_init — a request that never touches wp-admin (e.g.
        // an MCP call right after this deploy, before anyone next loads
        // wp-admin) could otherwise hit the column before it exists.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rowsprout_template_id BIGINT UNSIGNED NOT NULL,
            guid VARCHAR(255) NOT NULL,
            parent_guid VARCHAR(255) NULL,
            rowsprout_page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(50) NULL,
            generated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY template_guid (rowsprout_template_id, guid),
            KEY rowsprout_template_id (rowsprout_template_id),
            KEY parent_guid (parent_guid),
            KEY rowsprout_page_id (rowsprout_page_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        self::dropTranslationColumnIfExists( $table );

        // This plugin has no license concept anymore (moved entirely to
        // RowSprout Pro, see that plugin's License\Database) — drop this
        // even-older, pre-option table left over from schema version 8 or
        // earlier, if it's still there.
        self::dropLegacyLicenseTable();

        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    public static function maybeMigrate(): void
    {
        $installed = (string) get_option(self::SCHEMA_VERSION_OPTION, '');
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        self::activate();
    }

    /**
     * dbDelta() only ever adds columns, it never removes them, so the
     * now-unused "translation" column (never functionally used by this
     * plugin or by RowSprout Pro) needs an explicit drop for sites
     * upgrading from schema version 5 or earlier.
     */
    private static function dropTranslationColumnIfExists( string $table ): void
    {
        global $wpdb;

        $column = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'translation' ) );
        if ( ! empty( $column ) ) {
            $wpdb->query( 'ALTER TABLE ' . $table . ' DROP COLUMN translation' );
        }
    }

    /**
     * A licence table from schema version 8 or earlier; licensing lives in
     * RowSprout Pro now. Dropped for sites upgrading from those versions.
     */
    private static function dropLegacyLicenseTable(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'dynamic_pages_license';
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
    }
}
