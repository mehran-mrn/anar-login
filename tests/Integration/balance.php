<?php
/**
 * Balance scheduling and cached warnings on disposable local WordPress only.
 * Every HTTP call is mocked, no provider account is modified.
 *
 * @package AnarLogin
 */

use Anar\Login\Balance\Accounts;
use Anar\Login\Balance\Monitor;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Admin\BalancePage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}

$balance_options = array_merge(
	array( Settings::OPTION_KEY, Features::OPTION, Monitor::OPTION ),
	array_map(
		static function ( $key ) {
			return Monitor::STATE . $key;
		},
		\Anar\Login\Balance\Gateways::providers()
	)
);
$balance_original = array();
foreach ( $balance_options as $name ) {
	$balance_original[ $name ] = get_option( $name );
}
$balance_user = get_current_user_id();
$balance_requests = 0;
$balance_mode = 'success';
$balance_checks = 0;
$balance_verify = static function ( $condition, $message ) use ( &$balance_checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	++$balance_checks;
};
$balance_transport = static function ( $pre, $args, $url ) use ( &$balance_requests, &$balance_mode ) {
	++$balance_requests;
	if ( 'https://api.panelchi.com/account/info' !== $url ) {
		return new WP_Error( 'qa_http_blocked', 'External requests blocked.' );
	}
	if ( 'failure' === $balance_mode ) {
		return new WP_Error( 'qa_timeout', 'A simulated failure including a private echoed credential.' );
	}
	$data = array( 'data' => array( 'wallet' => array( 'availableBalance' => '49.20', 'totalBalance' => '51.20', 'lockedBalance' => '2.00', 'unit' => 'SMS_UNIT' ) ) );
	return array( 'response' => array( 'code' => 200, 'message' => 'Mocked' ), 'headers' => array(), 'body' => wp_json_encode( $data ), 'cookies' => array() );
};
add_filter( 'pre_http_request', $balance_transport, PHP_INT_MAX, 3 );
try {
	$login = ( new Settings() )->all();
	$login['provider_panelchi'] = array( 'api_key' => 'QA-ONLY-KEY', 'template_id' => 'qa' );
	$login['sms_provider'] = 'panelchi';
	update_option( Settings::OPTION_KEY, $login, false );
	update_option( Monitor::OPTION, array( 'enabled' => 0 ), false );
	$service = new Monitor();
	$balance_verify( is_wp_error( $service->refresh( 'panelchi' ) ) && 0 === $balance_requests, 'Disabled feature must make no HTTP requests.' );
	$balance_verify( ! wp_next_scheduled( Monitor::HOOK, array( 'panelchi' ) ), 'Disabled feature has no balance schedule.' );
	$config = array( 'enabled' => 1, 'accounts' => array( 'panelchi' => array( 'monitor' => 1, 'warn' => 0, 'threshold' => '', 'unit' => '' ) ) );
	update_option( Monitor::OPTION, $config, false );
	$balance_verify( Features::enabled( 'balance' ), 'The lightweight feature snapshot includes balance activation.' );
	$balance_verify( (bool) wp_next_scheduled( Monitor::HOOK, array( 'panelchi' ) ), 'The configured account is scheduled only after activation.' );
	$balance_verify( 0 === $balance_requests, 'Activating and scheduling never performs a synchronous provider request.' );
	$result = $service->refresh( 'panelchi' );
	$balance_verify( ! is_wp_error( $result ) && 49.2 === $result['value'], 'Available wallet credit is returned instead of locked or total credit.' );
	$state = Monitor::state( 'panelchi' );
	$balance_verify( false === strpos( wp_json_encode( $state ), 'QA-ONLY' ), 'The cached record contains no raw authentication data.' );
	$balance_verify( ! Monitor::low( 'panelchi' ), 'Warnings are off independently of periodic checks.' );
	$input = array( 'enabled' => 1, 'accounts' => array( 'panelchi' => array( 'monitor' => 1, 'warn' => 1, 'threshold' => '۵۰', 'unit' => 'SMS_UNIT' ) ) );
	$config = Monitor::sanitize( $input );
	$balance_verify( ! is_wp_error( $config ), 'An administrator can choose a Persian numeric threshold after the first check.' );
	update_option( Monitor::OPTION, $config, false );
	$balance_verify( Monitor::low( 'panelchi' ), 'A fresh balance below the selected threshold produces a warning.' );
	$before = $balance_requests;
	$balance_verify( is_wp_error( $service->refresh( 'panelchi' ) ) && $before === $balance_requests, 'Manual refreshes are throttled.' );
	$state['checked_at'] -= 61;
	update_option( Monitor::STATE . 'panelchi', $state, false );
	wp_clear_scheduled_hook( Monitor::HOOK, array( 'panelchi' ) );
	do_action( Monitor::HOOK, 'panelchi' );
	$next_check = wp_next_scheduled( Monitor::HOOK, array( 'panelchi' ) );
	$balance_verify( $before + 1 === $balance_requests && $next_check >= time() + Monitor::INTERVAL - 2, 'A scheduled check refreshes once and schedules the next check about 30 minutes later.' );
	$state = Monitor::state( 'panelchi' );
	$before = $balance_requests;
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $admins[0] );
	ob_start();
	( new BalancePage() )->notices();
	$notice = ob_get_clean();
	$balance_verify( false !== strpos( $notice, 'notice-warning' ), 'An administrator sees the low-credit warning.' );
	wp_set_current_user( 0 );
	ob_start();
	( new BalancePage() )->notices();
	$balance_verify( '' === ob_get_clean(), 'Anonymous visitors cannot see account balances or warnings.' );
	$balance_verify( $before === $balance_requests, 'Rendering notices never contacts the provider.' );
	$balance_mode = 'failure';
	$state['checked_at'] -= 61;
	update_option( Monitor::STATE . 'panelchi', $state, false );
	$result = $service->refresh( 'panelchi' );
	$failed = Monitor::state( 'panelchi' );
	$balance_verify( is_wp_error( $result ) && 49.2 === $failed['value'] && $failed['success_at'] === $state['success_at'], 'A provider failure preserves the last successful amount and timestamp.' );
	$balance_verify( ! Monitor::low( 'panelchi' ) && 'anar_balance_connection' === $failed['error'], 'A failed check never produces a false zero-credit warning.' );
	$balance_verify( false === strpos( wp_json_encode( $failed ), 'echoed credential' ), 'Raw HTTP errors are never persisted.' );
	$login['provider_panelchi']['api_key'] = 'QA-CHANGED-ACCOUNT';
	update_option( Settings::OPTION_KEY, $login, false );
	$balance_verify( array() === Monitor::state( 'panelchi' ), 'A credential change hides the previous account balance immediately.' );
	$config['enabled'] = 0;
	update_option( Monitor::OPTION, $config, false );
	$balance_verify( ! Features::enabled( 'balance' ) && ! wp_next_scheduled( Monitor::HOOK, array( 'panelchi' ) ), 'Disabling the feature removes scheduled checks and warnings.' );
	$before = $balance_requests;
	$service->run( 'panelchi' );
	$balance_verify( $before === $balance_requests, 'A previously queued callback is harmless after deactivation.' );
	WP_CLI::success( $balance_checks . ' balance integration checks passed; all HTTP mocked.' );
} finally {
	remove_filter( 'pre_http_request', $balance_transport, PHP_INT_MAX );
	wp_set_current_user( $balance_user );
	foreach ( $balance_original as $name => $value ) {
		false === $value ? delete_option( $name ) : update_option( $name, $value, false );
	}
	Features::refresh();
	Monitor::sync();
}
