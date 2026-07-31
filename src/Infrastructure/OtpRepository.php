<?php
/**
 * OTP persistence.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class OtpRepository {
	/**
	 * Store a fresh OTP and invalidate older active codes.
	 *
	 * @param string $identity Normalized phone or email.
	 * @param string $channel  sms|email.
	 * @param string $code     Plain code; it is hashed before persistence.
	 * @param int    $ttl      Lifetime in seconds.
	 * @return bool
	 */
	public function create( $identity, $channel, $code, $ttl ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'anar_login_otp';
		$identity_hash = $this->identity_hash( $identity );
		$now           = current_time( 'mysql', true );

		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
				"UPDATE {$table} SET consumed_at = %s WHERE identity_hash = %s AND channel = %s AND consumed_at IS NULL",
				$now,
				$identity_hash,
				$channel
			)
		);

		return false !== $wpdb->insert(
			$table,
			array(
				'identity_hash' => $identity_hash,
				'channel'       => $channel,
				'code_hash'     => wp_hash_password( $code ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + absint( $ttl ) ),
				'attempts'      => 0,
				'created_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Get newest active challenge.
	 *
	 * @param string $identity Normalized phone or email.
	 * @param string $channel  sms|email.
	 * @return object|null
	 */
	public function latest( $identity, $channel ) {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_otp';

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
				"SELECT * FROM {$table} WHERE identity_hash = %s AND channel = %s AND consumed_at IS NULL ORDER BY id DESC LIMIT 1",
				$this->identity_hash( $identity ),
				$channel
			)
		);
	}

	/**
	 * Increment attempts atomically.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function increment_attempts( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_otp';
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", absint( $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
	}

	/**
	 * Consume an OTP atomically.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function consume( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_otp';

		return 1 === $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
				"UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL",
				current_time( 'mysql', true ),
				absint( $id )
			)
		);
	}

	/**
	 * Remove expired/old rows.
	 *
	 * @return int|false
	 */
	public function cleanup() {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_otp';

		return $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from the trusted WP prefix.
				"DELETE FROM {$table} WHERE expires_at < %s OR (consumed_at IS NOT NULL AND consumed_at < %s)",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Non-reversible identity key.
	 *
	 * @param string $identity Identity.
	 * @return string
	 */
	private function identity_hash( $identity ) {
		return hash_hmac( 'sha256', strtolower( $identity ), wp_salt( 'auth' ) );
	}
}
