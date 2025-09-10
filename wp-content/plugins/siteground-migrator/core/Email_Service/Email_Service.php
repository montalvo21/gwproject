<?php

namespace SiteGround_Migrator\Email_Service;

/**
 * The email service class.
 */
class Email_Service {

	/**
	 * Send notification to the user.
	 *
	 * @since  1.0.0
	 *
	 * @param  string $recipient The email recipient.
	 * @param  string $subject   The email subject.
	 * @param  string $message   The email message.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function send_email( $recipient, $subject, $message ) {
		return wp_mail(
			$recipient,
			$subject,
			$message,
			array(
				'Content-Type: text/html; charset=UTF-8',
			)
		);
	}

	/**
	 * Prepare and send notifcaiton to the site admin, when the transfer is completed.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $data Array of data from the SiteGround api.
	 */
	public function prepare_and_send_notification( $data ) {

		// Prepare the options.
		$send_notification = get_option( 'siteground_migrator_send_email_notification' );
		$recipient         = get_option( 'siteground_migrator_email_recipient' );

		// Bail if the user has selected to not send notifications.
		if (
			'no' === $send_notification ||
			false === is_email( $recipient )
		) {
			return;
		}

		// We send notication on success/failure only.
		switch ( $data['status'] ) {
			case 0:
				// Send notification that transfer has failed.
				$subject = esc_html__( 'Migration to SiteGround Failed', 'siteground-migrator' );
				$file    = 'sg_migrator_failed.php';
				break;
			case 3:
				// Send notification that transfer is completed successfully.
				$subject = esc_html__( 'Migration to SiteGround Completed', 'siteground-migrator' );
				$file    = 'sg_migrator_successful.php';
				break;
			case 4:
				// Send notification that transfer is completed with errors.
				$subject = esc_html__( 'Migration to SiteGround completed, some files could not be transferred', 'siteground-migrator' );
				$file    = 'sg_migrator_successful_errors.php';
				break;
			// Do not send anything is the transfer is in progress.
			default:
				return;
		}

		// Turn on output buffering.
		ob_start();

		// Include the email template.
		include \SiteGround_Migrator\DIR . '/templates/email-templates/' . $file;

		// Get current buffer contents and delete current output buffer.
		$message = ob_get_clean();

		// Send the email.
		$this->send_email(
			$recipient,
			$subject,
			$message
		);
	}
}
