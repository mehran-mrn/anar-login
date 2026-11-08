<?php
/**
 * Campaign and prize controls with contextual help.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Admin\Help;

defined( 'ABSPATH' ) || exit;

final class GameAdmin {

	private static function input( $key, $label, $value, $type = 'number', $help = '' ) {
		$id = 'anar-game-' . sanitize_key( $key );
		?>
		<div class="anar-commerce-field"><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><?php Help::button( $help ); ?><br><input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo 'number' === $type ? 'min="0" step="any"' : ''; ?>></div>
		<?php
	}

	private static function select( $key, $label, $value, $options, $help = '' ) {
		$id = 'anar-game-' . sanitize_key( $key );
		?>
		<div class="anar-commerce-field"><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><?php Help::button( $help ); ?><br><select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>">
		<?php
		foreach ( $options as $key => $text ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html( $text ); ?></option><?php endforeach; ?></select></div>
		<?php
	}

	public static function render( $config ) {
		wp_enqueue_style( 'anar-game-preview', ANAR_LOGIN_URL . 'assets/css/games.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-game-design', ANAR_LOGIN_URL . 'assets/js/games-admin.js', array(), ANAR_LOGIN_VERSION, true );
		?>
		<h3><?php esc_html_e( 'Play limits and campaign', 'anar-toolkit' ); ?></h3><div class="anar-game-settings-grid">
		<?php
		self::select( 'game_quota_mode', __( 'How daily attempts are counted', 'anar-toolkit' ), $config['game_quota_mode'], array( 'shared' => __( 'One shared limit for all games', 'anar-toolkit' ), 'independent' => __( 'A separate limit for each game', 'anar-toolkit' ), 'combined' => __( 'Per-game limits and a total daily limit', 'anar-toolkit' ) ), __( 'Choose separate limits to let a customer play each game once a day. Existing installations keep their shared limit until you change this setting. Attempts already used today still count.', 'anar-toolkit' ) );
		self::input( 'games_total_limit', __( 'Total daily attempts in combined mode', 'anar-toolkit' ), $config['games_total_limit'] );
		self::input( 'game_start', __( 'Campaign starts', 'anar-toolkit' ), $config['game_start'], 'date', __( 'Optional Gregorian dates in the WordPress timezone. The end date is inclusive. Leave both empty for an ongoing promotion.', 'anar-toolkit' ) );
		self::input( 'game_end', __( 'Campaign ends', 'anar-toolkit' ), $config['game_end'], 'date' );
		self::select( 'game_skin', __( 'Game appearance', 'anar-toolkit' ), $config['game_skin'], array( 'berry' => __( 'Berry and cream', 'anar-toolkit' ), 'ocean' => __( 'Ocean and sand', 'anar-toolkit' ), 'forest' => __( 'Forest and gold', 'anar-toolkit' ) ) );
		self::input( 'game_color', __( 'Custom accent color', 'anar-toolkit' ), $config['game_color'], 'text', __( 'Optional six-digit color such as #86405B. Leave empty to use the selected palette. All games use your site font.', 'anar-toolkit' ) );
		?>
		</div><?php self::preview( $config ); ?><h3><?php esc_html_e( 'Shared prize pool', 'anar-toolkit' ); ?><?php Help::button( __( 'Add up to eight prizes. With no named rows, the single reward below remains active. A named row enables the pool. Keep the row in the same position when editing: its issued stock is tracked by that position. Changing labels or limits does not reset issued prizes.', 'anar-toolkit' ) ); ?></h3><div class="anar-game-settings-grid">
		<?php
		self::input( 'game_max_winners', __( 'Maximum winners per pool', 'anar-toolkit' ), $config['game_max_winners'], 'number', __( 'Zero means unlimited. Issued and reserved coupons both count. Shared games draw from one pool; a dedicated game has its own counters. Deleting customer records does not replenish stock.', 'anar-toolkit' ) );
		self::input( 'game_money_budget', __( 'Monetary budget per pool', 'anar-toolkit' ), $config['game_money_budget'], 'number', __( 'Enter the total in the current store currency. Zero means no monetary budget. New capped coupons reserve their maximum cost, including the tax reduction. Paid orders consume the actual discount and release the remainder. Unused expired coupons release their reservation. Saved unpaid orders keep it reserved. Older uncapped coupons are outside this new budget.', 'anar-toolkit' ) );
		self::input( 'game_reward_cap', __( 'Maximum money discount for the single reward', 'anar-toolkit' ), $config['game_reward_cap'], 'number', __( 'Required when a monetary budget is enabled and no named prizes are configured. This is the maximum saving from one coupon, including tax, in the store currency. Zero leaves the old reward uncapped when no monetary budget is set.', 'anar-toolkit' ) );
		self::input( 'game_percent_budget', __( 'Total percentage points per pool', 'anar-toolkit' ), $config['game_percent_budget'], 'number', __( 'An optional issuance limit, not a monetary budget. A 10% coupon uses 10 points. For example, 100 points permits ten 10% coupons. Fixed-amount prizes do not use these points. Zero means unlimited.', 'anar-toolkit' ) );
		self::select( 'game_pool_empty', __( 'When no prizes remain', 'anar-toolkit' ), $config['game_pool_empty'], array( 'stop' => __( 'Stop new games', 'anar-toolkit' ), 'continue' => __( 'Continue without a prize', 'anar-toolkit' ) ) );
		?>
		</div>
		<?php
		for ( $i = 1; $i <= 8; ++$i ) :
			$key = 'p' . $i;
			$prize = $config['game_prizes'][ $key ] ?? array( 'label' => '', 'type' => 'percent', 'amount' => 5, 'weight' => 1, 'stock' => 0, 'customer_limit' => 0, 'hours' => 48, 'cap' => '0', 'minimum' => 0, 'products' => '' );
			$prefix = 'game_prizes][' . $key . '][';
			global $wpdb;
			$issued = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pool = %s AND prize = %s', ( new Repository() )->table( 'game_awards' ), 'shared', $key ) );
			?>
			<details class="anar-game-settings"><summary><strong><?php echo esc_html( $prize['label'] ?: sprintf( /* translators: %d: prize row. */ __( 'Prize %d — unused', 'anar-toolkit' ), $i ) ); ?></strong></summary><p><?php echo esc_html( sprintf( /* translators: %d: issued or reserved coupons. */ __( 'Issued or reserved in the shared pool: %d', 'anar-toolkit' ), $issued ) ); ?></p><div class="anar-game-settings-grid">
			<?php
			self::input( $prefix . 'label', __( 'Prize display name', 'anar-toolkit' ), $prize['label'], 'text', __( 'For example: 10% off your next order. This name appears on the wheel and above the coupon. Clear it to remove this prize from future draws.', 'anar-toolkit' ) );
			self::select( $prefix . 'type', __( 'Discount type', 'anar-toolkit' ), $prize['type'], array( 'percent' => __( 'Percentage', 'anar-toolkit' ), 'fixed_cart' => __( 'Fixed cart amount', 'anar-toolkit' ) ) );
			self::input( $prefix . 'amount', __( 'Coupon discount amount', 'anar-toolkit' ), $prize['amount'] );
			self::input( $prefix . 'cap', __( 'Maximum money discount per coupon', 'anar-toolkit' ), $prize['cap'] ?? '0', 'number', __( 'The maximum total saving, including the tax reduction, in the store currency. Required for every prize when a monetary budget is enabled. The original percentage still applies up to this ceiling. Zero leaves the prize uncapped when no monetary budget is set.', 'anar-toolkit' ) );
			self::input( $prefix . 'weight', __( 'Prize selection weight', 'anar-toolkit' ), $prize['weight'], 'number', __( 'Used only after a winning result. Weights 6, 3 and 1 give available prizes relative chances of 60%, 30% and 10%. Sold-out prizes are removed before drawing.', 'anar-toolkit' ) );
			self::input( $prefix . 'stock', __( 'Maximum coupons for this prize', 'anar-toolkit' ), $prize['stock'], 'number', __( 'Zero means unlimited. This is the total number of coupons for this row, including those already issued, not extra stock to add.', 'anar-toolkit' ) );
			self::input( $prefix . 'customer_limit', __( 'Maximum wins per customer', 'anar-toolkit' ), $prize['customer_limit'], 'number', __( 'Zero means unlimited. This limit applies to this prize across games using the same pool.', 'anar-toolkit' ) );
			self::input( $prefix . 'hours', __( 'Coupon validity in hours', 'anar-toolkit' ), $prize['hours'] );
			self::input( $prefix . 'minimum', __( 'Minimum order amount', 'anar-toolkit' ), $prize['minimum'] );
			self::input( $prefix . 'products', __( 'Eligible product IDs', 'anar-toolkit' ), $prize['products'], 'text', __( 'Optional comma-separated WooCommerce product IDs. Leave empty for all eligible products.', 'anar-toolkit' ) );
			?>
			</div></details>
			<?php
		endfor;
		$shared_config = $config;
		$shared_config['game_pool_scopes']['wheel'] = 'shared';
		$usage = GameCampaign::availability( $shared_config, 'wheel' );
		$money = $wpdb->get_row( $wpdb->prepare( 'SELECT COALESCE(SUM(money_reserved),0) AS reserved, COALESCE(SUM(money_spent),0) AS spent FROM %i WHERE pool = %s AND currency = %s', ( new Repository() )->table( 'game_awards' ), 'shared', get_woocommerce_currency() ) );
		?>
		<p><?php echo esc_html( sprintf( /* translators: 1: reserved/issued coupons, 2: percentage points. */ __( 'Shared pool: %1$d coupons reserved or issued; %2$s percentage points used.', 'anar-toolkit' ), $usage['winners'], $usage['units'] ) ); ?></p><p><?php echo esc_html( sprintf( /* translators: 1: committed amount, 2: current currency. */ __( 'Reserved and spent from this pool: %1$s %2$s.', 'anar-toolkit' ), GameBudget::money_number( $usage['money'] ?? 0 ), get_woocommerce_currency() ) ); ?></p><p><?php echo esc_html( sprintf( /* translators: 1: reserved money, 2: spent money, 3: currency. */ __( 'Pending commitments: %1$s. Actual discounts spent: %2$s. Currency: %3$s.', 'anar-toolkit' ), GameBudget::money_number( $money->reserved ?? 0 ), GameBudget::money_number( $money->spent ?? 0 ), get_woocommerce_currency() ) ); ?></p>
		<?php
	}

	private static function preview( $config ) {
		?>
		<aside class="anar-game anar-skin-<?php echo esc_attr( $config['game_skin'] ); ?>" data-anar-game-preview style="max-width:430px;margin:20px 0;background:var(--game-paper);color:var(--game-accent);border-radius:18px;padding:20px;<?php echo $config['game_color'] ? esc_attr( '--game-accent:' . $config['game_color'] ) : ''; ?>"><strong><?php esc_html_e( 'Appearance preview', 'anar-toolkit' ); ?></strong><div style="display:flex;gap:20px;padding:18px 0">
		<?php
		GameView::icon( 'gift' );
		GameView::icon( 'gem' );
		GameView::icon( 'leaf' );
		?>
		</div><span style="display:inline-block;background:var(--game-accent);color:white;border-radius:10px;padding:8px 24px"><?php esc_html_e( 'A gift from your store', 'anar-toolkit' ); ?></span></aside>
		<?php
	}

	public static function game( $type, $config ) {
		self::input( 'game_limits][' . $type, __( 'Daily attempts for this game', 'anar-toolkit' ), $config['game_limits'][ $type ] ?? $config['games_limit'], 'number', __( 'Used in separate and combined modes. A value of 1 lets each customer play this game once a day.', 'anar-toolkit' ) );
		self::select( 'game_pool_scopes][' . $type, __( 'Prize pool for this game', 'anar-toolkit' ), $config['game_pool_scopes'][ $type ] ?? 'shared', array( 'shared' => __( 'Shared with other games', 'anar-toolkit' ), 'dedicated' => __( 'Dedicated stock for this game', 'anar-toolkit' ) ), __( 'A dedicated pool uses the same prize rows with independent stock, winner and percentage-point counters.', 'anar-toolkit' ) );
	}
}
