<?php

namespace SiteGround_Migrator\Loader;

use SiteGround_Migrator;

use SiteGround_Migrator\Background_Process\WP_Async_Request;
use SiteGround_Migrator\Background_Process\Siteground_WP_Background_Process;
use SiteGround_Migrator\Helper\Factory_Trait;
use SiteGround_Migrator\Helper\Helper;
use SiteGround_i18n\i18n_Service;

/**
 * Loader functions and main initialization class.
 */
class Loader {
	use Factory_Trait;

	/**
	 * Local Variables
	 */
	public $i18n_service;
	public $admin;
	public $api_service;
	public $cli;
	public $files_service;
	public $transfer_service;
	public $rest;

	/**
	 * Dependencies.
	 *
	 * @var array
	 */
	public $dependencies = array(
		'admin',
		'api_service',
		'cli',
		'files_service',
		'transfer_service',
		'rest',
	);

	/**
	 * External dependencies.
	 *
	 * @var array
	 */
	public $external_dependencies = array(
		'i18n_Service'   => array(
			'namespace' => 'i18n',
			'hook'      => 'i18n',
			'args'      => 'siteground-migrator',
		),
	);

	/**
	 * Create a new helper.
	 */
	public function __construct() {
		$this->load_external_dependencies();
		$this->load_dependencies();
		$this->add_hooks();

		// Add custom shutdown function to handle fatal errors.
		register_shutdown_function( array( $this, 'siteground_migrator_shutdown_handler' ) );
	}

	/**
	 * Load the main plugin dependencies.
	 *
	 * @since 2.0.0
	 */
	public function load_dependencies() {
		foreach ( $this->dependencies as $dependency ) {
			$this->factory( $dependency );
		}
	}

	/**
	 * Load all of our external dependencies.
	 *
	 * @since 2.0.0
	 */
	public function load_external_dependencies() {
		// Loop trough all deps.
		foreach ( $this->external_dependencies as $library => $props ) {

			// Build the class.
			$class = 'SiteGround_' . $props['namespace'] . '\\' . $library;

			// Check if class exists.
			if ( ! class_exists( $class ) ) {
				throw new \Exception( 'Unknown library type "' . $library . '".' );
			}

			// Lowercase the classsname we are going to use in the object context.
			$classname = strtolower( $library );

			// Check if we need to add any arguments when calling the class.
			$this->$classname = true === array_key_exists( 'args', $props ) ? new $class( $props['args'] ) : new $class();

			// Check if we need to add hooks for the specific dependency.
			if ( array_key_exists( 'hook', $props ) ) {
				call_user_func( array( $this, 'add_' . $props['hook'] . '_hooks' ) );
			}
		}
	}

	/**
	 * Add the hooks that the plugin will use to do the magic.
	 *
	 * @since 2.0.0
	 */
	public function add_hooks() {
		foreach ( $this->dependencies as $type ) {
			call_user_func( array( $this, 'add_' . $type . '_hooks' ) );
		}
	}

	/**
	 * Add the admin hooks.
	 *
	 * @since 2.0.0
	 */
	public function add_admin_hooks() {
		if ( is_network_admin() ) {
			// Register the top level page into the WordPress admin menu.
			add_action( 'network_admin_menu', array( $this->admin, 'add_menu_page' ) );
		}

		// Register the stylesheets for the admin area.
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_styles' ), 111 );
		// Register the JavaScript for the admin area.
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_scripts' ) );
		// Add styles to WordPress admin head.
		add_action( 'admin_print_styles', array( $this->admin, 'admin_print_styles' ) );

		// Register the top level page into the WordPress admin menu.
		add_action( 'admin_menu', array( $this->admin, 'add_menu_page' ) );
	}

	/**
	 * Add the API Service hooks.
	 *
	 * @since 2.0.0
	 */
	public function add_api_service_hooks() {
		// Fired when the transfer is completed and the site is migrated to SiteGround server.
		add_action( 'wp_ajax_nopriv_siteground_migrator_is_plugin_installed', array( $this->api_service, 'is_plugin_installed' ) );
	}

	/**
	 * Add WP-CLI hooks.
	 *
	 * @since 2.0.0
	 */
	public function add_cli_hooks() {
		// If we're in `WP_CLI` load the related files.
		if ( class_exists( 'WP_CLI' ) ) {
			add_action( 'init', array( $this->cli, 'register_commands' ) );
		}
	}

	/**
	 * Add Files Service hooks.
	 *
	 * @since 1.0.0
	 */
	public function add_files_service_hooks() {
		add_action( 'wp_ajax_nopriv_siteground_migrator_download_file', array( $this->files_service, 'download_file_from_uploads' ) );
	}

	/**
	 * Add Transfer service hooks.
	 *
	 * @since 1.0.0
	 */
	public function add_transfer_service_hooks() {
		// Handle all status updates from the remote api.
		add_action( 'wp_ajax_nopriv_siteground_migrator_update_transfer_status', array( $this->transfer_service, 'update_transfer_status_endpoint' ) );
		// Hide all annoying notices from our page.
		add_action( 'admin_init', array( $this->transfer_service, 'hide_errors_and_notices' ) );
	}

	/**
	 * Handle all functions shutdown and check for fatal errors in plugin.
	 *
	 * @since  1.0.5
	 */
	public function siteground_migrator_shutdown_handler() {
		// Get the last error.
		$error = error_get_last();

		// Bail if there is no error.
		if ( empty( $error ) ) {
			return;
		}

		// Update the status of transfer if the fatal error occured.
		if (
			strpos( $error['file'], plugin_dir_path( dirname( __FILE__ ) ) ) !== false &&
			E_ERROR === $error['type']
		) {
			$helper = new Helper();
			// Update the status.
			$this->transfer_service->update_status(
				esc_html__( 'Critical Transfer Error', 'siteground-migrator' ),
				0,
				$helper->get_error_message( $error )
			);

			// Log the fatal error in our custom log.
			$this->transfer_service->log_error( print_r( $error, true ) );

		}
	}
	/**
	 * Add i18n Hooks.
	 *
	 * @return void
	 */
	public function add_i18n_hooks() {
		// Load the plugin textdomain.
		add_action( 'after_setup_theme', array( $this->i18n_service, 'load_textdomain' ), 9999 );
		// Generate JSON translations.
		add_action( 'upgrader_process_complete', array( $this->i18n_service, 'update_json_translations' ), 10, 2 );
	}

	/**
	 * Add Rest Hooks.
	 *
	 * @since 2.0.0
	 */
	public function add_rest_hooks() {
		// Register rest routes.
		add_action( 'rest_api_init', array( $this->rest, 'register_rest_routes' ) );
	}
}
