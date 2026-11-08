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
	 * The network address is checked first, so one address cannot spend a
	 * victim's hourly allowance. The hourly allowance is shared by every
	 * purpose (login, recovery, linking, alerts), so adding features never
	 * multiplies how many codes one number can receive; the resend cooldown
	 * stays per purpose so switching tasks is not blocked.
	 *
	 * @param string $identity Normalized identity.
	 * @param string $scope    Purpose, e.g. "password"; empty for sign-in.
	 * @return true|WP_Error
	 */
	public function guard_request( $identity, $scope = '' ) {
		$resend = absint( $this->settings->get( 'resend_delay', 60 ) );
		$hourly = absint( $this->settings->get( 'max_requests_hour', 5 ) );
		$key    = $this->key( 'send_identity', $identity );

		if ( ! $this->claim( $this->cooldown_key( $identity, $scope ), $resend, 1 ) ) {
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

		if ( ! $this->claim( $this->ip_key(), HOUR_IN_SECONDS, $hourly * 4 ) || ! $this->claim( $key, HOUR_IN_SECONDS, $hourly ) ) {
			return new WP_Error( 'anar_rate_limited', __( 'Too many requests. Please try again later.', 'anar-toolkit' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Codes this network address requested in the current hour.
	 *
	 * @return int
	 */
	public function ip_requests() {
		return $this->attempts( $this->ip_key() );
	}

	/**
	 * Count a site-wide code delivery and return the hour's total.
	 *
	 * @return int
	 */
	public function count_site_request() {
		$key = $this->key( 'send_site', 'all' );
		$this->claim( $key, HOUR_IN_SECONDS, PHP_INT_MAX );

		return $this->attempts( $key );
	}

	/**
	 * Codes sent site-wide in the current hour.
	 *
	 * @return int
	 */
	public function site_requests() {
		return $this->attempts( $this->key( 'send_site', 'all' ) );
	}

	/**
	 * Allow a value exactly once within a window (replay protection).
	 *
	 * @param string $scope Scope.
	 * @param string $value Value.
	 * @param int    $ttl   Window.
	 * @return bool
	 */
	public function once( $scope, $value, $ttl ) {
		return $this->allow( $scope, $value, $ttl, 1 );
	}

	/**
	 * Allow up to $max uses of a value within a window.
	 *
	 * @param string $scope Scope.
	 * @param string $value Value.
	 * @param int    $ttl   Window.
	 * @param int    $max   Maximum uses.
	 * @return bool
	 */
	public function allow( $scope, $value, $ttl, $max ) {
		return $this->claim( $this->key( $scope, $value ), $ttl, $max );
	}

	/**
	 * Lift the resend cooldown after a failed delivery so the user can retry at once.
	 *
	 * Hourly counters are kept, so a failing gateway still cannot be hammered.
	 *
	 * @param string $identity Normalized identity.
	 * @param string $scope    Purpose used at request time.
	 * @return void
	 */
	public function release( $identity, $scope = '' ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'anar_login_limits', array( 'limit_key' => $this->cooldown_key( $identity, $scope ) ), array( '%s' ) );
	}

	/** Per-purpose resend cooldown key; sign-in keeps its original key. */
	private function cooldown_key( $identity, $scope ) {
		$scope = sanitize_key( (string) $scope );

		return $this->key( 'send_identity', '' === $scope ? $identity : $scope . '|' . $identity ) . '_cooldown';
	}

	private function ip_key() {
		return $this->key( 'send_ip', $this->client_ip() );
	}

	/** Current attempts in a live window. */
	private function attempts( $key ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT attempts FROM %i WHERE limit_key = %s AND expires_at > %d', $wpdb->prefix . 'anar_login_limits', $key, time() ) );
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
	 * Client address for limits.
	 *
	 * Behind a CDN or reverse proxy (ArvanCloud, Cloudflare, a load balancer)
	 * REMOTE_ADDR is the proxy, so every visitor would share one limit. The
	 * administrator can name the header that carries the visitor address.
	 * IPv6 visitors are grouped by /64, the smallest block a subscriber gets.
	 *
	 * @return string
	 */
	public function client_ip() {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$header = self::proxy_header( (string) $this->settings->get( 'client_ip_header', '' ) );
		if ( $header ) {
			$server = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
			$value  = isset( $_SERVER[ $server ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $server ] ) ) : '';
			// X-Forwarded-For lists the client first; the proxy appends the hops it saw.
			$value = trim( explode( ',', $value )[0] );
			if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				$ip = $value;
			}
		}

		return (string) apply_filters( 'anar_login_client_ip', self::bucket( $ip ? $ip : 'unknown' ) );
	}

	/**
	 * Headers an administrator may trust for the visitor address.
	 *
	 * @return array<string,string> Header => label.
	 */
	public static function proxy_headers() {
		return array(
			''                 => __( 'Direct connection (REMOTE_ADDR)', 'anar-toolkit' ),
			'x-real-ip'        => 'X-Real-IP',
			'x-forwarded-for'  => 'X-Forwarded-For',
			'cf-connecting-ip' => 'CF-Connecting-IP',
			'ar-real-ip'       => 'AR-Real-IP',
		);
	}

	/** Allowed header name, or empty. */
	public static function proxy_header( $header ) {
		$header = strtolower( trim( (string) $header ) );

		return '' !== $header && isset( self::proxy_headers()[ $header ] ) ? $header : '';
	}

	/**
	 * Proxy headers present on the current request, to guide the administrator.
	 *
	 * @return string[]
	 */
	public static function detected_proxy_headers() {
		$found = array();
		foreach ( array_keys( self::proxy_headers() ) as $header ) {
			if ( $header && ! empty( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) ) ] ) ) {
				$found[] = $header;
			}
		}

		return $found;
	}

	/** Group IPv6 addresses by their /64 network. */
	private static function bucket( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}
		$packed = inet_pton( $ip );

		return false === $packed ? $ip : bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
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
