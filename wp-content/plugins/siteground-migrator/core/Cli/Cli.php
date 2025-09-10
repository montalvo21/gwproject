<?php

namespace SiteGround_Migrator\Cli;

/**
 * SG Migrator Cli plugin class.
 */
class Cli {

	/**
	 * Register the CLI Commands.
	 *
	 * @since  @version
	 */
	public function register_commands() {
		\WP_CLI::add_command( 'migrator start', 'SiteGround_Migrator\Cli\Cli_Migrator' );
	}
}
