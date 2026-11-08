<?php
/**
 * Central message integration checks. Disposable local WordPress only; SMS is mocked.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Messaging\MessageCatalog;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local WordPress only.' );
}
$original = array();
$keys = array( LoginSettings::OPTION_KEY, 'anar_toolkit_marketing', 'anar_toolkit_features', 'cron' );
foreach ( MessageCatalog::all() as $message_id => $item ) {
	$keys[] = MessageSettings::OPTION . '_' . $message_id;
	$keys[] = MessageSettings::OPTION . '_' . $message_id . '_gift';
}
foreach ( $keys as $key ) {
	$original[ $key ] = get_option( $key );
}
$checks = array();
$requests = array();
$contexts = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$requests ) {
		$requests[] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'data' => array( 'uid' => 'message-qa', 'status' => 'CREATED' ) ) ), 'response' => array( 'code' => 200 ), 'cookies' => array() );
	},
	PHP_INT_MAX,
	3
);
add_filter( 'pre_wp_mail', '__return_true' );
add_action(
	'anar_toolkit_sms_result',
	static function ( $unused_phone, $context ) use ( &$contexts ) {
		$contexts[] = $context;
	},
	10,
	2
);
try {
	foreach ( $keys as $key ) {
		if ( 0 === strpos( $key, MessageSettings::OPTION ) ) {
			delete_option( $key );
		}
	}
	$settings = new LoginSettings();
	$config = $settings->all();
	$config['sms_provider'] = 'panelchi';
	$config['provider_panelchi'] = array( 'api_key' => 'QA-ONLY', 'template_id' => 'legacy-login', 'code_parameter' => 'otp' );
	update_option( LoginSettings::OPTION_KEY, $config );
	$registry = new ProviderRegistry( $settings );
	$assert( $registry->send( '+989121234567', '123456', 'Legacy', 'password_reset' ), 'Existing authentication still sends through the legacy provider without a migration write' );
	$assert( in_array( 'legacy-login', json_decode( $requests[0]['args']['body'], true ), true ), 'The old OTP pattern is preserved until a central override is saved' );
	$binding = array( 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'central-login', 'params' => 'code={code}', 'message' => 'Code {code}', 'inherit' => false );
	$revision = MessageSettings::fingerprint( MessageSettings::get( 'login_otp' ) );
	$assert( MessageSettings::save( 'login_otp', $binding, $revision ), 'A central binding can be created once' );
	$assert( ! MessageSettings::save( 'login_otp', array_replace( $binding, array( 'template' => 'stale' ) ), $revision ), 'A stale editor cannot overwrite a newer message' );
	$binding['template'] = 'reset-only';
	$assert( MessageSettings::save( 'password_reset', $binding, MessageSettings::fingerprint( MessageSettings::get( 'password_reset' ) ) ), 'Password recovery can have an independent pattern' );
	$assert( $registry->send( '+989121234567', '654321', 'Reset', 'password_reset' ), 'Password recovery uses its independent central binding' );
	$assert( in_array( 'reset-only', json_decode( $requests[1]['args']['body'], true ), true ), 'The reset payload contains the reset pattern, not the sign-in pattern' );
	$assert( $registry->send( '+989121234567', '987654', 'Game', 'game_phone' ), 'Uncustomized verification messages inherit the central sign-in pattern' );
	$assert( in_array( 'central-login', json_decode( $requests[2]['args']['body'], true ), true ), 'The inherited game payload contains the shared pattern' );
	$assert( in_array( 'password_reset', $contexts, true ) && in_array( 'game_phone', $contexts, true ), 'Transport reports retain the exact message purpose' );
	$binding['params'] = 'stop={unsubscribe_url}';
	$binding['template'] = 'birthday-plain';
	$assert( MessageSettings::save( 'mkt_birthday', $binding, MessageSettings::fingerprint( MessageSettings::get( 'mkt_birthday', 'plain' ) ), 'plain' ), 'The birthday message without a gift is saved separately' );
	$binding['params'] .= "\ngift={coupon_code}";
	$binding['template'] = 'birthday-gift';
	$assert( MessageSettings::save( 'mkt_birthday', $binding, MessageSettings::fingerprint( MessageSettings::get( 'mkt_birthday', 'gift' ) ), 'gift' ), 'The birthday gift pattern has its own binding' );
	$marketing = ( new \Anar\Login\Marketing\Settings() )->all();
	$marketing['rules']['birthday']['discount_type'] = 'none';
	update_option( 'anar_toolkit_marketing', $marketing );
	$assert( 'birthday-plain' === MessageSettings::get( 'mkt_birthday' )['template'], 'No-gift rules resolve the no-gift message at send time' );
	$marketing['rules']['birthday']['discount_type'] = 'percent';
	update_option( 'anar_toolkit_marketing', $marketing );
	$assert( 'birthday-gift' === MessageSettings::get( 'mkt_birthday' )['template'], 'Enabling a gift resolves the gift message at send time' );
	$submitted = $settings->all();
	unset( $submitted['provider_panelchi']['template_id'], $submitted['provider_panelchi']['code_parameter'], $submitted['sms_message'] );
	$clean = $settings->sanitize( $submitted, $registry->all() );
	$assert( in_array( 'legacy-login', $clean['provider_panelchi'], true ) && in_array( 'otp', $clean['provider_panelchi'], true ), 'Saving connection settings preserves hidden legacy pattern values' );
	$config['provider_panelchi']['api_key'] = 'DIFFERENT-QA-ACCOUNT';
	update_option( LoginSettings::OPTION_KEY, $config );
	$assert( ! empty( MessageSettings::get( 'login_otp' )['account_changed'] ), 'Changing the provider account invalidates the binding review and test fingerprint' );
	$before = count( $requests );
	$denied = $registry->send( '+989121234567', '123456', 'Changed', 'login_otp' );
	$after = count( $requests );
	$assert( is_wp_error( $denied ) && $before === $after, 'An unreviewed account change is blocked before any SMS request' );
	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => count( $checks ), 'results' => $checks, 'delivery' => 'mocked' ), JSON_PRETTY_PRINT ) );
} finally {
	foreach ( $original as $key => $value ) {
		false === $value ? delete_option( $key ) : update_option( $key, $value );
	}
}
