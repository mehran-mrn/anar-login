<?php
/**
 * Real cart/order money caps and reconciliation in disposable local WooCommerce.
 *
 * @package AnarLogin
 */

use Anar\Login\Marketing\GameBudget;
use Anar\Login\Marketing\Games;
use Anar\Login\Marketing\Repository;
use Anar\Login\Marketing\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Disposable local WooCommerce only.' );
}
global $wpdb;
$original = array();
foreach ( array( Settings::OPTION, 'woocommerce_enable_coupons', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on' ) as $option ) {
	$original[ $option ] = get_option( $option );
}
$repo = new Repository();
$checks = array();
$products = array();
$orders = array();
$profiles = array();
$rates = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'qa_no_http' );
	},
	PHP_INT_MAX
);
add_filter( 'pre_wp_mail', '__return_true' );
try {
	\Anar\Login\Infrastructure\Activator::activate();
	update_option( 'woocommerce_enable_coupons', 'yes' );
	WC()->customer = new WC_Customer( 0, true );
	WC()->customer->set_billing_country( 'US' );
	WC()->customer->set_billing_state( 'CA' );
	WC()->cart = new WC_Cart();
	$budget = new GameBudget();
	// Plugin::runtime already registered the hooks; do not register another discount calculator.
	$config = ( new Settings() )->defaults();
	$config['modules']['games'] = 1;
	$config['game_quota_mode'] = 'independent';
	$config['games_limit'] = 50;
	$config['game_reward_chance'] = 100;
	$config['game_money_budget'] = '80';
	$config['game_pool_scopes']['wheel'] = 'dedicated';
	$config['game_prizes'] = array( 'p8' => array( 'label' => '20% capped', 'type' => 'percent', 'amount' => '20', 'cap' => '40', 'weight' => 1, 'stock' => 0, 'customer_limit' => 0, 'hours' => 48, 'minimum' => 0, 'products' => '' ) );
	$wpdb->delete( $repo->table( 'game_awards' ), array( 'pool' => 'wheel' ) );
	update_option( Settings::OPTION, $config );
	foreach ( array( '+989120000301', '+989120000302', '+989120000303' ) as $phone ) {
		$profiles[] = $repo->profile( $repo->remember( $phone ) );
	}
	$profile = $profiles[0];
	WC()->customer->set_billing_phone( $profile->phone );
	foreach ( array( 500, 300, 100 ) as $price ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Game budget QA' );
		$product->set_regular_price( $price );
		$product->set_virtual( true );
		$product->set_tax_status( 'taxable' );
		$products[] = $product->save();
	}
	$result = ( new Games() )->play( $profile, 'wheel', 0, 0, 'money-first', 'money-move' );
	if ( is_wp_error( $result ) ) {
		WP_CLI::log( $result->get_error_code() . ': ' . $result->get_error_message() . ' DB: ' . $wpdb->last_error ); }
	$assert( ! is_wp_error( $result ) && $result['won'], 'A capped prize reserves its maximum monetary cost' );
	$code = $result['coupon'];
	$balance = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $result['play_id'] ) );
	$assert( 40.0 === (float) $balance->money_reserved && get_woocommerce_currency() === $balance->currency, 'Reservation records the amount and store currency' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	WC()->cart->add_to_cart( $products[0], 2 );
	WC()->cart->add_to_cart( $products[1], 1 );
	WC()->cart->apply_coupon( $code );
	WC()->cart->calculate_totals();
	$assert( abs( WC()->cart->get_discount_total() - 40 ) < 0.01, 'A percentage coupon across quantities and lines is capped at 40' );
	WC()->cart->calculate_totals();
	$assert( abs( WC()->cart->get_discount_total() - 40 ) < 0.01, 'Repeated total calculation does not accumulate another cap' );
	$assert( 'percent' === ( new WC_Coupon( $code ) )->get_discount_type(), 'Coupon retains its ordinary percentage type outside calculation' );
	$test_order = wc_create_order();
	$orders[] = $test_order->get_id();
	$test_order->set_billing_phone( $profile->phone );
	$test_order->set_billing_country( 'US' );
	$test_order->set_billing_state( 'CA' );
	$test_order->add_product( wc_get_product( $products[2] ), 1 );
	$test_order->calculate_totals();
	$applied = $test_order->apply_coupon( $code );
	$assert( ! is_wp_error( $applied ) && $test_order->get_discount_total() <= 40.000001 && $test_order->get_discount_total() > 19, 'Direct order coupon calculation uses the same bounded algorithm' );
	do_action( 'woocommerce_checkout_order_created', $test_order );
	$held = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $result['play_id'] ) );
	$assert( 'held' === $held->money_state && (int) $held->order_id === $test_order->get_id(), 'Classic checkout binds the reservation before payment' );
	do_action( 'woocommerce_store_api_checkout_order_processed', $test_order );
	$assert( 'held' === $wpdb->get_var( $wpdb->prepare( 'SELECT money_state FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $result['play_id'] ) ), 'Block checkout reuses the same order binding idempotently' );
	$test_order->set_status( 'processing' );
	$test_order->save();
	$spent = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $result['play_id'] ) );
	$assert( 'consumed' === $spent->money_state && 0.0 === (float) $spent->money_reserved && 20.0 === (float) $spent->money_spent, 'Payment settles the actual saving and clears the reservation' );
	$test_order->set_status( 'cancelled' );
	$test_order->save();
	$discounts = new WC_Discounts( WC()->cart );
	$assert( is_wp_error( $discounts->is_coupon_valid( new WC_Coupon( $code ) ) ), 'Cancellation cannot reactivate a consumed monetary gift' );
	$assert( (float) $wpdb->get_var( $wpdb->prepare( 'SELECT money_spent FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $result['play_id'] ) ) > 0, 'Cancellation does not replenish spent campaign money' );
	$second = ( new Games() )->play( $profiles[1], 'wheel', 0, 0, 'money-second', 'money-second-move' );
	$assert( ! is_wp_error( $second ) && $second['won'], 'A second cap fits the remaining budget' );
	$third = ( new Games() )->play( $profiles[2], 'wheel', 0, 0, 'money-third', 'money-third-move' );
	$assert( is_wp_error( $third ) || empty( $third['coupon'] ), 'New issuance cannot exceed spent plus reserved money' );
	WC()->customer->set_billing_phone( $profiles[1]->phone );
	$assert( ! is_wp_error( ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $second['coupon'] ) ) ), 'An unmodified funded coupon is valid for its intended customer' );
	$changed_coupon = new WC_Coupon( $second['coupon'] );
	$changed_coupon->update_meta_data( '_anar_game_money_cap', 80 );
	$changed_coupon->save();
	$assert( is_wp_error( ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $second['coupon'] ) ) ), 'Editing coupon metadata cannot raise the funded monetary ceiling' );
	$changed_coupon->update_meta_data( '_anar_game_money_cap', 40 );
	$changed_coupon->save();
	$wpdb->update( $repo->table( 'game_awards' ), array( 'expires_at' => time() - 10 ), array( 'play_id' => $second['play_id'] ) );
	GameBudget::sweep();
	$expired = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $second['play_id'] ) );
	$assert( 'expired' === $expired->money_state && 0.0 === (float) $expired->money_reserved, 'An unused expired coupon releases its reservation' );
	WC()->customer->set_billing_phone( $profiles[1]->phone );
	$assert( is_wp_error( ( new WC_Discounts( WC()->cart ) )->is_coupon_valid( new WC_Coupon( $second['coupon'] ) ) ), 'A released expired ledger entry cannot be revived by editing coupon expiry' );
	// Taxed calculation: both price-entry modes must respect total customer saving, including tax.
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_tax_based_on', 'billing' );
	$rates[] = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '20', 'tax_rate_name' => 'QA VAT', 'tax_rate_priority' => 99, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 99, 'tax_rate_class' => '' ) );
	foreach ( array( 'no', 'yes' ) as $included ) {
		update_option( 'woocommerce_prices_include_tax', $included );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$config['game_money_budget'] = '1000';
		update_option( Settings::OPTION, $config );
		$gift = ( new Games() )->play( $profiles[2], 'wheel', 0, 0, 'tax-' . $included, 'tax-move-' . $included );
		$assert( ! is_wp_error( $gift ) && $gift['won'], 'Tax scenario coupon issued: ' . $included );
		WC()->cart->empty_cart();
		WC()->customer->set_billing_phone( $profiles[2]->phone );
		WC()->cart->add_to_cart( $products[0], 2 );
		WC()->cart->add_to_cart( $products[1], 3 );
		WC()->cart->apply_coupon( $gift['coupon'] );
		WC()->cart->calculate_totals();
		$saving = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
		$assert( $saving <= 40.000001 && $saving > 39, 'Gross cart saving stays within cap with taxes: ' . $included );
		$tax_order = wc_create_order();
		$orders[] = $tax_order->get_id();
		$tax_order->set_billing_phone( $profiles[2]->phone );
		$tax_order->set_billing_country( 'US' );
		$tax_order->set_billing_state( 'CA' );
		$tax_order->set_prices_include_tax( 'yes' === $included );
		$tax_order->add_product( wc_get_product( $products[0] ), 3 );
		$tax_order->calculate_totals();
		$tax_order->apply_coupon( $gift['coupon'] );
		$gross_order = $tax_order->get_discount_total() + $tax_order->get_discount_tax();
		$assert( $gross_order <= 40.000001 && $gross_order > 39, 'Order gross saving is capped with taxes: ' . $included );
		do_action( 'woocommerce_store_api_checkout_order_processed', $tax_order );
		$wpdb->update( $repo->table( 'game_awards' ), array( 'expires_at' => time() - 10 ), array( 'play_id' => $gift['play_id'] ) );
		GameBudget::sweep();
		$assert( 'held' === $wpdb->get_var( $wpdb->prepare( 'SELECT money_state FROM %i WHERE play_id = %d', $repo->table( 'game_awards' ), $gift['play_id'] ) ), 'An unpaid saved order retains its reservation after expiry: ' . $included );

	}
	$config['game_prizes']['p8']['type'] = 'fixed_cart';
	$config['game_prizes']['p8']['amount'] = '100';
	update_option( Settings::OPTION, $config );
	$fixed = ( new Games() )->play( $profiles[2], 'wheel', 0, 0, 'fixed-money', 'fixed-money-move' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $products[0], 3 );
	WC()->cart->add_to_cart( $products[1], 2 );
	WC()->cart->apply_coupon( $fixed['coupon'] );
	WC()->cart->calculate_totals();
	$assert( WC()->cart->get_discount_total() + WC()->cart->get_discount_tax() <= 40.000001 && WC()->cart->get_discount_total() > 20, 'A fixed-amount prize also respects its gross monetary ceiling' );
	$config['game_prizes']['p8']['type'] = 'percent';
	$config['game_prizes']['p8']['amount'] = '20';
	update_option( Settings::OPTION, $config );

	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	WC()->cart->empty_cart();
	WC()->customer->set_billing_phone( $profiles[2]->phone );
	WC()->cart->add_to_cart( $products[0], 3 );
	$rest_gift = ( new Games() )->play( $profiles[2], 'wheel', 0, 0, 'rest-checkout', 'rest-move' );
	$assert( ! is_wp_error( $rest_gift ) && $rest_gift['won'], 'A fresh capped gift is available to Store API checkout' );
	add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' ); // Local fixture only; authentication is outside this calculation test.
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/apply-coupon' );
	$request->set_param( 'code', $rest_gift['coupon'] );
	$response = rest_do_request( $request );
	if ( $response->get_status() >= 400 ) {
		WP_CLI::log( wp_json_encode( $response->get_data() ) ); }
	$assert( $response->get_status() < 400, 'Store API applies the capped gift through its real cart route' );
	$gateways = WC()->payment_gateways()->payment_gateways();
	$gateways['cod']->enabled = 'yes';
	$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$address = array( 'first_name' => 'QA', 'last_name' => 'Buyer', 'address_1' => '123 Test Street', 'city' => 'Los Angeles', 'state' => 'CA', 'postcode' => '90210', 'country' => 'US', 'email' => 'game-budget@example.test', 'phone' => $profiles[2]->phone );
	$request->set_body_params( array( 'billing_address' => $address, 'shipping_address' => $address, 'payment_method' => 'cod' ) );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	if ( ! empty( $data['order_id'] ) ) {
		$orders[] = $data['order_id']; }
	if ( $response->get_status() >= 400 ) {
		WP_CLI::log( wp_json_encode( $data ) ); }
	$assert( $response->get_status() < 400 && ! empty( $data['order_id'] ), 'Actual Store API checkout creates the capped order successfully' );
	$rest_order = wc_get_order( $data['order_id'] );
	$assert( $rest_order->get_discount_total() + $rest_order->get_discount_tax() <= 40.000001 && $rest_order->get_discount_total() > 39, 'Store API checkout persists a real discount within the money ceiling' );
	remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => count( $checks ), 'results' => $checks ), JSON_PRETTY_PRINT ) );
} finally {
	foreach ( $orders as $order_id ) {
		$test_order = wc_get_order( $order_id );
		if ( $test_order ) {
			$test_order->delete( true );
		}
	}
	foreach ( $profiles as $profile ) {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 'meta_key' => '_anar_marketing_profile', 'meta_value' => $profile->id ) );
		foreach ( $ids as $coupon_id ) {
			wp_delete_post( $coupon_id, true );
		}
		foreach ( array( 'plays', 'game_awards' ) as $name ) {
			$wpdb->delete( $repo->table( $name ), array( 'profile_id' => $profile->id ) );
		}
		$wpdb->delete( $repo->table( 'profiles' ), array( 'id' => $profile->id ) );
	}
	foreach ( $products as $product_id ) {
		wp_delete_post( $product_id, true );
	}
	foreach ( $rates as $rate_id ) {
		WC_Tax::_delete_tax_rate( $rate_id );
	}
	foreach ( $original as $option => $value ) {
		false === $value ? delete_option( $option ) : update_option( $option, $value );
	}
}
