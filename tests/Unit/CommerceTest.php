<?php
/**
 * Reminder schedule boundaries and safe message rendering.
 *
 * @package AnarLogin
 */

use Anar\Login\Commerce\Settings;
use Anar\Login\Commerce\Templates;
use PHPUnit\Framework\TestCase;

final class CommerceTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['anar_test_options']  = array();
		$GLOBALS['anar_test_timezone'] = 'Asia/Tehran';
	}

	public function test_new_modules_are_opt_in() {
		$settings = new Settings();
		$this->assertFalse( (bool) $settings->enabled() );
		$this->assertSame( 30, $settings->get( 'events' )['recovery_1']['minutes'] );
	}

	public function test_sparse_settings_preserve_event_defaults() {
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = array( 'events' => array( 'paid_customer' => array( 'template' => 'approved' ) ) );
		$events = ( new Settings() )->get( 'events' );
		$this->assertSame( 'approved', $events['paid_customer']['template'] );
		$this->assertArrayHasKey( 'message', $events['paid_customer'] );
		$this->assertArrayHasKey( 'recovery_3', $events );
	}

	public function test_phone_recipients_are_normalized_deduplicated_and_limited() {
		$input = array( 'admin_phones' => "۰۹۱۲۱۲۳۴۵۶۷\n+989121234567\nbad\nmail@example.test\n09121111111\n09122222222\n09123333333\n09124444444\n09125555555" );
		$value = ( new Settings() )->sanitize( $input );
		$this->assertSame( 5, count( explode( "\n", $value['admin_phones'] ) ) );
		$this->assertStringStartsWith( '+989121234567', $value['admin_phones'] );
		$this->assertStringNotContainsString( 'example', $value['admin_phones'] );
	}

	public function test_schedule_is_ordered_bounded_and_can_disable_a_stage() {
		$value = ( new Settings() )->sanitize( array( 'events' => array( 'recovery_1' => array( 'minutes' => '۶۰' ), 'recovery_2' => array( 'minutes' => 30 ), 'recovery_3' => array( 'minutes' => 0 ) ), 'daily_limit' => 100, 'retention' => 7, 'link_days' => 30, 'start_hour' => 23, 'end_hour' => 9 ) );
		$this->assertSame( 60, $value['events']['recovery_1']['minutes'] );
		$this->assertSame( 61, $value['events']['recovery_2']['minutes'] );
		$this->assertSame( 0, $value['events']['recovery_3']['minutes'] );
		$this->assertSame( 10, $value['daily_limit'] );
		$this->assertSame( 7, $value['link_days'] );
		$this->assertSame( 24, $value['end_hour'] );
	}

	public function test_quiet_hours_use_the_site_timezone() {
		$settings = new Settings();
		$at = static function ( $time ) {
			return ( new DateTimeImmutable( $time, wp_timezone() ) )->getTimestamp();
		};
		$this->assertSame( $at( '2026-10-29 09:00' ), $settings->allowed_time( $at( '2026-10-29 08:59' ) ) );
		$this->assertSame( $at( '2026-10-29 20:59' ), $settings->allowed_time( $at( '2026-10-29 20:59' ) ) );
		$this->assertSame( $at( '2026-10-30 09:00' ), $settings->allowed_time( $at( '2026-10-29 21:00' ) ) );
	}

	public function test_quiet_hours_account_for_daylight_saving() {
		$GLOBALS['anar_test_timezone'] = 'Europe/Berlin';
		$at = ( new DateTimeImmutable( '2026-10-24 22:00', wp_timezone() ) )->getTimestamp();
		$expected = ( new DateTimeImmutable( '2026-10-25 09:00', wp_timezone() ) )->getTimestamp();
		$this->assertSame( $expected, ( new Settings() )->allowed_time( $at ) );
	}

	public function test_mapping_preserves_case_urls_and_equals_signs() {
		$params = Templates::parameters( "ORDER={order_id}\nlink={recovery_url}\ninvalid key=ignored\nno-equals", array( 'order_id' => '<b>12</b>', 'recovery_url' => 'https://example.test/?a=1&b=2' ) );
		$this->assertSame( array( 'ORDER' => '12', 'link' => 'https://example.test/?a=1&b=2' ), $params );
	}

	public function test_unicode_segment_boundaries_include_emoji_surrogates() {
		$this->assertSame( 1, Templates::segments( str_repeat( 'آ', 70 ) ) );
		$this->assertSame( 2, Templates::segments( str_repeat( 'آ', 71 ) ) );
		$this->assertSame( 2, Templates::segments( str_repeat( 'آ', 134 ) ) );
		$this->assertSame( 3, Templates::segments( str_repeat( 'آ', 135 ) ) );
		$this->assertSame( 2, Templates::segments( str_repeat( '😀', 36 ) ) );
	}
}
