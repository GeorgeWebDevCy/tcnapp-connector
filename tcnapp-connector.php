<?php
/**
 * Plugin Name:       TCN Platform
 * Plugin URI:        https://www.georgenicolaou.me/plugins/tcn-platform
 * Description:       Unified membership, MLM, and password-login API services for WooCommerce-powered TCN deployments.
 * Version:           0.3.11
 * Author:            George Nicolaou
 * Author URI:        https://www.georgenicolaou.me/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       tcnapp-connector
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TCN_PLATFORM_VERSION', '0.3.11' );
define( 'TCN_PLATFORM_PLUGIN_FILE', __FILE__ );
define( 'TCN_PLATFORM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCN_PLATFORM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once TCN_PLATFORM_PLUGIN_DIR . 'includes/Autoloader.php';
TCN\Platform\Autoloader::register();

register_activation_hook( __FILE__, array( '\\TCN\\Platform\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\TCN\\Platform\\Deactivator', 'deactivate' ) );

function tcn_platform_boot() {
    $plugin = new TCN\Platform\Plugin();
    $plugin->boot();
}

tcn_platform_boot();
