<?php
/**
 * Plugin Name: RowSprout
 * Plugin URI: https://rowsprout.com
 * Description: Generate SEO landing pages at scale from reusable templates and structured data groups.
 * Version: 3.2
 * Text Domain: rowsprout
 * Domain Path: /languages
 * Author: RowSprout
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! defined( 'ROWSPROUT_FILE' ) ) {
    define( 'ROWSPROUT_FILE', __FILE__ );
}

// Kept in sync with the "Version:" header above by hand on every release —
// used for cache-busting inline/no-file scripts (real asset files use their
// own filemtime() instead, see GroupsMetaBoxAssets/TemplateEditorTab).
if ( ! defined( 'ROWSPROUT_VERSION' ) ) {
    define( 'ROWSPROUT_VERSION', '3.2' );
}

if ( ! defined( 'ROWSPROUT_PATH' ) ) {
    define( 'ROWSPROUT_PATH', plugin_dir_path( __FILE__ ) );
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'RowSprout requires PHP 7.4 or higher.', 'rowsprout' );
        echo '</p></div>';
    } );

    return;
}

require_once ROWSPROUT_PATH . 'src/Support/Autoloader.php';

( new \RowSprout\Support\Autoloader(
    'RowSprout',
    ROWSPROUT_PATH . 'src'
) )->register();

require_once ROWSPROUT_PATH . 'src/Compat/Functions.php';

register_activation_hook( __FILE__, [ \RowSprout\Core\Database::class, 'activate' ] );
register_activation_hook( __FILE__, [ \RowSprout\Core\RemoveCptBase::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \RowSprout\Core\RemoveCptBase::class, 'deactivate' ] );

\RowSprout\Plugin::instance()->bootstrap();
