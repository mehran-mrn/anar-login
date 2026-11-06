<?php
/**
 * Security regression tests on a disposable clean local WordPress installation.
 * Run with wp eval-file. Mail and HTTP must be intercepted; never run on a live site.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! WP_DEBUG ) {
	throw new RuntimeException( 'Use a disposable local installation with WP_DEBUG enabled.' );
}

require_once ABSPATH . 'wp-admin/includes/user.php';
$checks = array();
$created = array();
$original = get_option( Settings::OPTION_KEY );
$original_default = get_option( 'default_role' );
$settings = new Settings();
$users = new UserService( $settings );
$repository = new OtpRepository();
$limiter = new RateLimiter( $settings );
$providers = new ProviderRegistry( $settings );
$otp = new OtpService( $settings, $repository, $limiter, $providers, $users );
$cookies = 0;
$announcements = 0;
$failed = 0;
$mail = '';
$sequence = 0;
$test_filters = array();
$roles_before = get_option( 'wp_user_roles' );

$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	$checks[] = $label;
};
$configure = static function ( $role = 'subscriber', $register = 1, $wc = 0 ) use ( $settings ) {
	update_option( Settings::OPTION_KEY, array_merge( $settings->defaults(), array( 'default_role' => $role, 'allow_registration' => $register, 'wc_customer_role' => $wc ) ) );
};
$capture = static function ( $id ) use ( &$created ) {
	$created[] = (int) $id;
};
add_action( 'user_register', $capture );
add_filter( 'send_auth_cookies', '__return_false' );
$cookie_capture = static function () use ( &$cookies ) {
	++$cookies;
};
add_action( 'set_auth_cookie', $cookie_capture );
$login_capture = static function () use ( &$announcements ) {
	++$announcements;
};
add_action( 'wp_login', $login_capture );
$failure_capture = static function () use ( &$failed ) {
	++$failed;
};
add_action( 'wp_login_failed', $failure_capture );
$mail_capture = static function ( $pre, $attributes ) use ( &$mail ) {
	$mail = $attributes['message'];
	return true;
};
add_filter( 'pre_wp_mail', $mail_capture, PHP_INT_MAX, 2 );
$add_filter = static function ( $hook, $callback ) use ( &$test_filters ) {
	add_filter( $hook, $callback, 99999, 3 );
	$test_filters[] = array( $hook, $callback );
};
$clear_filters = static function () use ( &$test_filters ) {
	foreach ( $test_filters as $filter ) {
		remove_filter( $filter[0], $filter[1], 99999 );
	}
	$test_filters = array();
};
$signup = static function ( $channel ) use ( &$sequence, $users ) {
	wp_set_current_user( 0 );
	++$sequence;
	$email = 'anar-security-' . $sequence . '-' . wp_generate_password( 8, false ) . '@example.test';
	if ( 'google' === $channel ) {
		return $users->login_google( array( 'sub' => wp_generate_uuid4(), 'email' => $email, 'email_verified' => true ), false );
	}
	$identity = 'sms' === $channel ? '+98913' . str_pad( (string) $sequence, 7, '0', STR_PAD_LEFT ) : $email;
	return $users->login_or_create( array( 'identity' => $identity, 'channel' => $channel ), false );
};
$assert_role = static function ( $user, $role, $label ) use ( $assert ) {
	$assert( $user instanceof WP_User && array( $role ) === array_values( $user->roles ) && ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'manage_options' ) && ! user_can( $user, 'upload_files' ), $label );
};

try {
	$assert( WP_DEBUG && 'local' === wp_get_environment_type(), 'Clean local environment has WP_DEBUG enabled' );
	add_role( 'anar_test_custom', 'Unsafe custom', array( 'read' => true, 'custom_manage_money' => true ) );
	add_role( 'anar_test_reader', 'Custom reader', array( 'read' => true ) );
	foreach ( array( 'administrator', 'editor', 'author', 'contributor', 'shop_manager', 'anar_test_custom', 'anar_test_reader', 'missing_role' ) as $unsafe ) {
		$assert( ! Settings::is_assignable_role( $unsafe ), 'Reject role: ' . $unsafe );
		$configure( $unsafe );
		foreach ( array( 'email', 'sms', 'google' ) as $channel ) {
			$assert_role( $signup( $channel ), 'subscriber', 'Unsafe stored ' . $unsafe . ' falls back safely for ' . $channel );
		}
	}
	$configure();
	update_option( 'default_role', 'administrator' );
	$assert_role( $signup( 'email' ), 'subscriber', 'WordPress default administrator role cannot affect public registration' );
	foreach ( array( 'author', 'administrator', 'anar_test_custom', 'anar_test_reader', 'missing_role', null, array( 'subscriber' ) ) as $filtered ) {
		$add_filter(
			'anar_login_new_user_role',
			static function () use ( $filtered ) {
				return $filtered;
			}
		);
		foreach ( array( 'email', 'sms', 'google' ) as $channel ) {
			$assert_role( $signup( $channel ), 'subscriber', 'Unsafe filtered role falls back safely for ' . $channel );
		}
		$clear_filters();
	}
	foreach ( array( 'subscriber', 'customer' ) as $test_role ) {
		$assert( Settings::is_assignable_role( $test_role ), 'Normal role allowed: ' . $test_role );
		$configure( $test_role );
		foreach ( array( 'email', 'sms', 'google' ) as $channel ) {
			$assert_role( $signup( $channel ), $test_role, 'Safe selected role used for ' . $channel . ': ' . $test_role );
		}
	}
	$configure( 'subscriber', 1, 1 );
	$assert_role( $signup( 'google' ), 'customer', 'WooCommerce preference retains safe customer role' );
	get_role( 'customer' )->add_cap( 'edit_products' );
	$assert( ! Settings::is_assignable_role( 'customer' ), 'Elevated customer role is refused' );
	$assert_role( $signup( 'sms' ), 'subscriber', 'Unsafe WooCommerce override falls back to subscriber after filters' );
	get_role( 'subscriber' )->add_cap( 'publish_posts' );
	$assert( ! Settings::is_assignable_role( 'subscriber' ), 'Elevated subscriber role is refused' );
	foreach ( array( 'email', 'sms', 'google' ) as $channel ) {
		$before = count( $created );
		$before_cookies = $cookies;
		$result = $signup( $channel );
		$assert( is_wp_error( $result ) && 'anar_registration_role' === $result->get_error_code() && count( $created ) === $before && $cookies === $before_cookies, 'Both safe roles elevated: no account or cookie for ' . $channel );
	}
	get_role( 'subscriber' )->remove_cap( 'publish_posts' );
	get_role( 'customer' )->remove_cap( 'edit_products' );
	$configure();
	remove_role( 'subscriber' );
	$before = count( $created );
	$assert( is_wp_error( $signup( 'google' ) ) && count( $created ) === $before, 'Missing subscriber fails closed instead of using core default role' );
	add_role( 'subscriber', 'Subscriber', $roles_before['subscriber']['capabilities'] );

	$configure( 'subscriber', 0 );
	foreach ( array( 'email', 'sms', 'google' ) as $channel ) {
		$before = count( $created );
		$assert( is_wp_error( $signup( $channel ) ) && count( $created ) === $before, 'Disabled public registration creates no user for ' . $channel );
	}
	$configure();
	foreach ( array( false, 'false', 'true', null ) as $verified ) {
		$before = count( $created );
		$result = $users->login_google( array( 'sub' => wp_generate_uuid4(), 'email' => 'unverified@example.test', 'email_verified' => $verified ) );
		$assert( is_wp_error( $result ) && count( $created ) === $before, 'Unverified Google email cannot create an account' );
	}

	$existing = $signup( 'email' );
	$existing->set_role( 'administrator' );
	$identity = array( 'identity' => $existing->user_email, 'channel' => 'email' );
	$configure( 'author', 0 );
	$assert( $existing->ID === $users->login_or_create( $identity, false )->ID && in_array( 'administrator', get_userdata( $existing->ID )->roles, true ), 'Verified existing account retains its role with registration disabled' );
	$other = get_user_by( 'id', 1 );
	foreach ( array( 'wp_authenticate_user', 'authenticate' ) as $hook ) {
		foreach ( array( new WP_Error( 'security_test_denial', 'Denied by security test.' ), null, false, $other ) as $denial ) {
			$add_filter(
				$hook,
				static function () use ( $denial ) {
					return $denial;
				}
			);
			$before_cookies = $cookies;
			$before_logins = $announcements;
			$before_failures = $failed;
			wp_set_current_user( 0 );
			$result = $users->login_or_create( $identity, false );
			$assert( is_wp_error( $result ) && $cookies === $before_cookies && $announcements === $before_logins && 0 === get_current_user_id() && $failed === $before_failures + 1, $hook . ' denial or identity substitution prevents cookies and announces failed login' );
			$clear_filters();
		}
	}
	$add_filter( 'anar_login_can_authenticate', '__return_false' );
	$before_cookies = $cookies;
	$assert( is_wp_error( $users->login_or_create( $identity, false ) ) && $cookies === $before_cookies, 'Plugin-specific account blocker remains effective' );
	$clear_filters();
	$hook_calls = array();
	foreach ( array( 'wp_authenticate_user', 'authenticate' ) as $hook ) {
		$add_filter(
			$hook,
			static function ( $user ) use ( &$hook_calls, $hook ) {
				$hook_calls[] = $hook;
				return $user;
			}
		);
	}
	$assert( $users->login_or_create( $identity, false ) instanceof WP_User && array( 'wp_authenticate_user', 'authenticate' ) === $hook_calls, 'Both core authentication filters run for a verified existing account' );
	$clear_filters();

	$configure();
	$email = 'anar-code-' . wp_generate_password( 10, false ) . '@example.test';
	$before = count( $created );
	$before_cookies = $cookies;
	$assert( is_wp_error( $otp->verify( $email, '12345', false ) ) && count( $created ) === $before && $cookies === $before_cookies, 'No OTP challenge: no account or cookie' );
	$assert( ! is_wp_error( $otp->request( $email ) ) && preg_match( '/\b(\d{5})\b/', $mail, $match ), 'Email OTP issued through intercepted mail' );
	$code = $match[1];
	$wrong = '99999' === $code ? '88888' : '99999';
	$assert( is_wp_error( $otp->verify( $email, $wrong, false ) ) && count( $created ) === $before, 'Wrong OTP cannot register' );
	$assert( ! is_wp_error( $otp->verify( $email, $code, false ) ) && 1 === count( $created ) - $before, 'Verified email OTP registers exactly one safe user' );
	$before_cookies = $cookies;
	$assert( is_wp_error( $otp->verify( $email, $code, false ) ) && $cookies === $before_cookies, 'Consumed OTP cannot be replayed' );
	$expired_email = 'expired-' . wp_generate_password( 10, false ) . '@example.test';
	$repository->create( $expired_email, 'email', '12345', 120 );
	$expired_challenge = $repository->latest( $expired_email, 'email' );
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'anar_login_otp', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $expired_challenge->id ) );
	$before = count( $created );
	$assert( is_wp_error( $otp->verify( $expired_email, '12345', false ) ) && count( $created ) === $before, 'Expired OTP cannot register' );
	$limited_email = 'limited-' . wp_generate_password( 10, false ) . '@example.test';
	$repository->create( $limited_email, 'email', '12345', 120 );
	for ( $attempt = 0; $attempt < 5; ++$attempt ) {
		$otp->verify( $limited_email, '99999', false );
	}
	$assert( is_wp_error( $otp->verify( $limited_email, '12345', false ) ) && count( $created ) === $before, 'Exhausted OTP cannot register even with the correct code' );

	$configure( 'author' );
	$config = $settings->all();
	$config['google_enabled'] = 1;
	$config['google_client_id'] = 'LOCAL-TEST';
	$config['google_secret'] = 'LOCAL-TEST';
	update_option( Settings::OPTION_KEY, $config );
	$oauth = new GoogleAuth( $settings, $users, $limiter );
	$oauth_email = 'oauth-' . wp_generate_password( 10, false ) . '@example.test';
	$http = static function ( $pre, $args, $url ) use ( $oauth_email ) {
		$body = false !== strpos( $url, '/token' ) ? array( 'access_token' => 'MOCK' ) : array( 'sub' => 'qa-' . $oauth_email, 'email' => $oauth_email, 'email_verified' => true );
		return array( 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200 ), 'headers' => array(), 'cookies' => array() );
	};
	add_filter( 'pre_http_request', $http, PHP_INT_MAX, 3 );
	$before = count( $created );
	$oauth->callback( 'bad-state', 'mock-code' );
	$assert( count( $created ) === $before, 'Invalid Google state cannot register' );
	$url = $oauth->start();
	parse_str( wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$_COOKIE[ GoogleAuth::COOKIE ] = $query['state'];
	$oauth->callback( $query['state'], 'mock-code' );
	$assert_role( get_user_by( 'email', $oauth_email ), 'subscriber', 'Full mocked Google OAuth callback ignores unsafe stored Author role' );
	$oauth->callback( $query['state'], 'mock-code' );
	$assert( 1 === count( $created ) - $before, 'Consumed Google state cannot create another account' );
	remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
	unset( $_COOKIE[ GoogleAuth::COOKIE ] );

	$report = array( 'checks' => count( $checks ), 'passed' => true, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null, 'php' => PHP_VERSION, 'wp_debug' => WP_DEBUG, 'mail_and_http' => 'mocked', 'results' => $checks );
} finally {
	$clear_filters();
	wp_set_current_user( 0 );
	remove_action( 'user_register', $capture );
	foreach ( $created as $test_id ) {
		wp_delete_user( $test_id );
	}
	update_option( Settings::OPTION_KEY, $original );
	update_option( 'default_role', $original_default );
	update_option( 'wp_user_roles', $roles_before );
	wp_roles()->for_site();
	remove_filter( 'pre_wp_mail', $mail_capture, PHP_INT_MAX );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_action( 'set_auth_cookie', $cookie_capture );
	remove_action( 'wp_login', $login_capture );
	remove_action( 'wp_login_failed', $failure_capture );
}

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
