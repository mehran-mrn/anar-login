<?php
/**
 * Google OAuth 2.0 authorization-code flow with PKCE.
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

	/**
	 * Query argument that carries a failed sign-in back to the login page.
	 */
	const ERROR_ARG = 'anar_login_error';

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
	 * Messages for the error codes the callback can send back to the login page.
	 *
	 * @return array<string,string>
	 */
	public static function error_messages() {
		return array(
			'google_cancelled'   => __( 'Google sign-in was cancelled.', 'anar-toolkit' ),
			'google_expired'     => __( 'The Google sign-in request is invalid or has expired.', 'anar-toolkit' ),
			'google_unverified'  => __( 'The Google account email is not verified.', 'anar-toolkit' ),
			'google_failed'      => __( 'Could not complete Google sign-in. Please try again.', 'anar-toolkit' ),
			'registration_off'   => __( 'Registration for new users is disabled.', 'anar-toolkit' ),
			'account_blocked'    => __( 'This account cannot sign in.', 'anar-toolkit' ),
		);
	}

	/**
	 * Build authorization URL and store CSRF state.
	 *
	 * @param string $redirect Requested post-login redirect.
	 * @param string $return   Page to return to when sign-in fails.
	 * @return string|WP_Error
	 */
	public function start( $redirect = '', $return = '' ) {
		if ( ! $this->settings->get( 'google_enabled', 0 ) ) {
			return new WP_Error( 'anar_google_disabled', __( 'Google sign-in is not enabled.', 'anar-toolkit' ), array( 'status' => 404 ) );
		}

		$guard = $this->limiter->guard_google();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$client_id = (string) $this->settings->get( 'google_client_id', '' );
		$secret    = (string) $this->settings->get( 'google_secret', '' );
		if ( ! $client_id || ! $secret ) {
			return new WP_Error( 'anar_google_config', __( 'The Google sign-in settings are incomplete.', 'anar-toolkit' ), array( 'status' => 503 ) );
		}

		$state    = bin2hex( random_bytes( 32 ) );
		$verifier = $this->base64url( random_bytes( 48 ) );

		// The state is bound to an HttpOnly cookie rather than the IP address, which
		// changes too often on mobile networks to be a reliable binding.
		set_transient(
			'anar_google_state_' . hash( 'sha256', $state ),
			array(
				'redirect' => $redirect ? wp_validate_redirect( (string) $redirect, '' ) : '',
				'return'   => wp_validate_redirect( remove_query_arg( self::ERROR_ARG, (string) $return ), $this->fallback_return() ),
				'verifier' => $verifier,
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->set_state_cookie( $state, time() + ( 10 * MINUTE_IN_SECONDS ) );

		return add_query_arg(
			array(
				'client_id'             => rawurlencode( $client_id ),
				'redirect_uri'          => rawurlencode( $this->callback_url() ),
				'response_type'         => 'code',
				'scope'                 => rawurlencode( 'openid email profile' ),
				'state'                 => $state,
				'code_challenge'        => $this->base64url( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
				'prompt'                => 'select_account',
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
	}

	/**
	 * Complete OAuth callback.
	 *
	 * Always resolves to a URL: the post-login destination on success, or the
	 * originating page with an error code that the login form displays.
	 *
	 * @param string $state State.
	 * @param string $code  Authorization code.
	 * @param string $error Error reported by Google, if any.
	 * @return string Safe final redirect.
	 */
	public function callback( $state, $code, $error = '' ) {
		$state  = sanitize_text_field( $state );
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$key    = 'anar_google_state_' . hash( 'sha256', $state );
		$saved  = $state ? get_transient( $key ) : false;

		delete_transient( $key );
		$this->set_state_cookie( '', time() - HOUR_IN_SECONDS );

		if ( ! $state || ! $cookie || ! hash_equals( $cookie, $state ) || ! is_array( $saved ) ) {
			return $this->error_url( 'google_expired' );
		}

		$return = (string) $saved['return'];
		if ( $error ) {
			return $this->error_url( 'google_cancelled', $return );
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
					'code_verifier' => (string) $saved['verifier'],
				),
			)
		);
		$token_data = is_wp_error( $token ) ? array() : json_decode( wp_remote_retrieve_body( $token ), true );
		if ( is_wp_error( $token ) || 200 !== wp_remote_retrieve_response_code( $token ) || empty( $token_data['access_token'] ) ) {
			return $this->error_url( 'google_failed', $return );
		}

		$profile = wp_safe_remote_get(
			'https://openidconnect.googleapis.com/v1/userinfo',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token_data['access_token'] ),
			)
		);
		if ( is_wp_error( $profile ) || 200 !== wp_remote_retrieve_response_code( $profile ) ) {
			return $this->error_url( 'google_failed', $return );
		}

		$data = json_decode( wp_remote_retrieve_body( $profile ), true );
		if ( ! is_array( $data ) || empty( $data['email_verified'] ) ) {
			return $this->error_url( 'google_unverified', $return );
		}

		$user = $this->users->login_google( $data, true );
		if ( is_wp_error( $user ) ) {
			if ( 'anar_factor_required' === $user->get_error_code() ) {
				$data = $user->get_error_data();
				return add_query_arg( 'redirect_to', rawurlencode( wp_validate_redirect( $saved['redirect'], '' ) ), $data['redirect'] );
			}
			$codes = array(
				'anar_registration_disabled' => 'registration_off',
				'anar_account_blocked'       => 'account_blocked',
			);

			return $this->error_url( $codes[ $user->get_error_code() ] ?? 'google_failed', $return );
		}

		return $this->users->redirect_url( $user, (string) $saved['redirect'] );
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
	 * Login page URL carrying an error code.
	 *
	 * @param string $error  Key from error_messages().
	 * @param string $return Originating page.
	 * @return string
	 */
	private function error_url( $error, $return = '' ) {
		$return = $return ? $return : $this->fallback_return();

		return add_query_arg( self::ERROR_ARG, $error, wp_validate_redirect( $return, home_url( '/' ) ) );
	}

	/**
	 * Where to send users when the originating page is unknown.
	 *
	 * @return string
	 */
	private function fallback_return() {
		$panel_id = absint( $this->settings->get( 'panel_page_id', 0 ) );
		$panel    = $panel_id ? get_permalink( $panel_id ) : '';

		return $panel ? $panel : home_url( '/' );
	}

	/**
	 * URL-safe base64 without padding (RFC 7636).
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE encoding.
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
