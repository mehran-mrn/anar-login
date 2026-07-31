<?php
/**
 * Authenticated profile API.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Api;

use Anar\Login\Auth\UserService;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class ProfileController {
	/** @var UserService */
	private $users;

	/**
	 * Constructor.
	 *
	 * @param UserService $users Users.
	 */
	public function __construct( UserService $users ) {
		$this->users = $users;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register endpoint.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			AuthController::NAMESPACE,
			'/profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Update current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$user = $this->users->update_profile( get_current_user_id(), $request->get_json_params() );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return array(
			'message' => __( 'اطلاعات حساب ذخیره شد.', 'anar-login' ),
			'user'    => $this->users->profile( $user ),
		);
	}
}
