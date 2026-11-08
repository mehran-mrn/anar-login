<?php
/**
 * Existing-account phone verification and game configuration regressions.
 * Run with wp eval-file in a disposable local WooCommerce installation only.
 *
 * @package AnarLogin
 */

use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Marketing\Games;
use Anar\Login\Marketing\PhoneVerification;
use Anar\Login\Marketing\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! class_exists( 'WooCommerce' ) ) {
	throw new RuntimeException( 'Disposable local WooCommerce only.' );
}
global $wpdb;
$original = array();
foreach ( array( Settings::OPTION, LoginSettings::OPTION_KEY, 'woocommerce_enable_coupons' ) as $key ) {
	$original[ $key ] = get_option( $key );
}
$checks = array();
$users = array();
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
$provider = new class() implements \Anar\Login\Messaging\SmsProviderInterface {
	public $code = '';
	public $fail = false;
	public $message = '';
	public function key() {
		return 'game_qa'; }
	public function title() {
		return 'Local game verification'; }
	public function fields() {
		return array(); }
	public function send( $recipient, $code, $unused_message ) {
		$this->code = $code;
		$this->message = $unused_message;
		return $this->fail ? new WP_Error( 'qa_delivery', 'Simulated failure' ) : true;
	}
};
$filter = static function ( $providers ) use ( $provider ) {
	$providers['game_qa'] = $provider;
	return $providers;
};
add_filter( 'anar_login_sms_providers', $filter );
add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'qa_no_http', 'No external requests' );
	},
	PHP_INT_MAX
);
add_filter( 'pre_wp_mail', '__return_true' );
$clear = static function () use ( $wpdb ) {
	$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'anar_login_limits' );
};
$service = new PhoneVerification();
$phone = '+989129876543';
try {
	\Anar\Login\Infrastructure\Activator::activate();
	$settings = new Settings();
	$config = $settings->defaults();
	$config['modules']['games'] = 1;
	$config['game_texts']['wheel'] = array( 'title' => 'Autumn wheel', 'reward_label' => 'Autumn gift', 'win_message' => 'Your autumn gift is ready.' );
	update_option( Settings::OPTION, $config );
	$login = ( new LoginSettings() )->defaults();
	$login['sms_provider'] = 'game_qa';
	$login['allow_registration'] = 0;
	update_option( LoginSettings::OPTION_KEY, $login );
	foreach ( array( 'old', 'other', 'conflict' ) as $name ) {
		$users[] = wp_create_user( 'game-qa-' . $name . '-' . wp_generate_password( 8, false ), wp_generate_password( 32 ), $name . '-' . wp_generate_password( 8, false ) . '@example.test' );
	}
	$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	wp_set_current_user( $users[0] );
	update_user_meta( $users[0], 'billing_phone', $phone );
	$assert( ! PhoneVerification::phone( $users[0] ), 'Billing phone alone never verifies an old account' );
	$form = ( new Games() )->form();
	$assert( false !== strpos( $form, 'data-game-phone' ) && false === strpos( $form, 'Sign in to play' ), 'Signed-in old accounts receive an inline verification form' );
	$assert( is_wp_error( $service->process( 'buyer@example.test', '', true ) ), 'Email is rejected for mobile verification' );
	$clear();
	$sent = $service->process( $phone, '', true );
	$code = $provider->code;
	$assert( ! is_wp_error( $sent ) && 6 === strlen( $code ), 'Old accounts can request SMS even when new registration is disabled' );
	$assert( is_wp_error( $service->process( $phone, '', true ) ), 'Resend cooldown is enforced' );
	$assert( is_wp_error( $service->process( $phone, 'wrong' ) ) && ! PhoneVerification::phone( $users[0] ), 'An incorrect code cannot link a phone' );
	wp_set_current_user( $users[1] );
	$assert( is_wp_error( $service->process( $phone, $code ) ), 'A different account cannot reuse the original account challenge' );
	wp_set_current_user( $users[0] );
	$original_cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Preserve the exact cookie bytes in this disposable fixture.
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $users[0], time() + HOUR_IN_SECONDS, 'logged_in', 'different-qa-session' );
	$assert( is_wp_error( $service->process( $phone, $code ) ), 'Another browser session cannot reuse the account challenge' );
	$_COOKIE[ LOGGED_IN_COOKIE ] = $original_cookie;
	$codes = new OtpRepository();
	$codes->create( $phone, 'sms', '112233', 300 );
	$assert( is_wp_error( $service->process( $phone, '112233' ) ), 'Login codes do not authorize account phone linking' );
	$result = $service->process( $phone, $code );
	$linked_phone = PhoneVerification::phone( $users[0] );
	$current_id = get_current_user_id();
	$expected_id = $users[0];
	$assert( ! is_wp_error( $result ) && ! empty( $result['verified'] ) && $phone === $linked_phone, 'Correct OTP links the normalized phone to the existing account' );
	$assert( $expected_id === $current_id && $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ), 'Verification preserves the current account and creates no new user' );
	$assert( is_wp_error( $service->process( $phone, $code ) ), 'A completed verification cannot be replayed or replace a phone' );
	$wheel = ( new Games() )->form( array( 'type' => 'wheel' ) );
	$quiz = ( new Games() )->form( array( 'type' => 'quiz' ) );
	$assert( false !== strpos( $wheel, 'Autumn wheel' ) && false !== strpos( $wheel, 'Autumn gift' ), 'Custom title and prize label appear in the actual wheel markup' );
	$assert( false !== strpos( $wheel, 'data-type="wheel"' ) && false !== strpos( $quiz, 'data-type="quiz"' ), 'Several game types can render at the same time' );
	$assert( array( 'A', 'B' ) === Games::quiz_options( array( 'game_quiz_options' => "A\n\nB\n" ) ), 'Quiz answer numbering ignores blank lines' );
	wp_set_current_user( $users[1] );
	$clear();
	$service->process( $phone, '', true );
	$assert( is_wp_error( $service->process( $phone, $provider->code ) ) && ! PhoneVerification::phone( $users[1] ), 'A phone linked to another account cannot be claimed' );
	update_user_meta( $users[2], 'digits_phone', '09121112222' );
	$clear();
	$service->process( '+989121112222', '', true );
	$assert( is_wp_error( $service->process( '+989121112222', $provider->code ) ), 'Legacy phone ownership is respected in local phone format' );
	$clear();
	$provider->fail = true;
	$assert( is_wp_error( $service->process( '+989123339999', '', true ) ), 'Delivery failure is reported instead of claiming the code was sent' );
	$provider->fail = false;
	$assert( ! is_wp_error( $service->process( '+989123339999', '', true ) ), 'Failed delivery releases cooldown for a retry' );
	$expired_code = $provider->code;
	$wpdb->query( "UPDATE {$wpdb->prefix}anar_login_otp SET expires_at = '2000-01-01 00:00:00' WHERE channel = 'game_phone'" );
	$assert( is_wp_error( $service->process( '+989123339999', $expired_code ) ), 'Expired account verification codes are rejected' );
	wp_set_current_user( 0 );
	$assert( is_wp_error( $service->process( $phone, '', true ) ), 'Signed-out visitors cannot use the account-linking service' );
	wp_set_current_user( $users[1] );
	$config['modules']['games'] = 0;
	update_option( Settings::OPTION, $config );
	$assert( is_wp_error( $service->process( $phone, '', true ) ), 'Disabling games also stops their phone verification service' );
	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => count( $checks ), 'results' => $checks, 'sms' => 'mocked' ), JSON_PRETTY_PRINT ) );
} finally {
	wp_set_current_user( 0 );
	remove_filter( 'anar_login_sms_providers', $filter );
	foreach ( $original as $key => $value ) {
		false === $value ? delete_option( $key ) : update_option( $key, $value );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $user_id ) {
		wp_delete_user( $user_id );
	}
}
