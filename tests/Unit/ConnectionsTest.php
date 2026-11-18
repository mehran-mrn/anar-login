<?php
/**
 * Shared connections and Safir v3 transport contracts.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\Providers\Bale;
use Anar\Login\Messaging\HttpClient;
use Anar\Login\Messaging\MessageSettings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class ConnectionsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_options'], $GLOBALS['anar_test_http_response'], $GLOBALS['anar_test_http_requests'] );
	}

	public function test_v3_sends_otp_text_and_template_with_one_connection() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'provider_bale' => array( 'api_key' => 'TEST-ONLY', 'bot_id' => '1234' ) );
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"message_id":"test","error_data":null}' );
		$bale = new Bale( new Settings(), new HttpClient() );
		$this->assertTrue( $bale->send( '+989123456789', '123456', '' ) );
		$this->assertTrue( $bale->send_text( '+989123456789', 'Order paid' ) );
		$this->assertTrue( $bale->send_template( '+989123456789', 'order', array( 'name' => 'Test' ) ) );
		$requests = $GLOBALS['anar_test_http_requests'];
		$this->assertCount( 3, $requests );
		$this->assertSame( 'TEST-ONLY', $requests[0]['args']['headers']['api-access-key'] );
		$body = json_decode( $requests[0]['args']['body'], true );
		$this->assertSame( 1234, $body['bot_id'] );
		$this->assertSame( '989123456789', $body['phone_number'] );
		$this->assertSame( '123456', $body['message_data']['otp_message']['otp'] );
		$this->assertSame( 'Order paid', json_decode( $requests[1]['args']['body'], true )['message_data']['message']['text'] );
		$this->assertSame( 'order', json_decode( $requests[2]['args']['body'], true )['message_data']['template_message']['template_id'] );
	}

	public function test_v3_rejection_and_unknown_response_are_distinguished() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'provider_bale' => array( 'api_key' => 'TEST-ONLY', 'bot_id' => '1234' ) );
		$bale = new Bale( new Settings(), new HttpClient() );
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"error_data":[{"code":17}]}' );
		$this->assertTrue( $bale->send( '+989123456789', '123456', '' )->get_error_data()['delivery_safe_to_retry'] );
		$GLOBALS['anar_test_http_response']['body'] = '{}';
		$this->assertFalse( $bale->send( '+989123456789', '123456', '' )->get_error_data()['delivery_safe_to_retry'] );
	}

	public function test_login_selection_does_not_replace_global_connection_or_other_bindings() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'panelchi', 'login_provider' => 'bale' );
		$this->assertSame( 'bale', MessageSettings::get( 'login_otp' )['provider'] );
		$this->assertSame( 'bale', MessageSettings::get( 'two_factor' )['provider'] );
		$this->assertSame( 'panelchi', ( new Settings() )->get( 'sms_provider' ) );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_password_reset' ] = array( 'provider' => 'panelchi', 'inherit' => false );
		$this->assertSame( 'panelchi', MessageSettings::get( 'password_reset' )['provider'] );
	}
}
