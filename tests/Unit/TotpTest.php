<?php
/**
 * Authenticator interoperability and replay-window checks.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\Totp;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Auth/Totp.php';

final class TotpTest extends TestCase {
	/** RFC 6238 SHA-1 vectors truncated to the six characters used by apps. */
	public function test_rfc_vectors() {
		$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
		foreach ( array( 59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037', 20000000000 => '353130' ) as $time => $expected ) {
			$this->assertSame( $expected, Totp::code( $secret, (int) floor( $time / 30 ) ) );
		}
	}

	public function test_expiry_replay_and_malformed_codes() {
		$secret = Totp::secret();
		$this->assertSame( 32, strlen( $secret ) );
		$counter = 2000;
		$code = Totp::code( $secret, $counter );
		$this->assertSame( $counter, Totp::match_counter( $secret, $code, $counter * 30 ) );
		$this->assertFalse( Totp::match_counter( $secret, $code, $counter * 30, $counter ) );
		$this->assertFalse( Totp::match_counter( $secret, $code, ( $counter + 3 ) * 30 ) );
		$this->assertFalse( Totp::match_counter( $secret, '12345x', $counter * 30 ) );
		$localized = strtr( $code, array_combine( range( 0, 9 ), preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY ) ) );
		$this->assertSame( $counter, Totp::match_counter( $secret, $localized, $counter * 30 ) );
	}
}
