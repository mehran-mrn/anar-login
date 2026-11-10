<?php
/**
 * Shared message editor checks in disposable WordPress; all SMS transport is mocked.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\MessageCenter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageSettings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local WordPress only.' );
}
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
$options = array( Settings::OPTION_KEY, 'anar_toolkit_marketing', MessageSettings::OPTION . '_login_otp', MessageSettings::OPTION . '_password_reset', MessageSettings::OPTION . '_mkt_birthday', MessageSettings::OPTION . '_mkt_birthday_gift', MessageSettings::OPTION . '_test_password_reset', 'anar_toolkit_features', 'anar_toolkit_commerce', 'cron' );
$original = array();
foreach ( $options as $key ) {
	$original[ $key ] = get_option( $key );
}
$original_user = get_current_user_id();
$checks = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test label.
	}
	$checks[] = $label;
};
$die = static function () {
	return static function () {
		throw new RuntimeException( 'qa_json_end' );
	};
};
add_filter( 'wp_die_ajax_handler', $die );
$call = static function ( $method, $get = array(), $request_data = array() ) {
	$_GET = $get;
	$_POST = $request_data;
	$_REQUEST = array_merge( $get, $request_data );
	ob_start();
	try {
		( new MessageCenter() )->$method();
	} catch ( RuntimeException $error ) {
		if ( 'qa_json_end' !== $error->getMessage() ) {
			ob_end_clean();
			throw $error;
		}
	}
	$output = ob_get_clean();
	return json_decode( $output, true );
};
$sent = 0;
add_filter(
	'pre_http_request',
	static function () use ( &$sent ) {
		++$sent;
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'data' => array( 'uid' => 'ui-qa', 'status' => 'CREATED' ) ) ), 'response' => array( 'code' => 200 ), 'cookies' => array() );
	},
	PHP_INT_MAX
);
try {
	wp_set_current_user( 1 );
	$chosen = ( new Settings() )->sanitize( array( 'sms_provider' => 'kavenegar' ) );
	$assert( 'kavenegar' === $chosen['sms_provider'] && 1 === $chosen['sms_provider_confirmed'], 'An explicit provider choice is saved as confirmed' );
	foreach ( $options as $key ) {
		if ( 0 === strpos( $key, MessageSettings::OPTION . '_' ) ) {
			delete_option( $key );
		}
	}
	$settings = ( new Settings() )->all();
	$settings['sms_provider'] = 'panelchi';
	$settings['provider_panelchi'] = array( 'api_key' => 'QA-ONLY', 'template_id' => 'qa-otp', 'code_parameter' => 'code' );
	update_option( Settings::OPTION_KEY, $settings );
	$get = array( 'id' => 'login_otp', 'nonce' => wp_create_nonce( 'anar_message_editor' ) );
	$result = $call( 'load_editor', $get );
	$assert( ! empty( $result['success'] ) && false !== strpos( $result['data']['html'], 'data-message-editor' ), 'An administrator loads the same editor used by every module' );
	$assert( false !== strpos( MessageCenter::url( 'login_otp' ), 'page=anar-login-settings' ) && false !== strpos( MessageCenter::url( 'login_otp' ), 'edit_message=login_otp' ) && false !== strpos( MessageCenter::url( 'shared' ), 'section=sending' ), 'Canonical links point to shared settings without the reserved WordPress notice parameter' );
	$assert( false !== strpos( $result['data']['html'], __( 'Save and close', 'anar-toolkit' ) ), 'Editor includes an explicit save-and-close action' );
	$html = $result['data']['html'];
	$assert( false !== strpos( $html, 'data-default-provider="panelchi"' ) && preg_match( '/<option value="panelchi"\s+selected=/', $html ), 'The sign-in editor preselects the provider saved in SMS settings' );
	$assert( 2 === substr_count( $html, 'type="radio" name="value[mode]"' ), 'The two delivery methods share an accessible radio group' );
	foreach ( array( 'code', 'site', 'domain', 'minutes' ) as $variable ) {
		$assert( false !== strpos( $html, '<code dir="ltr">{' . $variable . '}</code>' ), 'The verification editor lists the available variable {' . $variable . '}' );
	}
	$assert( false !== strpos( $html, '<small>' . __( 'Required', 'anar-toolkit' ) . '</small>' ) && false === strpos( $html, 'QA-ONLY' ), 'The editor marks required variables and keeps provider credentials out of the markup' );
	$get['id'] = 'unknown';
	$result = $call( 'load_editor', $get );
	$assert( empty( $result['success'] ), 'Unknown purposes cannot load an editor' );
	$get['id'] = 'login_otp';
	$get['nonce'] = 'invalid';
	$assert( null === $call( 'load_editor', $get ), 'Loading requires a valid editor nonce' );
	$binding = array( 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'qa-central', 'params' => 'code={code}', 'message' => 'Code {code}' );
	$request_data = array( 'id' => 'login_otp', '_wpnonce' => wp_create_nonce( 'anar_message_login_otp' ), 'variant' => 'plain', 'operation' => 'save', 'revision' => MessageSettings::fingerprint( MessageSettings::get( 'login_otp' ) ), 'value' => $binding );
	$result = $call( 'save', array(), $request_data );
	$assert( ! empty( $result['success'] ) && isset( $result['data']['revision'], $result['data']['status'] ), 'Saving returns a fresh revision and list status without navigation' );
	$assert( get_option( Settings::OPTION_KEY ) === $settings, 'Saving a message leaves the parent connection form untouched' );
	$result = $call( 'save', array(), $request_data );
	$assert( empty( $result['success'] ) && ! empty( $result['data']['reload'] ) && 'qa-central' === MessageSettings::get( 'login_otp' )['template'], 'A stale modal cannot overwrite a newer save and offers a reload' );
	$request_data['revision'] = MessageSettings::fingerprint( MessageSettings::get( 'login_otp' ) );
	$request_data['value']['params'] = 'code=missing';
	$result = $call( 'save', array(), $request_data );
	$assert( empty( $result['success'] ) && false !== strpos( $result['data']['text'], '{code}' ), 'Validation errors return inline and do not save invalid fields' );
	$request_data = array( 'id' => 'password_reset', '_wpnonce' => wp_create_nonce( 'anar_message_password_reset' ), 'variant' => 'plain', 'operation' => 'save', 'revision' => MessageSettings::fingerprint( MessageSettings::get( 'password_reset' ) ), 'value' => array_merge( $binding, array( 'inherit' => 1 ) ) );
	$result = $call( 'save', array(), $request_data );
	$assert( ! empty( $result['success'] ) && MessageSettings::get( 'password_reset' )['inherit'], 'An inherited message can be saved from the shared dialog' );
	$request_data['revision'] = $result['data']['revision'];
	$request_data['value'] = MessageSettings::sanitize( MessageSettings::get( 'password_reset' ) );
	$request_data['operation'] = 'test';
	$request_data['test_phone'] = '+989121234567';
	$result = $call( 'save', array(), $request_data );
	$assert( ! empty( $result['success'] ) && 1 === $sent, 'An inherited message test uses the saved effective configuration once' );
	$request_data['value']['inherit'] = 0;
	$request_data['value']['template'] = 'unsaved';
	$result = $call( 'save', array(), $request_data );
	$assert( empty( $result['success'] ) && 1 === $sent, 'Unsaved test changes are rejected before contacting the SMS provider' );
	foreach ( array( 'plain', 'gift' ) as $variant ) {
		$request_data = array( 'id' => 'mkt_birthday', '_wpnonce' => wp_create_nonce( 'anar_message_mkt_birthday' ), 'variant' => $variant, 'operation' => 'save', 'revision' => MessageSettings::fingerprint( MessageSettings::get( 'mkt_birthday', $variant ) ), 'value' => array_merge( $binding, array( 'template' => 'birthday-' . $variant, 'params' => 'stop={unsubscribe_url}' . ( 'gift' === $variant ? "\ngift={coupon_code}" : '' ) ) ) );
		$result = $call( 'save', array(), $request_data );
		$assert( ! empty( $result['success'] ), 'Gift variant saved independently: ' . $variant );
	}
	$assert( 'birthday-plain' === MessageSettings::get( 'mkt_birthday', 'plain' )['template'] && 'birthday-gift' === MessageSettings::get( 'mkt_birthday', 'gift' )['template'], 'Switching variants keeps their settings separate' );
	$marketing = ( new \Anar\Login\Marketing\Settings() )->all();
	update_option( 'anar_toolkit_marketing', $marketing );
	$request_data = array( 'id' => 'shared', 'operation' => 'shared', '_wpnonce' => wp_create_nonce( 'anar_message_shared' ), 'shared' => array( 'daily_limit' => 4 ) );
	$result = $call( 'save', array(), $request_data );
	$after = get_option( 'anar_toolkit_marketing' );
	$assert( ! empty( $result['success'] ) && 4 === $after['daily_limit'] && $marketing['rules'] === $after['rules'] && $marketing['modules'] === $after['modules'], 'Shared rules save independently of module rules and activation' );
	wp_set_current_user( 0 );
	$result = $call( 'load_editor', array( 'id' => 'login_otp', 'nonce' => wp_create_nonce( 'anar_message_editor' ) ) );
	$assert( empty( $result['success'] ), 'Loading is denied without administrator capability' );
	$result = $call( 'save', array(), $request_data );
	$assert( empty( $result['success'] ), 'Saving is denied without administrator capability' );
	if ( class_exists( 'WooCommerce' ) ) {
		wp_set_current_user( 1 );
		$core = (array) get_option( 'anar_toolkit_features', array() );
		$core = $core['switches'] ?? $core;
		$core['orders'] = 1;
		update_option( 'anar_toolkit_features', $core );
		$commerce_settings = new \Anar\Login\Commerce\Settings();
		$commerce = $commerce_settings->all();
		$commerce['recovery'] = 1;
		$commerce['paid_customer'] = 1;
		foreach ( array( 31, 83, 167 ) as $index => $minutes ) {
			$commerce['events'][ 'recovery_' . ( $index + 1 ) ]['minutes'] = $minutes;
		}
		update_option( 'anar_toolkit_commerce', $commerce );
		$_GET = array( 'page' => 'anar-toolkit-commerce', 'tab' => 'templates' );
		$workspace_tabs = \Anar\Login\Infrastructure\Features::workspace_tabs();
		$assert( ! isset( $workspace_tabs['templates'] ) && 'settings' === \Anar\Login\Infrastructure\Features::current_tab(), 'The template tab is removed and old bookmarks resolve to automation settings' );
		ob_start();
		( new \Anar\Login\Commerce\Admin( $commerce_settings, new \Anar\Login\Commerce\Repository(), new \Anar\Login\Messaging\ProviderRegistry( new Settings() ) ) )->render();
		$markup = ob_get_clean();
		$assert( false === strpos( $markup, 'id="templates"' ) && false !== strpos( $markup, 'data-order-event="paid_customer"' ) && false !== strpos( $markup, 'data-message-summary="paid_customer"' ), 'Order switches and their existing message editor render on one settings page' );
		foreach ( array( 31, 83, 167 ) as $index => $minutes ) {
			$name = 'name="anar_toolkit_commerce[events][recovery_' . ( $index + 1 ) . '][minutes]"';
			$assert( 1 === substr_count( $markup, $name ) && false !== strpos( $markup, 'value="' . $minutes . '"' ), 'Recovery timing is retained once next to its message: stage ' . ( $index + 1 ) );
		}
		$marketing['modules']['games'] = 1;
		$marketing['modules']['stock'] = 1;
		update_option( 'anar_toolkit_marketing', $marketing );
		foreach ( array( 'games' => 'game_phone', 'stock' => 'stock_verification' ) as $module => $purpose ) {
			$_GET = array( 'page' => 'anar-toolkit-' . $module );
			ob_start();
			( new \Anar\Login\Marketing\Admin() )->render();
			$markup = ob_get_clean();
			$assert( false !== strpos( $markup, 'data-message-summary="' . $purpose . '"' ), 'Verification message opens from its own module: ' . $module );
		}
	}
	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => count( $checks ), 'delivery' => 'mocked', 'results' => $checks ), JSON_PRETTY_PRINT ) );
} finally {
	foreach ( $original as $key => $value ) {
		false === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	wp_set_current_user( $original_user );
	remove_filter( 'wp_die_ajax_handler', $die );
	$_GET = array();
	$_POST = array();
	$_REQUEST = array();
}
