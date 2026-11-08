<?php
/**
 * Encrypted authenticator credentials and atomic recovery-code consumption.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class CredentialService {
	const META = '_anar_authenticator';

	public static function available() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	public function record( $user_id ) {
		return get_option( self::META . '_' . (int) $user_id, false );
	}

	public function remove( $user_id ) {
		return delete_option( self::META . '_' . (int) $user_id );
	}

	public function enabled( $user_id ) {
		return false !== $this->record( $user_id );
	}

	private function key() {
		return hash_hmac( 'sha256', 'anar-authenticator-v1', wp_salt( 'auth' ), true );
	}

	public function encrypt( $secret ) {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'Authenticator encryption is unavailable.' );
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $secret, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, 'anar-totp-v1' );
		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'Authenticator encryption failed.' );
		}
		return base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope.
	}

	public function decrypt( $envelope ) {
		if ( ! self::available() ) {
			return false;
		}
		$raw = base64_decode( $envelope, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return false;
		}
		return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'anar-totp-v1' );
	}

	public function enroll( $user_id, $secret, $code ) {
		$counter = Totp::match_counter( $secret, $code, time() );
		if ( is_multisite() || ! self::available() || false === $counter || $this->enabled( $user_id ) ) {
			return false;
		}
		$recovery = $this->new_recovery();
		$record = array(
			'version' => 1,
			'secret' => $this->encrypt( $secret ),
			'last' => $counter,
			'recovery' => $recovery['hashes'],
		);
		if ( ! add_option( self::META . '_' . (int) $user_id, $record, '', false ) ) {
			return false;
		}
		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$token = get_current_user_id() === (int) $user_id ? wp_get_session_token() : '';
		if ( $token ) {
			$sessions->destroy_others( $token );
		} else {
			$sessions->destroy_all();
		}
		return $recovery['codes'];
	}

	public function verify( $user_id, $code ) {
		$limits = new \Anar\Login\Infrastructure\RateLimiter( new \Anar\Login\Infrastructure\Settings() );
		if ( is_wp_error( $limits->guard_factor( $user_id ) ) ) {
			return false;
		}
		$previous = $this->record( $user_id );
		if ( ! is_array( $previous ) || 1 !== ( $previous['version'] ?? null ) || ! isset( $previous['secret'], $previous['last'], $previous['recovery'] ) || ! is_string( $previous['secret'] ) || ! is_array( $previous['recovery'] ) ) {
			return false;
		}
		$next = $previous;
		$code = trim( \Anar\Login\Support\Identity::latin_digits( $code ) );
		$index = array_search( hash( 'sha256', strtolower( $code ) ), $previous['recovery'], true );
		if ( false !== $index ) {
			unset( $next['recovery'][ $index ] );
			$next['recovery'] = array_values( $next['recovery'] );
		} else {
			$secret = $this->decrypt( $previous['secret'] );
			$counter = $secret ? Totp::match_counter( $secret, $code, time(), $previous['last'] ) : false;
			if ( false === $counter ) {
				return false;
			}
			$next['last'] = $counter;
		}
		$updated = \Anar\Login\Infrastructure\AtomicOptions::replace( self::META . '_' . (int) $user_id, $previous, $next );
		if ( $updated ) {
			$limits->clear_factor( $user_id );
		}
		return $updated;
	}
	private function new_recovery() {
		$codes = array();
		for ( $i = 0; $i < 10; ++$i ) {
			$codes[] = bin2hex( random_bytes( 16 ) );
		}
		return array(
			'codes' => $codes,
			'hashes' => array_map(
				static function ( $code ) {
					return hash( 'sha256', $code );
				},
				$codes
			),
		);
	}

	/** Caller must first pass the shared account-management proof. */
	public function regenerate_recovery( $user_id ) {
		$previous = $this->record( $user_id );
		if ( ! is_array( $previous ) ) {
			return false;
		}
		$recovery = $this->new_recovery();
		$next = $previous;
		$next['recovery'] = $recovery['hashes'];
		return \Anar\Login\Infrastructure\AtomicOptions::replace( self::META . '_' . (int) $user_id, $previous, $next ) ? $recovery['codes'] : false;
	}
}
