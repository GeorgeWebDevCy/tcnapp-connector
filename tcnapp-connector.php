<?php
/**
 * Plugin Name:       TCN Platform
 * Plugin URI:        https://www.georgenicolaou.me/plugins/tcn-platform
 * Description:       Unified membership, MLM, and password-login API services for WooCommerce-powered TCN deployments.
 * Version:           0.3.97
 * Author:            George Nicolaou
 * Author URI:        https://www.georgenicolaou.me/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       tcnapp-connector
 * Domain Path:       /languages
 */

// WordPress defines ABSPATH when the environment is fully bootstrapped. Hard exiting when the
// constant is missing protects against direct file access (e.g. via the browser) which could
// bypass WP's capability checks.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// The current plugin version is surfaced in multiple places (plugin header, constant, updater).
// Keeping a single source of truth via this constant makes it trivial to reference and compare
// versions throughout the codebase—for example when performing migrations.
define( 'TCN_PLATFORM_VERSION', '0.3.97' );
// The absolute path to this file is cached to avoid repeated calls to plugin_basename() and
// to ensure other classes (e.g. the autoloader) can reliably resolve relative paths.
define( 'TCN_PLATFORM_PLUGIN_FILE', __FILE__ );
// WordPress plugins are often distributed as zip archives that may live in arbitrary directories.
// Normalising the base directory here simplifies path calculations elsewhere in the code.
define( 'TCN_PLATFORM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
// Precomputing the URL equivalent of the plugin directory allows asset loaders to enqueue
// scripts and styles without recalculating the value on every request.
define( 'TCN_PLATFORM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    // Composer dependencies are optional. We guard the require to avoid fatal errors on
    // installations that skip vendor files (common in WordPress deployments).
    require_once __DIR__ . '/vendor/autoload.php';
}

// The plugin uses a PSR-4 style autoloader for its own classes to keep requires centralised.
require_once TCN_PLATFORM_PLUGIN_DIR . 'includes/Autoloader.php';
// Register the autoloader immediately so that subsequent class references (Activators, Plugin, …)
// are resolved automatically by PHP rather than manually including each file.
TCN\Platform\Autoloader::register();

// Register activation and deactivation hooks early so WordPress can execute the plugin-specific
// bootstrap and teardown logic at the appropriate lifecycle events.
register_activation_hook( __FILE__, array( '\\TCN\\Platform\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\TCN\\Platform\\Deactivator', 'deactivate' ) );

function tcn_platform_boot() {
    // The Plugin class orchestrates the full bootstrap: service registration, hooks, and module
    // configuration. Instantiating it here keeps the global namespace clean while providing a
    // predictable entry point for unit tests that may need to stub the class.
    $plugin = new TCN\Platform\Plugin();
    // Call boot() explicitly rather than doing work in the constructor to keep object creation
    // side-effect free—a convention that simplifies mocking and makes control flow obvious.
    $plugin->boot();
}

// Kick off plugin bootstrapping immediately so WordPress can register all hooks before it begins
// dispatching requests.
tcn_platform_boot();
