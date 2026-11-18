<?php
/**
 * Failover policy and migration compatibility.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\LoginDelivery;
use Anar\Login\Messaging\MessageSettings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class LoginDeliveryTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_options'] );
	}

	/** @dataProvider failure_cases */
	public function test_only_definite_failure_allows_a_second_send( $code, $data, $expected ) {
		$this->assertSame( $expected, LoginDelivery::may_fallback( new WP_Error( $code, 'test', $data ) ) );
	}

	public function failure_cases() {
		return array(
			array( 'anar_sms_network', array( 'delivery_safe_to_retry' => true ), true ),
			array( 'anar_sms_network', array( 'delivery_safe_to_retry' => false ), false ),
			array( 'anar_sms_http', array( 'status' => 400 ), true ),
			array( 'anar_sms_http', array( 'status' => 429 ), true ),
			array( 'anar_sms_http', array( 'status' => 408 ), false ),
			array( 'anar_sms_http', array( 'status' => 502 ), false ),
			array( 'anar_sms_http', array( 'status' => 503, 'delivery_safe_to_retry' => true ), true ),
			array( 'anar_sms_not_configured', array(), true ),
			array( 'anar_message_incomplete', array(), true ),
			array( 'unknown', array(), false ),
		);
	}

	public function test_primary_sms_binding_is_available_without_migration_writes() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'panelchi' );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = array( 'provider' => 'panelchi', 'template' => 'already-approved', 'params' => 'otp={code}', 'inherit' => false );
		$this->assertSame( 'already-approved', MessageSettings::get( 'login_sms' )['template'] );
		$this->assertNull( MessageSettings::stored( 'login_sms' ) );
	}

	/** @dataProvider provider_failures */
	public function test_sms_response_must_explicitly_reject_before_retrying( $provider, $body, $safe ) {
		$registry = new \Anar\Login\Messaging\ProviderRegistry( new Settings() );
		$method = new ReflectionMethod( \Anar\Login\Messaging\AbstractProvider::class, 'gateway_error' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$error = $method->invoke( $registry->all()[ $provider ], $body );
		$this->assertSame( $safe, LoginDelivery::may_fallback( $error ) );
	}

	public function provider_failures() {
		return array(
			array( 'panelchi', '{"success":false}', true ),
			array( 'maxsms', '{"success":false}', true ),
			array( 'kavenegar', '{"return":{"status":401}}', true ),
			array( 'melipayamak', '{"RetStatus":5}', true ),
			array( 'ippanel', '{"meta":{"status":false}}', true ),
			array( 'smsir', '{"status":0}', true ),
			array( 'farazsms', '"-1"', true ),
			array( 'custom', '{"error":"unrecognized"}', false ),
			array( 'panelchi', '<html>unknown</html>', false ),
			array( 'maxsms', '{}', false ),
			array( 'kavenegar', '{}', false ),
			array( 'melipayamak', '{}', false ),
			array( 'ippanel', '{}', false ),
			array( 'smsir', '{}', false ),
			array( 'farazsms', '<html>unknown</html>', false ),
		);
	}

	public function test_bale_only_configuration_cannot_become_an_sms_fallback() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'bale' );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = array( 'provider' => 'bale', 'template' => 'otp' );
		$this->assertSame( '', MessageSettings::get( 'login_sms' )['provider'] );
	}

	public function test_new_priority_is_opt_in_and_explicit_purpose_binding_is_preserved() {
		$settings = new Settings();
		$this->assertFalse( LoginDelivery::applies( $settings, 'login_otp' ) );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'mobile_delivery_order' => 'bale_sms' );
		$this->assertTrue( LoginDelivery::applies( $settings, 'login_otp' ) );
		$this->assertTrue( LoginDelivery::applies( $settings, 'password_reset' ) );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_password_reset' ] = array( 'inherit' => false, 'provider' => 'panelchi' );
		$this->assertFalse( LoginDelivery::applies( $settings, 'password_reset' ) );
		$this->assertFalse( LoginDelivery::applies( $settings, 'recovery_request' ) );
	}
}
