<?php
/**
 * Native balance schemas, secret isolation and warning boundaries.
 *
 * @package AnarLogin
 */

use Anar\Login\Balance\Accounts;
use Anar\Login\Balance\Gateways;
use Anar\Login\Balance\Monitor;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Settings.php';
require_once dirname( __DIR__, 2 ) . '/src/Messaging/HttpClient.php';
require_once dirname( __DIR__, 2 ) . '/src/Balance/Gateways.php';
require_once dirname( __DIR__, 2 ) . '/src/Balance/Accounts.php';
require_once dirname( __DIR__, 2 ) . '/src/Balance/Monitor.php';

final class BalanceTest extends TestCase {
	private $original;
	protected function setUp(): void {
		$this->original = $GLOBALS['anar_test_options'] ?? array();
		$GLOBALS['anar_test_http_requests'] = array();
		$GLOBALS['anar_test_options'] = array( Settings::OPTION_KEY => array( 'provider_panelchi' => array( 'api_key' => 'QA-KEY-ONLY' ) ), Features::OPTION => array( 'login' => 0, 'reset' => 0, 'orders' => 0 ), Monitor::OPTION => array( 'enabled' => 1, 'accounts' => array( 'panelchi' => array( 'monitor' => 1, 'warn' => 1, 'threshold' => '10', 'unit' => 'SMS_UNIT' ) ) ) );
	}
	protected function tearDown(): void {
		$GLOBALS['anar_test_options'] = $this->original;
	}

	public function test_native_balances_and_locked_credit_are_not_mixed() {
		$cases = array(
			'melipayamak' => array( array( 'RetStatus' => 1, 'Value' => '0' ), 0.0, 'CREDIT' ),
			'kavenegar' => array( array( 'return' => array( 'status' => 200 ), 'entries' => array( 'remaincredit' => 1500000 ) ), 1500000.0, 'IRR' ),
			'ippanel' => array( array( 'meta' => array( 'status' => true ), 'data' => array( 'credit' => 25, 'gift' => 50, 'yesterday_credit' => 100 ) ), 25.0, 'CREDIT' ),
			'panelchi' => array( array( 'data' => array( 'wallet' => array( 'availableBalance' => '49.20', 'totalBalance' => '51.20', 'lockedBalance' => '2.00', 'unit' => 'SMS_UNIT' ) ) ), 49.2, 'SMS_UNIT' ),
			'maxsms' => array( array( 'data' => array( 'wallet' => array( 'availableBalance' => '-2', 'unit' => 'IR' ) ) ), -2.0, 'IR' ),
			'smsir' => array( array( 'status' => 1, 'data' => 12.5 ), 12.5, 'CREDIT' ),
			'farazsms' => array( array( 'status' => 'success', 'data' => array( 'balanceAmount' => 5000, 'balanceCount' => 25 ) ), 5000.0, 'IRT' ),
		);
		foreach ( $cases as $provider => $case ) {
			$this->assertSame( array( 'value' => $case[1], 'unit' => $case[2] ), Gateways::parse( $provider, $case[0] ) );
		}
	}

	public function test_provider_errors_never_become_a_zero_balance() {
		foreach ( Gateways::providers() as $provider ) {
			$this->assertInstanceOf( WP_Error::class, Gateways::parse( $provider, array( 'error' => 'zero', 'Value' => 0, 'data' => 0 ) ) );
		}
		foreach ( array( null, true, false, array(), 'NaN', INF, -INF, '99999999999999999999999' ) as $value ) {
			$this->assertNull( Gateways::number( $value ) );
		}
		$this->assertInstanceOf( WP_Error::class, Gateways::parse( 'panelchi', array( 'data' => array( 'wallet' => array( 'availableBalance' => 0, 'unit' => 'UNKNOWN' ) ) ) ) );
	}

	public function test_faraz_uses_a_separate_key_and_only_documented_balance_url() {
		$gateway = new Gateways();
		$this->assertInstanceOf( WP_Error::class, $gateway->fetch( 'farazsms', array( 'username' => 'legacy', 'password' => 'legacy-password' ) ) );
		$this->assertCount( 0, $GLOBALS['anar_test_http_requests'] );
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'status' => 'success', 'data' => array( 'balanceAmount' => 5000 ) ) ) );
		$result = $gateway->fetch( 'farazsms', array( 'balance_api_key' => 'QA-BALANCE-KEY', 'password' => 'NEVER-SEND-THIS' ) );
		$this->assertSame( 5000.0, $result['value'] );
		$request = $GLOBALS['anar_test_http_requests'][0];
		$this->assertSame( 'https://api.iranpayamak.com/ws/v1/account/balance', $request['url'] );
		$this->assertSame( 'QA-BALANCE-KEY', $request['args']['headers']['Api-Key'] );
		$this->assertStringNotContainsString( 'NEVER-SEND-THIS', wp_json_encode( $request ) );
		$this->assertSame( 0, $request['args']['redirection'] );
	}

	public function test_transport_errors_do_not_expose_provider_bodies_or_tokens() {
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => 401 ), 'body' => 'echoed-token-PRIVATE' );
		$result = ( new Gateways() )->fetch( 'panelchi', array( 'api_key' => 'PRIVATE-KEY' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringNotContainsString( 'PRIVATE', $result->get_error_message() );
	}

	public function test_threshold_accepts_persian_numbers_and_rejects_ambiguous_input() {
		$this->assertSame( 1200.5, Monitor::threshold( '۱٬۲۰۰٫۵' ) );
		$this->assertSame( 0.0, Monitor::threshold( '٠' ) );
		foreach ( array( '', '1,5', '-1', '1e2', '12.12345', '1000000000001', array(), true ) as $value ) {
			$this->assertNull( Monitor::threshold( $value ) );
		}
	}

	private function balance( $changes = array() ) {
		$state = array_replace( array( 'account' => Accounts::fingerprint( 'panelchi' ), 'value' => 10.0, 'unit' => 'SMS_UNIT', 'success_at' => time(), 'checked_at' => time(), 'error' => '' ), $changes );
		$GLOBALS['anar_test_options'][ Monitor::STATE . 'panelchi' ] = $state;
	}

	public function test_warnings_require_opt_in_fresh_success_and_matching_account_unit() {
		$this->balance();
		$this->assertTrue( Monitor::low( 'panelchi' ) );
		$this->balance( array( 'value' => 10.01 ) );
		$this->assertFalse( Monitor::low( 'panelchi' ) );
		foreach ( array( array( 'unit' => 'IR' ), array( 'success_at' => time() - 3601 ), array( 'error' => 'connection' ), array( 'account' => 'different-account' ) ) as $change ) {
			$this->balance( $change );
			$this->assertFalse( Monitor::low( 'panelchi' ) );
		}
		$this->balance();
		$GLOBALS['anar_test_options'][ Monitor::OPTION ]['accounts']['panelchi']['warn'] = 0;
		$this->assertFalse( Monitor::low( 'panelchi' ) );
		$GLOBALS['anar_test_options'][ Monitor::OPTION ]['accounts']['panelchi']['warn'] = 1;
		$GLOBALS['anar_test_options'][ Monitor::OPTION ]['enabled'] = 0;
		$this->assertFalse( Monitor::low( 'panelchi' ) );
		$this->assertFalse( Features::queue_needed() );
		$this->assertCount( 0, $GLOBALS['anar_test_http_requests'] );
	}

	public function test_warning_threshold_cannot_be_saved_against_an_unseen_or_changed_unit() {
		$input = array( 'enabled' => 1, 'accounts' => array( 'panelchi' => array( 'monitor' => 1, 'warn' => 1, 'threshold' => '۱۰', 'unit' => 'SMS_UNIT' ) ) );
		$this->assertInstanceOf( WP_Error::class, Monitor::sanitize( $input ) );
		$this->balance();
		$clean = Monitor::sanitize( $input );
		$this->assertSame( '10', $clean['accounts']['panelchi']['threshold'] );
		$this->assertSame( 1, $clean['accounts']['panelchi']['warn'] );
		$input['accounts']['panelchi']['unit'] = 'IR';
		$this->assertInstanceOf( WP_Error::class, Monitor::sanitize( $input ) );
		$input['accounts']['panelchi']['warn'] = 0;
		$this->assertSame( 0, Monitor::sanitize( $input )['accounts']['panelchi']['warn'] );
	}
}
