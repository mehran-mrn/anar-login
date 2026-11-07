<?php
/**
 * Provider transport and response contract regression tests; no network calls.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\HttpClient;
use Anar\Login\Messaging\NotificationSender;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Messaging\Providers\IPPanel;
use Anar\Login\Messaging\Providers\MaxSms;
use Anar\Login\Messaging\Providers\Panelchi;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class ProvidersTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array(
			'sms_provider'     => 'panelchi',
			'provider_panelchi' => array( 'api_key' => 'Bearer TEST-ONLY', 'template_id' => 'login', 'code_parameter' => 'otp' ),
			'provider_maxsms' => array( 'api_key' => 'Bearer MAXSMS-ONLY', 'template_id' => 'max-login', 'code_parameter' => 'OTP' ),
			'provider_ippanel'  => array( 'api_key' => 'TEST-ONLY', 'from' => '+983000505', 'template_id' => 'approved' ),
		);
		$GLOBALS['anar_test_http_requests'] = array();
		$this->respond( array( 'data' => array( 'uid' => 'test-request', 'status' => 'CREATED' ) ), 201 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ], $GLOBALS['anar_test_http_requests'], $GLOBALS['anar_test_http_response'] );
	}

	private function respond( $body, $status = 200 ) {
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => $status ), 'body' => wp_json_encode( $body ) );
	}

	public function test_registry_and_otp_request_contract() {
		$registry = new ProviderRegistry( new Settings() );
		$this->assertArrayHasKey( 'ippanel', $registry->all() );
		$this->assertArrayHasKey( 'panelchi', $registry->all() );
		$this->assertArrayHasKey( 'maxsms', $registry->all() );
		$this->assertTrue( $registry->send( '+989123456789', '012345', 'ignored reference text' ) );
		$request = $GLOBALS['anar_test_http_requests'][0];
		$this->assertSame( 'https://api.panelchi.com/sms/pattern', $request['url'] );
		$this->assertSame( 'Bearer TEST-ONLY', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame(
			array( 'pattern' => 'login', 'variables' => array( 'otp' => '012345' ), 'recipient' => '+989123456789', 'sourceNumber' => '10001' ),
			json_decode( $request['args']['body'], true )
		);
	}

	public function test_commerce_uses_separate_pattern_and_all_recovery_variables() {
		$sender = new NotificationSender( new Settings(), new HttpClient() );
		$vars   = array( 'code' => '012345', 'recovery_page' => 'https://example.org/recover', 'unsubscribe_url' => 'https://example.org/unsubscribe' );
		$event  = array( 'template' => 'recover', 'params' => "code={code}\nentry={recovery_page}\nstop={unsubscribe_url}", 'message' => '{code}' );
		$this->assertTrue( $sender->send( 'panelchi', '09123456789', $event, $vars, true ) );
		$body = json_decode( $GLOBALS['anar_test_http_requests'][0]['args']['body'], true );
		$this->assertSame( 'recover', $body['pattern'] );
		$this->assertSame( array( 'code' => $vars['code'], 'entry' => $vars['recovery_page'], 'stop' => $vars['unsubscribe_url'] ), $body['variables'] );
		$event['params'] = 'code={code}';
		$this->assertInstanceOf( WP_Error::class, $sender->send( 'panelchi', '09123456789', $event, $vars, true ) );
		$this->assertCount( 1, $GLOBALS['anar_test_http_requests'] );
	}

	public function panelchi_family() {
		return array( array( Panelchi::class ), array( MaxSms::class ) );
	}

	/** @dataProvider panelchi_family */
	public function test_panelchi_family_rejects_failed_malformed_and_http_error_responses( $class ) {
		$provider = new $class( new Settings(), new HttpClient() );
		foreach ( array( 'REJECTED', 'CANCELED', 'FAILED', 'NO_BALANCE', 'UNKNOWN', '' ) as $status ) {
			$this->respond( array( 'data' => array( 'uid' => 'test-request', 'status' => $status ) ) );
			$this->assertInstanceOf( WP_Error::class, $provider->send( '+989123456789', '012345', '' ) );
		}
		$this->respond( array( 'data' => array( 'status' => 'CREATED' ) ) );
		$this->assertInstanceOf( WP_Error::class, $provider->send( '+989123456789', '012345', '' ) );
		$this->respond( array( 'detail' => 'User inactive', 'code' => 1003 ), 412 );
		$this->assertSame( 'anar_sms_http', $provider->send( '+989123456789', '012345', '' )->get_error_code() );
		$GLOBALS['anar_test_http_response'] = new WP_Error( 'timeout', 'simulated timeout' );
		$this->assertSame( 'anar_sms_network', $provider->send( '+989123456789', '012345', '' )->get_error_code() );
	}

	public function test_incomplete_credentials_do_not_send() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_panelchi']['api_key'] = '';
		$provider = new Panelchi( new Settings(), new HttpClient() );
		$this->assertSame( 'anar_sms_not_configured', $provider->send( '+989123456789', '12345', '' )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['anar_test_http_requests'] );
	}

	public function test_maxsms_otp_uses_its_own_credentials_and_pattern() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['sms_provider'] = 'maxsms';
		$registry = new ProviderRegistry( new Settings() );
		$this->assertSame( 'MaxSMS', $registry->all()['maxsms']->title() );
		$this->assertTrue( $registry->send( '+989123456789', '012345', '' ) );
		$request = $GLOBALS['anar_test_http_requests'][0];
		$this->assertSame( 'https://api.panelchi.com/sms/pattern', $request['url'] );
		$this->assertSame( 'Bearer MAXSMS-ONLY', $request['args']['headers']['Authorization'] );
		$this->assertSame( array( 'pattern' => 'max-login', 'variables' => array( 'OTP' => '012345' ), 'recipient' => '+989123456789', 'sourceNumber' => '10001' ), json_decode( $request['args']['body'], true ) );
	}

	public function test_maxsms_commerce_uses_all_variables_and_a_separate_pattern() {
		$sender = new NotificationSender( new Settings(), new HttpClient() );
		$vars = array( 'code' => '012345', 'recovery_page' => 'https://example.org/recover', 'unsubscribe_url' => 'https://example.org/unsubscribe' );
		$event = array( 'template' => 'max-recovery', 'params' => "code={code}\nentry={recovery_page}\nstop={unsubscribe_url}", 'message' => '{code}' );
		$this->assertTrue( $sender->send( 'maxsms', '09123456789', $event, $vars, true ) );
		$request = $GLOBALS['anar_test_http_requests'][0];
		$this->assertSame( 'Bearer MAXSMS-ONLY', $request['args']['headers']['Authorization'] );
		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( 'max-recovery', $body['pattern'] );
		$this->assertSame( array( 'code' => $vars['code'], 'entry' => $vars['recovery_page'], 'stop' => $vars['unsubscribe_url'] ), $body['variables'] );
		$event['params'] = 'code={code}';
		$this->assertInstanceOf( WP_Error::class, $sender->send( 'maxsms', '09123456789', $event, $vars, true ) );
		$this->assertCount( 1, $GLOBALS['anar_test_http_requests'] );
	}

	public function test_maxsms_never_falls_back_to_panelchi_credentials() {
		unset( $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_maxsms'] );
		$provider = new MaxSms( new Settings(), new HttpClient() );
		$this->assertSame( 'anar_sms_not_configured', $provider->send( '+989123456789', '012345', '' )->get_error_code() );
		$sender = new NotificationSender( new Settings(), new HttpClient() );
		$this->assertInstanceOf( WP_Error::class, $sender->send( 'maxsms', '+989123456789', array( 'template' => 'order', 'params' => 'order={order_id}', 'message' => '{order_id}' ), array( 'order_id' => '123' ) ) );
		$this->assertCount( 0, $GLOBALS['anar_test_http_requests'] );
	}

	public function test_ippanel_success_requires_true_status_and_message_ids() {
		$provider = new IPPanel( new Settings(), new HttpClient() );
		foreach ( array( false, 'false', null ) as $status ) {
			$this->respond( array( 'meta' => array( 'status' => $status ), 'data' => array( 'message_outbox_ids' => array( 11 ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $provider->send( '+989123456789', '12345', '' ) );
		}
		$this->respond( array( 'meta' => array( 'status' => true ), 'data' => array( 'message_outbox_ids' => array( 11 ) ) ) );
		$this->assertTrue( $provider->send( '+989123456789', '12345', '' ) );
		$body = json_decode( $GLOBALS['anar_test_http_requests'][3]['args']['body'], true );
		$this->assertSame( 'pattern', $body['sending_type'] );
		$this->assertSame( array( '+989123456789' ), $body['recipients'] );
		$this->assertSame( array( 'code' => '12345' ), $body['params'] );
	}
}
