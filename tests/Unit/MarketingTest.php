<?php
/**
 * Prediction quality and campaign audience configuration boundaries.
 *
 * @package AnarLogin
 */

use Anar\Login\Marketing\Prediction;
use Anar\Login\Marketing\Settings;
use Anar\Login\Marketing\Contacts;
use PHPUnit\Framework\TestCase;

final class MarketingTest extends TestCase {
	public function test_insufficient_and_duplicate_history_produce_no_prediction() {
		$this->assertNull( Prediction::estimate( array( 100, 100, 200 ) ) );
		$this->assertNull( Prediction::estimate( array( 100, 200, 300 ) ) );
	}

	public function test_regular_intervals_and_outlier_confidence() {
		$regular = Prediction::estimate(
			array_map(
				static function ( $day ) {
					return $day * DAY_IN_SECONDS;
				},
				array( 1, 31, 61, 91, 121 )
			)
		);
		$this->assertSame( 30, $regular['days'] );
		$this->assertSame( 100, $regular['confidence'] );
		$this->assertSame( 4, $regular['samples'] );
		$irregular = Prediction::intervals( array( 2, 20, 70, 300 ) );
		$this->assertLessThan( 60, $irregular['confidence'] );
		$this->assertSame( 2.5, Prediction::median( array( 4, 1, 3, 2 ) ) );
	}

	public function test_contact_import_normalizes_deduplicates_and_rejects_non_phone_identities() {
		$parsed = Contacts::parse( "۰۹۱۲۳۳۳۴۴۴۴,Customer\n+989123334444\nattacker@example.test\n09125556666\ninvalid" );
		$this->assertSame( array( '+989123334444', '+989125556666' ), $parsed['phones'] );
		$this->assertSame( 2, $parsed['invalid'] );
	}

	public function test_marketing_modules_are_disabled_and_limits_are_clamped() {
		$settings = new Settings();
		$this->assertEmpty( array_filter( $settings->defaults()['modules'] ) );
		$clean = $settings->sanitize( array( 'daily_limit' => 999, 'gap_hours' => -2, 'prediction_minimum' => 1, 'game_reward_amount' => 999, 'game_reward_type' => 'percent', 'retention' => 9999, 'products' => '1,-2,3,1', 'rules' => array( 'birthday' => array( 'enabled' => 1, 'discount_type' => 'percent', 'discount_amount' => 500 ) ) ) );
		$this->assertSame( 10, $clean['daily_limit'] );
		$this->assertSame( 1, $clean['gap_hours'] );
		$this->assertSame( 3, $clean['prediction_minimum'] );
		$this->assertSame( '100', $clean['game_reward_amount'] );
		$this->assertSame( 365, $clean['retention'] );
		$this->assertSame( '100', $clean['rules']['birthday']['discount_amount'] );
		$this->assertSame( '1,2,3', $clean['products'] );
	}

	public function test_game_texts_are_sanitized_and_partial_updates_preserve_other_games() {
		$settings = new Settings();
		$clean = $settings->sanitize( array( 'game_texts' => array( 'wheel' => array( 'reward_label' => '<b>Ten percent off</b>', 'win_message' => array( 'invalid' ), 'unknown' => 'discard' ), 'unknown' => array( 'title' => 'discard' ) ) ) );
		$this->assertSame( 'Ten percent off', $clean['game_texts']['wheel']['reward_label'] );
		$this->assertSame( '', $clean['game_texts']['wheel']['win_message'] );
		$this->assertArrayNotHasKey( 'unknown', $clean['game_texts'] );
		$this->assertArrayNotHasKey( 'unknown', $clean['game_texts']['wheel'] );
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = $clean;
		try {
			$partial = $settings->sanitize( array( '_partial' => 1, 'game_texts' => array( 'quiz' => array( 'title' => 'Store knowledge' ) ) ) );
			$this->assertSame( 'Ten percent off', $partial['game_texts']['wheel']['reward_label'] );
			$this->assertSame( 'Store knowledge', $partial['game_texts']['quiz']['title'] );
		} finally {
			unset( $GLOBALS['anar_test_options'][ Settings::OPTION ] );
		}
	}
}
