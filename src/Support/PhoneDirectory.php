<?php
/**
 * The single lookup from a verified mobile number to WordPress accounts.
 *
 * Login, password recovery and number linking all resolve phones here, in one
 * query, so they cannot disagree about which account owns a number.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Support;

defined( 'ABSPATH' ) || exit;

final class PhoneDirectory {
	const META_KEY = '_anar_phone';

	/**
	 * Meta keys that may hold a verified phone, in priority order.
	 *
	 * Billing phones are customer-entered and never prove ownership, so they are
	 * deliberately absent. digits_phone is a fallback for sites migrating from Digits.
	 *
	 * @return string[]
	 */
	public static function keys() {
		$keys = (array) apply_filters( 'anar_login_phone_meta_keys', array( self::META_KEY, 'digits_phone' ) );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $keys ) ) ) );
	}

	/**
	 * Stored spellings of one canonical number.
	 *
	 * @param string $phone Canonical +98 number.
	 * @return string[]
	 */
	public static function spellings( $phone ) {
		return array_values( array_unique( array( $phone, ltrim( $phone, '+' ), Identity::local_phone( $phone ) ) ) );
	}

	/**
	 * Accounts linked to a number, best match (highest-priority key) first.
	 *
	 * @param string $phone   Canonical +98 number.
	 * @param int    $limit   Maximum accounts to return.
	 * @param int    $exclude Account to ignore.
	 * @return array<int,string> Meta key that matched, by user ID.
	 */
	public static function find( $phone, $limit = 2, $exclude = 0 ) {
		global $wpdb;

		$keys   = self::keys();
		$values = self::spellings( $phone );
		if ( ! $keys || '' === (string) $phone ) {
			return array();
		}
		$key_list   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$value_list = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// One indexed (meta_key) query instead of a get_users() round trip per key and spelling.
		// A canonical phone supersedes stale migration metadata, including after a number change.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder lists are generated above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT candidate.user_id, candidate.meta_key FROM %i AS candidate
				WHERE candidate.meta_key IN ($key_list) AND candidate.meta_value IN ($value_list) AND candidate.user_id <> %d
				AND (candidate.meta_key = %s OR NOT EXISTS (
					SELECT 1 FROM %i AS canonical WHERE canonical.user_id = candidate.user_id AND canonical.meta_key = %s AND canonical.meta_value <> ''
				))
				ORDER BY FIELD(candidate.meta_key, $key_list), candidate.umeta_id LIMIT %d",
				array_merge( array( $wpdb->usermeta ), $keys, $values, array( absint( $exclude ), self::META_KEY, $wpdb->usermeta, self::META_KEY ), $keys, array( max( 1, absint( $limit ) ) * count( $keys ) ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$found = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row->user_id;
			if ( ! isset( $found[ $id ] ) ) {
				$found[ $id ] = (string) $row->meta_key;
			}
			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Whether a number is free to link to an account.
	 *
	 * @param string $phone   Canonical number.
	 * @param int    $user_id Account that would own it.
	 * @return bool
	 */
	public static function available( $phone, $user_id ) {
		return ! self::find( $phone, 1, $user_id );
	}

	/**
	 * Link a number whose ownership was just proven, if no other account owns it.
	 *
	 * Links are serialized so two simultaneous verifications cannot claim one number.
	 *
	 * @param int    $user_id User.
	 * @param string $phone   Canonical number.
	 * @param string $source  Why it was linked.
	 * @param bool   $replace Whether an existing canonical number may be replaced.
	 * @return true|\WP_Error
	 */
	public static function claim( $user_id, $phone, $source, $replace = true ) {
		global $wpdb;

		$locks = $wpdb->prefix . 'anar_login_limits';
		$lock  = hash( 'sha256', 'game-phone-links' );
		$owner = random_int( 1, 2147483647 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND expires_at <= %d', $locks, $lock, time() ) );
		if ( 1 !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (limit_key, attempts, expires_at) VALUES (%s, %d, %d)', $locks, $lock, $owner, time() + MINUTE_IN_SECONDS ) ) ) {
			return new \WP_Error( 'anar_phone_busy', __( 'Mobile verification is busy. Please request a new code and try again.', 'anar-toolkit' ) );
		}
		try {
			clean_user_cache( $user_id );
			$current = (string) get_user_meta( $user_id, self::META_KEY, true );
			if ( ( ! $replace && '' !== $current && ! in_array( $current, self::spellings( $phone ), true ) ) || ! self::available( $phone, $user_id ) ) {
				return new \WP_Error( 'anar_phone_unavailable', __( 'This number cannot be linked to this account. Contact the store for assistance.', 'anar-toolkit' ) );
			}
			if ( ! self::link( $user_id, $phone, $source ) ) {
				return new \WP_Error( 'anar_phone_save', __( 'Your mobile number could not be saved. Please try again.', 'anar-toolkit' ) );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND attempts = %d', $locks, $lock, $owner ) );
		}

		return true;
	}

	/**
	 * Store a verified number as the account's canonical phone.
	 *
	 * @param int    $user_id User.
	 * @param string $phone   Canonical number.
	 * @param string $source  Why it was linked.
	 * @return bool
	 */
	public static function link( $user_id, $phone, $source ) {
		if ( (string) get_user_meta( $user_id, self::META_KEY, true ) === $phone ) {
			return true;
		}
		if ( ! update_user_meta( $user_id, self::META_KEY, $phone ) ) {
			return false;
		}
		do_action( 'anar_login_phone_identity_linked', $user_id, $source );

		return true;
	}
}
