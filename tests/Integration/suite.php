<?php
/**
 * Expanded-suite regression checks in a disposable local WooCommerce installation.
 * All provider HTTP and mail are intercepted. Never run against a real store.
 *
 * @package AnarLogin
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WP-CLI eval-file runs this disposable fixture in an isolated function.

use Anar\Login\Commerce\Coupons;
use Anar\Login\Commerce\Module;
use Anar\Login\Commerce\OrderEvents;
use Anar\Login\Commerce\Repository as CartRepository;
use Anar\Login\Commerce\Settings as CartSettings;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Marketing\Engine;
use Anar\Login\Marketing\Games;
use Anar\Login\Marketing\Frontend;
use Anar\Login\Marketing\Invoices;
use Anar\Login\Marketing\Prediction;
use Anar\Login\Marketing\Repository;
use Anar\Login\Marketing\Rewards;
use Anar\Login\Marketing\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Disposable local WooCommerce only.' ); }
global $wpdb;
$checks = array();
$requests = array();
$products = array();
$orders = array();
$users = array();
function anar_suite_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	} $GLOBALS['anar_suite_checks'][] = $message; }
$GLOBALS['anar_suite_checks'] = array();
add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests ) {
		$requests[] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'data' => array( 'uid' => 'test-' . count( $requests ), 'status' => 'CREATED' ) ) ), 'response' => array( 'code' => 201, 'message' => 'Created' ), 'cookies' => array() );
	},
	PHP_INT_MAX,
	3
);
$original = array();
foreach ( array( Settings::OPTION, CartSettings::OPTION, LoginSettings::OPTION_KEY, \Anar\Login\Infrastructure\Features::OPTION, 'woocommerce_enable_coupons' ) as $key ) {
	$original[ $key ] = get_option( $key ); }
$repo = new Repository();
$carts = new CartRepository();
$settings = new Settings();
$cart_settings = new CartSettings();
$engine = new Engine( $settings, $repo );
$module = new Module( $cart_settings, $carts );
$clear_limits = static function () use ( $wpdb ) {
	$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'anar_login_limits' );
};
try {
	\Anar\Login\Infrastructure\Activator::activate();
	update_option( \Anar\Login\Infrastructure\Features::OPTION, array( 'login' => 1, 'reset' => 1, 'orders' => 1 ) );
	foreach ( array( 'profiles', 'orders', 'purchases', 'jobs', 'stock', 'feedback', 'plays', 'referrals', 'bulk', 'visits' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $repo->table( $table ) ) ); }
	foreach ( array( 'contacts', 'carts', 'messages' ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $carts->table( $table ) ) ); }
	$clear_limits();
	update_option( 'woocommerce_enable_coupons', 'yes' );
	$login = ( new LoginSettings() )->defaults();
	$login['sms_provider'] = 'panelchi';
	$login['provider_panelchi'] = array( 'api_key' => 'TEST-ONLY', 'template_id' => 'test-login', 'code_parameter' => 'code' );
	$login['password_reset_enabled'] = 1;
	update_option( LoginSettings::OPTION_KEY, $login );
	$config = $cart_settings->defaults();
	$config['recovery'] = 1;
	$config['capture_mode'] = 'automatic';
	$config['provider'] = 'panelchi';
	$config['start_hour'] = 0;
	$config['end_hour'] = 24;
	$config['coupon_enabled'] = 1;
	$config['coupon_stage'] = 2;
	$config['coupon_amount'] = '10';
	$config['coupon_minimum'] = '100';
	$config['shipment_customer'] = 1;
	$config['status_completed'] = 1;
	$config['admin_phones'] = '+989121111111';
	foreach ( $config['events'] as &$event ) {
		$event['template'] = 'test';
		$event['params'] = "code={code}\nentry={recovery_page}\nstop={unsubscribe_url}\ngift={coupon_code}\norder={order_id}\ntracking={tracking_number}";
	} unset( $event );
	update_option( CartSettings::OPTION, $config );
	$module->register();
	$marketing = $settings->defaults();
	$marketing['provider'] = 'panelchi';
	update_option( Settings::OPTION, $marketing );
	anar_suite_check( ! $settings->enabled(), 'Every new marketing module is disabled by default' );
	foreach ( array( 'profiles', 'orders', 'purchases', 'jobs', 'stock', 'feedback', 'plays', 'referrals', 'bulk', 'visits' ) as $table ) {
		anar_suite_check( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $repo->table( $table ) ) ) !== null, 'Schema installed: ' . $table ); }
	$product = new WC_Product_Simple();
	$product->set_name( 'Anar suite test product' );
	$product->set_regular_price( '2000' );
	$product->set_status( 'publish' );
	$product->set_stock_status( 'instock' );
	$products[] = $product->save();
	$product_b = new WC_Product_Simple();
	$product_b->set_name( 'Anar suite recommendation' );
	$product_b->set_regular_price( '1500' );
	$product_b->set_status( 'publish' );
	$product_b->set_stock_status( 'instock' );
	$products[] = $product_b->save();
	if ( ! WC()->session ) {
		WC()->initialize_session();
	} if ( ! WC()->customer ) {
		WC()->customer = new WC_Customer( 0, true );
	} if ( ! WC()->cart ) {
		WC()->cart = new WC_Cart(); }
	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $product->get_id(), 1 );
	WC()->cart->calculate_totals();
	$id = $module->remember( '09123334444', 'automatic@example.test', 'checkout' );
	$cart = $carts->cart( $id );
	$contact = $cart ? $carts->contact( $cart->contact_id ) : null;
	anar_suite_check( $id && $contact && 0 === (int) $contact->consented_at && 'checkout' === $contact->capture_source, 'Automatic capture works without fabricating explicit consent' );
	$wpdb->update( $carts->table( 'carts' ), array( 'updated_at' => time() - 600 ), array( 'id' => $id ) );
	$before = $carts->cart( $id )->updated_at;
	$module->remember( '09123334444', '', 'checkout' );
	anar_suite_check( (int) $carts->cart( $id )->updated_at === (int) $before, 'Reading an unchanged cart does not reset its inactivity clock' );
	$carts->suppress( $contact->id );
	$clear_limits();
	anar_suite_check( 0 === $module->remember( '09123334444', '', 'checkout' ), 'Automatic capture cannot reverse an unsubscribe' );
	$contact_id = $carts->consent( '+989125555555', 'gift@example.test', 'Test explicit preference' );
	$cart_id = $carts->save_cart( 'suite-gift', $contact_id, array( array( 'product_id' => $product->get_id(), 'quantity' => 1, 'variation_id' => 0, 'variation' => array() ) ), 2000, get_woocommerce_currency() );
	$coupons = new Coupons( $cart_settings, $carts );
	$discount = $coupons->issue( $carts->cart( $cart_id ), 1 );
	anar_suite_check( '' === $discount['coupon_code'], 'No recovery discount before the configured stage' );
	$discount = $coupons->issue( $carts->cart( $cart_id ), 2 );
	$coupon = new WC_Coupon( $discount['coupon_code'] );
	anar_suite_check( $coupon->get_id() && 1 === $coupon->get_usage_limit(), 'Recovery gift creates a single-use WooCommerce coupon' );
	$again = $coupons->issue( $carts->cart( $cart_id ), 3 );
	anar_suite_check( $discount['coupon_code'] === $again['coupon_code'], 'Reminder retries reuse the same recovery coupon' );
	WC()->customer->set_billing_phone( '09121112222' );
	$rejected = false;
	try {
		$coupons->valid( true, $coupon );
	} catch ( Exception $e ) {
		$rejected = true;
	} anar_suite_check( $rejected, 'Another billing phone cannot use a personal recovery coupon' );
	WC()->customer->set_billing_phone( '+989125555555' );
	anar_suite_check( true === $coupons->valid( true, $coupon ), 'The intended billing phone can use its coupon' );
	$unpaid = wc_create_order();
	$unpaid->add_product( $product, 1 );
	$unpaid->set_billing_phone( '+989125555555' );
	$unpaid->calculate_totals();
	$orders[] = $unpaid->save();
	$coupons->apply( $carts->cart( $cart_id ), $unpaid );
	anar_suite_check( in_array( $discount['coupon_code'], $unpaid->get_coupon_codes(), true ) && (float) $unpaid->get_discount_total() > 0, 'Verified unpaid-order recovery applies a real WooCommerce discount' );
	$user_id = wp_insert_user( array( 'user_login' => 'anar-suite-' . wp_generate_password( 8, false ), 'user_email' => 'anar-suite-' . wp_generate_password( 8, false ) . '@example.test', 'user_pass' => 'Original-Test-Password-123', 'role' => 'subscriber' ) );
	$users[] = $user_id;
	update_user_meta( $user_id, '_anar_phone', '+989126666666' );
	$codes = new \Anar\Login\Infrastructure\OtpRepository();
	$limits = new \Anar\Login\Infrastructure\RateLimiter( new LoginSettings() );
	$providers = new \Anar\Login\Messaging\ProviderRegistry( new LoginSettings() );
	$reset = new \Anar\Login\Auth\PasswordRecovery( new LoginSettings(), $codes, $limits, $providers );
	$clear_limits();
	$reset->request( '09126666666' );
	$last = end( $requests );
	$body = json_decode( $last['args']['body'], true );
	$code = $body['variables']['code'];
	anar_suite_check( (bool) preg_match( '/^\d{6}$/', $code ), 'Password reset sends a six-digit code through the selected provider' );
	anar_suite_check( ! $codes->latest( '+989126666666', 'sms' ), 'Password reset code is isolated from login challenges' );
	$session = WP_Session_Tokens::get_instance( $user_id )->create( time() + 3600 );
	$result = $reset->reset( '09126666666', $code, 'New-Secure-Test-Password-456' );
	anar_suite_check( ! is_wp_error( $result ) && wp_check_password( 'New-Secure-Test-Password-456', get_userdata( $user_id )->user_pass ), 'Phone proof sets a new password through WordPress reset APIs' );
	anar_suite_check( ! WP_Session_Tokens::get_instance( $user_id )->verify( $session ), 'Password recovery revokes previous sessions' );
	anar_suite_check( is_wp_error( $reset->reset( '09126666666', $code, 'Other-New-Password-789' ) ), 'A password reset code cannot be replayed' );
	$clear_limits();
	$deny = static function () {
		return false;
	};
	add_filter( 'allow_password_reset', $deny );
	$count = count( $requests );
	$reset->request( '09126666666' );
	remove_filter( 'allow_password_reset', $deny );
	anar_suite_check( count( $requests ) === $count, 'Password reset honors account-policy denials' );
	$marketing['modules'] = array_fill_keys( array_keys( Settings::modules() ), 1 );
	$marketing['modules']['traffic'] = 0;
	$marketing['minimum_orders'] = 0;
	$marketing['prediction_confidence'] = 60;
	$marketing['games_limit'] = 1;
	$marketing['game_reward_chance'] = 100;
	$marketing['game_quiz_question'] = 'Test question';
	$marketing['game_quiz_options'] = "One\nTwo";
	$marketing['game_quiz_answer'] = 2;
	foreach ( $marketing['rules'] as &$rule ) {
		$rule['template'] = 'test-campaign';
		$rule['params'] = "name={name}\ntext={message}\nlink={action_url}\nproduct={product_name}\ngift={coupon_code}\nstop={unsubscribe_url}";
	} unset( $rule );
	update_option( Settings::OPTION, $marketing );
	$engine->register();
	$profile_id = $repo->remember( '+989126666666', get_userdata( $user_id )->user_email, 'QA buyer', $user_id );
	$profile = $repo->profile( $profile_id );
	for ( $i = 0; $i < 5; ++$i ) {
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->add_product( $product, 1 );
		$order->set_billing_phone( $profile->phone );
		$order->set_billing_email( $profile->email );
		$order->set_billing_first_name( 'QA buyer' );
		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->set_date_paid( time() - ( 125 - $i * 30 ) * DAY_IN_SECONDS );
		$orders[] = $order->save();
		$repo->order( $order ); }
	$facts = $repo->facts( $profile_id );
	anar_suite_check( 5 === (int) $facts->orders, 'Repeated purchase facts are recorded without duplicating orders' );
	$repo->order( $order );
	anar_suite_check( 5 === (int) $repo->facts( $profile_id )->orders, 'Replaying paid-order callbacks is idempotent' );
	$prediction = ( new Prediction() )->for_product( $profile_id, $product->get_id(), $marketing );
	anar_suite_check( $prediction && 30 === $prediction['days'] && $prediction['confidence'] >= 60, 'Prediction derives a 30-day interval from actual repeated purchases' );
	$engine->order( $order );
	$events = new OrderEvents( $cart_settings, $carts );
	$order->set_status( 'completed' );
	$order->save();
	$events->status( $order->get_id(), 'processing', 'completed' );
	$events->status( $order->get_id(), 'processing', 'completed' );
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event = 'status_completed' AND object_id = %d", $carts->table( 'messages' ), $order->get_id() ) );
	anar_suite_check( 1 === $n, 'Repeated order status callbacks produce one status notification' );
	$order->update_meta_data( '_anar_tracking_number', 'QA-001' );
	$order->update_meta_data( '_anar_tracking_carrier', 'QA carrier' );
	$order->save();
	$events->shipment( $order );
	$events->shipment( $order );
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event = 'shipment_customer' AND object_id = %d", $carts->table( 'messages' ), $order->get_id() ) );
	anar_suite_check( 1 === $n, 'The same tracking number is not queued twice' );
	$tracking = OrderEvents::tracking( $order );
	anar_suite_check( 'QA-001' === $tracking['tracking_number'] && 'QA carrier' === $tracking['carrier'], 'Shipment variables are read with WooCommerce CRUD' );
	$expires = time() + HOUR_IN_SECONDS;
	$url = Invoices::url( $order, $expires );
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$query = Frontend::decode( $query['ai'] );
	wp_set_current_user( 0 );
	anar_suite_check( Invoices::authorized( $order, $expires, $query['signature'] ), 'A valid expiring invoice link grants only invoice access' );
	anar_suite_check( ! Invoices::authorized( $order, $expires, str_repeat( '0', 64 ) ), 'A tampered invoice signature is rejected' );
	anar_suite_check( ! Invoices::authorized( $order, time() - 1, $query['signature'] ), 'An expired invoice link is rejected' );
	$stop = Frontend::link( 'stop', $profile_id );
	anar_suite_check( strlen( $stop ) <= 100 && strlen( $url ) <= 100, 'Compact purpose-scoped links fit a 100-character provider token on the QA domain' );
	parse_str( wp_parse_url( $stop, PHP_URL_QUERY ), $stop_query );
	$stop_query = Frontend::decode( $stop_query['am'] );
	anar_suite_check( Frontend::authorized( 'stop', $profile_id, $stop_query['expires'], $stop_query['signature'] ), 'Marketing opt-out links are correctly signed' );
	anar_suite_check( ! Frontend::authorized( 'survey', $profile_id, $stop_query['expires'], $stop_query['signature'] ), 'Link scopes cannot be swapped to access surveys' );
	foreach ( Games::types() as $game => $label ) {
		$wpdb->delete( $repo->table( 'plays' ), array( 'profile_id' => $profile_id ) );
		$result = ( new Games() )->play( $profile, $game, 0, 'quiz' === $game ? 2 : 0 );
		if ( 'memory' === $game && ! is_wp_error( $result ) ) {
			$play = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $repo->table( 'plays' ), $result['play_id'] ) );
			$payload = json_decode( $play->payload, true );
			$pairs = array();
			foreach ( $payload['board'] as $index => $symbol ) {
				$pairs[ $symbol ][] = $index;
			} $first_symbol = $payload['board'][0];
			$result = ( new Games() )->play( $profile, $game, $play->id, $pairs[ $first_symbol ][1] );
			unset( $pairs[ $first_symbol ] );
			foreach ( $pairs as $pair ) {
				foreach ( $pair as $index ) {
					$result = ( new Games() )->play( $profile, $game, $play->id, $index ); }
			}
		}
		anar_suite_check( ! is_wp_error( $result ) && $result['finished'] && $result['coupon'], 'Server-controlled gift flow works: ' . $game );
		$replay = ( new Games() )->play( $profile, $game, $result['play_id'], 'quiz' === $game ? 2 : 0 );
		anar_suite_check( ! is_wp_error( $replay ) && $result['coupon'] === $replay['coupon'], 'A completed game cannot mint a second reward: ' . $game );
		anar_suite_check( is_wp_error( ( new Games() )->play( $profile, $game, 0, 0 ) ), 'Daily play quota enforced: ' . $game );
	}
	$survey_rule = $marketing['rules']['survey'];
	$referral_order = wc_create_order();
	$referral_order->add_product( $product, 1 );
	$referral_order->set_billing_phone( '+989127777777' );
	$referral_order->set_billing_email( 'referral-' . wp_generate_password( 8, false ) . '@example.test' );
	$referral_order->calculate_totals();
	$referral_order->set_status( 'completed' );
	$referral_order->set_date_paid( time() - 9 * DAY_IN_SECONDS );
	$referral_order->set_date_completed( time() - 8 * DAY_IN_SECONDS );
	$referral_order->update_meta_data( '_anar_referrer', $user_id );
	$orders[] = $referral_order->save();
	$referral_profile = $repo->profile( $repo->order( $referral_order ) );
	$referrals = new \Anar\Login\Marketing\Referrals();
	$referrals->paid( $referral_order, $referral_profile );
	$referrals->paid( $referral_order, $referral_profile );
	$relation = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $repo->table( 'referrals' ), $referral_order->get_id() ) );
	anar_suite_check( $relation && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE order_id = %d', $repo->table( 'referrals' ), $referral_order->get_id() ) ), 'An eligible first-purchase referral is recorded only once' );
	$wpdb->update( $repo->table( 'referrals' ), array( 'created_at' => time() - 8 * DAY_IN_SECONDS ), array( 'id' => $relation->id ) );
	$referrals->settle();
	$relation = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $repo->table( 'referrals' ), $relation->id ) );
	anar_suite_check( 'rewarded' === $relation->status && $relation->coupon_id, 'A completed referral earns one coupon after its refund waiting period' );
	$referrals->refunded( $referral_order->get_id() );
	$referral_coupon = new WC_Coupon( $relation->coupon_id );
	anar_suite_check( $referral_coupon->get_date_expires()->getTimestamp() <= time(), 'Refunding a referred order revokes its unused reward' );
	$survey_rule['discount_type'] = 'percent';
	$survey_rule['discount_amount'] = '5';
	$gift = ( new Rewards() )->coupon( $profile, $survey_rule, 'survey-test-low-rating' );
	anar_suite_check( (bool) $gift, 'Survey thank-you rewards do not require a positive rating' );
	$wpdb->insert( $repo->table( 'bulk' ), array( 'title' => 'QA paused campaign', 'state' => 'paused', 'filters' => '{}', 'scheduled_at' => time(), 'created_at' => time() ) );
	$bulk_id = $wpdb->insert_id;
	$repo->enqueue( 'bulk', $profile, array( 'bulk_id' => $bulk_id, 'message' => 'QA only' ), $bulk_id, 'test-paused' );
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'mkt_bulk' ORDER BY id DESC LIMIT 1", $carts->table( 'messages' ) ) );
	$count = count( $requests );
	$engine->process( $job );
	anar_suite_check( count( $requests ) === $count, 'Paused bulk campaigns cannot send messages' );
	$wpdb->update( $repo->table( 'bulk' ), array( 'state' => 'cancelled' ), array( 'id' => $bulk_id ) );
	$engine->process( $job );
	anar_suite_check( 'cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $carts->table( 'messages' ), $job->id ) ), 'Cancellation stops already-queued bulk work' );
	$marketing['rules']['survey']['discount_type'] = 'percent';
	$marketing['rules']['survey']['discount_amount'] = '5';
	$marketing['rules']['survey']['params'] = "link={action_url}\nstop={unsubscribe_url}";
	update_option( Settings::OPTION, $marketing );
	$repo->enqueue( 'survey', $profile, array( 'order_source' => $order->get_id() ), $order->get_id(), 'qa-survey-send' );
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'mkt_survey' ORDER BY id DESC LIMIT 1", $carts->table( 'messages' ) ) );
	$count = count( $requests );
	$engine->process( $job );
	anar_suite_check( count( $requests ) === $count + 1 && 'accepted' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $carts->table( 'messages' ), $job->id ) ), 'A rewarded survey sends its scoped response link before minting any reward' );
	$wpdb->update( $repo->table( 'bulk' ), array( 'state' => 'queued', 'filters' => '{"segment":"new"}' ), array( 'id' => $bulk_id ) );
	$repo->enqueue( 'bulk', $profile, array( 'bulk_id' => $bulk_id ), $bulk_id, 'changed-audience' );
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'mkt_bulk' ORDER BY id DESC LIMIT 1", $carts->table( 'messages' ) ) );
	$engine->process( $job );
	anar_suite_check( 'cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $carts->table( 'messages' ), $job->id ) ), 'A queued bulk message rechecks its audience segment immediately before sending' );
	$repo->enqueue( 'stock', $profile, array( 'product_id' => $product->get_id(), 'subscription_id' => 9999999 ), $product->get_id(), 'deleted-subscription' );
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'mkt_stock' ORDER BY id DESC LIMIT 1", $carts->table( 'messages' ) ) );
	$engine->process( $job );
	anar_suite_check( 'cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $carts->table( 'messages' ), $job->id ) ), 'A deleted stock subscription cannot send a stale notification' );
	$wpdb->update( $repo->table( 'bulk' ), array( 'filters' => '{"segment":"all"}' ), array( 'id' => $bulk_id ) );
	$repo->enqueue( 'bulk', $profile, array( 'bulk_id' => $bulk_id ), $bulk_id, 'shared-cap' );
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'mkt_bulk' ORDER BY id DESC LIMIT 1", $carts->table( 'messages' ) ) );
	$count = count( $requests );
	$engine->process( $job );
	$scheduled = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $carts->table( 'messages' ), $job->id ) );
	anar_suite_check( count( $requests ) === $count && 'pending' === $scheduled->status && $scheduled->scheduled_at > time(), 'The shared per-phone campaign gap postpones a second campaign' );
	$carts->suppress( $carts->capture_contact( $profile->phone, '', 'account' ) );
	anar_suite_check( ! $repo->allowed( $profile ), 'A shared unsubscribe blocks all new marketing campaigns' );
	$dashboard = ( new \Anar\Login\Commerce\Dashboard( $carts ) )->data( 30 );
	anar_suite_check( is_array( $dashboard['chart'] ) && count( $dashboard['chart'] ) === 14, 'Dashboard queries return a bounded real daily series' );
	$export = ( new \Anar\Login\Marketing\Privacy() )->export( $profile->email );
	anar_suite_check( ! empty( $export['data'] ) && false === strpos( wp_json_encode( $export ), 'TEST-ONLY' ), 'Marketing export excludes credentials and code secrets' );
	$erased = ( new \Anar\Login\Marketing\Privacy() )->erase( $profile->email );
	anar_suite_check( $erased['items_removed'] && ! $repo->profile( $profile_id ), 'Marketing erasure removes local customer campaign data' );
	anar_suite_check( (bool) wc_get_order( $order->get_id() ), 'Marketing erasure preserves the WooCommerce order' );
	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => count( $GLOBALS['anar_suite_checks'] ), 'results' => $GLOBALS['anar_suite_checks'], 'all_http_and_mail' => 'mocked' ), JSON_PRETTY_PRINT ) );
} finally {
	wp_set_current_user( 0 );
	foreach ( $original as $key => $value ) {
		false === $value ? delete_option( $key ) : update_option( $key, $value ); }
	foreach ( $orders as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true ); }
	}
	foreach ( $products as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			$product->delete( true ); }
	}
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php'; }
	foreach ( $users as $id ) {
		wp_delete_user( $id ); }
}
