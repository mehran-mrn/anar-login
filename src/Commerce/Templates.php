<?php
/**
 * Safe plain-text message rendering and provider variable mappings.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

defined( 'ABSPATH' ) || exit;

final class Templates {

	public static function render( $text, $variables ) {
		$replace = array();
		foreach ( $variables as $key => $value ) {
			$replace[ '{' . $key . '}' ] = wp_strip_all_tags( (string) $value );
		}
		return strtr( (string) $text, $replace );
	}

	public static function parameters( $mapping, $variables ) {
		$params = array();
		foreach ( preg_split( '/\r?\n/', trim( (string) $mapping ) ) as $line ) {
			$parts = explode( '=', $line, 2 );
			if ( 2 !== count( $parts ) || ! preg_match( '/^[a-zA-Z0-9_]{1,40}$/', trim( $parts[0] ) ) ) {
				continue;
			}
			$params[ trim( $parts[0] ) ] = self::render( trim( $parts[1] ), $variables );
		}
		return $params;
	}

	/** Conservative Unicode estimate, including surrogate pairs. Provider billing may differ. */
	public static function segments( $message ) {
		$units = preg_match_all( '/./us', $message ) + preg_match_all( '/[\x{10000}-\x{10FFFF}]/u', $message );
		return $units <= 70 ? 1 : (int) ceil( $units / 67 );
	}
}
