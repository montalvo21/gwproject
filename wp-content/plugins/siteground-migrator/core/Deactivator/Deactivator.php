<?php

namespace SiteGround_Migrator\Deactivator;

use SiteGround_Migrator\Directory_Service\Directory_Service;
use SiteGround_Migrator\Transfer_Service\Transfer_Service;

/**
 * Class managing plugin deactivation.
 */
class Deactivator {

	/**
	 * Delete temp dirrectory upon plugin deactivation.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		$status = (int) get_option( 'siteground_migrator_transfer_status', false );

		// Cancel and reset the transfer if is still in progress while deactivating.
		if ( 1 === $status || 2 === $status ) {
			Transfer_Service::get_instance()->cancel_and_reset();
		}

		Directory_Service::get_instance()->remove_temp_dir();

		global $wpdb;

		// Delete the plugin options.
		$result = $wpdb->get_results( "
			DELETE
			FROM $wpdb->options
			WHERE `option_name` LIKE 'siteground_migrator_%'"
		);
	}
}
