<?php
/**
 * Authentication flow and credential regression tests on disposable WordPress.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\AccountSecurity;
use Anar\Login\Auth\CredentialService;
use Anar\Login\Auth\LoginFlowService;
use Anar\Login\Auth\Totp;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Messaging\ProviderRegistry;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! WP_DEBUG ) {
	throw new RuntimeException( 'Use a disposable local installation with WP_DEBUG enabled.' );
}
$checks = 0;
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	++$checks;
};
$_COOKIE[ LoginFlowService::COOKIE ] = bin2hex( random_bytes( 32 ) );
$user_id = wp_insert_user( array( 'user_login' => 'anar-security-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'security-' . time() . '@example.test', 'role' => 'subscriber' ) );
$credentials = new CredentialService();
$flows = new LoginFlowService();
$tokens = array();
try {
	$secret = Totp::secret();
	$assert( ! $credentials->enabled( $user_id ), 'No implicit enrollment' );
	$assert( false === $credentials->enroll( $user_id, $secret, 'bad' ), 'Enrollment needs possession proof' );
	$counter = (int) floor( time() / 30 );
	$codes = $credentials->enroll( $user_id, $secret, Totp::code( $secret, $counter ) );
	$assert( is_array( $codes ) && 10 === count( $codes ), 'Ten recovery codes issued' );
	$assert( $credentials->enabled( $user_id ), 'Credential is active' );
	$record = $credentials->record( $user_id );
	$assert( false === strpos( wp_json_encode( $record ), $secret ), 'Secret encrypted at rest' );
	$assert( false === strpos( wp_json_encode( $record ), $codes[0] ), 'Recovery codes hashed at rest' );
	$assert( false === $credentials->enroll( $user_id, Totp::secret(), '123456' ), 'Existing credential cannot be overwritten' );
	$assert( ! $credentials->verify( $user_id, Totp::code( $secret, $counter ) ), 'Enrollment code cannot replay' );
	$assert( $credentials->verify( $user_id, $codes[0] ), 'Recovery code accepted' );
	$assert( ! $credentials->verify( $user_id, $codes[0] ), 'Recovery replay rejected' );
	$assert( 9 === count( $credentials->record( $user_id )['recovery'] ), 'Only one recovery code consumed' );
	$assert( ! $credentials->verify( $user_id, 'wrong' ), 'Wrong factor rejected' );
	$assert( $credentials->verify( $user_id, $codes[1] ), 'Fresh proof authorizes recovery-code rotation' );
	$rotated = $credentials->regenerate_recovery( $user_id );
	$assert( is_array( $rotated ) && 10 === count( $rotated ), 'Ten replacement recovery codes issued' );
	$assert( ! $credentials->verify( $user_id, $codes[2] ), 'Rotation invalidates old recovery codes' );
	$assert( $credentials->verify( $user_id, $rotated[0] ), 'New recovery code works' );
	$assert( ! $credentials->verify( $user_id, $rotated[0] ), 'New recovery code remains single-use' );
	$token = $flows->issue( 'login', array( 'user_id' => $user_id ) );
	$tokens[] = $token;
	$assert( is_string( $token ) && 64 === strlen( $token ), 'Random 256-bit flow ID' );
	$assert( false === $flows->read( $token, 'reset' ), 'Purpose isolation' );
	$cookie = sanitize_key( wp_unslash( $_COOKIE[ LoginFlowService::COOKIE ] ?? '' ) );
	$_COOKIE[ LoginFlowService::COOKIE ] = str_repeat( 'a', 64 );
	$assert( false === $flows->read( $token, 'login' ), 'Browser binding' );
	$_COOKIE[ LoginFlowService::COOKIE ] = $cookie;
	$first = $flows->attempt( $token, 'login' );
	$second = $flows->attempt( $token, 'login' );
	$assert( ! $flows->consume( $token, 'login', $first ), 'Stale state cannot consume' );
	$assert( $flows->consume( $token, 'login', $second ), 'Current state consumed' );
	$assert( ! $flows->consume( $token, 'login', $second ), 'Consumed flow cannot replay' );
	$token = $flows->issue( 'limited', array() );
	$tokens[] = $token;
	for ( $i = 0; $i < 5; ++$i ) {
		$assert( is_array( $flows->attempt( $token, 'limited' ) ), 'Bounded attempt reserved' );
	}
	$assert( false === $flows->attempt( $token, 'limited' ), 'Attempt ceiling enforced' );
	$settings = new Settings();
	$users = new UserService( $settings );
	$repo = new OtpRepository();
	$otp = new OtpService( $settings, $repo, new RateLimiter( $settings ), new ProviderRegistry( $settings ), $users );
	$security = new AccountSecurity( $otp, $users );
	$user = get_userdata( $user_id );
	$denied = new WP_Error( 'test_denied', 'Denied' );
	$assert( $denied === $security->gate( $denied, $user ), 'Existing security denial preserved' );
	$gate = $security->gate( true, $user );
	$assert( is_wp_error( $gate ) && 'anar_factor_required' === $gate->get_error_code(), 'Shared gate requires enrolled factor' );
	$assert( false === $security->application_passwords( true, $user ), 'Machine password cannot bypass factor' );
	$identity = array( 'identity' => 'security-isolation@example.test', 'channel' => 'email' );
	$repo->create( $identity['identity'], 'email', '123456', 120 );
	$assert( is_wp_error( $otp->check( $identity, '123456', 'security_manage_' . $user_id ) ), 'Login code cannot authorize enrollment' );
	$repo->create( 'security_manage_' . $user_id . '|' . $identity['identity'], 'email', '654321', 120 );
	$assert( true === $otp->check( $identity, '654321', 'security_manage_' . $user_id ), 'Dedicated management code accepted' );
	$assert( is_wp_error( $otp->check( $identity, '654321' ) ), 'Management code cannot authorize login' );
	$assert( true === $otp->check( $identity, '123456' ), 'Login challenge remains independent' );
	$credentials->remove( $user_id );
	$assert( true === $security->gate( true, $user ), 'Unenrolled user keeps current login behavior' );
} finally {
	$credentials->remove( $user_id );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'anar_auth_flow_' ) . '%' ) );
}
WP_CLI::success( $checks . ' account-security checks passed.' );
