<?php
/**
 * On-demand account phone audit and additive Digits migration.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Support;

defined( 'ABSPATH' ) || exit;

final class UserMigration {

	const COMPLETE = 'anar_digits_migration_complete';
	const BATCH = 200;
	const KEYS = array( '_anar_phone', 'digits_phone', 'digits_phone_no', 'digt_countrycode', 'digits_phone_verification_skipped' );

	/** Called only inside the tools screen, never during normal plugin bootstrap. */
	public static function available() {
		if ( get_option( self::COMPLETE, false ) ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT source.user_id FROM %i source INNER JOIN %i account ON account.ID = source.user_id
				WHERE source.meta_key IN ('digits_phone', 'digits_phone_no') AND source.meta_value <> ''
				AND NOT EXISTS (SELECT 1 FROM %i target WHERE target.user_id = source.user_id AND target.meta_key = %s AND target.meta_value <> '')
				AND (%d = 0 OR EXISTS (SELECT 1 FROM %i site_member WHERE site_member.user_id = source.user_id AND site_member.meta_key = %s)) LIMIT 1",
				$wpdb->usermeta,
				$wpdb->users,
				$wpdb->usermeta,
				PhoneDirectory::META_KEY,
				is_multisite() ? 1 : 0,
				$wpdb->usermeta,
				$wpdb->get_blog_prefix() . 'capabilities'
			)
		);
	}

	/** Strict phone input: do not turn arbitrary text into a login identity. */
	public static function phone( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( Identity::latin_digits( (string) $value ) );
		if ( ! preg_match( '/^[+0-9\s().-]+$/D', $value ) ) {
			return '';
		}
		$identity = Identity::normalize( $value );
		return ! is_wp_error( $identity ) && 'sms' === $identity['channel'] && preg_match( '/^\+989[0-9]{9}$/D', $identity['identity'] ) ? $identity['identity'] : '';
	}

	/** Interpret source fields without changing the user or trusting billing data. */
	public static function inspect( $meta ) {
		$meta = array_replace( array_fill_keys( self::KEYS, array() ), $meta );
		$canonical = array_values( array_filter( $meta['_anar_phone'], 'strlen' ) );
		$full = array_values( array_filter( $meta['digits_phone'], 'strlen' ) );
		$parts = array_values( array_filter( $meta['digits_phone_no'], 'strlen' ) );
		$country = $meta['digt_countrycode'][0] ?? '';
		$digits = $full;
		foreach ( $parts as $part ) {
			$digits[] = $country ? (string) $country . ltrim( Identity::latin_digits( $part ), '0' ) : $part;
		}
		$normalized = array_values( array_unique( array_filter( array_map( array( self::class, 'phone' ), $digits ) ) ) );
		$current = array_values( array_unique( array_filter( array_map( array( self::class, 'phone' ), $canonical ) ) ) );
		$row = array(
			'phone' => 1 === count( $normalized ) ? $normalized[0] : ( $current[0] ?? '' ),
			'phones' => array_values( array_unique( array_merge( $current, $normalized ) ) ),
			'digits' => (bool) $digits,
			'hash' => hash( 'sha256', wp_json_encode( $meta ) ),
			'status' => 'empty',
		);
		$invalid = count( array_filter( array_map( array( self::class, 'phone' ), $digits ) ) ) !== count( $digits );
		if ( $canonical ) {
			$row['status'] = count( $current ) !== 1 || count( array_filter( array_map( array( self::class, 'phone' ), $canonical ) ) ) !== count( $canonical ) ? 'invalid' : 'linked';
			if ( $digits && ( $invalid || count( $normalized ) !== 1 || $normalized !== $current ) ) {
				$row['status'] = 'different';
			}
		} elseif ( $digits ) {
			$row['status'] = $invalid || count( $normalized ) !== 1 ? 'invalid' : 'ready';
			if ( array_filter( $meta['digits_phone_verification_skipped'] ) ) {
				$row['status'] = 'unverified';
			}
		}
		return $row;
	}

	private static function metadata( $user_id ) {
		$data = array();
		foreach ( self::KEYS as $key ) {
			$data[ $key ] = array_map(
				static function ( $value ) {
					return is_scalar( $value ) ? (string) $value : '[invalid]';
				},
				get_user_meta( $user_id, $key, false )
			);
		}
		return $data;
	}

	public static function start() {
		global $wpdb;
		return array(
			'cursor' => 0,
			'max' => (int) $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->users" ),
			'scanned' => 0,
			'rows' => array(),
			'candidates' => array(),
			'owners' => array(),
			'migration' => self::available(),
			'done' => false,
			'applying' => false,
			'applied' => false,
			'offset' => 0,
			'imported' => 0,
			'changed' => 0,
		);
	}

	/** A bounded chunk keeps the request independent of the full account count. */
	public static function scan( $job ) {
		if ( $job['done'] ) {
			return $job;
		}
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE ID > %d AND ID <= %d
				AND (%d = 0 OR EXISTS (SELECT 1 FROM %i site_member WHERE site_member.user_id = ID AND site_member.meta_key = %s)) ORDER BY ID LIMIT %d',
				$wpdb->users,
				$job['cursor'],
				$job['max'],
				is_multisite() ? 1 : 0,
				$wpdb->usermeta,
				$wpdb->get_blog_prefix() . 'capabilities',
				self::BATCH
			)
		);
		update_meta_cache( 'user', $ids );
		foreach ( $ids as $id ) {
			$job['cursor'] = (int) $id;
			++$job['scanned'];
			$row = self::inspect( self::metadata( $id ) );
			if ( 'empty' === $row['status'] ) {
				continue;
			}
			$job['rows'][ $id ] = $row;
			foreach ( $row['phones'] as $phone ) {
				$job['owners'][ $phone ][ $id ] = true;
			}
		}
		$job['done'] = count( $ids ) < self::BATCH;
		if ( $job['done'] ) {
			foreach ( $job['rows'] as &$row ) {
				foreach ( $row['phones'] as $phone ) {
					if ( count( $job['owners'][ $phone ] ) > 1 ) {
						$row['status'] = 'conflict';
						break;
					}
				}
			}
			unset( $row );
			unset( $job['owners'] );
			foreach ( $job['rows'] as $id => $row ) {
				if ( 'ready' === $row['status'] ) {
					$job['candidates'][] = $id;
				}
			}
		}
		return $job;
	}

	/** Import a previewed batch; existing canonical numbers and source data stay intact. */
	public static function apply( $job ) {
		$ids = $job['candidates'];
		$batch = array_slice( $ids, $job['offset'], self::BATCH );
		$job['applying'] = true;
		foreach ( $batch as $id ) {
			++$job['offset'];
			if ( 'ready' !== $job['rows'][ $id ]['status'] ) {
				continue;
			}
			clean_user_cache( $id );
			$current = self::inspect( self::metadata( $id ) );
			if ( ! get_userdata( $id ) || ! current_user_can( 'edit_user', $id ) || ! hash_equals( $job['rows'][ $id ]['hash'], $current['hash'] ) || 'ready' !== $current['status'] ) {
				$job['rows'][ $id ]['status'] = 'changed';
				++$job['changed'];
				continue;
			}
			$result = PhoneDirectory::claim( $id, $current['phone'], 'digits-migration', false );
			if ( is_wp_error( $result ) ) {
				$job['rows'][ $id ]['status'] = 'changed';
				++$job['changed'];
				continue;
			}
			$job['rows'][ $id ]['status'] = 'imported';
			++$job['imported'];
		}
		$job['applied'] = $job['offset'] >= count( $ids );
		if ( $job['applied'] && ! self::available() ) {
			update_option( self::COMPLETE, array( 'at' => time(), 'by' => get_current_user_id(), 'imported' => $job['imported'] ), false );
		}
		return $job;
	}

	public static function counts( $job ) {
		$counts = array( 'ready' => 0, 'linked' => 0, 'review' => 0, 'imported' => 0 );
		foreach ( $job['rows'] as $row ) {
			$key = isset( $counts[ $row['status'] ] ) ? $row['status'] : 'review';
			++$counts[ $key ];
		}
		return $counts;
	}
}
