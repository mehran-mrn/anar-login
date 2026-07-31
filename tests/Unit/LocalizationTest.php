<?php
/**
 * Localization asset tests.
 *
 * @package AnarLogin
 */

use PHPUnit\Framework\TestCase;

final class LocalizationTest extends TestCase {
	/**
	 * Persian catalog covers every translatable source message.
	 *
	 * @return void
	 */
	public function test_persian_catalog_covers_source_messages() {
		$root       = dirname( __DIR__, 2 );
		$catalog    = include $root . '/languages/anar-login-fa_IR.l10n.php';
		$messages   = $catalog['messages'];
		$functions  = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
		$unresolved = array();
		$files      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Unit test reads local source files.
			$tokens = token_get_all( file_get_contents( $file->getPathname() ) );
			$count  = count( $tokens );

			for ( $index = 0; $index < $count; $index++ ) {
				$token = $tokens[ $index ];
				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $functions, true ) ) {
					continue;
				}

				for ( $argument = $index + 1; $argument < $count; $argument++ ) {
					$candidate = $tokens[ $argument ];
					if ( is_array( $candidate ) && T_CONSTANT_ENCAPSED_STRING === $candidate[0] ) {
						$message = stripcslashes( substr( $candidate[1], 1, -1 ) );
						if ( 'Anar Login' !== $message && ! isset( $messages[ $message ] ) ) {
							$unresolved[] = $message;
						}
						break;
					}
					if ( ';' === $candidate ) {
						break;
					}
				}
			}
		}

		$this->assertSame( array(), array_values( array_unique( $unresolved ) ) );
		$this->assertSame( 'fa_IR', $catalog['language'] );
	}

	/**
	 * Legacy MO catalog has a valid little-endian gettext header.
	 *
	 * @return void
	 */
	public function test_persian_mo_catalog_is_valid() {
		$path = dirname( __DIR__, 2 ) . '/languages/anar-login-fa_IR.mo';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Unit test reads a local binary fixture.
		$handle = fopen( $path, 'rb' );

		$this->assertIsResource( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Unit test reads a local binary fixture.
		$header = fread( $handle, 8 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Unit test closes its local fixture handle.
		fclose( $handle );

		$data = unpack( 'Vmagic/Vrevision', $header );
		$this->assertSame( 0x950412de, $data['magic'] );
		$this->assertSame( 0, $data['revision'] );
	}
}
