<?php
/**
 * Public authentication REST endpoints.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Api;

use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Auth\OtpService;
use Anar\Login\Infrastructure\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class AuthController {
	const NAMESPACE = 'anar-login/v1';

	/** @var OtpService */
	private $otp;

	/** @var GoogleAuth */
	private $google;

	/** @var Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param OtpService $otp      OTP service.
	 * @param GoogleAuth $google   Google auth.
	 * @param Settings   $settings Settings.
	 */
	public function __construct( OtpService $otp, GoogleAuth $google, Settings $settings ) {
		$this->otp      = $otp;
		$this->google   = $google;
		$this->settings = $settings;
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
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/auth/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request_code' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'identity' => array( 'required' => true, 'type' => 'string' ),
					'website'  => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/auth/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_code' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'identity' => array( 'required' => true, 'type' => 'string' ),
					'code'     => array( 'required' => true, 'type' => 'string' ),
					'remember' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/google/start',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'google_start' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/google/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'google_callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Request OTP.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function request_code( WP_REST_Request $request ) {
		if ( $request->get_param( 'website' ) ) {
			return new WP_Error( 'anar_bot', __( 'درخواست نامعتبر است.', 'anar-login' ), array( 'status' => 400 ) );
		}

		return $this->otp->request( (string) $request->get_param( 'identity' ) );
	}

	/**
	 * Verify OTP.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function verify_code( WP_REST_Request $request ) {
		return $this->otp->verify(
			(string) $request->get_param( 'identity' ),
			(string) $request->get_param( 'code' ),
			(bool) $request->get_param( 'remember' )
		);
	}

	/**
	 * Redirect to Google.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function google_start( WP_REST_Request $request ) {
		$url = $this->google->start( (string) $request->get_param( 'redirect' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );

		return $response;
	}

	/**
	 * Handle Google callback and redirect safely.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function google_callback( WP_REST_Request $request ) {
		if ( $request->get_param( 'error' ) ) {
			return new WP_Error( 'anar_google_denied', __( 'ورود با گوگل لغو شد.', 'anar-login' ), array( 'status' => 400 ) );
		}

		$url = $this->google->callback(
			(string) $request->get_param( 'state' ),
			(string) $request->get_param( 'code' )
		);
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );

		return $response;
	}
}
