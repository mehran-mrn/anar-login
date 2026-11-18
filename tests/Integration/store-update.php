<?php
/**
 * Local WordPress, WooCommerce and Elementor release regressions.
 *
 * @package AnarLogin
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) || ! class_exists( '\Elementor\Plugin' ) ) {
	throw new RuntimeException( 'Local WooCommerce and Elementor required.' ); }
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} ++$checks;
};
wp_set_current_user( 1 );
$products = array();
$coupons = array();
$orders = array();
$options = array();
foreach ( array( 'woocommerce_enable_coupons', 'woocommerce_calc_taxes', 'anar_toolkit_commerce', 'anar_toolkit_marketing', 'anar_login_settings', 'anar_toolkit_messages_login_otp' ) as $key ) {
	$options[ $key ] = get_option( $key ); }
try {
	update_option( 'woocommerce_enable_coupons', 'yes' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	WC()->customer = new WC_Customer( 0, true );
	WC()->customer->set_billing_phone( '+989120000301' );
	WC()->cart = new WC_Cart();
	foreach ( array( 500, 300, 100 ) as $price ) {
		$p = new WC_Product_Simple();
		$p->set_name( 'Release regression product' );
		$p->set_regular_price( $price );
		$p->set_virtual( true );
		$p->set_tax_status( 'none' );
		$products[] = $p->save(); }
	$coupon = new WC_Coupon();
	$coupon->set_code( 'anar-release-' . wp_generate_password( 8, false ) );
	$coupon->set_discount_type( 'anar_recovery_capped' );
	$coupon->set_amount( 0 );
	$coupon->update_meta_data( '_anar_recovery_money_cap', 40 );
	$coupon->update_meta_data( '_anar_recovery_kind', 'percent' );
	$coupon->update_meta_data( '_anar_recovery_value', 20 );
	$coupons[] = $coupon->save();
	WC()->cart->add_to_cart( $products[0], 2 );
	WC()->cart->add_to_cart( $products[1], 1 );
	$assert( WC()->cart->apply_coupon( $coupon->get_code() ), 'Recovery coupon applies' );
	WC()->cart->calculate_totals();
	$assert( abs( WC()->cart->get_discount_total() - 40 ) < 0.01, 'Multi-line cart respects ceiling' );
	WC()->cart->calculate_totals();
	$assert( abs( WC()->cart->get_discount_total() - 40 ) < 0.01, 'Recalculation does not accumulate discount' );
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $products[2], 1 );
	WC()->cart->apply_coupon( $coupon->get_code() );
	WC()->cart->calculate_totals();
	$assert( abs( WC()->cart->get_discount_total() - 20 ) < 0.01, 'Smaller cart recalculates percentage below ceiling' );
	$qa_order = wc_create_order();
	$orders[] = $qa_order->get_id();
	$qa_order->add_product( wc_get_product( $products[0] ), 2 );
	$qa_order->add_product( wc_get_product( $products[1] ), 1 );
	$result = $qa_order->apply_coupon( $coupon->get_code() );
	$assert( ! is_wp_error( $result ), 'Order applies coupon' );
	$qa_order->calculate_totals();
	$assert( $qa_order->get_discount_total() <= 40 && $qa_order->get_discount_total() > 39, 'Order calculation respects ceiling' );

	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	update_option( 'woocommerce_tax_based_on', 'billing' );
	WC()->customer->set_billing_country( 'US' );
	WC()->customer->set_billing_state( 'CA' );
	WC()->customer->set_is_vat_exempt( false );
	$rate_id = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => '10', 'tax_rate_name' => 'QA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
	try {
		$tax_product = wc_get_product( $products[0] );
		$tax_product->set_tax_status( 'taxable' );
		$tax_product->save();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $products[0], 2 );
		WC()->cart->apply_coupon( $coupon->get_code() );
		WC()->cart->calculate_totals();
		$gross_discount = WC()->cart->get_discount_total() + WC()->cart->get_discount_tax();
		$assert( $gross_discount <= 40.001 && $gross_discount > 39, 'Discount ceiling includes tax savings' );
	} finally {
		WC_Tax::_delete_tax_rate( $rate_id );
		update_option( 'woocommerce_calc_taxes', 'no' ); }
	// Check actual Elementor saved-element reconstruction, not just registration prototypes.
	$manager = \Elementor\Plugin::$instance->widgets_manager;
	foreach ( array( 'login', 'button', 'recovery' ) as $kind ) {
		$assert( null !== $manager->get_widget_types( 'anar-' . $kind ), 'Elementor registers ' . $kind );
		$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance( array( 'id' => 'anarqa' . $kind, 'elType' => 'widget', 'widgetType' => 'anar-' . $kind, 'settings' => array() ) );
		$assert( $widget && 'anar-' . $kind === $widget->get_name(), 'Saved widget retains its kind: ' . $kind );
	}
	delete_option( 'anar_toolkit_messages_login_otp' );
	update_option( 'anar_login_settings', array( 'sms_provider' => 'panelchi' ) );
	update_option( 'anar_toolkit_commerce', array( 'provider' => '' ) );
	$store = new \Anar\Login\Commerce\Settings();
	$assert( 'panelchi' === $store->provider(), 'Store follows sign-in provider' );
	update_option( 'anar_login_settings', array( 'sms_provider' => 'maxsms' ) );
	$assert( 'maxsms' === $store->provider(), 'Inherited provider follows subsequent changes' );
	update_option( 'anar_toolkit_commerce', array( 'provider' => 'kavenegar' ) );
	$assert( 'kavenegar' === $store->provider(), 'Explicit store connection remains independent' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	( new \Anar\Login\Admin\SettingsPage( new \Anar\Login\Infrastructure\Settings(), \Anar\Login\Messaging\ProviderRegistry::shared() ) )->menu();
	( new \Anar\Login\Admin\FeaturesPage() )->menu();
	$root_callbacks = 0;
	foreach ( $GLOBALS['wp_filter']['toplevel_page_anar-login']->callbacks as $callbacks ) {
		$root_callbacks += count( $callbacks ); }
	$assert( 1 === $root_callbacks, 'Root settings render once' );
	ob_start();
	// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Generated WordPress menu hook.
	do_action( 'toplevel_page_anar-login' );
	$html = ob_get_clean();
	$assert( 1 === substr_count( $html, 'data-panel="features"' ), 'Feature settings appear once' );
	$assert( substr_count( $html, 'role="switch"' ) === count( \Anar\Login\Admin\FeaturesPage::labels() ), 'All feature switches are reachable' );
	WP_CLI::success( $checks . ' store, coupon, Elementor and admin release checks passed.' );
} finally {
	WC()->cart->empty_cart();
	foreach ( $orders as $qa_id ) {
		$qa_order = wc_get_order( $qa_id );
		if ( $qa_order ) {
			$qa_order->delete( true ); }
	}
	foreach ( array_merge( $products, $coupons ) as $qa_id ) {
		wp_delete_post( $qa_id, true ); }
	foreach ( $options as $key => $value ) {
		false === $value ? delete_option( $key ) : update_option( $key, $value ); }
}
