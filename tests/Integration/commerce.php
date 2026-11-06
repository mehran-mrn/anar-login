<?php
/**
 * Run with wp eval-file in a disposable local WordPress + WooCommerce installation.
 * HTTP and mail are intercepted; no real SMS is sent. Not shipped in the plugin ZIP.
 *
 * @package AnarLogin
 */

use Anar\Login\Commerce\Module;
use Anar\Login\Commerce\Privacy;
use Anar\Login\Commerce\Repository;
use Anar\Login\Commerce\Settings;
use Anar\Login\Commerce\Worker;
use Anar\Login\Messaging\HttpClient;
use Anar\Login\Messaging\NotificationSender;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Use a disposable local WooCommerce installation.' );
}

global $wpdb, $anar_checks;
$anar_checks = array();
function anar_toolkit_assert( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$GLOBALS['anar_checks'][] = $label;
}

$settings = new Settings();
$repo     = new Repository();
$module   = new Module( $settings, $repo );
$login    = new \Anar\Login\Infrastructure\Settings();
$sender   = new NotificationSender( $login, new HttpClient() );
$worker   = new Worker( $settings, $repo, $module, $sender );
$privacy  = new Privacy( $repo, $settings );
$original = get_option( Settings::OPTION );
$old_login = $login->all();
$requests = array();
$test_mode     = 'accepted';
add_filter( 'pre_wp_mail', '__return_true' );
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests, &$test_mode ) {
		$requests[] = array( 'url' => $url, 'args' => $args );
		if ( 'network' === $test_mode ) {
			return new WP_Error( 'network_failure', 'Test network failure' );
		}
		$body = array( 'return' => array( 'status' => 'rejected' === $test_mode ? 400 : 200 ) );
		if ( false !== strpos( $url, 'edge.ippanel.com' ) ) {
			$body = array( 'meta' => array( 'status' => true ), 'data' => array( 'message_outbox_ids' => array( 11 ) ) );
		}
		if ( false !== strpos( $url, 'api.panelchi.com' ) ) {
			$body = array( 'data' => array( 'uid' => 'test-request', 'status' => 'CREATED' ) );
		}
		if ( false !== strpos( $url, 'api.sms.ir' ) ) {
			$body = array( 'status' => 1, 'data' => array( 'messageId' => 11 ) );
		}
		if ( false !== strpos( $url, 'payamak-panel.com' ) ) {
			$body = array( 'RetStatus' => 1, 'Value' => '12345678901234567' );
		}
		if ( false !== strpos( $url, 'patterns/pattern' ) ) {
			$body = 123456;
		}
		return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	},
	PHP_INT_MAX,
	3
);

try {
	delete_option( Settings::OPTION );
	foreach ( array( 'messages', 'carts', 'contacts' ) as $name ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $repo->table( $name ) ) );
	}
	delete_option( 'anar_toolkit_worker_lock' );
	delete_option( 'anar_toolkit_scan_cursor' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key LIKE %s', $wpdb->prefix . 'anar_login_limits', $wpdb->esc_like( 'anar_recovery_' ) . '%' ) );
	$config = $settings->defaults();
	anar_toolkit_assert( ! $settings->enabled(), 'Modules default to disabled' );
	$config['recovery'] = 1;
	$config['paid_customer'] = 1;
	$config['paid_admin'] = 1;
	$config['admin_phones'] = '+989121111111';
	$config['start_hour'] = 0;
	$config['end_hour'] = 24;
	$config['provider'] = 'kavenegar';
	foreach ( $config['events'] as $key => &$event ) {
		$event['template'] = 'approved-' . $key;
		$event['params'] = 0 === strpos( $key, 'recovery_' ) ? "token={code}\ntoken2={recovery_page}\ntoken3={unsubscribe_url}" : "token={order_id}\ntoken2={total}";
		if ( isset( $event['minutes'] ) ) {
			$event['minutes'] = 'recovery_1' === $key ? 1 : 0;
		}
	}
	unset( $event );
	update_option( Settings::OPTION, $config );
	$login_data = $old_login;
	foreach ( array( 'kavenegar', 'ippanel', 'panelchi', 'farazsms', 'melipayamak', 'smsir' ) as $provider ) {
		$login_data[ 'provider_' . $provider ] = array( 'api_key' => 'TEST-ONLY', 'username' => 'TEST-ONLY', 'password' => 'TEST-ONLY', 'from' => '1000' );
	}
	update_option( \Anar\Login\Infrastructure\Settings::OPTION_KEY, $login_data );

	$contact = $repo->consent( '+989123456789', 'shopper@example.test', Module::consent_text() );
	anar_toolkit_assert( $contact > 0, 'Consent persisted with phone, email and timestamp' );
	anar_toolkit_assert( $repo->consent( '+989123456789', 'shopper@example.test', Module::consent_text() ) === $contact, 'Phone consent is normalized to one contact' );
	$cart_id = $repo->save_cart( 'qa-session', $contact, array( array( 'product_id' => 1, 'variation_id' => 0, 'variation' => array(), 'quantity' => 1 ) ), 120000, 'IRR' );
	anar_toolkit_assert( $cart_id > 0 && $module->valid_cart( $repo->cart( $cart_id ) ), 'Consented cart can be recovered' );
	$wpdb->update( $repo->table( 'carts' ), array( 'updated_at' => time() - 120 ), array( 'id' => $cart_id ) );
	$worker->run();
	$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'recovery_1' AND object_id = %d", $repo->table( 'messages' ), $cart_id ) );
	anar_toolkit_assert( $job && 'accepted' === $job->status, 'Due recovery request accepted by mocked provider' );
	anar_toolkit_assert( count( $requests ) === 1, 'Exactly one request for the due recovery stage' );
	anar_toolkit_assert( isset( $requests[0]['args']['body']['token3'] ) && false !== strpos( $requests[0]['args']['body']['token3'], '.u.' ), 'Unsubscribe link is passed to the provider' );
	$worker->run();
	anar_toolkit_assert( count( $requests ) === 1, 'Repeated worker execution does not duplicate a reminder' );
	anar_toolkit_assert( ! $repo->claim( $job->id ), 'Completed queue rows cannot be claimed again' );
	$lease = $repo->lock();
	anar_toolkit_assert( $lease && false === $repo->lock(), 'Concurrent worker is excluded by atomic lease' );
	$repo->unlock( $lease );

	$cart = $repo->cart( $cart_id );
	$delivered_code = $requests[0]['args']['body']['token'];
	$code_store = new \Anar\Login\Infrastructure\OtpRepository();
	$challenge = $code_store->latest( '+989123456789', 'recovery' );
	anar_toolkit_assert( preg_match( '/^\d{6}$/', $delivered_code ) && $challenge->code_hash !== $delivered_code, 'Recovery SMS contains a six-digit code stored only as a keyed hash' );
	anar_toolkit_assert( null === $code_store->latest( '+989123456789', 'sms' ), 'Recovery challenges are isolated from login SMS challenges' );
	$before_user = get_current_user_id();
	$verified_cart = $module->verify_recovery( '09123456789', $delivered_code );
	anar_toolkit_assert( ! is_wp_error( $verified_cart ) && (int) $verified_cart->id === $cart_id, 'Phone and recovery code resolve the correct saved cart' );
	anar_toolkit_assert( get_current_user_id() === $before_user, 'Recovery verification never authenticates an account' );
	anar_toolkit_assert( is_wp_error( $module->verify_recovery( '09123456789', $delivered_code ) ), 'A recovery code cannot be replayed' );
	$fresh = $module->issue_code( $cart );
	for ( $guess = 0; $guess < 5; ++$guess ) {
		anar_toolkit_assert( is_wp_error( $module->verify_recovery( '09123456789', 'wrong' ) ), 'Wrong recovery guess rejected ' . $guess );
	}
	anar_toolkit_assert( is_wp_error( $module->verify_recovery( '09123456789', $fresh ) ), 'Correct code is refused after five wrong attempts' );
	$fresh = $module->issue_code( $cart );
	$challenge = $code_store->latest( '+989123456789', 'recovery' );
	$wpdb->update( $wpdb->prefix . 'anar_login_otp', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $challenge->id ) );
	anar_toolkit_assert( is_wp_error( $module->verify_recovery( '09123456789', $fresh ) ), 'Expired recovery code is rejected' );
	anar_toolkit_assert( '' === Module::link( $cart, 'recover' ), 'No bearer recovery link is generated' );
	$altered = clone $cart;
	++$altered->id;
	anar_toolkit_assert( Module::signature( $cart, 'unsubscribe' ) !== Module::signature( $altered, 'unsubscribe' ), 'Changing cart ID invalidates the opt-out signature' );
	anar_toolkit_assert( false === strpos( Module::link( $cart, 'unsubscribe' ), 'shopper' ) && false === strpos( Module::link( $cart, 'unsubscribe' ), '989123' ), 'Opt-out links contain no email or phone' );
	$repo->enqueue( 'recovery_2', $cart_id, '+989123456789', time() );
	$repo->suppress( $contact );
	anar_toolkit_assert( ! $module->valid_cart( $repo->cart( $cart_id ) ), 'Opt-out disables recovery links' );
	$worker->run();
	anar_toolkit_assert( count( $requests ) === 1, 'Opt-out cancels pending reminders' );

	$test_order = wc_create_order();
	$test_order->set_billing_phone( '09123456789' );
	$test_order->set_billing_email( 'shopper@example.test' );
	$test_order->set_billing_first_name( 'QA shopper' );
	$test_order->set_currency( 'IRR' );
	$test_order->set_total( 120000 );
	$test_order->save();
	$module->payment_complete( $test_order->get_id() );
	anar_toolkit_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event IN ('paid_customer', 'paid_admin')", $repo->table( 'messages' ) ) ), 'Creating an unpaid order does not notify anyone' );
	$test_order->payment_complete( 'qa-transaction' );
	$module->payment_complete( $test_order->get_id() );
	$module->payment_complete( $test_order->get_id() );
	$worker->run();
	anar_toolkit_assert( 3 === count( $requests ), 'One payment notification per customer and administrator despite repeated callbacks' );
	anar_toolkit_assert( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event IN ('paid_customer', 'paid_admin') AND status = 'accepted'", $repo->table( 'messages' ) ) ), 'Payment jobs accepted independently for both recipients' );
	$worker->run();
	anar_toolkit_assert( 3 === count( $requests ), 'Paid-event jobs remain idempotent across queue runs' );

	$vars = array( 'order_id' => '123', 'total' => '120000', 'code' => '123456', 'recovery_page' => 'https://example.test/recovery', 'unsubscribe_url' => 'https://example.test/stop' );
	$event = array( 'template' => '123', 'message' => '{order_id}', 'params' => 'ORDER={order_id}' );
	foreach ( array( 'ippanel', 'panelchi', 'farazsms', 'melipayamak', 'smsir' ) as $provider ) {
		$available = ( new \Anar\Login\Messaging\ProviderRegistry( $login ) )->all();
		if ( ! isset( $available[ $provider ] ) ) {
			anar_toolkit_assert( is_wp_error( $sender->send( $provider, '+989123456789', $event, $vars ) ), $provider . ' is unavailable in the public release' );
			continue;
		}
		anar_toolkit_assert( true === $sender->send( $provider, '+989123456789', $event, $vars ), $provider . ' multi-variable request format and success response' );
	}
	$before = count( $requests );
	anar_toolkit_assert( is_wp_error( $sender->send( 'kavenegar', '+989123456789', $event, $vars, true ) ), 'Recovery message without code and opt-out link is rejected before HTTP' );
	anar_toolkit_assert( count( $requests ) === $before, 'Invalid recovery code templates produce no network request' );

	$contact = $repo->consent( '+989123456789', 'shopper@example.test', Module::consent_text() );
	$network_cart = $repo->save_cart( 'network-session', $contact, array( array( 'product_id' => 1 ) ), 1, 'IRR' );
	$wpdb->update( $repo->table( 'carts' ), array( 'updated_at' => time() - 120 ), array( 'id' => $network_cart ) );
	$test_mode = 'network';
	$worker->run();
	$network_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'recovery_1' AND object_id = %d", $repo->table( 'messages' ), $network_cart ) );
	anar_toolkit_assert( $network_job && 'uncertain' === $network_job->status, 'Ambiguous network result is not reported as failed delivery' );
	anar_toolkit_assert( ! $repo->retry( $network_job->id ), 'Ambiguous results cannot be retried and double-charge shoppers' );
	$before = count( $requests );
	$worker->run();
	anar_toolkit_assert( count( $requests ) === $before, 'Network failures are not automatically resent' );

	$test_mode = 'rejected';
	$failure_cart = $repo->save_cart( 'failure-session', $contact, array( array( 'product_id' => 1 ) ), 1, 'IRR' );
	$wpdb->update( $repo->table( 'carts' ), array( 'updated_at' => time() - 120 ), array( 'id' => $failure_cart ) );
	$worker->run();
	$failure_job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE event = 'recovery_1' AND object_id = %d", $repo->table( 'messages' ), $failure_cart ) );
	anar_toolkit_assert( $failure_job && 'failed' === $failure_job->status && 'anar_sms_rejected' === $failure_job->error_code, 'Provider rejection records only a safe error code' );
	anar_toolkit_assert( $repo->retry( $failure_job->id ), 'A confirmed provider rejection can be explicitly retried' );
	$test_mode = 'accepted';
	$worker->run();
	anar_toolkit_assert( 'accepted' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', $repo->table( 'messages' ), $failure_job->id ) ), 'Retry succeeds after the provider recovers' );

	$manual_contact = $repo->consent( '+989120000000', 'manual@example.test', Module::consent_text() );
	$manual_cart = $repo->save_cart( 'manual-session', $manual_contact, array( array( 'product_id' => 1 ) ), 1, 'IRR' );
	$manual_config = $config;
	$manual_config['events']['recovery_1']['minutes'] = 0;
	$manual_config['start_hour'] = ( (int) wp_date( 'G' ) + 1 ) % 24;
	$manual_config['end_hour'] = $manual_config['start_hour'] + 1;
	update_option( Settings::OPTION, $manual_config );
	$repo->enqueue( 'recovery_request', $manual_cart, '+989120000000', time(), 'qa-request' );
	$before = count( $requests );
	$worker->run();
	anar_toolkit_assert( count( $requests ) === $before + 1, 'Explicit new-code request works outside automated reminder hours and inactivity delays' );
	$manual_sms = end( $requests );
	anar_toolkit_assert( $manual_sms['args']['body']['token2'] === $module->recovery_page(), 'SMS contains the configured recovery-code entry page' );
	anar_toolkit_assert( false === strpos( $manual_sms['args']['body']['token2'], $manual_sms['args']['body']['token'] ), 'Entry-page URL contains no recovery code' );
	anar_toolkit_assert( ! is_wp_error( $module->verify_recovery( '09120000000', $manual_sms['args']['body']['token'] ) ), 'Requested code can verify the intended phone and cart' );
	update_option( Settings::OPTION, $config );
	$before = count( $requests );
	$too_long = array( 'template' => 'approved', 'message' => '{name}', 'params' => 'token={name}' );
	anar_toolkit_assert( is_wp_error( $sender->send( 'kavenegar', '+989123456789', $too_long, array( 'name' => str_repeat( 'a', 101 ) ) ) ), 'Kavenegar 100-character token limit enforced before HTTP' );
	anar_toolkit_assert( count( $requests ) === $before, 'Oversized template variables create no network request' );

	$export = $privacy->export( 'shopper@example.test' );
	anar_toolkit_assert( count( $export['data'] ) >= 3 && $export['done'], 'WordPress privacy export includes consent, carts and messages' );
	$serialized = wp_json_encode( $export );
	anar_toolkit_assert( false === strpos( $serialized, $cart->seed ) && false === strpos( $serialized, 'TEST-ONLY' ), 'Privacy export does not expose link seeds or gateway credentials' );
	$erased = $privacy->erase( 'shopper@example.test' );
	anar_toolkit_assert( $erased['items_removed'] && ! $repo->contact( $contact ), 'WordPress privacy erasure deletes local reminder identity' );
	anar_toolkit_assert( wc_get_order( $test_order->get_id() ) instanceof WC_Order, 'Privacy erasure preserves WooCommerce order records' );
	anar_toolkit_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE contact_id = %d', $repo->table( 'carts' ), $contact ) ), 'Erasure removes contact cart snapshots' );

	$repo->enqueue( 'paid_customer', 999999, '+989121234567', time() );
	$worker->run();
	anar_toolkit_assert( 'cancelled' === $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE object_id = %d', $repo->table( 'messages' ), 999999 ) ), 'Deleted or invalid orders cannot send payment messages' );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET updated_at = %d', $repo->table( 'messages' ), time() - 91 * DAY_IN_SECONDS ) );
	$repo->cleanup( 30 );
	anar_toolkit_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $repo->table( 'messages' ) ) ), 'Retention removes expired local queue records' );
	$test_order->delete( true );
	WP_CLI::success( count( $anar_checks ) . ' commerce integration checks passed; all SMS HTTP and mail mocked.' );
	WP_CLI::line( wp_json_encode( $anar_checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
} finally {
	update_option( \Anar\Login\Infrastructure\Settings::OPTION_KEY, $old_login );
	if ( false === $original ) {
		delete_option( Settings::OPTION );
	} else {
		update_option( Settings::OPTION, $original );
	}
}
