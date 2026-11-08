<?php
/**
 * Expiring browser-bound authentication flows with atomic state transitions.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class LoginFlowService {
	const COOKIE = 'anar_login_browser';

	private function browser( $create = false ) {
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Strict hexadecimal validation below.
		if ( preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			return $value;
		}
		if ( ! $create || headers_sent() ) {
			return '';
		}
		$value = bin2hex( random_bytes( 32 ) );
		setcookie( self::COOKIE, $value, array( 'expires' => 0, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::COOKIE ] = $value;
		return $value;
	}

	public function issue( $purpose, array $data, $ttl = 300 ) {
		$browser = $this->browser( true );
		if ( ! $browser ) {
			return new \WP_Error( 'anar_flow_cookie', __( 'Please allow cookies and try again.', 'anar-toolkit' ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		$state = array( 'purpose' => $purpose, 'browser' => hash( 'sha256', $browser ), 'expires' => time() + min( 600, max( 30, (int) $ttl ) ), 'attempts' => 0, 'data' => $data );
		return add_option( $this->key( $token ), wp_json_encode( $state ), '', false ) ? $token : new \WP_Error( 'anar_flow_storage', __( 'Please try again.', 'anar-toolkit' ) );
	}

	private function key( $token ) {
		return 'anar_auth_flow_' . hash( 'sha256', $token );
	}

	public function read( $token, $purpose ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', (string) $token ) ) {
			return false;
		}
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $this->key( $token ) ) );
		$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$browser = $this->browser();
		if ( ! is_array( $state ) || $state['purpose'] !== $purpose || $state['expires'] <= time() || ! $browser || ! hash_equals( $state['browser'], hash( 'sha256', $browser ) ) ) {
			return false;
		}
		return $state;
	}

	/** Reserve an attempt before checking a factor. Concurrent stale readers lose. */
	public function attempt( $token, $purpose ) {
		global $wpdb;
		$state = $this->read( $token, $purpose );
		if ( ! $state || $state['attempts'] >= 5 ) {
			return false;
		}
		$previous = wp_json_encode( $state );
		++$state['attempts'];
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, wp_json_encode( $state ), $this->key( $token ), $previous ) );
		wp_cache_delete( $this->key( $token ), 'options' );
		return 1 === $updated ? $state : false;
	}

	public function consume( $token, $purpose, array $state ) {
		global $wpdb;
		if ( ! $this->read( $token, $purpose ) || $state['purpose'] !== $purpose || $state['expires'] <= time() ) {
			return false;
		}
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, $this->key( $token ), wp_json_encode( $state ) ) );
		wp_cache_delete( $this->key( $token ), 'options' );
		return 1 === $deleted;
	}

	public static function cleanup() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s LIMIT 1000', $wpdb->options, $wpdb->esc_like( 'anar_auth_flow_' ) . '%' ) );
		foreach ( $rows as $row ) {
			$state = json_decode( $row->option_value, true );
			if ( ! is_array( $state ) || ( $state['expires'] ?? 0 ) <= time() ) {
				delete_option( $row->option_name );
			}
		}
	}
}
