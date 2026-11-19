<?php
/**
 * Anar toolkit update: configuration, delivery and presentation.
 *
 * @package AnarLogin
 */

use PHPUnit\Framework\TestCase;
use Anar\Login\Commerce\CappedDiscount;
use Anar\Login\Messaging\Providers\Bale;
use Anar\Login\Messaging\HttpClient;
use Anar\Login\Infrastructure\Settings;
require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();
function get_transient( $key ) {
	return $GLOBALS['anar_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['anar_test_transients'][ $key ] = $value;
	$GLOBALS['anar_test_transient_ttls'][ $key ] = $ttl; }
function delete_transient( $key ) {
	unset( $GLOBALS['anar_test_transients'][ $key ] ); }
function wc_add_number_precision( $value ) {
	return round( $value * 100 ); }
final class UpdateFeaturesTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_transients'], $GLOBALS['anar_test_http_response'], $GLOBALS['anar_test_options'], $GLOBALS['anar_test_http_requests'] ); }
	public function test_bale_authenticates_and_sends_numeric_code_to_international_phone() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'provider_bale' => array( 'username' => 'test', 'password' => 'secret' ) );
		$GLOBALS['anar_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"test-token","expires_in":3600,"balance":10}' );
		$provider = new Bale( new Settings(), new HttpClient() );
		$this->assertTrue( $provider->send( '+989123456789', '123456', '' ) );
		$requests = $GLOBALS['anar_test_http_requests'];
		$this->assertCount( 2, $requests );
		$this->assertSame( 'client_credentials', $requests[0]['args']['body']['grant_type'] );
		$this->assertSame( array( 'phone' => '989123456789', 'otp' => 123456 ), json_decode( $requests[1]['args']['body'], true ) );
		$this->assertSame( 'Bearer test-token', $requests[1]['args']['headers']['Authorization'] );
		$this->assertTrue( $provider->send( '+989123456789', '654321', '' ) );
		$this->assertCount( 3, $GLOBALS['anar_test_http_requests'] );
	}
	public function test_bale_rejects_text_and_invalid_codes_without_http() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'provider_bale' => array( 'username' => 'test', 'password' => 'secret' ) );
		$provider = new Bale( new Settings(), new HttpClient() );
		$this->assertInstanceOf( WP_Error::class, $provider->send( '+989123456789', '012345', '' ) );
		$this->assertInstanceOf( WP_Error::class, $provider->send_text( '+989123456789', 'message' ) );
		$this->assertEmpty( $GLOBALS['anar_test_http_requests'] ?? array() );
	}
	public function test_recovery_discount_ceiling_and_repeated_calculation() {
		$cap = new CappedDiscount();
		$coupon = new class() { public function get_meta( $key ) {
				return '_anar_recovery_money_cap' === $key ? 100 : '';
		} public function set_discount_type( $type ) {} };
		$property = new ReflectionProperty( CappedDiscount::class, 'contexts' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true ); }
		$contexts = $property->getValue( $cap );
		foreach ( array( array( 'a' => 15000, 'b' => 15000 ), array( 'a' => 4000, 'b' => 3000 ) ) as $values ) {
			$contexts[ $coupon ] = array( 'restore' => 'anar_recovery_capped', 'tax' => array() );
			$result = $cap->limit( $values, $coupon );
			$this->assertLessThanOrEqual( 10000, array_sum( $result ) );
			if ( array_sum( $values ) < 10000 ) {
				$this->assertSame( $values, $result ); }
			$this->assertFalse( $contexts->offsetExists( $coupon ) );
		}
	}

	public function test_nested_elementor_recovery_widgets_are_detected_before_headers() {
		$this->assertTrue( \Anar\Login\Integrations\Elementor::contains_recovery( array( array( 'elType' => 'container', 'elements' => array( array( 'widgetType' => 'anar-recovery' ) ) ) ) ) );
		$this->assertFalse( \Anar\Login\Integrations\Elementor::contains_recovery( array( array( 'widgetType' => 'anar-login' ) ) ) );
		$this->assertFalse( \Anar\Login\Integrations\Elementor::contains_recovery( null ) );
	}
	public function test_store_provider_uses_only_the_active_connection() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'sms_provider' => 'panelchi' );
		$store = new \Anar\Login\Commerce\Settings();
		$this->assertSame( 'panelchi', $store->provider() );
		$GLOBALS['anar_test_options']['anar_toolkit_commerce'] = array( 'provider' => 'maxsms' );
		$this->assertSame( 'panelchi', $store->provider() );
	}
}
