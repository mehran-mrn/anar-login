<?php
/**
 * Lightweight PSR-4 style autoloader.
 *
 * @package AnarLogin
 */

namespace Anar\Login;

defined( 'ABSPATH' ) || exit;

final class Autoloader {
	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Load an Anar Login class.
	 *
	 * @param string $class Fully qualified class name.
	 * @return void
	 */
	private static function autoload( $class ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = ANAR_LOGIN_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
