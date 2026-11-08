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
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $secret, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, 'anar-totp-v1' );
		if ( false === $ciphertext ) {
			throw new \RuntimeException( 'Authenticator encryption failed.' );
		}
		return base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary envelope.
	}

	public function decrypt( $envelope ) {
		$raw = base64_decode( $envelope, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary envelope.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return false;
		}
		return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'anar-totp-v1' );
	}

	public function enroll_encrypted( $user_id, $encrypted, $code ) {
		$secret = $this->decrypt( $encrypted );
		return $secret ? $this->enroll( $user_id, $secret, $code ) : false;
	}

	public function enroll( $user_id, $secret, $code ) {
		$counter = Totp::match_counter( $secret, $code, time() );
		if ( false === $counter || $this->enabled( $user_id ) ) {
			return false;
		}
		$codes = array();
		for ( $i = 0; $i < 10; ++$i ) {
			$codes[] = bin2hex( random_bytes( 16 ) );
		}
		$record = array(
			'version' => 1,
			'secret' => $this->encrypt( $secret ),
			'last' => $counter,
			'recovery' => array_map(
				static function ( $value ) {
					return hash( 'sha256', $value );
				},
				$codes
			),
		);
		return add_option( self::META . '_' . (int) $user_id, $record, '', false ) ? $codes : false;
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
		global $wpdb;
		$key = self::META . '_' . (int) $user_id;
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, maybe_serialize( $next ), $key, maybe_serialize( $previous ) ) );
		wp_cache_delete( $key, 'options' );
		if ( 1 === $updated ) {
			$limits->clear_factor( $user_id );
		}
		return 1 === $updated;
	}
}
