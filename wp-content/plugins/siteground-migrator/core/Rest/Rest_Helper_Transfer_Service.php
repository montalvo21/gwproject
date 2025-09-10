<?php
namespace SiteGround_Migrator\Rest;

use SiteGround_Migrator\Transfer_Service\Transfer_Service;
use SiteGround_Migrator\Rest\Rest_Helper;
use SiteGround_Migrator\Helper\Helper;

/**
 * Rest Helper class that manages Transfer Service options.
 */
class Rest_Helper_Transfer_Service extends Rest_Helper {
	/**
	 * Transfer_Serivce instance.
	 *
	 * @var Transfer_Service
	 */
	public $transfer_service;

	/**
	 * The constructor.
	 */
	public function __construct() {
		$this->transfer_service = new Transfer_Service();
	}

	/**
	 * Get the transfer status.
	 *
	 * @since 2.0.0
	 */
	public function get_transfer_status() {
		// Send the response.
		self::send_json_success(
			'',
			array(
				'transfer_status'   => get_option( 'siteground_migrator_transfer_status', array() ),
				'transfer_progress' => get_option( 'siteground_migrator_progress', 0 ),
			)
		);
	}

	/**
	 * Resume the transfer.
	 *
	 * @since  2.0.0
	 */
	public function transfer_continue() {
		// Adding this as a local variable, for it to be compatible with PHP version < 7.0
		$transfer_service = $this->transfer_service;

		// Update the status, that transfer has started.
		$transfer_service::update_status( esc_html__( 'Transfer started. Creating archives of files...', 'siteground-migrator' ) );

		$transfer_service->run_background_processes();

		// Send a message to the front-end.
		self::send_json_success( esc_html__( 'Transfer started. Creating archives of files...', 'siteground-migrator' ) );
	}

	/**
	 * Cancel the transfer.
	 *
	 * @param object $request Body of the request.
	 *
	 * @since  2.0.0
	 */
	public function transfer_cancelled( $request ) {
		// Invalidate the token.
		$body = json_decode( sanitize_text_field( wp_unslash( $request->get_body() ) ), true );

		$this->transfer_service->cancel_and_reset();

		// Send the response.
		self::send_json_success(
			esc_html__( 'Transfer cancelled.', 'siteground-migrator' ),
			array(
				'success' => 'true',
			)
		);
	}

	/**
	 * Initiate new transfer.
	 *
	 * @since  2.0.0
	 *
	 * @param  object $request The request.
	 */
	public function initiate_new_transfer( $request ) {
		// Invalidate the token.
		$body = json_decode( sanitize_text_field( wp_unslash( $request->get_body() ) ), true );

		$this->transfer_service->cancel_and_reset();

		// Send the response.
		self::send_json_success(
			'',
			array(
				'success' => 'true',
			)
		);
	}

	/**
	 * Get the transfer token.
	 *
	 * @since  2.0.0
	 */
	public function get_transfer_token() {
		// Get the transfer status.
		$transfer_token = get_option( 'siteground_migrator_transfer_token', '' );

		// Send the response.
		self::send_json_success(
			'',
			array(
				'success' => 'true',
				'data'    => array(
					'transfer_token' => $transfer_token,
				),
			)
		);
	}

	/**
	 * Update the transfer token.
	 *
	 * @param object $request The request sent by the client.
	 *
	 * @since 2.0.0
	 */
	public function update_transfer_token( $request ) {
		$data = json_decode( sanitize_text_field( wp_unslash( $request->get_body() ) ), true );

		// Bail and do not start the transfer if environment has isuses.
		if ( true === $this->transfer_service->check_environment_before_transfer() ) {
			return;
		}

		// Bail if the transfer token is empty.
		if ( empty( $data ) || empty( $data['transfer_token'] ) ) {
			self::send_json_error(
				__( 'Transfer Token Missing.', 'siteground-migrator' )
			);
		}

		// Update the token.
		update_option(
			'siteground_migrator_transfer_token',
			sanitize_text_field( wp_unslash( $data['transfer_token'] ) )
		);

		// Set user preferences for email notification.
		update_option(
			'siteground_migrator_send_email_notification',
			true === $data['send_email_notification'] ? 'yes' : 'no'
		);

		// Update the token.
		update_option(
			'siteground_migrator_email_recipient',
			sanitize_text_field( wp_unslash( $data['email_recepient'] ) )
		);

		// Clear LiteSpeed cache, if existing.
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			\LiteSpeed\Purge::purge_all();
		}

		// Start the transfer.
		$this->transfer_service->transfer_start();

		// Send the response.
		self::send_json_success(
			__( 'Transfer Started.', 'siteground-migrator' ),
			array(
				'success' => 'true',
				'data'    => array(
					'transfer_token'          => $data['transfer_token'],
					'send_email_notification' => $data['send_email_notification'],
					'email_recipient'         => $data['email_recepient'],
				),
			)
		);
	}

	/**
	 * Get the setup info when transfer is succesful.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function transfer_success() {
		// Get current status.
		$status = get_option( 'siteground_migrator_transfer_status' );
		// Get new URL.
		$temp_url = ! empty( $status['temp_url'] ) ? $status['temp_url'] : '';
		// Get the new nameservers.
		$new_nameservers = ! empty( $status['dns_servers'] ) ? $status['dns_servers'] : array();
		// Check if previous hosting is SiteGround.
		$is_siteground = get_option( 'siteground_migrator_is_siteground_env', false );

		// Set initial data.
		$data = array(
			'success' => 1,
			'data'    => array(
				'new_nameservers' => $new_nameservers,
				'temp_url'        => $temp_url,
			),
		);

		if (
			$is_siteground ||
			! empty( get_option( 'sg_migrator_colibri', false ) )
		) {
			// Send the response.
			self::send_json_success(
				__( 'Transfer Completed Successfully!', 'siteground-migrator' ),
				$data
			);
		}

		$new_sitespeed = array(
			'mobile'  => Helper::get_sitespeed( $temp_url, 'mobile' ),
			'desktop' => Helper::get_sitespeed( $temp_url, 'desktop' ),
		);
		$old_sitespeed = array(
			'mobile'  => Helper::get_sitespeed( get_home_url( '/' ), 'mobile' ),
			'desktop' => Helper::get_sitespeed( get_home_url( '/' ), 'desktop' ),
		);

		// Check all SiteSpeed tests, bail before adding the results, if any are missing.
		if (
			false === $new_sitespeed['mobile'] ||
			false === $new_sitespeed['desktop'] ||
			false === $old_sitespeed['mobile'] ||
			false === $old_sitespeed['desktop']
		) {
			// Send the response.
			self::send_json_success(
				__( 'Transfer Completed Successfully!', 'siteground-migrator' ),
				$data
			);
		}

		if (
			( ! empty( $new_sitespeed ) && ! empty( $old_sitespeed ) ) &&
			(
				$new_sitespeed['desktop'] > $old_sitespeed['desktop'] ||
				( $new_sitespeed['desktop'] + 1000 ) > $old_sitespeed['desktop']  ||
				$new_sitespeed['desktop'] > 2000
			)
		) {
			// Send the response.
			self::send_json_success(
				__( 'Transfer Completed Successfully!', 'siteground-migrator' ),
				$data
			);
		}

		$data['data']['sitespeed'] = array(
			'new' => $new_sitespeed,
			'old' => $old_sitespeed,
		);

		// Send the response.
		self::send_json_success(
			__( 'Transfer Completed Successfully!', 'siteground-migrator' ),
			$data
		);
	}

}
