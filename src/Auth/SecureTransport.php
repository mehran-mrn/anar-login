<?php
/**
 * Transport requirement for newly enrolled security factors.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class SecureTransport {
	public static function available() {
		if ( is_ssl() && 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
			return true;
		}
		return 'local' === wp_get_environment_type() && in_array( wp_parse_url( home_url( '/' ), PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}
}
