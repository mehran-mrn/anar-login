<?php
/**
 * Sender migration, shared defaults and local completeness contracts.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Messaging\ProviderStatus;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class SenderSettingsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_options'] );
	}

	/** @dataProvider legacy_cases */
	public function test_legacy_modes_preserve_available_contacts_and_mobile_senders( $raw, $expected ) {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = $raw;
		$this->assertSame( $expected, ( new Settings() )->get( 'login_methods' ) );
		$this->assertSame( $raw, $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] );
	}

	public function legacy_cases() {
		return array(
			array( array( 'identity_mode' => 'email', 'sms_provider' => 'bale' ), array( 'email' ) ),
			array( array( 'identity_mode' => 'phone', 'sms_provider' => 'panelchi' ), array( 'sms' ) ),
			array( array( 'identity_mode' => 'both', 'sms_provider' => 'panelchi' ), array( 'sms', 'email' ) ),
			array( array( 'identity_mode' => 'phone', 'sms_provider' => 'bale' ), array( 'bale' ) ),
			array( array( 'identity_mode' => 'both', 'login_provider' => 'bale' ), array( 'email', 'bale' ) ),
			array( array( 'identity_mode' => 'phone', 'mobile_delivery_order' => 'bale_sms' ), array( 'sms', 'bale' ) ),
			array( array( 'identity_mode' => 'both', 'mobile_delivery_order' => 'sms_bale' ), array( 'sms', 'email', 'bale' ) ),
		);
	}

	public function test_simplified_choices_override_retired_priority_and_email_broadcast_options() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'login_methods' => array( 'bale', 'email' ), 'identity_mode' => 'phone', 'login_delivery_mode' => 'simultaneous', 'mobile_delivery_order' => 'sms_bale' );
		$settings = new Settings();
		$this->assertSame( array( 'email', 'bale' ), $settings->get( 'login_methods' ) );
		$this->assertSame( 'both', $settings->get( 'identity_mode' ) );
		$saved = $settings->sanitize( array( 'login_methods' => array( 'bale' ) ) );
		$this->assertSame( array( 'bale' ), $saved['login_methods'] );
		$this->assertSame( 'phone', $saved['identity_mode'] );
		$empty = $settings->sanitize( array( 'login_methods_present' => 1 ) );
		$this->assertSame( array( 'email', 'bale' ), $empty['login_methods'] );
	}

	public function test_all_messages_use_the_single_active_provider() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'login_methods' => array( 'sms' ), 'sms_provider' => 'panelchi', 'sms_provider_confirmed' => 1, 'login_provider' => 'maxsms' );
		$this->assertSame( 'panelchi', MessageSettings::get( 'login_otp' )['provider'] );
		$this->assertSame( 'panelchi', ( new \Anar\Login\Commerce\Settings() )->provider() );
		$this->assertSame( 'panelchi', MessageSettings::get( 'mkt_birthday' )['provider'] );
		$GLOBALS['anar_test_options']['anar_toolkit_commerce'] = array( 'provider' => 'ippanel' );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_paid_customer' ] = array( 'provider' => 'smsir' );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['sms_provider'] = 'kavenegar';
		$this->assertSame( 'kavenegar', MessageSettings::get( 'login_otp' )['provider'] );
		$this->assertSame( 'kavenegar', ( new \Anar\Login\Commerce\Settings() )->provider() );
		$this->assertSame( 'kavenegar', MessageSettings::get( 'paid_customer' )['provider'] );
		$this->assertSame( 'kavenegar', MessageSettings::get( 'mkt_birthday' )['provider'] );
	}

	public function test_retired_provider_override_is_rejected_before_http() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'panelchi' );
		$GLOBALS['anar_test_http_requests'] = array();
		$sender = new \Anar\Login\Messaging\NotificationSender( new Settings(), new \Anar\Login\Messaging\HttpClient() );
		$result = $sender->send( 'maxsms', '09123456789', array( 'message' => 'Test', 'mode' => 'text' ), array() );
		$this->assertSame( 'anar_message_account_changed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['anar_test_http_requests'] );
		$this->assertSame( 'panelchi', MessageSettings::sanitize( array( 'provider' => 'maxsms' ) )['provider'] );
	}

	public function test_legacy_dedicated_sms_template_survives_the_new_choices() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'melipayamak', 'login_provider' => 'bale', 'mobile_delivery_order' => 'bale_sms', 'mobile_sms_provider' => 'panelchi' );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = array( 'provider' => 'bale', 'template' => 'otp', 'params' => 'code={code}' );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_sms' ] = array( 'provider' => 'panelchi', 'template' => 'already-approved', 'params' => 'otp={code}', 'inherit' => false );
		$value = MessageSettings::get( 'login_otp' );
		$this->assertSame( 'melipayamak', $value['provider'] );
		$this->assertSame( 'already-approved', $value['template'] );
		$this->assertTrue( $value['account_changed'] );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = array( 'provider' => 'panelchi', 'template' => 'new-approved', 'sender_schema' => 2 );
		$this->assertSame( 'new-approved', MessageSettings::get( 'login_otp' )['template'] );
	}

	public function test_lists_group_complete_accounts_first_and_exclude_messengers() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'provider_panelchi' => array( 'api_key' => 'TEST' ), 'provider_smsir' => array( 'api_key' => 'TEST' ), 'provider_ippanel' => array( 'api_key' => 'TEST' ), 'provider_bale' => array( 'username' => 'TEST', 'password' => 'TEST' ) );
		$all = ( new ProviderRegistry( new Settings() ) )->all();
		$sorted = ProviderStatus::sorted( $all );
		$this->assertSame( array( 'panelchi', 'smsir' ), array_slice( array_keys( $sorted ), 0, 2 ) );
		$this->assertArrayNotHasKey( 'bale', $sorted );
		$this->assertFalse( ProviderStatus::ready( $all['ippanel'] ) );
		$this->assertFalse( ProviderStatus::ready( $all['custom'] ) );
		$this->assertTrue( ProviderStatus::ready( $all['bale'] ) );
		$this->assertStringContainsString( '⚠', ProviderStatus::label( $all['ippanel'] ) );
	}
}
