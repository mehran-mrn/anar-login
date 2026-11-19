<?php
/**
 * Delivery-independent recovery codes, activated only after a saved-code proof.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\AtomicOptions;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class RecoveryCodes {

	const PREFIX = '_anar_recovery_codes_';

	public function record( $user_id ) {
		return get_option( self::PREFIX . (int) $user_id, false );
	}

	public function pending( $user_id ) {
		return get_option( self::PREFIX . (int) $user_id . '_pending', false );
	}

	public function ready( $user_id ) {
		$record = $this->record( $user_id );
		return is_array( $record ) && ! empty( $record['confirmed'] ) && ! empty( $record['hashes'] );
	}

	public static function prepared( $user_id ) {
		$credential = ( new CredentialService() )->record( $user_id );
		return ( new self() )->ready( $user_id ) || ( is_array( $credential ) && ! empty( $credential['recovery'] ) );
	}

	/** Caller must first verify the current contact or an existing factor. */
	public function issue( $user_id, $confirmed = false ) {
		$codes = array();
		for ( $i = 0; $i < 10; ++$i ) {
			$codes[] = bin2hex( random_bytes( 16 ) );
		}
		$record = array( 'version' => 1, 'confirmed' => (bool) $confirmed, 'hashes' => array() );
		$record['hashes'] = array_map(
			static function ( $code ) {
				return hash( 'sha256', $code );
			},
			$codes
		);
		$key = self::PREFIX . (int) $user_id . ( $confirmed ? '' : '_pending' );
		$record['previous'] = hash( 'sha256', wp_json_encode( $this->record( $user_id ) ) );
		$previous = $confirmed ? $this->record( $user_id ) : $this->pending( $user_id );
		$saved = false === $previous ? add_option( $key, $record, '', false ) : AtomicOptions::replace( $key, $previous, $record );
		if ( $saved && $confirmed ) {
			delete_option( self::PREFIX . (int) $user_id . '_pending' );
		}
		return $saved ? $codes : false;
	}

	public function verify( $user_id, $code, $confirm = false ) {
		$limits = new RateLimiter( new Settings() );
		if ( is_wp_error( $limits->guard_factor( $user_id ) ) ) {
			return false;
		}
		$record = $confirm ? $this->pending( $user_id ) : $this->record( $user_id );
		if ( ! is_array( $record ) || 1 !== ( $record['version'] ?? 0 ) || ! is_array( $record['hashes'] ?? null ) || ( empty( $record['confirmed'] ) && ! $confirm ) ) {
			return false;
		}
		$index = array_search( hash( 'sha256', strtolower( trim( $code ) ) ), $record['hashes'], true );
		if ( false === $index ) {
			return false;
		}
		$next = $record;
		unset( $next['hashes'][ $index ] );
		$next['hashes'] = array_values( $next['hashes'] );
		$next['confirmed'] = true;
		$key = self::PREFIX . (int) $user_id;
		if ( $confirm ) {
			$active = $this->record( $user_id );
			if ( ! hash_equals( $record['previous'], hash( 'sha256', wp_json_encode( $active ) ) ) || ! AtomicOptions::remove( $key . '_pending', $record ) ) {
				return false;
			}
			$updated = false === $active ? add_option( $key, $next, '', false ) : AtomicOptions::replace( $key, $active, $next );
		} else {
			$updated = AtomicOptions::replace( $key, $record, $next );
		}
		if ( $updated ) {
			$limits->clear_factor( $user_id );
		}
		return $updated;
	}

	public function remove( $user_id ) {
		delete_option( self::PREFIX . (int) $user_id . '_pending' );
		return delete_option( self::PREFIX . (int) $user_id );
	}
}
