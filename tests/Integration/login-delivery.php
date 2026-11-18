<?php
/**
 * Ordered login delivery against real WordPress storage, with all transport mocked.
 * Run with wp eval-file on a disposable local WordPress installation.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\CodeChallenge;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\LoginDelivery;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! WP_DEBUG ) {
	throw new RuntimeException( 'Disposable local installation required.' );
}

global $wpdb;
$checks = array();
$option_names = array( Settings::OPTION_KEY, MessageSettings::OPTION . '_login_otp', MessageSettings::OPTION . '_login_sms', MessageSettings::OPTION . '_password_reset' );
$original = array();
foreach ( $option_names as $name ) {
	$original[ $name ] = get_option( $name, null );
	delete_option( $name );
}
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) ); }
	$checks[] = $label;
};
$settings = new Settings();
$base = array_merge(
	$settings->defaults(),
	array(
		'identity_mode' => 'both',
		'mobile_delivery_order' => 'bale_sms',
		'sms_provider' => 'panelchi',
		'mobile_sms_provider' => 'panelchi',
		'provider_panelchi' => array( 'api_key' => 'TEST-ONLY', 'template_id' => 'legacy-otp', 'code_parameter' => 'otp' ),
		'provider_bale' => array( 'username' => 'TEST-ONLY', 'password' => 'TEST-ONLY' ),
	)
);
$auth = array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"mock-token","expires_in":3600}' );
$bale_ok = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":0}' );
$sms_ok = array( 'response' => array( 'code' => 200 ), 'body' => '{"data":{"uid":"mock-id","status":"ACCEPTED"}}' );
$reject = array( 'response' => array( 'code' => 400 ), 'body' => '{"code":17,"message":"not registered"}' );
$queue = array();
$requests = array();
$http = static function ( $pre, $args, $url ) use ( &$queue, &$requests ) {
	$requests[] = array( 'url' => $url, 'body' => $args['body'] ?? '' );
	if ( ! $queue ) {
		throw new RuntimeException( 'Unexpected outgoing request.' ); }
	return array_shift( $queue );
};
add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
$cache = 'anar_bale_' . hash_hmac( 'sha256', 'TEST-ONLY|TEST-ONLY', wp_salt( 'auth' ) );
$reset = static function ( $order = 'bale_sms' ) use ( $base, &$requests, $cache ) {
	update_option( Settings::OPTION_KEY, array_replace( $base, array( 'mobile_delivery_order' => $order ) ) );
	delete_transient( $cache );
	$requests = array();
};
$send = static function ( $context = 'login_otp' ) use ( $settings ) {
	return ( new ProviderRegistry( $settings ) )->send( '+989123456789', '123456', 'Code 123456', $context );
};
$keys = array();
$mail_count = 0;
$mail_filter = static function () use ( &$mail_count ) {
	++$mail_count;
	return true;
};
add_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX );

try {
	$reset();
	$queue = array( $auth, $bale_ok );
	$assert( true === $send() && 2 === count( $requests ), 'Bale success never sends SMS' );
	$reset();
	$queue = array( $auth, $reject, $sms_ok );
	$assert( true === $send() && 3 === count( $requests ), 'Non-member rejection falls back to SMS' );
	$assert( 123456 === json_decode( $requests[1]['body'], true )['otp'] && '123456' === json_decode( $requests[2]['body'], true )['variables']['otp'], 'Fallback sends the same code through both services' );
	$reset();
	$queue = array( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ), $sms_ok );
	$assert( true === $send() && 2 === count( $requests ), 'Token request failure safely falls back before any Bale code was sent' );
	$reset();
	$queue = array( $auth, new WP_Error( 'http_request_failed', 'cURL error 7: connection refused' ), $sms_ok );
	$assert( true === $send(), 'Connection refusal safely falls back' );
	$reset();
	$queue = array( $auth, array( 'response' => array( 'code' => 503 ), 'body' => '{"code":2}' ), $sms_ok );
	$assert( true === $send(), 'Explicit Bale API rejection falls back even on HTTP 503' );
	foreach ( array( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ), array( 'response' => array( 'code' => 503 ), 'body' => '<html>Unavailable</html>' ), array( 'response' => array( 'code' => 200 ), 'body' => 'invalid response' ) ) as $response ) {
		$reset();
		$queue = array( $auth, $response );
		$result = $send();
		$assert( is_wp_error( $result ) && ! empty( $result->get_error_data()['delivery_uncertain'] ) && 2 === count( $requests ), 'Ambiguous send response never causes duplicate delivery' );
	}
	$reset( 'sms_bale' );
	$queue = array( $sms_ok );
	$assert( true === $send() && 1 === count( $requests ), 'SMS-first success does not contact Bale' );
	$reset( 'sms_bale' );
	$queue = array( $reject, $auth, $bale_ok );
	$assert( true === $send(), 'SMS rejection falls back to Bale' );
	$reset( 'sms_bale' );
	$queue = array( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":false}' ), $auth, $bale_ok );
	$assert( true === $send(), 'Explicit SMS API rejection falls back on HTTP 200' );
	$reset( 'sms_bale' );
	$queue = array( array( 'response' => array( 'code' => 200 ), 'body' => 'unrecognized' ) );
	$result = $send();
	$assert( is_wp_error( $result ) && ! empty( $result->get_error_data()['delivery_uncertain'] ) && 1 === count( $requests ), 'Unrecognized SMS response cannot trigger duplicate delivery' );
	$reset( 'sms_bale' );
	$queue = array( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
	$result = $send();
	$assert( is_wp_error( $result ) && ! empty( $result->get_error_data()['delivery_uncertain'] ) && 1 === count( $requests ), 'Ambiguous SMS timeout does not send a Bale duplicate' );
	$reset();
	$queue = array( $auth, $reject, $reject );
	$assert( is_wp_error( $send() ) && 3 === count( $requests ), 'Both rejected services stop after one attempt each' );
	$reset( 'single' );
	$queue = array( $sms_ok );
	$assert( true === $send() && 1 === count( $requests ), 'Single-service installations retain their original delivery' );
	$reset();
	$test_options = get_option( Settings::OPTION_KEY );
	$test_options['sms_provider'] = 'bale';
	update_option( Settings::OPTION_KEY, $test_options );
	$queue = array( $sms_ok );
	$assert( true === $send( 'login_sms' ) && 1 === count( $requests ) && false !== strpos( $requests[0]['url'], 'panelchi.com' ), 'Dedicated SMS message test uses its selected panel even when the old primary is Bale' );
	$reset();
	$queue = array( $auth, $reject, $sms_ok );
	$assert( true === $send( 'password_reset' ), 'Inherited verification messages use the priority order' );
	$binding = MessageSettings::get( 'login_sms' );
	$binding['inherit'] = false;
	update_option( MessageSettings::OPTION . '_password_reset', $binding );
	$reset();
	$queue = array( $sms_ok );
	$assert( true === $send( 'password_reset' ) && 1 === count( $requests ), 'Explicit per-purpose provider overrides remain independent' );
	$assert( ! LoginDelivery::applies( $settings, 'recovery_request' ) && ! LoginDelivery::applies( $settings, 'mkt_birthday' ), 'Commerce and campaigns never enter login failover' );
	$bad = $binding;
	$bad['provider'] = 'bale';
	$assert( ! empty( MessageSettings::errors( 'login_sms', $bad ) ), 'Bale cannot be chosen as the SMS fallback' );
	$bad = $binding;
	$bad['inherit'] = true;
	$assert( ! empty( MessageSettings::errors( 'login_sms', $bad ) ), 'SMS fallback cannot recursively inherit login delivery' );
	$reset();
	$sanitized = $settings->sanitize( array( 'mobile_delivery_order' => 'untrusted', 'mobile_sms_provider' => 'bale' ) );
	$assert( 'single' === $sanitized['mobile_delivery_order'] && '' === $sanitized['mobile_sms_provider'], 'Invalid routing settings are rejected' );
	$assert( 'bale_sms' === $settings->sanitize( array() )['mobile_delivery_order'], 'Missing routing field preserves the saved mode' );

	$repository = new OtpRepository();
	$limiter = new RateLimiter( $settings );
	$challenge = new CodeChallenge( $settings, $repository, $limiter, new ProviderRegistry( $settings ) );
	$issue = static function ( $response, $limit = true ) use ( $reset, &$queue, $auth, $challenge, &$keys ) {
		$reset();
		$queue = array( $auth, $response );
		$key = 'delivery-qa-' . wp_generate_uuid4();
		$keys[] = $key;
		$phone = '+989' . random_int( 100000000, 999999999 );
		$code = '';
		$result = $challenge->issue(
			array( 'identity' => $phone, 'channel' => 'sms' ),
			$key,
			static function ( $value ) use ( &$code ) {
				$code = $value;
				return 'Code ' . $value;
			},
			array( 'limit' => $limit )
		);
		return array( $result, $key, $phone, $code );
	};
	list( $result, $key, $phone, $code ) = $issue( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
	$assert( true === $result && ! is_wp_error( $challenge->verify( $key, 'sms', $code, array( 'consume' => false ) ) ), 'Possibly delivered OTP remains valid in real WordPress storage' );
	$guard = $limiter->guard_request( $phone );
	$assert( is_wp_error( $guard ) && 'anar_too_soon' === $guard->get_error_code(), 'Ambiguous delivery retains the resend cooldown' );
	$reset();
	$queue = array( $auth, $reject, $sms_ok );
	$key = 'delivery-qa-' . wp_generate_uuid4();
	$keys[] = $key;
	$code = '';
	$phone = '+989' . random_int( 100000000, 999999999 );
	$before = $limiter->site_requests();
	$result = $challenge->issue(
		array( 'identity' => $phone, 'channel' => 'sms' ),
		$key,
		static function ( $value ) use ( &$code ) {
			$code = $value;
			return $value;
		}
	);
	$assert( true === $result && $before + 1 === $limiter->site_requests() && ! is_wp_error( $challenge->verify( $key, 'sms', $code ) ), 'Fallback stores one verifiable challenge and consumes one site quota' );
	$assert( is_wp_error( $challenge->verify( $key, 'sms', $code ) ), 'Consumed fallback OTP cannot be replayed' );
	$reset();
	$queue = array( $auth, $reject, $reject );
	$key = 'delivery-qa-' . wp_generate_uuid4();
	$keys[] = $key;
	$phone = '+989' . random_int( 100000000, 999999999 );
	$result = $challenge->issue(
		array( 'identity' => $phone, 'channel' => 'sms' ),
		$key,
		static function ( $value ) {
			return $value;
		}
	);
	$assert( is_wp_error( $result ) && ! $repository->latest( $key, 'sms' ) && true === $limiter->guard_request( $phone ), 'Definite failure consumes the challenge and releases its cooldown' );
	$reset();
	$queue = array();
	$key = 'delivery-qa-' . wp_generate_uuid4();
	$keys[] = $key;
	$assert(
		true === $challenge->issue(
			array( 'identity' => 'qa@example.test', 'channel' => 'email' ),
			$key,
			static function ( $value ) {
				return array( 'Code', $value );
			},
			array( 'limit' => false )
		) && 1 === $mail_count && ! $requests,
		'Email remains available alongside mobile without contacting SMS or Bale'
	);
	$report = array( 'passed' => true, 'checks' => count( $checks ), 'results' => $checks );
} finally {
	remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX );
	delete_transient( $cache );
	foreach ( $original as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value ); }
	}
	foreach ( $keys as $key ) {
		$wpdb->delete( $wpdb->prefix . 'anar_login_otp', array( 'identity_hash' => hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ) ) );
	}
}
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
