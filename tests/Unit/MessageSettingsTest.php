<?php
/**
 * Message migration, validation and purpose-specific transport checks.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageCatalog;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class MessageSettingsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'panelchi', 'provider_panelchi' => array( 'api_key' => 'TEST-ONLY', 'template_id' => 'old-login', 'code_parameter' => 'otp' ) );
	}

	protected function tearDown(): void {
		foreach ( array_keys( $GLOBALS['anar_test_options'] ) as $key ) {
			if ( Settings::OPTION_KEY === $key || 0 === strpos( $key, MessageSettings::OPTION ) || 'anar_toolkit_marketing' === $key ) {
				unset( $GLOBALS['anar_test_options'][ $key ] );
			}
		}
	}

	public function test_all_existing_purposes_have_one_catalog_entry() {
		$items = MessageCatalog::all();
		$this->assertCount( 34, $items );
		$this->assertArrayHasKey( 'recovery_request', $items );
		$this->assertArrayHasKey( 'stock_verification', $items );
		$this->assertArrayHasKey( 'mkt_birthday', $items );
	}

	public function test_old_auth_template_remains_available_without_migration_writes() {
		$this->assertSame( 'old-login', MessageSettings::get( 'password_reset' )['template'] );
		$this->assertSame( 'otp={code}', MessageSettings::get( 'login_otp' )['params'] );
		$this->assertNull( MessageSettings::stored( 'login_otp' ) );
		$this->assertSame( MessageSettings::fingerprint( MessageSettings::get( 'login_otp' ) ), MessageSettings::fingerprint( MessageSettings::get( 'login_otp' ) ) );
	}

	public function test_fresh_sign_in_uses_meli_and_defaults_to_template_without_a_sender() {
		unset( $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] );
		$this->assertSame( 'melipayamak', MessageSettings::get( 'login_otp' )['provider'] );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array(
			'sms_provider' => 'melipayamak',
			'provider_melipayamak' => array( 'username' => 'demo', 'password' => 'test' ),
		);
		$this->assertSame( 'template', MessageSettings::get( 'login_otp' )['mode'] );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_melipayamak']['from'] = '30001234';
		$this->assertSame( 'text', MessageSettings::get( 'login_otp' )['mode'] );
	}

	public function test_old_provider_defaults_change_without_switching_configured_providers() {
		$options = &$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ];
		$options = array( 'sms_provider' => '' );
		$this->assertSame( 'melipayamak', MessageSettings::get( 'login_otp' )['provider'] );
		$options['sms_provider'] = 'kavenegar';
		$this->assertSame( 'melipayamak', MessageSettings::get( 'login_otp' )['provider'] );
		$options['provider_kavenegar'] = array( 'api_key' => 'TEST-ONLY' );
		$this->assertSame( 'kavenegar', MessageSettings::get( 'login_otp' )['provider'] );
		unset( $options['provider_kavenegar'] );
		$options['sms_provider_confirmed'] = 1;
		$this->assertSame( 'kavenegar', MessageSettings::get( 'login_otp' )['provider'] );
		$options['sms_provider'] = 'panelchi';
		$this->assertSame( 'panelchi', MessageSettings::get( 'login_otp' )['provider'] );
	}

	public function test_security_messages_can_override_or_inherit_the_login_pattern() {
		$value = array( 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'new-login', 'params' => 'code={code}', 'message' => '{code}', 'inherit' => false );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = $value;
		$this->assertSame( 'new-login', MessageSettings::get( 'game_phone' )['template'] );
		$value['template'] = 'reset-only';
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_password_reset' ] = $value;
		$this->assertSame( 'reset-only', MessageSettings::get( 'password_reset' )['template'] );
		$this->assertSame( 'new-login', MessageSettings::get( 'game_phone' )['template'] );
	}

	public function test_required_and_unknown_variables_are_validated_before_delivery() {
		$value = MessageSettings::get( 'login_otp' );
		$this->assertSame( array(), MessageSettings::errors( 'login_otp', $value ) );
		$value['params'] = 'code={coupon_code}';
		$this->assertCount( 2, MessageSettings::errors( 'login_otp', $value ) );
		$value['params'] = "code={code}\ncode={code}";
		$this->assertNotEmpty( MessageSettings::errors( 'login_otp', $value ) );
		$value['params'] = 'code={code}';
		$value['mode'] = 'text';
		$this->assertNotEmpty( MessageSettings::errors( 'login_otp', $value ) );
	}

	public function test_custom_verification_can_send_a_code_without_message_text() {
		$config = array( 'url' => 'https://sms.example.test/api', 'params' => 'token={code}' );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'custom', 'provider_custom' => $config );
		$value = MessageSettings::get( 'login_otp' );
		$value['message'] = '';
		$this->assertSame( array(), MessageSettings::errors( 'login_otp', $value ) );
		$this->assertNotEmpty( MessageSettings::errors( 'paid_customer', $value ) );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_custom']['params'] = 'text={message}';
		$this->assertNotEmpty( MessageSettings::errors( 'login_otp', $value ) );
	}

	public function test_gift_and_plain_variants_have_independent_bindings() {
		$value = array( 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'birthday-no-gift', 'params' => 'stop={unsubscribe_url}', 'message' => 'Birthday', 'inherit' => false );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_mkt_birthday' ] = $value;
		$value['template'] = 'birthday-gift';
		$value['params'] .= "\ngift={coupon_code}";
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_mkt_birthday_gift' ] = $value;
		$this->assertSame( 'birthday-no-gift', MessageSettings::get( 'mkt_birthday', 'plain' )['template'] );
		$this->assertSame( 'birthday-gift', MessageSettings::get( 'mkt_birthday', 'gift' )['template'] );
		$this->assertContains( 'coupon_code', MessageCatalog::required( 'mkt_birthday', 'gift' ) );
		$this->assertNotContains( 'coupon_code', MessageCatalog::required( 'mkt_birthday', 'plain' ) );
	}
}
