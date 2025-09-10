<?php
namespace SiteGround_Migrator\Helper;

use SiteGround_i18n\i18n_Service;

/**
 * Trait used for factory pattern in the plugin.
 */
trait Factory_Trait {

	/**
	 * Create a new dependency.
	 *
	 * @since 2.0.0
	 *
	 * @param string $namespace        The namespace of the dependency.
	 * @param string $class (optional) The type of the dependency.
	 *
	 * @throws \Exception Exception If the type is not supported.
	 */
	public function factory( $namespace, $class = null ) {
		$path = str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $namespace ) ) );

		// Adding exception for i18n dependency.
		if ( 'i18n' === $namespace ) {
			$this->$namespace = new \SiteGround_i18n\i18n_Service( 'siteground-migrator' );
			return;
		}

		// Build the type and path for the dependency.
		if ( empty( $class ) ) {
			$type = $path;
		} else {
			$type = str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $class ) ) );
		}

		$class_path = 'SiteGround_Migrator\\' . $path . '\\' . $type;

		if ( ! class_exists( $class_path ) ) {
			throw new \Exception( 'Unknown dependency type "' . $type . '" in "' . $path . '".' );
		}

		// Define the class.
		if ( empty( $class ) ) {
			$this->$namespace = new $class_path();
		} else {
			$this->$class = new $class_path();
		}
	}
}
