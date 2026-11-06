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

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET consumed_at = %s WHERE identity_hash = %s AND channel = %s AND consumed_at IS NULL',
				$table,
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
				'code_hash'     => $this->code_hash( $identity_hash, $code ),
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

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE identity_hash = %s AND channel = %s AND consumed_at IS NULL ORDER BY id DESC LIMIT 1',
				$table,
				$this->identity_hash( $identity ),
				$channel
			)
		);
	}

	/**
	 * Reserve one verification attempt atomically.
	 *
	 * The conditional update is the only gate, so parallel requests cannot
	 * exceed the attempt limit by reading the same counter value.
	 *
	 * @param int $id  Row ID.
	 * @param int $max Maximum attempts for one challenge.
	 * @return bool False when the challenge is exhausted or already consumed.
	 */
	public function claim_attempt( $id, $max ) {
		global $wpdb;

		$table = $wpdb->prefix . 'anar_login_otp';

		return 1 === $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET attempts = attempts + 1 WHERE id = %d AND consumed_at IS NULL AND attempts < %d AND expires_at > %s',
				$table,
				absint( $id ),
				absint( $max ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Compare a submitted code with a stored challenge in constant time.
	 *
	 * @param object $challenge Challenge row.
	 * @param string $identity  Normalized phone or email.
	 * @param string $code      Submitted code.
	 * @return bool
	 */
	public function matches( $challenge, $identity, $code ) {
		$stored = (string) $challenge->code_hash;

		// Rows created before 0.3.0 used WordPress password hashing.
		if ( 0 === strpos( $stored, '$' ) ) {
			return wp_check_password( $code, $stored );
		}

		return hash_equals( $stored, $this->code_hash( $this->identity_hash( $identity ), $code ) );
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

		return 1 === $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND expires_at > %s',
				$table,
				current_time( 'mysql', true ),
				absint( $id ),
				current_time( 'mysql', true )
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

		return $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s OR (consumed_at IS NOT NULL AND consumed_at < %s)',
				$table,
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

	/**
	 * Keyed code hash bound to one identity.
	 *
	 * @param string $identity_hash Identity HMAC.
	 * @param string $code          Plain code.
	 * @return string
	 */
	private function code_hash( $identity_hash, $code ) {
		return hash_hmac( 'sha256', $identity_hash . '|' . $code, wp_salt( 'secure_auth' ) );
	}
}
