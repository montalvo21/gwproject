<?php
namespace SiteGround_Migrator\Rest;

use Siteground_Migrator\Helper\Factory_Trait;
/**
 * Main Rest class.
 */
class Rest {
	use Factory_Trait;

	const REST_NAMESPACE = 'siteground-migrator/v1';

	/**
	 * Rest_Helper_Transfer_Service instance
	 *
	 * @var Rest_Helper_Transfer_Service
	 */
	public $rest_helper_transfer_service;

	/**
	 * Dependencies.
	 *
	 * @since 2.0.0
	 *
	 * @var array
	 */
	public $dependencies = array(
		'transfer_service' => 'rest_helper_transfer_service',
	);

	/**
	 * The constructor.
	 */
	public function __construct() {
		$this->load_dependencies();
	}

	/**
	 * Load the main plugin dependencies.
	 *
	 * @since  2.0.0
	 */
	public function load_dependencies() {
		foreach ( $this->dependencies as $dependency => $classes ) {
			$this->factory( 'rest', $classes );
		}
	}

	/**
	 * Check if a given request has admin access.
	 *
	 * @since  2.0.0
	 *
	 * @param  WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function check_permissions( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register REST routes.
	 *
	 * @since  2.0.0
	 */
	public function register_rest_routes() {
		foreach ( $this->dependencies as $dependency => $classes ) {
			call_user_func( array( $this, 'register_' . $dependency . '_rest_routes' ) );
		}

	}

	/**
	 * Register Transfer Status REST Routes.
	 *
	 * @since  2.0.0
	 */
	public function register_transfer_service_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/transfer-status/',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'get_transfer_status' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transfer-continue/',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'transfer_continue' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transfer-cancelled/',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'transfer_cancelled' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/initiate-new-transfer/',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'initiate_new_transfer' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transfer-token/',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'update_transfer_token' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transfer-token/',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'get_transfer_token' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/transfer-success/',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this->rest_helper_transfer_service, 'transfer_success' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);
	}
}
