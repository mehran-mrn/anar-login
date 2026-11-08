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
				'args'                => array(
					'display_name' => array( 'required' => true, 'type' => 'string' ),
					'first_name'   => array( 'required' => false, 'type' => 'string' ),
					'last_name'    => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
		// Right after sign-up the browser has no REST nonce yet; a browser-bound one-time flow authorizes the name step.
		register_rest_route(
			AuthController::NAMESPACE,
			'/profile/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'      => array( 'required' => true, 'type' => 'string' ),
					'first_name' => array( 'required' => true, 'type' => 'string' ),
					'last_name'  => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Name step offered to a just-registered account, or null.
	 *
	 * @param \WP_User $user Account created by this sign-in.
	 * @param string   $mode off, optional or required.
	 * @return array<string,mixed>|null
	 */
	public static function completion( $user, $mode ) {
		if ( ! in_array( $mode, array( 'optional', 'required' ), true ) || ( $user->first_name && $user->last_name ) ) {
			return null;
		}
		$token = ( new \Anar\Login\Auth\LoginFlowService() )->issue( 'profile_complete', array( 'user_id' => (int) $user->ID ), 600 );
		if ( is_wp_error( $token ) ) {
			return null;
		}

		return array(
			'token'    => $token,
			'required' => 'required' === $mode,
			'strings'  => array(
				'title' => __( 'Welcome! What should we call you?', 'anar-toolkit' ),
				'first' => __( 'First name', 'anar-toolkit' ),
				'last'  => __( 'Last name', 'anar-toolkit' ),
				'save'  => __( 'Save and continue', 'anar-toolkit' ),
				'skip'  => __( 'Skip for now', 'anar-toolkit' ),
			),
		);
	}

	/**
	 * Save the name step for the account that just signed up.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,string>|WP_Error
	 */
	public function complete( WP_REST_Request $request ) {
		$flows   = new \Anar\Login\Auth\LoginFlowService();
		$token   = (string) $request->get_param( 'token' );
		$attempt = $flows->attempt( $token, 'profile_complete' );
		$first   = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last    = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
		if ( ! $attempt ) {
			return new WP_Error( 'anar_profile_expired', __( 'This step has expired. You can add your name from your account page.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		if ( '' === $first || '' === $last || mb_strlen( $first ) > 60 || mb_strlen( $last ) > 60 ) {
			return new WP_Error( 'anar_profile_name', __( 'Enter your first and last name.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		if ( ! $flows->consume( $token, 'profile_complete', $attempt ) ) {
			return new WP_Error( 'anar_profile_expired', __( 'This step has expired. You can add your name from your account page.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		$user = $this->users->update_profile(
			(int) $attempt['data']['user_id'],
			array(
				'display_name' => $first . ' ' . $last,
				'first_name'   => $first,
				'last_name'    => $last,
			)
		);
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( function_exists( 'WC' ) ) {
			// Prefill checkout with the same name.
			foreach ( array( 'billing_first_name' => $first, 'billing_last_name' => $last ) as $key => $value ) {
				if ( ! get_user_meta( $user->ID, $key, true ) ) {
					update_user_meta( $user->ID, $key, $value );
				}
			}
		}

		return array( 'message' => __( 'Your account information has been saved.', 'anar-toolkit' ) );
	}

	/**
	 * Update current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$user = $this->users->update_profile(
			get_current_user_id(),
			array(
				'display_name' => $request->get_param( 'display_name' ),
				'first_name'   => $request->get_param( 'first_name' ),
				'last_name'    => $request->get_param( 'last_name' ),
			)
		);
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return array(
			'message' => __( 'Your account information has been saved.', 'anar-toolkit' ),
			'user'    => $this->users->profile( $user ),
		);
	}
}
