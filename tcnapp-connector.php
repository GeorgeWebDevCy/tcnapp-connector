<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.georgenicolaou.me
 * @since             1.0.0
 * @package           Tcnapp_Connector
 *
 * @wordpress-plugin
 * Plugin Name:       TCNApp Connector
 * Plugin URI:        https://www.georgenicolaou.me/plugins/tcnapp-connector
 * Description:       A connector plugin for the TCNApp
 * Version:           1.0.0
 * Author:            George Nicolaou
 * Author URI:        https://www.georgenicolaou.me/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       tcnapp-connector
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'TCNAPP_CONNECTOR_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-tcnapp-connector-activator.php
 */
function activate_tcnapp_connector() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tcnapp-connector-activator.php';
	Tcnapp_Connector_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-tcnapp-connector-deactivator.php
 */
function deactivate_tcnapp_connector() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-tcnapp-connector-deactivator.php';
	Tcnapp_Connector_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_tcnapp_connector' );
register_deactivation_hook( __FILE__, 'deactivate_tcnapp_connector' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-tcnapp-connector.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_tcnapp_connector() {

	$plugin = new Tcnapp_Connector();
	$plugin->run();

}
run_tcnapp_connector();
