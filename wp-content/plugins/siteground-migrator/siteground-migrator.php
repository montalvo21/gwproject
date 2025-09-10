<?php

namespace SiteGround_Migrator;

use SiteGround_Migrator\Loader\Loader;
use SiteGround_Migrator\Activator\Activator;
use SiteGround_Migrator\Deactivator\Deactivator;
use ShuttleExport\Exporter;


/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.siteground.com
 * @since             1.0.0
 * @package           SiteGround_Migrator
 *
 * @wordpress-plugin
 * Plugin Name:       SiteGround Migrator
 * Plugin URI:        http://siteground.com
 * Description:       This plugin is designed to migrate your WordPress site to SiteGround
 * Version:           2.0.9
 * Author:            SiteGround
 * Author URI:        https://www.siteground.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       siteground-migrator
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define version constant.
if ( ! defined( __NAMESPACE__ . '\VERSION' ) ) {
    define( __NAMESPACE__ . '\VERSION', '2.0.9' );
}

// Define slug constant.
if ( ! defined( __NAMESPACE__ . '\PLUGIN_SLUG' ) ) {
    define( __NAMESPACE__ . '\PLUGIN_SLUG', 'siteground-migrator' );
}

// Define root directory.
if ( ! defined( __NAMESPACE__ . '\DIR' ) ) {
    define( __NAMESPACE__ . '\DIR', __DIR__ );
}

// Define root URL.
if ( ! defined( __NAMESPACE__ . '\URL' ) ) {
    $root_url = \trailingslashit( DIR );

    // Sanitize directory separator on Windows.
    $root_url = str_replace( '\\', '/', $root_url );

    $wp_plugin_dir = str_replace( '\\', '/', WP_PLUGIN_DIR );
    $root_url = str_replace( $wp_plugin_dir, \plugins_url(), $root_url );

    define( __NAMESPACE__ . '\URL', \untrailingslashit( $root_url ) );

    unset( $root_url );
}
require_once( \SiteGround_Migrator\DIR . '/vendor/autoload.php' );

register_activation_hook( __FILE__, array( new Activator(), 'activate' ) );
register_deactivation_hook( __FILE__, array( new Deactivator(), 'deactivate' ) );

// Initialize helper.
global $sg_migrator_loader;

if ( ! isset( $sg_migrator_loader ) ) {
    $sg_migrator_loader = new Loader();
}
