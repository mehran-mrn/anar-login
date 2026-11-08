<?php
/**
 * Lazy WebAuthn library boundary with an exact site-origin requirement.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class PasskeyServer {
	public static function available() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return PHP_VERSION_ID >= 80000 && SecureTransport::available() && function_exists( 'openssl_verify' ) && ( 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) || 'localhost' === $host );
	}

	public static function origin() {
		$parts = wp_parse_url( home_url( '/' ) );
		return $parts['scheme'] . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) && ! ( ( 'https' === $parts['scheme'] && 443 === $parts['port'] ) || ( 'http' === $parts['scheme'] && 80 === $parts['port'] ) ) ? ':' . $parts['port'] : '' );
	}

	public static function server() {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'Passkeys require HTTPS and PHP 8.0 or later.' );
		}
		return new \Anar\Login\Vendor\WebAuthn\WebAuthn( wp_strip_all_tags( get_bloginfo( 'name' ) ), wp_parse_url( home_url( '/' ), PHP_URL_HOST ), array( 'none' ), true );
	}

	public static function decode( $encoded ) {
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 131072 || ! preg_match( '/^[A-Za-z0-9_-]*$/D', $encoded ) ) {
			throw new \InvalidArgumentException( 'Invalid binary credential data.' );
		}
		return \Anar\Login\Vendor\WebAuthn\Binary\ByteBuffer::fromBase64Url( $encoded )->getBinaryString();
	}

	public static function encode( $binary ) {
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WebAuthn binary transport.
	}

	public static function check_client( $json ) {
		$client = json_decode( $json, true );
		if ( ! is_array( $client ) || ( $client['origin'] ?? '' ) !== self::origin() || ! empty( $client['crossOrigin'] ) || isset( $client['topOrigin'] ) ) {
			throw new \InvalidArgumentException( 'The credential origin does not match this site.' );
		}
	}
}
