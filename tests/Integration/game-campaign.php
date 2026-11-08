<?php
/**
 * Quota, pool, retry and migration regressions. Disposable local WooCommerce only.
 *
 * @package AnarLogin
 */

use Anar\Login\Marketing\GameCampaign;
use Anar\Login\Marketing\Games;
use Anar\Login\Marketing\Repository;
use Anar\Login\Marketing\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Disposable local WooCommerce only.' );
}
global $wpdb;
$original = get_option( Settings::OPTION );
$coupons = get_option( 'woocommerce_enable_coupons' );
$repo = new Repository();
$checks = array();
$profiles = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
$clear = static function () use ( $wpdb, $repo ) {
	foreach ( array( 'plays', 'game_awards' ) as $name ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $repo->table( $name ) ) );
	}
};
add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'qa_no_http', 'External requests blocked.' );
	},
	PHP_INT_MAX
);
add_filter( 'pre_wp_mail', '__return_true' );
try {
	\Anar\Login\Infrastructure\Activator::activate();
	update_option( 'woocommerce_enable_coupons', 'yes' );
	$clear();
	foreach ( array( '+989120000101', '+989120000102', '+989120000103' ) as $phone ) {
		$profiles[] = $repo->profile( $repo->remember( $phone ) );
	}
	$profile = $profiles[0];
	$games = new Games();
	$config = ( new Settings() )->defaults();
	$config['modules']['games'] = 1;
	$config['game_reward_chance'] = 100;
	$config['game_quiz_question'] = 'Test question';
	$config['game_quiz_options'] = "A\nB";
	update_option( Settings::OPTION, $config );
	$first = $games->play( $profile, 'wheel', 0, 0, 'shared-first', 'move-first' );
	$assert( ! is_wp_error( $first ) && $first['won'], 'Legacy shared mode issues the configured single reward' );
	$wpdb->delete( $repo->table( 'game_awards' ), array( 'play_id' => $first['play_id'] ) );
	delete_option( 'anar_game_pool_migrated' );
	$assert( GameCampaign::migrate(), 'Earlier issued coupons migrate into the shared pool' );
	$assert( 1 === GameCampaign::availability( $config, 'wheel' )['winners'], 'Migration retains the earlier winner count' );
	$assert( is_wp_error( $games->play( $profile, 'scratch' ) ), 'Legacy shared mode still counts all games together' );
	$replay = $games->play( $profile, 'wheel', 0, 0, 'shared-first', 'move-first' );
	$assert( $replay['coupon'] === $first['coupon'], 'A lost first response is recovered by request token without another attempt' );
	$config['game_quota_mode'] = 'independent';
	update_option( Settings::OPTION, $config );
	$assert( is_wp_error( $games->play( $profile, 'wheel' ) ), 'Switching quota mode does not restore attempts already spent on that game' );
	$assert( ! is_wp_error( $games->play( $profile, 'scratch' ) ), 'Independent quota permits a different game on the same day' );
	$assert( is_wp_error( $games->play( $profile, 'scratch' ) ), 'Independent quota rejects a second attempt on the same game' );
	$config['game_quota_mode'] = 'combined';
	$config['games_total_limit'] = 2;
	update_option( Settings::OPTION, $config );
	$assert( is_wp_error( $games->play( $profile, 'boxes' ) ), 'Combined quota also enforces the shared total' );
	$clear();
	$config['game_quota_mode'] = 'independent';
	$config['game_prizes'] = array();
	foreach ( array( 5, 10, 20 ) as $i => $amount ) {
		$config['game_prizes'][ 'p' . ( $i + 1 ) ] = array( 'label' => $amount . '% gift', 'type' => 'percent', 'amount' => (string) $amount, 'weight' => 1, 'stock' => 1, 'customer_limit' => 1, 'hours' => 24, 'minimum' => '100', 'products' => '' );
	}
	$config['game_max_winners'] = 3;
	update_option( Settings::OPTION, $config );
	$amounts = array();
	foreach ( array( 'wheel', 'scratch', 'boxes' ) as $game_type ) {
		$result = $games->play( $profile, $game_type );
		$assert( ! is_wp_error( $result ) && $result['won'], 'A distinct stocked prize is issued: ' . $game_type );
		$coupon = new WC_Coupon( wc_get_coupon_id_by_code( $result['coupon'] ) );
		$amounts[] = (int) $coupon->get_amount();
		$assert( '100' === $coupon->get_minimum_amount() && 1 === $coupon->get_usage_limit(), 'Pool coupon enforces minimum spend and one use: ' . $game_type );
	}
	sort( $amounts );
	$assert( array( 5, 10, 20 ) === $amounts, '5%, 10% and 20% are separate prizes, never accumulated' );
	$assert( is_wp_error( $games->play( $profiles[1], 'cards' ) ), 'Pool exhaustion stops a new participant before spending their attempt' );
	$config['game_pool_empty'] = 'continue';
	update_option( Settings::OPTION, $config );
	$result = $games->play( $profiles[1], 'cards', 0, 0, 'empty-replay', 'empty-move' );
	$assert( ! is_wp_error( $result ) && $result['exhausted'] && ! $result['coupon'], 'Continue mode returns an explicit exhausted result' );
	$config['game_max_winners'] = 100;
	foreach ( $config['game_prizes'] as &$prize ) {
		$prize['stock'] = 100;
	}
	unset( $prize );
	update_option( Settings::OPTION, $config );
	$replay = $games->play( $profiles[1], 'cards', 0, 0, 'empty-replay', 'empty-move' );
	$assert( ! $replay['coupon'] && $replay['exhausted'], 'Replenishing prizes cannot change a completed no-prize result' );
	$clear();
	$config['game_prizes'] = array( 'p1' => $config['game_prizes']['p2'] );
	$config['game_prizes']['p1']['customer_limit'] = 0;
	$config['game_percent_budget'] = '15';
	update_option( Settings::OPTION, $config );
	$assert( ! is_wp_error( $games->play( $profile, 'wheel' ) ), 'First 10-point prize fits the pool budget' );
	$second = $games->play( $profiles[1], 'scratch' );
	$assert( ! is_wp_error( $second ) && ! $second['coupon'], 'A second 10-point prize cannot exceed a 15-point issuance budget' );
	$config['game_pool_scopes']['scratch'] = 'dedicated';
	update_option( Settings::OPTION, $config );
	$assert( ! is_wp_error( $games->play( $profiles[2], 'scratch' ) ), 'Dedicated pools have independent budget and stock counters' );
	$clear();
	$config['game_percent_budget'] = '0';
	$config['game_max_winners'] = 1;
	$config['game_pool_scopes'] = array();
	update_option( Settings::OPTION, $config );
	$held = GameCampaign::lock( 'profile:' . $profile->id );
	$assert( is_wp_error( $games->play( $profile, 'wheel' ) ), 'Concurrent operations for one customer cannot reserve overlapping quota slots' );
	GameCampaign::unlock( $held );
	$held = GameCampaign::lock( 'prizes' );
	$result = $games->play( $profile, 'wheel', 0, 0, 'busy-retry', 'busy-move' );
	$assert( is_wp_error( $result ), 'A contended global prize lock fails closed' );
	GameCampaign::unlock( $held );
	$retry = $games->play( $profile, 'wheel', 0, 0, 'busy-retry', 'busy-move' );
	$assert( ! is_wp_error( $retry ) && $retry['won'], 'A temporarily busy award can be resumed without another quota slot' );
	$result = $games->play( $profiles[1], 'scratch' );
	$assert( ! is_wp_error( $result ) && ! $result['coupon'], 'The global maximum winner count applies across games and customers' );
	$clear();
	$fail_coupon = static function () {
		throw new RuntimeException( 'Simulated coupon database failure' );
	};
	add_filter( 'woocommerce_new_coupon_data', $fail_coupon );
	$result = $games->play( $profile, 'wheel', 0, 0, 'coupon-failure', 'coupon-move' );
	$assert( is_wp_error( $result ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $repo->table( 'game_awards' ) ) ), 'Coupon creation failure keeps one reservation for safe retry' );
	remove_filter( 'woocommerce_new_coupon_data', $fail_coupon );
	$retry = $games->play( $profile, 'wheel', 0, 0, 'coupon-failure', 'coupon-move' );
	$assert( ! is_wp_error( $retry ) && $retry['won'], 'Retry completes the same reserved prize after coupon creation recovers' );
	$clear();
	$config['game_max_winners'] = 0;
	update_option( Settings::OPTION, $config );
	$memory = $games->play( $profile, 'memory', 0, 0, 'memory-reload', 'memory-move' );
	$repeat = $games->play( $profile, 'memory', 0, 0, 'memory-reload', 'memory-move' );
	$assert( $memory['moves'] === $repeat['moves'] && $memory['play_id'] === $repeat['play_id'], 'Retrying a memory move does not count or reveal a second card' );
	$config['game_start'] = '2099-01-01';
	update_option( Settings::OPTION, $config );
	$assert( is_wp_error( $games->play( $profiles[1], 'wheel' ) ), 'Campaign dates block new starts' );
	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => count( $checks ), 'results' => $checks ), JSON_PRETTY_PRINT ) );
} finally {
	$clear();
	foreach ( $profiles as $profile ) {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_anar_marketing_profile', 'meta_value' => $profile->id ) );
		foreach ( $ids as $coupon_id ) {
			wp_delete_post( $coupon_id, true );
		}
		$wpdb->delete( $repo->table( 'profiles' ), array( 'id' => $profile->id ) );
	}
	false === $original ? delete_option( Settings::OPTION ) : update_option( Settings::OPTION, $original );
	update_option( 'woocommerce_enable_coupons', $coupons );
}
