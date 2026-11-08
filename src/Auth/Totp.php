<?php
/**
 * RFC 6238 interoperable six-character authenticator codes.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class Totp {
	public static function provisioning_uri( $secret, $account, $issuer ) {
		$issuer = trim( str_replace( ':', ' ', $issuer ) );
		$account = trim( str_replace( ':', ' ', $account ) );
		return 'otpauth://totp/' . rawurlencode( $issuer ) . ':' . rawurlencode( $account ) . '?' . http_build_query( array( 'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30 ), '', '&', PHP_QUERY_RFC3986 );
	}

	public static function secret() {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$result = '';
		foreach ( str_split( random_bytes( 32 ) ) as $byte ) {
			$result .= $alphabet[ ord( $byte ) & 31 ];
		}
		return $result;
	}

	public static function code( $secret, $counter ) {
		$bits = '';
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		foreach ( str_split( $secret ) as $letter ) {
			$index = strpos( $alphabet, $letter );
			if ( false === $index ) {
				throw new \InvalidArgumentException( 'Invalid authenticator secret.' );
			}
			$bits .= str_pad( decbin( $index ), 5, '0', STR_PAD_LEFT );
		}
		$key = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$key .= chr( bindec( $byte ) );
			}
		}
		$hash = hash_hmac( 'sha1', pack( 'N2', (int) floor( $counter / 4294967296 ), $counter & 0xffffffff ), $key, true );
		$offset = ord( $hash[19] ) & 15;
		$value = unpack( 'N', substr( $hash, $offset, 4 ) )[1] & 0x7fffffff;
		return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	public static function match_counter( $secret, $code, $time, $last = -1 ) {
		$code = trim( \Anar\Login\Support\Identity::latin_digits( (string) $code ) );
		if ( ! preg_match( '/^[0-9]{6}$/D', $code ) ) {
			return false;
		}
		$current = (int) floor( $time / 30 );
		foreach ( array( $current, $current - 1, $current + 1 ) as $counter ) {
			if ( $counter > $last && hash_equals( self::code( $secret, $counter ), $code ) ) {
				return $counter;
			}
		}
		return false;
	}
}
