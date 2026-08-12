<?php
/**
 * Rate limiting using the object cache/transients.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Enforce resend and hourly limits for identity and IP.
	 *
	 * @param string $identity Normalized identity.
	 * @return true|WP_Error
	 */
	public function guard_request( $identity ) {
		$resend = absint( $this->settings->get( 'resend_delay', 60 ) );
		$hourly = absint( $this->settings->get( 'max_requests_hour', 5 ) );
		$ip     = $this->client_ip();
		$key    = $this->key( 'send_identity', $identity );
		$ip_key = $this->key( 'send_ip', $ip );

		if ( get_transient( $key . '_cooldown' ) ) {
			return new WP_Error(
				'anar_too_soon',
				sprintf(
					/* translators: %d: seconds */
					__( 'Please wait %d seconds before requesting another code.', 'anar-login' ),
					$resend
				),
				array( 'status' => 429 )
			);
		}

		if ( $this->increment( $key, HOUR_IN_SECONDS ) > $hourly || $this->increment( $ip_key, HOUR_IN_SECONDS ) > ( $hourly * 4 ) ) {
			return new WP_Error( 'anar_rate_limited', __( 'Too many requests. Please try again later.', 'anar-login' ), array( 'status' => 429 ) );
		}

		set_transient( $key . '_cooldown', 1, $resend );

		return true;
	}

	/**
	 * Limit Google auth starts per IP.
	 *
	 * @return true|WP_Error
	 */
	public function guard_google() {
		if ( $this->increment( $this->key( 'google', $this->client_ip() ), HOUR_IN_SECONDS ) > 20 ) {
			return new WP_Error( 'anar_rate_limited', __( 'Too many requests.', 'anar-login' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Client address, filterable for trusted proxy setups.
	 *
	 * @return string
	 */
	public function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return (string) apply_filters( 'anar_login_client_ip', $ip );
	}

	/**
	 * Increment one fixed-window counter.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Lifetime.
	 * @return int
	 */
	private function increment( $key, $ttl ) {
		$value = absint( get_transient( $key ) );
		++$value;
		set_transient( $key, $value, $ttl );

		return $value;
	}

	/**
	 * Build private cache key.
	 *
	 * @param string $scope Scope.
	 * @param string $value Sensitive input.
	 * @return string
	 */
	private function key( $scope, $value ) {
		return 'anar_' . sanitize_key( $scope ) . '_' . hash_hmac( 'sha256', (string) $value, wp_salt( 'nonce' ) );
	}
}
