<?php
/**
 * Scope isolation and credential validation for independent settings forms.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\ScopedSettings;
use Anar\Login\Admin\SettingsPage;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ScopedSettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array_merge( ( new Settings() )->defaults(), array( 'sms_provider' => 'kavenegar', 'sms_provider_confirmed' => 1, 'otp_length' => 7, 'google_enabled' => 1, 'form_logo_url' => 'https://', 'provider_kavenegar' => array( 'api_key' => 'QA-KEY' ), 'provider_melipayamak' => array( 'username' => 'QA-USER', 'password' => 'QA-SECRET', 'from' => '3000' ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_options'] );
	}

	public function test_otp_save_cannot_change_provider_or_unrelated_settings() {
		$before = $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ];
		$values = ScopedSettings::values( 'otp', array( 'otp_length' => '6', 'otp_ttl' => '180', 'resend_delay' => '45', 'max_requests_hour' => '9', 'max_verify_attempts' => '4', 'sms_provider' => 'melipayamak', 'google_enabled' => 0, 'provider_kavenegar' => array( 'api_key' => 'INJECTED' ) ) );
		$this->assertSame( 6, $values['otp_length'] );
		$this->assertSame( 180, $values['otp_ttl'] );
		foreach ( array( 'sms_provider', 'google_enabled', 'provider_kavenegar', 'form_logo_url' ) as $key ) {
			$this->assertSame( $before[ $key ], $values[ $key ] );
		}
		$this->assertSame( $before, $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ], 'Previewing validated settings must never write them.' );
	}

	public function test_incomplete_or_invalid_otp_values_are_rejected() {
		$valid = array( 'otp_length' => 6, 'otp_ttl' => 180, 'resend_delay' => 45, 'max_requests_hour' => 9, 'max_verify_attempts' => 4 );
		foreach ( array( 'otp_length' => 9, 'otp_ttl' => 59, 'resend_delay' => array(), 'max_requests_hour' => 'five', 'max_verify_attempts' => 11 ) as $key => $bad ) {
			$value = array_replace( $valid, array( $key => $bad ) );
			$this->assertSame( 'anar_otp_range', ScopedSettings::values( 'otp', $value )->get_error_code() );
		}
		$this->assertSame( 'anar_otp_range', ScopedSettings::values( 'otp', array() )->get_error_code() );
	}

	public function test_connection_save_preserves_secrets_and_otp_and_ignores_other_fields() {
		$values = ScopedSettings::values( 'connections', array( 'sms_provider' => 'melipayamak', 'provider_melipayamak' => array( 'username' => 'NEW-USER', 'password' => '', 'from' => '5000' ), 'otp_length' => 4, 'google_enabled' => 0 ) );
		$this->assertIsArray( $values );
		$this->assertSame( 'melipayamak', $values['sms_provider'] );
		$this->assertSame( 'QA-SECRET', $values['provider_melipayamak']['password'] );
		$this->assertSame( 'NEW-USER', $values['provider_melipayamak']['username'] );
		$this->assertSame( 7, $values['otp_length'] );
		$this->assertSame( 1, $values['google_enabled'] );
		$this->assertSame( 'kavenegar', $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['sms_provider'] );
	}

	public function test_incomplete_credentials_never_replace_active_provider() {
		unset( $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_melipayamak'] );
		$result = ScopedSettings::values( 'connections', array( 'sms_provider' => 'melipayamak', 'provider_melipayamak' => array( 'username' => 'QA' ) ) );
		$this->assertSame( 'anar_connection_incomplete', $result->get_error_code() );
		$this->assertSame( 'kavenegar', $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['sms_provider'] );
	}

	public function test_unknown_or_messenger_provider_cannot_become_active_sms() {
		foreach ( array( 'bale', 'not-a-provider' ) as $key ) {
			$this->assertSame( 'anar_provider', ScopedSettings::values( 'connections', array( 'sms_provider' => $key ) )->get_error_code() );
		}
		$this->assertSame( 'anar_scope', ScopedSettings::values( 'untrusted-scope', array() )->get_error_code() );
	}

	public function test_main_form_preserves_values_owned_by_independent_forms() {
		$settings = new Settings();
		$page = new SettingsPage( $settings, new ProviderRegistry( $settings ) );
		$values = $page->sanitize( array( 'brand_color' => '#112233' ) );
		$this->assertSame( 7, $values['otp_length'] );
		$this->assertSame( 'QA-KEY', $values['provider_kavenegar']['api_key'] );
		$this->assertSame( '3000', $values['provider_melipayamak']['from'] );
	}
}
