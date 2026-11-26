<?php
/**
 * Source interpretation tests; migration never guesses between conflicting identities.
 *
 * @package AnarLogin
 */

use Anar\Login\Support\UserMigration;
use PHPUnit\Framework\TestCase;

final class UserMigrationTest extends TestCase {

	/** @dataProvider sources */
	public function test_it_classifies_sources_without_mutating_them( $meta, $status, $phone ) {
		$before = $meta;
		$row = UserMigration::inspect( $meta );
		$this->assertSame( $status, $row['status'] );
		$this->assertSame( $phone, $row['phone'] );
		$this->assertSame( $before, $meta );
	}

	public function sources() {
		return array(
			'no records' => array( array(), 'empty', '' ),
			'full Digits' => array( array( 'digits_phone' => array( '+989121234567' ) ), 'ready', '+989121234567' ),
			'Persian Digits' => array( array( 'digits_phone' => array( '۰۹۱۲۱۲۳۴۵۶۷' ) ), 'ready', '+989121234567' ),
			'split Digits' => array( array( 'digits_phone_no' => array( '9121234567' ), 'digt_countrycode' => array( '+98' ) ), 'ready', '+989121234567' ),
			'local split Digits' => array( array( 'digits_phone_no' => array( '09121234567' ), 'digt_countrycode' => array( '+98' ) ), 'ready', '+989121234567' ),
			'full and split agree' => array( array( 'digits_phone' => array( '+989121234567' ), 'digits_phone_no' => array( '9121234567' ), 'digt_countrycode' => array( '98' ) ), 'ready', '+989121234567' ),
			'foreign number' => array( array( 'digits_phone' => array( '+447700900123' ) ), 'invalid', '' ),
			'text is not a phone' => array( array( 'digits_phone' => array( 'owner 09121234567' ) ), 'invalid', '' ),
			'email is not a phone' => array( array( 'digits_phone' => array( 'person@example.test' ) ), 'invalid', '' ),
			'unverified' => array( array( 'digits_phone' => array( '09121234567' ), 'digits_phone_verification_skipped' => array( '1' ) ), 'unverified', '+989121234567' ),
			'verified zero marker' => array( array( 'digits_phone' => array( '09121234567' ), 'digits_phone_verification_skipped' => array( '0' ) ), 'ready', '+989121234567' ),
			'linked account' => array( array( '_anar_phone' => array( '+989121234567' ) ), 'linked', '+989121234567' ),
			'both same account' => array( array( '_anar_phone' => array( '+989121234567' ), 'digits_phone' => array( '09121234567' ) ), 'linked', '+989121234567' ),
			'canonical wins but report mismatch' => array( array( '_anar_phone' => array( '+989121234568' ), 'digits_phone' => array( '09121234567' ) ), 'different', '+989121234567' ),
			'inconsistent source' => array( array( 'digits_phone' => array( '+989121234567' ), 'digits_phone_no' => array( '9121234568' ), 'digt_countrycode' => array( '+98' ) ), 'invalid', '' ),
			'multiple source rows' => array( array( 'digits_phone' => array( '+989121234567', '+989121234568' ) ), 'invalid', '' ),
			'invalid canonical never replaced' => array( array( '_anar_phone' => array( 'not a phone' ), 'digits_phone' => array( '09121234567' ) ), 'different', '+989121234567' ),
		);
	}

	public function test_completed_migration_short_circuits_detection() {
		$GLOBALS['anar_test_options'][ UserMigration::COMPLETE ] = array( 'at' => 123 );
		try {
			// Unit bootstrap deliberately has no wpdb: detection must return before a query.
			$this->assertFalse( UserMigration::available() );
		} finally {
			unset( $GLOBALS['anar_test_options'][ UserMigration::COMPLETE ] );
		}
	}
}
