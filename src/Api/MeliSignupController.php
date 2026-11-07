<?php
/**
 * Administrator-only opt-in partner registration endpoints.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Api;

use Anar\Login\Partners\MeliSignup;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class MeliSignupController {

	public function routes() {
		foreach ( array( 'products' => 'GET', 'register' => 'POST' ) as $action => $method ) {
			register_rest_route( AuthController::NAMESPACE, '/admin/meli-signup/' . $action, array( 'methods' => $method, 'permission_callback' => array( $this, 'allowed' ), 'callback' => array( $this, $action ) ) );
		}
	}

	public function allowed( WP_REST_Request $request ) {
		return current_user_can( 'manage_options' ) && wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	public function products() {
		return ( new MeliSignup() )->products();
	}

	public function register( WP_REST_Request $request ) {
		return ( new MeliSignup() )->register( $request->get_json_params() );
	}
}
