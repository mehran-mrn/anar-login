<?php
/**
 * Simple login methods against real WordPress storage; every transport mocked.
 * Run with wp eval-file on a disposable local WordPress installation.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\CodeChallenge;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Messaging\ProviderUsage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! WP_DEBUG ) {
	throw new RuntimeException( 'Disposable local installation required.' );
}

global $wpdb;
$checks = array();
$option_names = array( Settings::OPTION_KEY, MessageSettings::OPTION . '_login_otp', MessageSettings::OPTION . '_login_sms', MessageSettings::OPTION . '_password_reset', 'anar_toolkit_commerce', 'anar_toolkit_marketing' );
$original = array();
foreach ( $option_names as $name ) {
	$original[ $name ] = get_option( $name, null );
	delete_option( $name );
}
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
$settings = new Settings();
$base = array_merge( $settings->defaults(), array( 'login_methods' => array( 'sms', 'email', 'bale' ), 'sms_provider' => 'panelchi', 'bot_challenge' => 'off', 'max_requests_hour' => 30, 'provider_panelchi' => array( 'api_key' => 'TEST-ONLY', 'template_id' => 'legacy-otp', 'code_parameter' => 'otp' ), 'provider_bale' => array( 'username' => 'TEST-ONLY', 'password' => 'TEST-ONLY' ) ) );
$auth = array( 'response' => array( 'code' => 200 ), 'body' => '{"access_token":"mock-token","expires_in":3600}' );
$bale_ok = array( 'response' => array( 'code' => 200 ), 'body' => '{"code":0}' );
$sms_ok = array( 'response' => array( 'code' => 200 ), 'body' => '{"data":{"uid":"mock-id","status":"ACCEPTED"}}' );
$reject = array( 'response' => array( 'code' => 400 ), 'body' => '{"code":17}' );
$queue = array();
$requests = array();
$http = static function ( $pre, $args, $url ) use ( &$queue, &$requests ) {
	$requests[] = array( 'url' => $url, 'body' => $args['body'] ?? '' );
	if ( ! $queue ) {
		throw new RuntimeException( 'Unexpected outgoing request.' );
	}
	return array_shift( $queue );
};
add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
$cache = 'anar_bale_' . hash_hmac( 'sha256', 'TEST-ONLY|TEST-ONLY', wp_salt( 'auth' ) );
$reset = static function ( $methods = array( 'sms', 'email', 'bale' ) ) use ( $base, &$requests, $cache ) {
	update_option( Settings::OPTION_KEY, array_replace( $base, array( 'login_methods' => $methods ) ) );
	delete_transient( $cache );
	$requests = array();
};
$send = static function ( $context = 'login_otp' ) use ( $settings ) {
	return ( new ProviderRegistry( $settings ) )->send( '+989123456789', '123456', 'Code 123456', $context );
};
$keys = array();
$mail_count = 0;
$mail_success = true;
$qa_user = 0;
$mail_filter = static function () use ( &$mail_count, &$mail_success ) {
	++$mail_count;
	return $mail_success;
};
add_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX );

try {
	$reset( array( 'sms' ) );
	$queue = array( $sms_ok );
	$assert( true === $send() && 1 === count( $requests ), 'SMS-only uses its selected SMS account' );
	$reset( array( 'bale' ) );
	$queue = array( $auth, $bale_ok );
	$assert( true === $send() && 2 === count( $requests ), 'Bale-only never calls SMS' );
	$reset();
	$queue = array( $sms_ok, $auth, $bale_ok );
	$assert( true === $send() && 3 === count( $requests ), 'Both mobile senders run even after the first succeeds' );
	$assert( '123456' === json_decode( $requests[0]['body'], true )['variables']['otp'] && 123456 === json_decode( $requests[2]['body'], true )['otp'], 'Both mobile senders receive exactly the same OTP' );
	$reset();
	$queue = array( $reject, $auth, $bale_ok );
	$assert( true === $send() && 3 === count( $requests ), 'SMS failure does not prevent Bale success' );
	$reset();
	$queue = array( $sms_ok, $auth, $reject );
	$assert( true === $send() && 3 === count( $requests ), 'A non-member on Bale can still receive the SMS code' );
	$reset();
	$queue = array( $reject, $auth, $reject );
	$assert( is_wp_error( $send() ) && 3 === count( $requests ), 'Both definite rejections fail after one send each' );
	$reset();
	$queue = array( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ), $auth, $reject );
	$result = $send();
	$assert( is_wp_error( $result ) && ! empty( $result->get_error_data()['delivery_uncertain'] ) && 3 === count( $requests ), 'Uncertain SMS delivery keeps the shared challenge usable while Bale is still attempted' );
	$reset();
	$queue = array( $sms_ok, $auth, $bale_ok );
	$assert( true === $send( 'password_reset' ) && 3 === count( $requests ), 'Inherited verification uses the same enabled mobile senders' );
	update_option( MessageSettings::OPTION . '_password_reset', array( 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'reset-only', 'params' => 'otp={code}', 'message' => '{code}', 'inherit' => false ) );
	$reset();
	$queue = array( $sms_ok );
	$assert( true === $send( 'password_reset' ) && 1 === count( $requests ) && 'reset-only' === json_decode( $requests[0]['body'], true )['pattern'], 'Existing per-purpose provider and template overrides are preserved' );

	$repository = new OtpRepository();
	$limiter = new RateLimiter( $settings );
	$challenge = new CodeChallenge( $settings, $repository, $limiter, new ProviderRegistry( $settings ) );
	$reset();
	$queue = array( $sms_ok, $auth, $bale_ok );
	$key = 'delivery-qa-' . wp_generate_uuid4();
	$keys[] = $key;
	$phone = '+989' . random_int( 100000000, 999999999 );
	$before = $limiter->site_requests();
	$result = $challenge->issue(
		array( 'identity' => $phone, 'channel' => 'sms' ),
		$key,
		static function ( $value ) {
			return $value;
		}
	);
	$code = json_decode( $requests[0]['body'], true )['variables']['otp'];
	$assert( true === $result && $before + 1 === $limiter->site_requests(), 'Two mobile sends count as one site request' );
	$assert( ! is_wp_error( $challenge->verify( $key, 'sms', $code ) ) && is_wp_error( $challenge->verify( $key, 'sms', $code ) ), 'Shared OTP verifies once and cannot be replayed' );
	$reset();
	$queue = array( $reject, $auth, $reject );
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
	$assert( is_wp_error( $result ) && ! $repository->latest( $key, 'sms' ) && true === $limiter->guard_request( $phone ), 'Definite failure discards the undelivered code and releases cooldown' );
	$reset( array( 'bale' ) );
	$queue = array( $auth, new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
	$key = 'delivery-qa-' . wp_generate_uuid4();
	$keys[] = $key;
	$phone = '+989' . random_int( 100000000, 999999999 );
	$code = '';
	$result = $challenge->issue(
		array( 'identity' => $phone, 'channel' => 'sms' ),
		$key,
		static function ( $value ) use ( &$code ) {
			$code = $value;
			return $value;
		}
	);
	$assert( true === $result && ! is_wp_error( $challenge->verify( $key, 'sms', $code ) ) && is_wp_error( $limiter->guard_request( $phone ) ), 'Bale timeout retains its possibly delivered code and cooldown' );

	// Real linked account: retired simultaneous/backup settings must be ignored.
	$reset();
	$qa_user = wp_create_user( 'delivery-' . wp_generate_uuid4(), wp_generate_password(), 'delivery-' . wp_generate_uuid4() . '@example.test' );
	$assert( ! is_wp_error( $qa_user ), 'Disposable linked account created' );
	$qa_phone = '+989' . random_int( 100000000, 999999999 );
	update_user_meta( $qa_user, '_anar_phone', $qa_phone );
	$qa_email = get_userdata( $qa_user )->user_email;
	$configured = get_option( Settings::OPTION_KEY );
	$configured['login_delivery_mode'] = 'simultaneous';
	$configured['login_email_backup'] = 'mobile';
	update_option( Settings::OPTION_KEY, $configured );
	$otp = new OtpService( $settings, $repository, $limiter, new ProviderRegistry( $settings ), new UserService( $settings ) );
	$queue = array( $sms_ok, $auth, $bale_ok );
	$assert( ! is_wp_error( $otp->request( $qa_phone ) ) && 0 === $mail_count && 3 === count( $requests ), 'Mobile entry sends only SMS and Bale, never the linked email' );
	$requests = array();
	$queue = array();
	$assert( ! is_wp_error( $otp->request( $qa_email ) ) && 1 === $mail_count && ! $requests, 'Email entry sends email only, never linked SMS or Bale' );
	$limiter->release( $qa_email );
	$mail_success = false;
	$assert( is_wp_error( $otp->request( $qa_email ) ) && ! $requests, 'Failed email has no hidden mobile backup' );
	$mail_success = true;

	foreach ( array( array( 'sms' ), array( 'email' ), array( 'bale' ), array( 'sms', 'email' ), array( 'sms', 'bale' ), array( 'email', 'bale' ), array( 'sms', 'email', 'bale' ) ) as $methods ) {
		$reset( $methods );
		$contact_mode = $settings->get( 'identity_mode' );
		$assert( ( in_array( 'email', $methods, true ) ? ( count( $methods ) > 1 ? 'both' : 'email' ) : 'phone' ) === $contact_mode, 'Contact form mode matches enabled senders: ' . implode( ',', $methods ) );
	}

	// Existing 1.8 SMS template survives the simplified route and later edits.
	$legacy = $base;
	unset( $legacy['login_methods'] );
	$legacy['mobile_delivery_order'] = 'bale_sms';
	$legacy['login_provider'] = 'bale';
	$legacy['mobile_sms_provider'] = 'panelchi';
	update_option( Settings::OPTION_KEY, $legacy );
	update_option( MessageSettings::OPTION . '_login_otp', array( 'provider' => 'bale', 'mode' => 'template', 'template' => 'otp', 'params' => 'code={code}', 'message' => '{code}' ) );
	update_option( MessageSettings::OPTION . '_login_sms', array( 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'saved-sms-template', 'params' => 'otp={code}', 'message' => '{code}', 'inherit' => false ) );
	$binding = MessageSettings::get( 'login_otp' );
	$assert( 'saved-sms-template' === $binding['template'] && 'panelchi' === $binding['provider'] && ! MessageSettings::errors( 'login_otp', $binding ), 'Existing dedicated SMS template is available without rewriting account data' );
	$binding['template'] = 'updated-sms-template';
	$assert( MessageSettings::save( 'login_otp', $binding, MessageSettings::fingerprint( MessageSettings::get( 'login_otp' ) ) ), 'Migrated SMS template can be saved with optimistic concurrency' );
	$assert( 'updated-sms-template' === MessageSettings::get( 'login_otp' )['template'], 'Edited SMS template supersedes its old fallback binding' );
	$records = array_column( ProviderUsage::all(), null, 'id' );
	$assert( in_array( 'bale', $records['login_otp']['keys'], true ) && in_array( 'panelchi', $records['login_otp']['keys'], true ), 'Usage summary includes both enabled mobile sign-in senders' );
	$assert( array( 'panelchi' ) === $records['password_reset']['keys'], 'Usage summary respects the explicit per-purpose override' );
	$assert( ! in_array( 'bale', $records['paid_customer']['keys'], true ), 'Store usage follows the SMS default, not the login messenger' );
	$legacy_input = $settings->all();
	$legacy_input['provider_bale']['api_key'] = '';
	$legacy_input['provider_bale']['bot_id'] = '';
	$preserved = $settings->sanitize( $legacy_input, ( new ProviderRegistry( $settings ) )->all() );
	$assert( $base['provider_bale'] === $preserved['provider_bale'], 'Unused v3 fields do not change legacy Bale credentials or account fingerprints' );
	$report = array( 'passed' => true, 'checks' => count( $checks ), 'results' => $checks );
} finally {
	if ( $qa_user && ! is_wp_error( $qa_user ) ) {
		$keys[] = $qa_phone;
		$keys[] = $qa_email;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $qa_user );
	}
	remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $mail_filter, PHP_INT_MAX );
	delete_transient( $cache );
	foreach ( $original as $name => $value ) {
		if ( null === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value );
		}
	}
	foreach ( $keys as $key ) {
		$wpdb->delete( $wpdb->prefix . 'anar_login_otp', array( 'identity_hash' => hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ) ) );
	}
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
