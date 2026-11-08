<?php
/**
 * Campaign input boundaries and backwards-compatible settings.
 *
 * @package AnarLogin
 */

use Anar\Login\Marketing\GameCampaign;
use Anar\Login\Marketing\Settings;
use PHPUnit\Framework\TestCase;

final class GameCampaignTest extends TestCase {
	public function test_old_configuration_keeps_shared_quota_and_single_reward() {
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = array( 'games_limit' => 2, 'game_reward_amount' => '12', 'game_reward_type' => 'percent' );
		try {
			$config = ( new Settings() )->all();
			$this->assertSame( 'shared', $config['game_quota_mode'] );
			$this->assertSame( 2, $config['games_limit'] );
			$this->assertSame( '12', GameCampaign::prizes( $config )['legacy']['amount'] );
		} finally {
			unset( $GLOBALS['anar_test_options'][ Settings::OPTION ] );
		}
	}

	public function test_palette_and_dates_reject_markup_and_invalid_days() {
		$value = GameCampaign::sanitize( array( 'game_color' => '#fff;display:none', 'game_skin' => 'unknown', 'game_start' => '2026-02-31', 'game_end' => '2026-11-30' ) );
		$this->assertSame( '', $value['game_color'] );
		$this->assertSame( 'berry', $value['game_skin'] );
		$this->assertSame( '', $value['game_start'] );
		$this->assertSame( '2026-11-30', $value['game_end'] );
	}

	public function test_prize_rows_are_bounded_and_empty_labels_remove_rows() {
		$value = GameCampaign::sanitize( array( 'game_prizes' => array( 'p1' => array( 'label' => '<b>20% gift</b>', 'type' => 'percent', 'amount' => 999, 'weight' => -5, 'hours' => 9000, 'stock' => -1 ), 'p2' => array( 'label' => '' ) ) ) );
		$this->assertCount( 1, $value['game_prizes'] );
		$this->assertSame( '20% gift', $value['game_prizes']['p1']['label'] );
		$this->assertSame( '100', $value['game_prizes']['p1']['amount'] );
		$this->assertSame( 1, $value['game_prizes']['p1']['weight'] );
		$this->assertSame( 720, $value['game_prizes']['p1']['hours'] );
		$this->assertSame( 0, $value['game_prizes']['p1']['stock'] );
	}

	public function test_per_game_limits_do_not_change_other_modules_on_partial_save() {
		$config = ( new Settings() )->defaults();
		$config['modules']['birthday'] = 1;
		$config['game_quota_mode'] = 'independent';
		$GLOBALS['anar_test_options'][ Settings::OPTION ] = $config;
		try {
			$value = ( new Settings() )->sanitize( array( '_partial' => 1, 'game_limits' => array( 'wheel' => 3, 'scratch' => 1 ) ) );
			$this->assertSame( 1, $value['modules']['birthday'] );
			$this->assertSame( 'independent', $value['game_quota_mode'] );
			$this->assertSame( 3, $value['game_limits']['wheel'] );
			$this->assertSame( 1, $value['game_limits']['scratch'] );
		} finally {
			unset( $GLOBALS['anar_test_options'][ Settings::OPTION ] );
		}
	}
}
