<?php
/**
 * Budget input and reservation boundaries.
 *
 * @package AnarLogin
 */

use Anar\Login\Marketing\GameBudget;
use Anar\Login\Marketing\GameCampaign;
use PHPUnit\Framework\TestCase;

final class GameBudgetTest extends TestCase {
	public function test_uncapped_prize_is_allowed_only_without_money_budget() {
		$this->assertTrue( GameBudget::fits( array( 'game_money_budget' => 0 ), array( 'cap' => 0 ), 0 ) );
		$this->assertFalse( GameBudget::fits( array( 'game_money_budget' => 100 ), array( 'cap' => 0 ), 0 ) );
	}

	public function test_reservation_includes_existing_spent_and_reserved_money() {
		$this->assertTrue( GameBudget::fits( array( 'game_money_budget' => 100 ), array( 'cap' => 40 ), 60 ) );
		$this->assertFalse( GameBudget::fits( array( 'game_money_budget' => 100 ), array( 'cap' => 40 ), 60.01 ) );
		$this->assertFalse( GameBudget::fits( array( 'game_money_budget' => 50 ), array( 'cap' => 40 ), 60 ) );
	}

	public function test_money_fields_and_each_prize_ceiling_are_sanitized() {
		$config = GameCampaign::sanitize( array( 'game_money_budget' => -100, 'game_reward_cap' => 50.125, 'game_prizes' => array( 'p1' => array( 'label' => 'Gift', 'amount' => 20, 'cap' => 125.123 ) ) ) );
		$this->assertSame( '0', $config['game_money_budget'] );
		$this->assertSame( '50.13', $config['game_reward_cap'] );
		$this->assertSame( '125.12', $config['game_prizes']['p1']['cap'] );
	}
}
