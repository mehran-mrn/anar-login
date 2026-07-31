<?php
/**
 * Google OAuth 2.0 authorization-code flow.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GoogleAuth {
	const COOKIE = 'anar_google_state';

	/** @var Settings */
	private $settings;

	/** @var UserService */
	private $users;

	/** @var RateLimiter */
	private $limiter;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param UserService $users    Users.
	 * @param RateLimiter $limiter  Limiter.
	 */
	public function __construct( Settings $settings, UserService $users, RateLimiter $limiter ) {
		$this->settings = $settings;
		$this->users    = $users;
		$this->limiter  = $limiter;
	}

	/**
	 * Build authorization URL and store CSRF state.
	 *
	 * @param string $redirect Requested post-login redirect.
	 * @return string|WP_Error
	 */
	public function start( $redirect = '' ) {
		if ( ! $this->settings->get( 'google_enabled', 0 ) ) {
			return new WP_Error( 'anar_google_disabled', __( 'Google sign-in is not enabled.', 'anar-login' ), array( 'status' => 404 ) );
		}

		$guard = $this->limiter->guard_google();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$client_id = (string) $this->settings->get( 'google_client_id', '' );
		$secret    = (string) $this->settings->get( 'google_secret', '' );
		if ( ! $client_id || ! $secret ) {
			return new WP_Error( 'anar_google_config', __( 'The Google sign-in settings are incomplete.', 'anar-login' ), array( 'status' => 503 ) );
		}

		$state    = bin2hex( random_bytes( 32 ) );
		$fallback = home_url( '/' );
		$redirect = wp_validate_redirect( $redirect, $fallback );

		set_transient(
			'anar_google_state_' . hash( 'sha256', $state ),
			array(
				'redirect' => $redirect,
				'ip'       => hash( 'sha256', $this->limiter->client_ip() ),
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->set_state_cookie( $state, time() + ( 10 * MINUTE_IN_SECONDS ) );

		return add_query_arg(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $this->callback_url(),
				'response_type' => 'code',
				'scope'         => 'openid email profile',
				'state'         => $state,
				'prompt'        => 'select_account',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
	}

	/**
	 * Complete OAuth callback.
	 *
	 * @param string $state State.
	 * @param string $code  Authorization code.
	 * @return string|WP_Error Safe final redirect.
	 */
	public function callback( $state, $code ) {
		$state  = sanitize_text_field( $state );
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$key    = 'anar_google_state_' . hash( 'sha256', $state );
		$saved  = get_transient( $key );

		delete_transient( $key );
		$this->set_state_cookie( '', time() - HOUR_IN_SECONDS );

		if ( ! $state || ! $cookie || ! hash_equals( $cookie, $state ) || ! is_array( $saved ) ) {
			return new WP_Error( 'anar_google_state', __( 'The Google sign-in request is invalid or has expired.', 'anar-login' ) );
		}

		if ( ! hash_equals( (string) $saved['ip'], hash( 'sha256', $this->limiter->client_ip() ) ) ) {
			return new WP_Error( 'anar_google_state', __( 'The sign-in request address has changed.', 'anar-login' ) );
		}

		$token = wp_safe_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => sanitize_text_field( $code ),
					'client_id'     => (string) $this->settings->get( 'google_client_id', '' ),
					'client_secret' => (string) $this->settings->get( 'google_secret', '' ),
					'redirect_uri'  => $this->callback_url(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $token ) ) {
			return new WP_Error( 'anar_google_token', __( 'Could not connect to Google.', 'anar-login' ) );
		}

		$token_data = json_decode( wp_remote_retrieve_body( $token ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $token ) || empty( $token_data['access_token'] ) ) {
			return new WP_Error( 'anar_google_token', __( 'Could not obtain sign-in authorization from Google.', 'anar-login' ) );
		}

		$profile = wp_safe_remote_get(
			'https://openidconnect.googleapis.com/v1/userinfo',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token_data['access_token'] ),
			)
		);
		if ( is_wp_error( $profile ) || 200 !== wp_remote_retrieve_response_code( $profile ) ) {
			return new WP_Error( 'anar_google_profile', __( 'Could not retrieve the Google profile.', 'anar-login' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $profile ), true );
		if ( empty( $data['email_verified'] ) ) {
			return new WP_Error( 'anar_google_email', __( 'The Google account email is not verified.', 'anar-login' ) );
		}

		$user = $this->users->login_google( $data, true );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return wp_validate_redirect( (string) $saved['redirect'], home_url( '/' ) );
	}

	/**
	 * Callback URL to add in Google Cloud Console.
	 *
	 * @return string
	 */
	public function callback_url() {
		return rest_url( 'anar-login/v1/google/callback' );
	}

	/**
	 * Set state cookie.
	 *
	 * @param string $value   Value.
	 * @param int    $expires Expiration.
	 * @return void
	 */
	private function set_state_cookie( $value, $expires ) {
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
