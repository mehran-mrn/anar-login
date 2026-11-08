<?php
/**
 * Real cryptographic WebAuthn fixtures on a disposable local WordPress site.
 *
 * @package AnarLogin
 */

use Anar\Login\Api\PasskeyController;
use Anar\Login\Auth\AccountSecurity;
use Anar\Login\Auth\LoginFlowService;
use Anar\Login\Auth\PasskeyRepository;
use Anar\Login\Auth\PasskeyServer;
use Anar\Login\Auth\CredentialService;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Messaging\ProviderRegistry;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! WP_DEBUG || PHP_VERSION_ID < 80000 ) {
	throw new RuntimeException( 'Use a disposable local PHP 8+ installation with WP_DEBUG.' );
}

/** Minimal deterministic CBOR encoder for test fixtures, never used by the plugin. */
function anar_passkey_fixture_cbor( $value, $bytes = false ) {
	$length = static function ( $major, $size ) {
		return $size < 24 ? chr( ( $major << 5 ) | $size ) : ( $size < 256 ? chr( ( $major << 5 ) | 24 ) . chr( $size ) : chr( ( $major << 5 ) | 25 ) . pack( 'n', $size ) );
	};
	if ( is_int( $value ) ) {
		return $value >= 0 ? $length( 0, $value ) : $length( 1, -1 - $value );
	}
	if ( is_string( $value ) ) {
		return $length( $bytes ? 2 : 3, strlen( $value ) ) . $value;
	}
	$result = $length( 5, count( $value ) );
	foreach ( $value as $key => $item ) {
		$result .= anar_passkey_fixture_cbor( $key ) . ( is_array( $item ) && isset( $item['bytes'] ) ? anar_passkey_fixture_cbor( $item['bytes'], true ) : anar_passkey_fixture_cbor( $item ) );
	}
	return $result;
}

$local_origin = static function ( $url ) {
	return str_replace( 'http://127.0.0.1', 'http://localhost', $url );
};
add_filter( 'home_url', $local_origin );
$original = get_option( Settings::OPTION_KEY );
$created = 0;
$checks = 0;
$cookies = 0;
$assert = static function ( $value, $label ) use ( &$checks ) {
	if ( ! $value ) {
		throw new RuntimeException( esc_html( $label ) );
	} ++$checks;
};
$block_cookie = static function () {
	return false;
};
$count_cookie = static function () use ( &$cookies ) {
	++$cookies;
};
add_filter( 'send_auth_cookies', $block_cookie );
add_action( 'set_logged_in_cookie', $count_cookie );
$fixture_ip = '2001:db8:' . bin2hex( random_bytes( 2 ) ) . '::1';
$ip_filter = static function () use ( $fixture_ip ) {
	return $fixture_ip;
};
add_filter( 'anar_login_client_ip', $ip_filter );
$network = static function () {
	throw new RuntimeException( 'Passkeys must not contact an external service.' );
};
add_filter( 'pre_http_request', $network );
$_COOKIE[ LoginFlowService::COOKIE ] = bin2hex( random_bytes( 32 ) );
try {
	update_option( Settings::OPTION_KEY, array_merge( ( new Settings() )->defaults(), (array) $original, array( 'passkeys_enabled' => 1 ) ) );
	$created = wp_insert_user( array( 'user_login' => 'anar-passkey-' . wp_generate_password( 8, false ), 'user_email' => 'passkey-' . time() . '@example.test', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	wp_set_current_user( $created );
	$settings = new Settings();
	$users = new UserService( $settings );
	$otp = new OtpService( $settings, new OtpRepository(), new RateLimiter( $settings ), new ProviderRegistry( $settings ), $users );
	$controller = new PasskeyController( new AccountSecurity( $otp, $users ) );
	$flows = new LoginFlowService();
	$keys = new PasskeyRepository();
	$request = static function ( $operation, array $params, $authenticated = false ) use ( $controller ) {
		$req = new WP_REST_Request( 'POST', '/anar-login/v1/passkey/' . $operation );
		foreach ( $params as $key => $value ) {
			$req->set_param( $key, $value ); }
		if ( $authenticated ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); }
		$result = $controller->handle( $operation, $req );
		return is_wp_error( $result ) ? $result : json_decode( wp_json_encode( $result->get_data() ), true );
	};
	$fixtures = array();
	foreach ( array( false, true ) as $backup ) {
		$flow = $flows->issue( 'passkey_register', array( 'user_id' => $created, 'password' => hash( 'sha256', get_userdata( $created )->user_pass ), 'credential' => hash( 'sha256', wp_json_encode( ( new CredentialService() )->record( $created ) ) ) ) );
		$assert( is_wp_error( $request( 'options', array( 'flow' => $flow ) ) ), 'Enrollment requires a REST nonce' );
		$options = $request( 'options', array( 'flow' => $flow ), true );
		$assert( ! is_wp_error( $options ), 'Resident UV registration options returned' );
		$assert( 'required' === $options['publicKey']['authenticatorSelection']['userVerification'], 'User verification is mandatory' );
		$assert( 'required' === $options['publicKey']['authenticatorSelection']['residentKey'], 'Discoverable credentials requested' );
		$private = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		$details = openssl_pkey_get_details( $private );
		$credential_id = random_bytes( 32 );
		$cose = anar_passkey_fixture_cbor( array( 1 => 2, 3 => -7, -1 => 1, -2 => array( 'bytes' => $details['ec']['x'] ), -3 => array( 'bytes' => $details['ec']['y'] ) ) );
		$auth = hash( 'sha256', $options['publicKey']['rp']['id'], true ) . chr( $backup ? 0x4d : 0x45 ) . pack( 'N', 0 ) . str_repeat( "\0", 16 ) . pack( 'n', strlen( $credential_id ) ) . $credential_id . $cose;
		$attestation = anar_passkey_fixture_cbor( array( 'fmt' => 'none', 'attStmt' => array(), 'authData' => array( 'bytes' => $auth ) ) );
		$client = wp_json_encode( array( 'type' => 'webauthn.create', 'challenge' => $options['publicKey']['challenge'], 'origin' => PasskeyServer::origin(), 'crossOrigin' => false ) );
		$payload = array( 'flow' => $options['flow'], 'id' => PasskeyServer::encode( $credential_id ), 'clientDataJSON' => PasskeyServer::encode( $client ), 'attestationObject' => PasskeyServer::encode( $attestation ), 'name' => $backup ? 'Synced test key' : 'Device test key' );
		$result = $request( 'register', $payload, true );
		$assert( ! is_wp_error( $result ), 'Real EC registration accepted' );
		$assert( is_wp_error( $request( 'register', $payload, true ) ), 'Registration flow cannot replay' );
		$record = $keys->get( hash( 'sha256', $credential_id ) );
		$assert( $record && $record['user_id'] === $created, 'Credential ownership stored server-side' );
		$assert( $record['backup_eligible'] === $backup, 'Backup eligibility is retained' );
		$fixtures[] = array( 'private' => $private, 'id' => $credential_id, 'handle' => $options['publicKey']['user']['id'], 'backup' => $backup );
	}
	$assert( 2 === count( $keys->all( $created ) ), 'Multiple keys belong to one account' );
	$assert( $keys->rename( $created, hash( 'sha256', $fixtures[0]['id'] ), 'Renamed device' ), 'Key name can be changed' );
	$assert( 'Renamed device' === $keys->get( hash( 'sha256', $fixtures[0]['id'] ) )['name'], 'Renaming persists without changing ownership' );
	$assert( ! $keys->rename( $created + 1, hash( 'sha256', $fixtures[0]['id'] ), 'Other account' ), 'Another account cannot rename a key' );
	$assert( $fixtures[0]['handle'] === $fixtures[1]['handle'], 'User handle stays stable across keys' );
	$assertion = static function ( $fixture, $assertion_mode = 'valid', $counter = 1 ) use ( $request ) {
		$options = $request( 'options', array( 'remember' => false ) );
		if ( is_wp_error( $options ) ) {
			return $options; }
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$auth = hash( 'sha256', 'rp' === $assertion_mode ? 'wrong.example' : $host, true ) . chr( ( 'uv' === $assertion_mode ? 1 : 5 ) | ( $fixture['backup'] ? 8 : 0 ) ) . pack( 'N', $counter );
		$client = wp_json_encode( array( 'type' => 'webauthn.get', 'challenge' => $options['publicKey']['challenge'], 'origin' => 'origin' === $assertion_mode ? 'https://wrong.example' : PasskeyServer::origin(), 'crossOrigin' => false ) );
		openssl_sign( $auth . hash( 'sha256', $client, true ), $signature, $fixture['private'], OPENSSL_ALGO_SHA256 );
		return array( 'flow' => $options['flow'], 'id' => PasskeyServer::encode( $fixture['id'] ), 'clientDataJSON' => PasskeyServer::encode( $client ), 'authenticatorData' => PasskeyServer::encode( $auth ), 'signature' => PasskeyServer::encode( 'signature' === $assertion_mode ? random_bytes( 64 ) : $signature ), 'userHandle' => 'handle' === $assertion_mode ? PasskeyServer::encode( random_bytes( 32 ) ) : $fixture['handle'] );
	};
	$before = $cookies;
	foreach ( array( 'origin', 'rp', 'uv', 'signature', 'handle' ) as $assertion_mode ) {
		$payload = $assertion( $fixtures[0], $assertion_mode );
		$assert( ! is_wp_error( $payload ) && is_wp_error( $request( 'verify', $payload ) ), 'Reject invalid assertion: ' . $assertion_mode );
	}
	$assert( $before === $cookies, 'Invalid assertions create no sessions' );
	$payload = $assertion( $fixtures[0] );
	$assert( ! is_wp_error( $request( 'verify', $payload ) ), 'Valid signed assertion creates a session' );
	$assert( $cookies === $before + 1, 'Exactly one session created' );
	$assert( is_wp_error( $request( 'verify', $payload ) ), 'Signed assertion cannot replay' );
	$assert( is_wp_error( $request( 'verify', $assertion( $fixtures[0] ) ) ), 'Device counter cannot repeat' );
	$assert( is_wp_error( $request( 'verify', $assertion( $fixtures[0], 'valid', 0 ) ) ), 'Device counter cannot reset to zero after incrementing' );
	foreach ( array( 0, 0 ) as $counter ) {
		$assert( ! is_wp_error( $request( 'verify', $assertion( $fixtures[1], 'valid', $counter ) ) ), 'Synced passkey may retain a zero counter' );
	}
	$payload = $assertion( $fixtures[1] );
	$keys->remove( $created, hash( 'sha256', $fixtures[1]['id'] ) );
	$assert( is_wp_error( $request( 'verify', $payload ) ), 'Revoked key cannot sign in' );
	$assert( is_wp_error( $request( 'options', array( 'flow' => array( 'invalid' ) ) ) ), 'Malformed request rejected without coercion' );
	$assert( 1 === count( $keys->all( $created ) ), 'Removing one key preserves the other' );
} finally {
	wp_set_current_user( 0 );
	if ( $created && ! is_wp_error( $created ) ) {
		( new PasskeyRepository() )->remove_user( $created );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created );
	}
	update_option( Settings::OPTION_KEY, $original );
	remove_filter( 'send_auth_cookies', $block_cookie );
	remove_action( 'set_logged_in_cookie', $count_cookie );
	remove_filter( 'pre_http_request', $network );
	remove_filter( 'anar_login_client_ip', $ip_filter );
	remove_filter( 'home_url', $local_origin );
}
WP_CLI::success( $checks . ' passkey cryptographic checks passed; no external requests.' );
