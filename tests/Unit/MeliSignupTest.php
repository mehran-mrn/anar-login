<?php
/**
 * Registration boundaries and response validation, without external requests.
 *
 * @package AnarLogin
 */

use Anar\Login\Partners\MeliSignup;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Partners/MeliSignup.php';

final class MeliSignupTest extends TestCase {

	private function input() {
		return array( 'first_name' => 'نام', 'last_name' => 'آزمایشی', 'mobile_number' => '۰۹۱۲۱۲۳۴۵۶۷', 'product_code' => 2, 'ticket' => '1780000000.' . str_repeat( 'a', 32 ) . '.' . str_repeat( 'b', 64 ), 'consent' => true );
	}

	protected function setUp(): void {
		$GLOBALS['anar_test_http_requests'] = array();
	}

	private function response( $data, $status = 200 ) {
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => $status ), 'body' => wp_json_encode( $data ) );
	}

	public function test_no_request_until_explicit_action_and_unavailable_falls_back() {
		$service = new MeliSignup();
		$this->assertCount( 0, $GLOBALS['anar_test_http_requests'] );
		$this->response( array( 'ready' => false ) );
		$this->assertSame( array( 'ready' => false ), $service->products() );
		$this->assertSame( MeliSignup::RELAY . 'products', $GLOBALS['anar_test_http_requests'][0]['url'] );
		$this->assertArrayNotHasKey( 'body', $GLOBALS['anar_test_http_requests'][0]['args'] );
	}

	public function test_products_only_exposes_validated_plan_data() {
		$this->response( array( 'ready' => true, 'ticket' => $this->input()['ticket'], 'token' => 'MUST-NOT-REACH-UI', 'price_unit' => 'bogus', 'products' => array( array( 'product_code' => 2, 'product_title' => '<b>حرفه‌ای</b>', 'product_price' => 199000, 'extra' => 'secret' ) ) ) );
		$data = ( new MeliSignup() )->products();
		$this->assertTrue( $data['ready'] );
		$this->assertSame( '', $data['price_unit'] );
		$this->assertSame( 'حرفه‌ای', $data['products'][0]['product_title'] );
		$this->assertArrayNotHasKey( 'token', $data );
		$this->assertArrayNotHasKey( 'extra', $data['products'][0] );
		$this->assertArrayNotHasKey( 'X-Affiliate-Api-Token', $GLOBALS['anar_test_http_requests'][0]['args']['headers'] );
	}

	public function test_registration_requires_consent_and_valid_input_before_network() {
		$input = $this->input();
		$valid = MeliSignup::payload( $input );
		$this->assertSame( '09121234567', $valid['mobile_number'] );
		foreach ( array( array( 'consent' => false ), array( 'consent' => 'true' ), array( 'mobile_number' => 'name@example.test' ), array( 'last_name' => '' ), array( 'first_name' => array() ), array( 'product_code' => '2' ), array( 'ticket' => 'bad' ), array( 'website' => 'spam' ) ) as $change ) {
			$this->assertInstanceOf( WP_Error::class, ( new MeliSignup() )->register( array_replace( $input, $change ) ) );
		}
		$this->assertCount( 0, $GLOBALS['anar_test_http_requests'] );
	}

	public function test_invoice_is_on_official_https_host_only() {
		$this->assertSame( 'https://sign.melipayamak.com/invoice/123', MeliSignup::invoice_url( 'https://sign.melipayamak.com/invoice/123' ) );
		foreach ( array( 'http://sign.melipayamak.com/invoice/123', 'https://evil.test/invoice/123', 'https://sign.melipayamak.com.evil.test/invoice/123', 'https://user@sign.melipayamak.com/invoice/123', 'https://sign.melipayamak.com:444/invoice/123', 'https://sign.melipayamak.com/invoice/123?next=evil', 'https://sign.melipayamak.com/other/123', array() ) as $url ) {
			$this->assertSame( '', MeliSignup::invoice_url( $url ) );
		}
	}

	public function test_success_returns_invoice_but_no_payment_or_activation_claim() {
		$this->response( array( 'invoice_url' => 'https://sign.melipayamak.com/invoice/123', 'password' => 'NEVER-EXPOSE' ) );
		$data = ( new MeliSignup() )->register( $this->input() );
		$this->assertSame( 'https://sign.melipayamak.com/invoice/123', $data['invoice_url'] );
		$this->assertArrayNotHasKey( 'password', $data );
		$this->assertArrayNotHasKey( 'paid', $data );
		$request = $GLOBALS['anar_test_http_requests'][0];
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertStringNotContainsString( 'referral_code', $request['args']['body'] );
		$this->assertStringNotContainsString( 'api_token', $request['args']['body'] );
	}
}
