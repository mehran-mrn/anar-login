<?php
/**
 * Unique public-key credentials, separate from the user's authenticator secret.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class PasskeyRepository {
	const INDEX = '_anar_passkey_id';

	public function handle( $user_id ) {
		$key = 'anar_passkey_user_' . (int) $user_id;
		$handle = get_option( $key );
		if ( $handle ) {
			return $handle;
		}
		$handle = PasskeyServer::encode( random_bytes( 32 ) );
		if ( add_option( $key, $handle, '', false ) ) {
			return $handle;
		}
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
	}

	public function get( $key ) {
		return preg_match( '/^[a-f0-9]{64}$/D', $key ) ? get_option( 'anar_passkey_' . $key, false ) : false;
	}

	public function all( $user_id ) {
		$result = array();
		foreach ( array_unique( get_user_meta( $user_id, self::INDEX, false ) ) as $key ) {
			$record = $this->get( $key );
			if ( is_array( $record ) && (int) $record['user_id'] === (int) $user_id ) {
				$result[ $key ] = $record;
			}
		}
		return $result;
	}

	public function create( $credential_id, array $record ) {
		$key = hash( 'sha256', $credential_id );
		if ( ! add_option( 'anar_passkey_' . $key, $record, '', false ) ) {
			return false;
		}
		if ( ! add_user_meta( $record['user_id'], self::INDEX, $key ) ) {
			delete_option( 'anar_passkey_' . $key );
			return false;
		}
		return true;
	}

	/** Compare-and-swap prevents accepting a revoked or concurrently changed key. */
	public function update( $key, array $previous, array $next ) {
		foreach ( array( 'user_id', 'public_key', 'credential_id', 'handle', 'backup_eligible', 'created' ) as $field ) {
			if ( ! isset( $previous[ $field ], $next[ $field ] ) || $previous[ $field ] !== $next[ $field ] ) {
				return false;
			}
		}
		return \Anar\Login\Infrastructure\AtomicOptions::replace( 'anar_passkey_' . $key, $previous, $next );
	}

	public function remove( $user_id, $key ) {
		$record = $this->get( $key );
		if ( ! is_array( $record ) || (int) $record['user_id'] !== (int) $user_id ) {
			return false;
		}
		if ( ! \Anar\Login\Infrastructure\AtomicOptions::remove( 'anar_passkey_' . $key, $record ) ) {
			return false;
		}
		delete_user_meta( $user_id, self::INDEX, $key );
		return true;
	}

	public function rename( $user_id, $key, $name ) {
		$record = $this->get( $key );
		$name = trim( mb_substr( sanitize_text_field( $name ), 0, 80 ) );
		if ( ! is_array( $record ) || (int) $record['user_id'] !== (int) $user_id || '' === $name ) {
			return false;
		}
		$next = $record;
		$next['name'] = $name;
		$next['revision'] = bin2hex( random_bytes( 16 ) );
		return $this->update( $key, $record, $next );
	}

	public function remove_user( $user_id ) {
		foreach ( $this->all( $user_id ) as $key => $record ) {
			$this->remove( $user_id, $key );
		}
		delete_option( 'anar_passkey_user_' . (int) $user_id );
	}
}
