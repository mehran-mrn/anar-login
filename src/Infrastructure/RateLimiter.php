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

		if ( ! $this->claim( $key . '_cooldown', $resend, 1 ) ) {
			return new WP_Error(
				'anar_too_soon',
				sprintf(
					/* translators: %d: seconds */
					__( 'Please wait %d seconds before requesting another code.', 'anar-toolkit' ),
					$resend
				),
				array( 'status' => 429 )
			);
		}

		if ( ! $this->claim( $key, HOUR_IN_SECONDS, $hourly ) || ! $this->claim( $ip_key, HOUR_IN_SECONDS, $hourly * 4 ) ) {
			return new WP_Error( 'anar_rate_limited', __( 'Too many requests. Please try again later.', 'anar-toolkit' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Lift the resend cooldown after a failed delivery so the user can retry at once.
	 *
	 * Hourly counters are kept, so a failing gateway still cannot be hammered.
	 *
	 * @param string $identity Normalized identity.
	 * @return void
	 */
	public function release( $identity ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'anar_login_limits', array( 'limit_key' => $this->key( 'send_identity', $identity ) . '_cooldown' ), array( '%s' ) );
	}

	/**
	 * Limit Google auth starts per IP.
	 *
	 * @return true|WP_Error
	 */
	public function guard_google() {
		if ( ! $this->claim( $this->key( 'google', $this->client_ip() ), HOUR_IN_SECONDS, 20 ) ) {
			return new WP_Error( 'anar_rate_limited', __( 'Too many requests.', 'anar-toolkit' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/** Limit recovery guesses across fresh browser sessions and code replacements. */
	public function guard_recovery( $identity ) {
		if ( ! $this->claim( $this->key( 'recovery_ip', $this->client_ip() ), 15 * MINUTE_IN_SECONDS, 30 ) || ! $this->claim( $this->key( 'recovery_identity', $identity ), HOUR_IN_SECONDS, 10 ) ) {
			return new WP_Error( 'anar_recovery_rate_limited', __( 'Too many recovery attempts. Please try again later.', 'anar-toolkit' ) );
		}
		return true;
	}

	public function guard_passkey() {
		return $this->claim( $this->key( 'passkey', $this->client_ip() ), 300, 30 ) ? true : new WP_Error( 'anar_rate_limited', __( 'Too many requests. Please try again later.', 'anar-toolkit' ), array( 'status' => 429 ) );
	}

	/** Bound authenticator guesses across freshly issued flows. */
	public function guard_factor( $user_id ) {
		if ( ! $this->claim( $this->key( 'factor_user', $user_id ), 300, 10 ) || ! $this->claim( $this->key( 'factor_ip', $this->client_ip() ), 900, 100 ) ) {
			return new WP_Error( 'anar_rate_limited', __( 'Too many requests. Please try again later.', 'anar-toolkit' ), array( 'status' => 429 ) );
		}
		return true;
	}

	public function clear_factor( $user_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'anar_login_limits', array( 'limit_key' => $this->key( 'factor_user', $user_id ) ), array( '%s' ) );
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
	 * Reserve one request atomically inside an expiring database window.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Lifetime.
	 * @param int    $max Maximum requests.
	 * @return bool
	 */
	private function claim( $key, $ttl, $max ) {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_limits';
		$now   = time();
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE limit_key = %s AND expires_at <= %d',
				$table,
				$key,
				$now
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (limit_key, attempts, expires_at) VALUES (%s, 0, %d)',
				$table,
				$key,
				$now + $ttl
			)
		);

		return 1 === $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = attempts + 1 WHERE limit_key = %s AND attempts < %d AND expires_at > %d',
				$table,
				$key,
				$max,
				$now
			)
		);
	}

	/**
	 * Remove expired limits during scheduled maintenance.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_limits';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %d', $table, time() ) );
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
