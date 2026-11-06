<?php
/**
 * Feature isolation and configuration preservation.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Features;
use Anar\Login\Marketing\Settings;
use PHPUnit\Framework\TestCase;

final class FeaturesTest extends TestCase {
	private $original;
	protected function setUp(): void {
		$this->original = $GLOBALS['anar_test_options'] ?? array();
		$GLOBALS['anar_test_options'] = array( Features::OPTION => array( 'login' => 0, 'reset' => 0, 'login_log' => 0, 'orders' => 0 ) ); }
	protected function tearDown(): void {
		$GLOBALS['anar_test_options'] = $this->original; }

	public function test_all_disabled_needs_no_queue_or_customer_facts() {
		$this->assertEmpty( array_filter( Features::all() ) );
		$this->assertFalse( Features::queue_needed() );
		$this->assertFalse( Features::facts_needed() );
	}

	public function test_print_only_invoices_need_no_worker() {
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = array( 'modules' => array( 'invoice' => 1 ) );
		$this->assertTrue( Features::enabled( 'invoice' ) );
		$this->assertFalse( Features::queue_needed() );
		$this->assertFalse( Features::facts_needed() );
		$GLOBALS['anar_test_options'][ Settings::OPTION ]['rules']['invoice']['enabled'] = 1;
		$this->assertTrue( Features::queue_needed() );
		$this->assertTrue( Features::facts_needed() );
	}

	public function test_stopping_order_module_preserves_its_saved_event_selection() {
		$GLOBALS['anar_test_options']['anar_toolkit_commerce'] = array( 'paid_customer' => 1 );
		$this->assertFalse( Features::enabled( 'orders' ) );
		$this->assertFalse( Features::queue_needed() );
		$GLOBALS['anar_test_options'][ Features::OPTION ]['orders'] = 1;
		$this->assertTrue( Features::enabled( 'orders' ) );
		$this->assertTrue( Features::queue_needed() );
	}

	public function test_games_only_does_not_start_message_worker() {
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = array( 'modules' => array( 'games' => 1 ) );
		$this->assertTrue( Features::facts_needed() );
		$this->assertFalse( Features::queue_needed() );
		$this->assertFalse( Features::enabled( 'traffic' ) );
	}

	public function test_partial_settings_keep_other_modules_and_allow_unchecking_a_stage() {
		$settings = new Settings();
		$saved = $settings->defaults();
		$saved['modules']['games'] = 1;
		$saved['rules']['bulk']['template'] = 'keep-this-template';
		$saved['game_reward_amount'] = '9';
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = $saved;
		$clean = $settings->sanitize( array( '_partial' => 1, 'rules' => array( 'welcome' => array( 'enabled' => 0 ) ) ) );
		$this->assertSame( 1, $clean['modules']['games'] );
		$this->assertSame( 'keep-this-template', $clean['rules']['bulk']['template'] );
		$this->assertSame( '9', $clean['game_reward_amount'] );
		$this->assertSame( 0, $clean['rules']['welcome']['enabled'] );
	}
}
