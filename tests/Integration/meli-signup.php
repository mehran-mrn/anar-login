<?php
/**
 * End-to-end opt-in registration contracts on a disposable local installation.
 * All HTTP calls are intercepted; no account or invoice is created externally.
 *
 * @package AnarLogin
 */

use Anar\Login\Partners\MeliSignup;
use Anar\Login\Api\MeliSignupController;
use MRNWP\Site\Meli_Partner;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}

require_once dirname( __DIR__, 3 ) . '/mrnwp-site/includes/meli-partner.php';
$old = get_option( Meli_Partner::OPTION );
$old_user = get_current_user_id();
$old_ip = $_SERVER['REMOTE_ADDR'] ?? null;
// Isolate abuse counters from other local test runs.
$_SERVER['REMOTE_ADDR'] = '198.18.' . random_int( 0, 255 ) . '.' . random_int( 1, 254 );
$requests = array();
$signup_mode = 'success';
$checks = array();
$verify = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
$transport = static function ( $pre, $args, $url ) use ( &$requests, &$signup_mode ) {
	$requests[] = array( 'url' => $url, 'args' => $args );
	if ( 0 === strpos( $url, MeliSignup::RELAY ) ) {
		if ( false !== strpos( $url, '/products' ) ) {
			$result = Meli_Partner::products();
		} else {
			$request = new WP_REST_Request( 'POST' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( $args['body'] );
			$result = Meli_Partner::register( $request );
		}
		$status = is_wp_error( $result ) ? $result->get_error_data()['status'] : 200;
		$body = is_wp_error( $result ) ? array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) : $result->get_data();
	} elseif ( Meli_Partner::API . 'products' === $url ) {
		$status = 200;
		$body = array( 'success' => true, 'data' => array( array( 'product_code' => 2, 'product_title' => 'طرح آزمایشی', 'product_price' => 199000, 'price_per_sms' => '142' ) ) );
	} elseif ( Meli_Partner::API . 'fast-register' === $url ) {
		if ( 'timeout' === $signup_mode ) {
			return new WP_Error( 'qa_timeout', 'Mocked timeout' );
		}
		$status = 200;
		$body = array( 'success' => true, 'data' => array( 'invoice_url' => 'bad-link' === $signup_mode ? 'https://evil.test/invoice/123' : 'https://sign.melipayamak.com/invoice/1782042000', 'affiliate_registered' => true, 'payment_status' => 'PRE_PAID' ) );
	} else {
		return new WP_Error( 'qa_blocked', 'External HTTP is blocked.' );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => 'Mocked' ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $transport, PHP_INT_MAX, 3 );
try {
	delete_option( Meli_Partner::OPTION );
	$verify( false === Meli_Partner::products()->get_data()['ready'], 'Missing private token disables registration without provider HTTP' );
	$verify( 0 === count( $requests ), 'No provider request when token is absent' );
	$verify( array( 'ready' => false ) === ( new MeliSignup() )->products(), 'Plugin receives unavailable status for affiliate fallback' );
	update_option( Meli_Partner::OPTION, array( 'token' => 'QA-ONLY-NOT-A-REAL-TOKEN', 'mobile' => '09120000000', 'price_unit' => 'IRT' ), false );
	$plans = ( new MeliSignup() )->products();
	$verify( true === $plans['ready'] && 2 === $plans['products'][0]['product_code'], 'Configured service provides validated live-plan schema and a ticket' );
	$verify( false === strpos( wp_json_encode( $plans ), 'QA-ONLY' ) && false === strpos( wp_json_encode( $plans ), '09120000000' ), 'Affiliate credentials never reach client output' );
	$input = array( 'first_name' => 'نام آزمایشی', 'last_name' => 'تست انار', 'mobile_number' => '۰۹۱۲۹۹۹۹۰۰۱', 'product_code' => 2, 'ticket' => $plans['ticket'], 'consent' => true );
	$before = count( $requests );
	$verify( is_wp_error( ( new MeliSignup() )->register( array_replace( $input, array( 'consent' => false ) ) ) ), 'Personal data cannot be submitted without explicit consent' );
	$verify( count( $requests ) === $before, 'Invalid consent makes no relay request' );
	$result = ( new MeliSignup() )->register( $input );
	$verify( ! is_wp_error( $result ) && 'https://sign.melipayamak.com/invoice/1782042000' === $result['invoice_url'], 'Opt-in signup returns the official invoice link' . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
	$provider_requests = array_values(
		array_filter(
			$requests,
			static function ( $row ) {
				return Meli_Partner::API . 'fast-register' === $row['url'];
			}
		)
	);
	$verify( 1 === count( $provider_requests ), 'Exactly one external registration request' );
	$body = $provider_requests[0]['args']['body'];
	$verify( array( 'first_name', 'last_name', 'mobile_number', 'product_code' ) === array_keys( $body ) && '09129999001' === $body['mobile_number'], 'Provider receives only the documented fields and normalized phone' );
	$verify( 'QA-ONLY-NOT-A-REAL-TOKEN' === $provider_requests[0]['args']['headers']['X-Affiliate-Api-Token'] && '09120000000' === $provider_requests[0]['args']['headers']['X-Affiliate-Mobile'], 'Private credential and affiliate mobile are sent in headers only' );
	$repeat = ( new MeliSignup() )->register( $input );
	$verify( $repeat['invoice_url'] === $result['invoice_url'], 'Repeated identical submission returns the same invoice' );
	$provider_after = array_filter(
		$requests,
		static function ( $row ) {
			return Meli_Partner::API . 'fast-register' === $row['url'];
		}
	);
	$verify( 1 === count( $provider_after ), 'Idempotent repeat never creates a second account' );
	$direct = new WP_REST_Request( 'POST' );
	$direct->set_header( 'Content-Type', 'application/json' );
	$direct->set_body( wp_json_encode( array_replace( $input, array( 'ticket' => $plans['ticket'] . 'x' ) ) ) );
	$verify( is_wp_error( Meli_Partner::register( $direct ) ), 'Tampered registration ticket is rejected' );
	foreach ( array( 'bad-link' => '09129999002', 'timeout' => '09129999003' ) as $scenario => $phone ) {
		$signup_mode = $scenario;
		$next = ( new MeliSignup() )->products();
		$trial = array_replace( $input, array( 'ticket' => $next['ticket'], 'mobile_number' => $phone ) );
		$verify( is_wp_error( ( new MeliSignup() )->register( $trial ) ), $scenario . ' is never reported as registration success' );
		$count = count( $requests );
		$verify( is_wp_error( ( new MeliSignup() )->register( $trial ) ), $scenario . ' remains uncertain on repeat' );
		$verify( count( $requests ) === $count + 1, $scenario . ' repeat only reaches the relay, never the provider' );
	}
	$controller = new MeliSignupController();
	$permission = new WP_REST_Request( 'GET' );
	wp_set_current_user( 0 );
	$verify( ! $controller->allowed( $permission ), 'Anonymous users cannot use the merchant administrator API' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $admins[0] );
	$verify( ! $controller->allowed( $permission ), 'An administrator still needs a valid REST nonce' );
	$permission->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$verify( (bool) $controller->allowed( $permission ), 'An administrator with a valid nonce can open signup' );
	WP_CLI::success( count( $checks ) . ' partner registration checks passed; all HTTP mocked.' );
	WP_CLI::line( wp_json_encode( $checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
} finally {
	remove_filter( 'pre_http_request', $transport, PHP_INT_MAX );
	wp_set_current_user( $old_user );
	if ( null === $old_ip ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $old_ip;
	}
	false === $old ? delete_option( Meli_Partner::OPTION ) : update_option( Meli_Partner::OPTION, $old, false );
	global $wpdb;
	$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( Meli_Partner::PREFIX ) . '%' ) );
	foreach ( $keys as $key ) {
		delete_option( $key );
	}
}
