<?php

namespace SiteGround_Migrator\Background_Process;

use SiteGround_Migrator\Helper\Log_Service_Trait;
use SiteGround_Migrator\Transfer_Service\Transfer_Service;
use SiteGround_Migrator\Background_Process\Siteground_WP_Background_Process;
/**
 * Provides functionallity to fire off non-blocking asynchronous requests as a background processes.
 */
class Background_Process extends Siteground_WP_Background_Process {
	use Log_Service_Trait;

	/**
	 * Action.
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $action = 'background_process';

	/**
	 * Task
	 *
	 * @param array $item Array containing the class and the
	 *                    method to call in background process.
	 *
	 * @return mixed      False on process success.
	 *                    The current item on failure, which will restart the process.
	 */
	protected function task( $item ) {
		$status = get_option( 'siteground_migrator_transfer_status' );

		// Cancel the transfer if any of the previous processes has failed.
		if ( 0 === $status['status'] ) {
			Transfer_Service::get_instance()->transfer_cancelled( false );
			return false;
		}

		// Bail if the method is not callable.
		if ( ! is_callable( array( $item['class'], $item['method'] ) ) ) {
			return false;
		}

		$attempts = intval( $item['attempts'] );

		// Retry until success or reaching max allowed attempts.
		for ( $i = 0; $i <= $attempts; $i++ ) {
			// Call the class method.
			$result = call_user_func( array( $item['class'], $item['method'] ) );

			// @todo: fina a way to improve this ugly condition.
			// Check for successful result or max attempts reached.
			if (
				1 === $result['status'] ||
				2 === $result['status'] ||
				$i === $attempts ||
				isset( $result['skip_retrying'] )
			) {
				Transfer_Service::update_status(
					$result['title'],
					$result['status'],
					isset( $result['description'] ) ? $result['description'] : ''
				);

				// Update the transfer progress.
				Transfer_Service::get_instance()->update_transfer_progress( 6 );

				// Remove process from queue.
				return false;
			}

			// Log error in case of failure and retry.
			$this->log_error( sprintf( 'Process failed : %s. Retrying...', $item['method'] ) );
		}

		// Remove the process from queue.
		return false;
	}

	/**
	 * Complete.
	 *
	 * @since 1.0.0
	 */
	protected function complete() {
		parent::complete();
	}
}
