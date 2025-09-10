<?php

namespace SiteGround_Migrator\Cli;

use SiteGround_Migrator\Transfer_Service\Transfer_Service;
/**
 * The Cli migrator command class.
 */
class Cli_Migrator {

	/**
	 * SiteGround Migrator command.
	 *
	 * ## OPTIONS
	 *
	 * <token>
	 * : Transfer token.
	 *
	 * [--email=<email>]
	 * : Email address.
	 */
	public function __invoke( $args, $assoc_args ) {
		// Post args.
		$args = array(
			'siteground_migrator_transfer_token' => $args[0],
			'siteground_migrator_update_options' => wp_create_nonce( 'siteground_migrator_options' ),
		);

		// Check for email args.
		if ( ! empty( $assoc_args['email'] ) ) {
			// Bail if the provided email is invalid.
			if ( ! filter_var( $assoc_args['email'], FILTER_VALIDATE_EMAIL ) ) {
				WP_CLI::error( 'Please enter valid email address.' );
			}

			// Add the email args if the email is ok.
			$args['siteground_migrator_send_email_notification'] = 'yes';
			$args['siteground_migrator_email_recipient']         = $assoc_args['email'];

			// Set user preferences for email notification.
			update_option(
				'siteground_migrator_send_email_notification',
				true === $args['siteground_migrator_send_email_notification'] ? 'yes' : 'no'
			);

			// Update the email recipient option.
			update_option(
				'siteground_migrator_email_recipient',
				sanitize_text_field( wp_unslash( $args['siteground_migrator_email_recipient'] ) )
			);

		}

		// Update the token.
		update_option(
			'siteground_migrator_transfer_token',
			sanitize_text_field( wp_unslash( $args['siteground_migrator_transfer_token'] ) )
		);

		// Start the transfer.
		$transfer_service = new Transfer_Service();
		$transfer_service->transfer_start();

		// Wait for option to be updated.
		sleep( 1 );

		// Get the status after the request completes.
		$status = get_option( 'siteground_migrator_transfer_status' );

		if ( false === $status ) {
			\WP_CLI::error( esc_html__( 'Can not initiate the transfer.', 'siteground-migrator' ) );
		}

		switch ( $status['status'] ) {
			// Show the error if the status is 0.
			case 0:
				\WP_CLI::error( $status['message'] . '. ' . $status['description'] );
				break;

			case 5:
				Transfer_Service::get_instance()->transfer_continue();
				\WP_CLI::success( esc_html__( 'Transfer started. Creating archives of files...', 'siteground-migrator' ) );
				break;

			default:
				// Show success message.
				\WP_CLI::success( $status['message'] . '. ' . $status['description'] );
				break;
		}
	}
}
