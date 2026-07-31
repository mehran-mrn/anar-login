<?php
/**
 * Identity normalization tests.
 *
 * @package AnarLogin
 */

use Anar\Login\Support\Identity;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase {
	/**
	 * @dataProvider phoneProvider
	 */
	public function test_it_normalizes_iranian_mobile_numbers( $input ) {
		$result = Identity::normalize( $input );

		$this->assertIsArray( $result );
		$this->assertSame( '+989121234567', $result['identity'] );
		$this->assertSame( 'sms', $result['channel'] );
	}

	public function phoneProvider() {
		return array(
			array( '09121234567' ),
			array( '+989121234567' ),
			array( '00989121234567' ),
			array( '98 912 123 4567' ),
			array( '۰۹۱۲۱۲۳۴۵۶۷' ),
			array( '٠٩١٢١٢٣٤٥٦٧' ),
		);
	}

	public function test_it_normalizes_email() {
		$result = Identity::normalize( '  Person+Login@Example.com ' );

		$this->assertSame( 'person+login@example.com', $result['identity'] );
		$this->assertSame( 'email', $result['channel'] );
	}

	/**
	 * @dataProvider invalidProvider
	 */
	public function test_it_rejects_invalid_identity( $input ) {
		$this->assertInstanceOf( WP_Error::class, Identity::normalize( $input ) );
	}

	public function invalidProvider() {
		return array(
			array( '' ),
			array( '02112345678' ),
			array( '0912123' ),
			array( 'not-an-email' ),
			array( 'test@' ),
		);
	}
}
