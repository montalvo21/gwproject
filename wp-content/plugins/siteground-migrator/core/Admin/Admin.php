<?php

namespace SiteGround_Migrator\Admin;

use SiteGround_i18n\i18n_Service;
/**
 * Handle all hooks for our custom admin page.
 */
class Admin {

	/**
	 * The plugin pages ids.
	 *
	 * @var array
	 */
	public $plugin_pages = array(
		'toplevel_page_siteground-migrator',
		'toplevel_page_siteground-migrator-network',
	);

	/**
	 * Styles to be dequeued.
	 *
	 * @var array
	 */
	public $dequeued_styles = array(
		'auxin-front-icon', // Phlox Theme.
		'mks_shortcodes_simple_line_icons', // Meks Flexible Shortcodes.
		'onthego-admin-styles', // Toolset Types
		'foogra-icons', // Foogra Theme
	);

	/**
	 * The admin page slug
	 */
	const PAGE_SLUG = 'siteground_migrator_settings';

	/**
	 * Print the admin top menu styles.
	 *
	 * @since  2.0.0
	 */
	public function admin_print_styles() {
		// Bail if we are on different page.
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		$current_screen = \get_current_screen();

		// Remove notices.
		echo '<style>.notice { display:none!important; } </style>';

		// Get the current screen id.
		$id = strtoupper(
			str_replace(
				'-',
				'_',
				$current_screen->id
			)
		);

		// Check if it's the main page for the plugin, if so, rename the id to Home.
		if ( 'TOPLEVEL_PAGE_SITEGROUND_MIGRATOR' === $id ) {
			$id = 'Home';
		}

		$i18n_service = new i18n_Service( 'siteground-migrator' );

		// Collect data regarding the current plugin set up, urls, locales, etc.
		$data = array(
			'rest_base'  => untrailingslashit( get_rest_url( null, '/' ) ),
			'home_url'   => get_site_url(),
			'localeSlug' => join( '-', explode( '_', \get_user_locale() ) ),
			'locale'     => $i18n_service->get_i18n_data_json(),
			'wp_nonce'   => wp_create_nonce( 'wp_rest' ),
		);

		// Pass the serialized data and page_id.
		echo '<script>window.addEventListener("load", function(){ SGMigrator.init({ domElementId: "root", page: SGMigrator.PAGE.' . $id . ',config:' . json_encode( $data ) . '})});</script>';
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    2.0.0
	 */
	public function enqueue_styles() {
		echo '<style>.toplevel_page_siteground-migrator.menu-top .wp-menu-image img { width:20px; } </style>';

		// Bail if this is not our settgins page.
		if ( false === $this->is_plugin_page() ) {
			return;
		}

		// Dequeue conflicting styles.
		foreach ( $this->dequeued_styles as $style ) {
			wp_dequeue_style( $style );
		}

		wp_enqueue_style(
			'siteground-migrator-admin',
			\SiteGround_Migrator\URL . '/assets/css/main.min.css',
			array(),
			\SiteGround_Migrator\VERSION,
			'all'
		);
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    2.0.0
	 */
	public function enqueue_scripts() {
		// Bail if this is not our settgins page.
		if ( false === $this->is_plugin_page() ) {
			return;
		}

		// Enqueue the siteground-migrator script.
		wp_enqueue_script(
			'siteground-migrator-admin',
			\SiteGround_Migrator\URL . '/assets/js/main.min.js',
			array( 'jquery' ), // Dependencies.
			\SiteGround_Migrator\VERSION,
			true
		);
	}

	/**
	 * Title of the settings page.
	 *
	 * @since 2.0.0
	 *
	 * @return string The title of the settings page.
	 */
	public static function get_page_title() {
		return __( 'SiteGround Migrator', 'siteground-migrator' );
	}

	/**
	 * Add the plugin options page.
	 *
	 * @since 2.0.0
	 */
	public function add_menu_page() {
		add_menu_page(
			self::get_page_title(), // Page title.
			'SG Migrator', // Menu item title.
			'manage_options', // Capability.
			\SiteGround_Migrator\PLUGIN_SLUG, // Page slug.
			array( $this, 'display_settings_page' ), // Output function.
			\SiteGround_Migrator\URL . '/assets/img/icon.svg'
		);

		// register settings section.
		add_settings_section(
			self::PAGE_SLUG,
			__( 'Website Migration Settings', 'siteground-migrator' ),
			'',
			self::PAGE_SLUG
		);
	}

	/**
	 * Output the settings page content.
	 *
	 * @since  2.0.0
	 */
	public function display_settings_page() {
		echo '<div id="root"></div>';
	}

	/**
	 * Check if this is the SiteGround Migrator page.
	 *
	 * @since  2.0.0
	 *
	 * @return bool True/False
	 */
	public function is_plugin_page() {
		// Bail if the page is not an admin screen.
		if ( ! is_admin() ) {
			return false;
		}

		$current_screen = \get_current_screen();

		if ( in_array( $current_screen->id, $this->plugin_pages ) ) {
			return true;
		}

		return false;
	}
}
