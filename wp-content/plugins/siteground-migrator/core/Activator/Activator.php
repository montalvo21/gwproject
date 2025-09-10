<?php

namespace SiteGround_Migrator\Activator;

use SiteGround_Migrator\Helper\Helper;
use SiteGround_Migrator\Directory_Service\Directory_Service;
/**
 * Class managing plugin activation.
 */
class Activator {

	/**
	 * Fires on plugin activation.
	 *
	 * @since    1.0.0
	 */
	public function activate() {
		// Check the php version and deactivate the plugin is it's lower that 7.0.
		if ( version_compare( PHP_VERSION, '7.0', '<' ) ) {
			$this->siteground_migrator_compatability_warning();
			$this->siteground_migrator_deactivate_self();
		}

		if ( is_multisite() && is_network_admin() ) {
			$this->siteground_migrator_multisite_warning();
			$this->siteground_migrator_deactivate_self();
		}

		// Check the hosting envirnoment.
		self::check_hosting_environment();
		// Set the temp directory.
		self::set_temp_directory();
		// Set the encryption key.
		self::set_encryption_key();

		$directory_service = new Directory_Service();

		$directory_service->create_temp_directories();
	}

	/**
	 * Set temp directory.
	 *
	 * @since 1.0.0
	 */
	public static function set_temp_directory() {
		// Try to get the temp dir.
		$temp_dir = get_option( 'siteground_migrator_temp_directory' );

		// Set the directory is it's empty.
		if ( empty( $temp_dir ) ) {
			update_option( 'siteground_migrator_temp_directory', time() . '-' . sha1( mt_rand() ) );
		}
	}

	/**
	 * Set the encryption key for current installation.
	 *
	 * @since 1.0.0
	 */
	public static function set_encryption_key() {
		// Get the encryption key.
		$encryption_key = get_option( 'siteground_migrator_encryption_key' );

		// Generate encryption key if it's not set already.
		if ( empty( $encryption_key ) ) {
			update_option( 'siteground_migrator_encryption_key', sha1( uniqid() ) );
		}
	}

	/**
	 * Check the hosting environment.
	 *
	 * @since  1.0.25
	 */
	public static function check_hosting_environment() {
		// Update the option.
		update_option( 'siteground_migrator_is_siteground_env', Helper::is_siteground() );
	}

	/**
	 * Display notice for minimum supported php version.
	 *
	 * @since  1.0.0
	 */
	public function siteground_migrator_compatability_warning() {
		printf(
			__( '<div class="error"><p>“%1$s” requires PHP %2$s (or newer) to function properly. Your site is using PHP %3$s. Please upgrade. The plugin has been automatically deactivated.</p></div>', 'siteground-migrator' ),
			'SiteGround Migrator',
			'7.0',
			PHP_VERSION
		);

		// Hide "Plugin activated" message.
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}

	/**
	 * Display notice if wp is multisite.
	 *
	 * @since  1.0.1
	 */
	public function siteground_migrator_multisite_warning() {
		_e( '<div class="error"><p>This plugin does not support full Multise Network migrations.</p></div>', 'siteground-migrator' );

		// Hide "Plugin activated" message.
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}

	/**
	 * Deactivate the plugin if server php version
	 * is lower than plugin supported version.
	 *
	 * @since  1.0.0
	 */
	public function siteground_migrator_deactivate_self() {
		deactivate_plugins( plugin_basename( __FILE__ ) );
	}
}
