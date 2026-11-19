<?php
/**
 * Recovery-code enrollment, outage and feature-mutation checks on local WordPress.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\AccountSecurity;
use Anar\Login\Auth\RecoveryCodes;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Admin\FeaturesPage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! WP_DEBUG ) {
	throw new RuntimeException( 'Disposable local WordPress only.' );
}
$checks = 0;
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	++$checks;
};
$user_id = wp_insert_user( array( 'user_login' => 'recovery-qa-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'recovery-' . time() . '@example.test', 'role' => 'administrator' ) );
$original_user = get_current_user_id();
$original_login = get_option( Settings::OPTION_KEY );
$original_features = get_option( Features::OPTION );
$codes = new RecoveryCodes();
$original_cookie = $_COOKIE[ \Anar\Login\Auth\LoginFlowService::COOKIE ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restore the exact local test cookie.
try {
	wp_set_current_user( $user_id );
	$assert( ! $codes->ready( $user_id ), 'No implicit backup enrollment' );
	$issued = $codes->issue( $user_id );
	$assert( 10 === count( $issued ), 'Ten random backup codes' );
	$assert( ! $codes->ready( $user_id ), 'Unconfirmed codes cannot unlock policy activation' );
	$assert( false === strpos( wp_json_encode( $codes->pending( $user_id ) ), $issued[0] ), 'Only code hashes persist' );
	$assert( ! $codes->verify( $user_id, $issued[0] ), 'Staged code cannot authenticate' );
	$assert( ! $codes->verify( $user_id, 'wrong', true ), 'Wrong confirmation rejected' );
	$assert( $codes->verify( $user_id, $issued[0], true ), 'Saved-code proof activates recovery' );
	$assert( $codes->ready( $user_id ), 'Confirmed recovery is ready' );
	$assert( ! $codes->verify( $user_id, $issued[0] ), 'Confirmation code cannot replay' );
	$assert( 9 === count( $codes->record( $user_id )['hashes'] ), 'Confirmation consumes exactly one code' );
	$replacement = $codes->issue( $user_id );
	$assert( $codes->verify( $user_id, $issued[1] ), 'Old codes remain usable until rotation is confirmed' );
	$assert( ! $codes->verify( $user_id, $replacement[0], true ), 'Stale rotation cannot overwrite a changed active set' );
	$replacement = $codes->issue( $user_id );
	$assert( $codes->verify( $user_id, $replacement[0], true ), 'Current rotation succeeds' );
	$assert( ! $codes->verify( $user_id, $issued[2] ), 'Rotation invalidates previous codes' );
	$assert( ! $codes->verify( $user_id + 100000, $replacement[1] ), 'Account isolation' );
	$assert( $codes->verify( $user_id, strtoupper( $replacement[1] ) ), 'Recovery accepts normalized hexadecimal input' );
	$assert( ! $codes->verify( $user_id, $replacement[1] ), 'Code remains single-use' );
	$login = ( new Settings() )->all();
	$login['two_factor'] = 'privileged';
	update_option( Settings::OPTION_KEY, $login, false );
	$assert( true === FeaturesPage::set_feature( 'two_factor', true ), 'Prepared administrator may enable MFA' );
	$codes->remove( $user_id );
	FeaturesPage::set_feature( 'two_factor', false );
	$denied = FeaturesPage::set_feature( 'two_factor', true );
	$assert( is_wp_error( $denied ) && 'anar_recovery_required' === $denied->get_error_code(), 'MFA activation without recovery is blocked' );
	$assert( ! Features::enabled( 'two_factor' ), 'Rejected activation leaves the policy off' );
	$replacement = $codes->issue( $user_id, true );
	FeaturesPage::set_feature( 'two_factor', true );
	$_COOKIE[ \Anar\Login\Auth\LoginFlowService::COOKIE ] = bin2hex( random_bytes( 32 ) );
	$settings = new Settings();
	$users = new UserService( $settings );
	$otp = new OtpService( $settings, new OtpRepository(), new RateLimiter( $settings ), new ProviderRegistry( $settings ), $users );
	$security = new AccountSecurity( $otp, $users );
	$begin = new ReflectionMethod( $security, 'begin' );
	$begin->setAccessible( true );
	$network_failure = static function () {
		return new WP_Error( 'qa_sms_down', 'Simulated service outage.' );
	};
	add_filter( 'pre_wp_mail', $network_failure );
	$result = $begin->invoke( $security, get_userdata( $user_id ), false, 'otp' );
	remove_filter( 'pre_wp_mail', $network_failure );
	$assert( is_wp_error( $result ) && 'anar_factor_required' === $result->get_error_code(), 'Delivery failure retains the verification screen' );
	parse_str( wp_parse_url( $result->get_error_data()['redirect'], PHP_URL_QUERY ), $query );
	$flows = new \Anar\Login\Auth\LoginFlowService();
	$state = $flows->attempt( $query['flow'], 'strong_login' );
	$verify = new ReflectionMethod( $security, 'verify_factor' );
	$verify->setAccessible( true );
	$assert( ! $verify->invoke( $security, $query['flow'], $state, get_userdata( $user_id ), 'wrong', '', true ), 'An outage never bypasses proof' );
	$assert( $verify->invoke( $security, $query['flow'], $state, get_userdata( $user_id ), $replacement[0], '', true ), 'Saved backup authorizes the bound login without delivery' );
	$assert( $flows->consume( $query['flow'], 'strong_login', $state ), 'Verified flow consumed once' );
	$assert( ! $flows->consume( $query['flow'], 'strong_login', $state ), 'Login proof cannot replay' );
	$result = $begin->invoke( $security, get_userdata( $user_id ), false, 'otp' );
	parse_str( wp_parse_url( $result->get_error_data()['redirect'], PHP_URL_QUERY ), $query );
	$state = $flows->attempt( $query['flow'], 'strong_login' );
	$rotated = $codes->issue( $user_id, true );
	$assert( ! $verify->invoke( $security, $query['flow'], $state, get_userdata( $user_id ), $rotated[0], '', true ), 'Rotating codes invalidates pending recovery proofs' );
	$assert( true === FeaturesPage::set_feature( 'login_log', true ), 'Unrelated feature can be enabled' );
	$cancellations = 0;
	$count_cancellation = static function ( $query ) use ( &$cancellations ) {
		if ( false !== strpos( $query, "SET status = 'cancelled', error_code = 'module_disabled'" ) ) {
			++$cancellations;
		}
		return $query;
	};
	add_filter( 'query', $count_cancellation );
	$assert( true === FeaturesPage::set_feature( 'login_log', false ), 'Unrelated feature can be disabled' );
	remove_filter( 'query', $count_cancellation );
	$assert( $cancellations <= 1, 'A feature change runs at most one queue cancellation query' );
	WP_CLI::success( 'Recovery access: ' . $checks . ' checks passed.' );
} finally {
	$codes->remove( $user_id );
	wp_set_current_user( $original_user );
	update_option( Settings::OPTION_KEY, $original_login, false );
	update_option( Features::OPTION, $original_features, true );
	if ( null === $original_cookie ) {
		unset( $_COOKIE[ \Anar\Login\Auth\LoginFlowService::COOKIE ] );
	} else {
		$_COOKIE[ \Anar\Login\Auth\LoginFlowService::COOKIE ] = $original_cookie;
	}
	wp_delete_user( $user_id );
}
