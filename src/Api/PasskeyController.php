<?php
/**
 * Optional passkey endpoints. Loaded only for a passkey request.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Api;

use Anar\Login\Auth\AccountSecurity;
use Anar\Login\Auth\CredentialService;
use Anar\Login\Auth\LoginFlowService;
use Anar\Login\Auth\PasskeyRepository;
use Anar\Login\Auth\PasskeyServer;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Upstream WebAuthn DTO properties follow the browser protocol.
final class PasskeyController {
	private $security;
	private $flows;
	private $keys;
	private $registration = false;

	public function __construct( AccountSecurity $security ) {
		$this->security = $security;
		$this->flows = new LoginFlowService();
		$this->keys = new PasskeyRepository();
	}

	public function handle( $operation, $request ) {
		$this->registration = 'register' === $operation || ( 'options' === $operation && is_string( $request->get_param( 'flow' ) ) && '' !== $request->get_param( 'flow' ) );
		nocache_headers();
		if ( ! Features::enabled( 'login' ) || ! ( new Settings() )->get( 'passkeys_enabled', 0 ) || ! PasskeyServer::available() || strlen( (string) $request->get_body() ) > 262144 ) {
			return $this->invalid();
		}
		foreach ( array( 'flow', 'id', 'clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle', 'name', 'redirect_to' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value && ! is_string( $value ) ) {
				return $this->invalid();
			}
		}
		try {
			if ( 'options' === $operation ) {
				$result = $this->options( $request );
			} elseif ( 'register' === $operation ) {
				$result = $this->register( $request );
			} elseif ( 'verify' === $operation ) {
				$result = $this->verify( $request );
			} else {
				return $this->invalid();
			}
			return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200, array( 'Cache-Control' => 'no-store' ) );
		} catch ( \Throwable $error ) {
			// Library errors may contain raw credential data; never expose or log them.
			return $this->invalid();
		}
	}

	private function invalid() {
		return new WP_Error( 'anar_passkey_invalid', ( $this->registration ? __( 'The passkey could not be registered. Please try again.', 'anar-toolkit' ) : __( 'The passkey could not be verified. Please try again or use another sign-in method.', 'anar-toolkit' ) ), array( 'status' => 400 ) );
	}

	private function enrollment( $request, $reserve = false ) {
		$token = (string) $request->get_param( 'flow' );
		$state = $reserve ? $this->flows->attempt( $token, 'passkey_register' ) : $this->flows->read( $token, 'passkey_register' );
		$user = is_user_logged_in() ? wp_get_current_user() : false;
		if ( ! $state || ! $user || (int) $state['data']['user_id'] !== (int) $user->ID || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! hash_equals( $state['data']['password'], hash( 'sha256', $user->user_pass ) ) || ! hash_equals( $state['data']['credential'], hash( 'sha256', wp_json_encode( ( new CredentialService() )->record( $user->ID ) ) ) ) ) {
			return false;
		}
		return $state;
	}

	private function options( $request ) {
		$limit = ( new RateLimiter( new Settings() ) )->guard_passkey();
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		$server = PasskeyServer::server();
		if ( $request->get_param( 'flow' ) ) {
			$state = $this->enrollment( $request );
			if ( ! $state || count( $this->keys->all( $state['data']['user_id'] ) ) >= 10 ) {
				return $this->invalid();
			}
			$user = wp_get_current_user();
			$ids = array_map(
				static function ( $record ) {
					return PasskeyServer::decode( $record['credential_id'] );
				},
				$this->keys->all( $user->ID )
			);
			$handle = $this->keys->handle( $user->ID );
			$options = $server->getCreateArgs( PasskeyServer::decode( $handle ), $user->user_login, $user->display_name, 120, true, true, null, array_values( $ids ) );
			$registration = $this->flows->issue( 'passkey_attestation', array( 'enrollment' => (string) $request->get_param( 'flow' ), 'user_id' => (int) $user->ID, 'handle' => $handle, 'challenge' => PasskeyServer::encode( $server->getChallenge()->getBinaryString() ) ), 120 );
			return is_wp_error( $registration ) ? $registration : array( 'flow' => $registration, 'publicKey' => $options->publicKey );
		}
		$options = $server->getGetArgs( array(), 120, true, true, true, true, true, true );
		$token = $this->flows->issue( 'passkey_login', array( 'challenge' => PasskeyServer::encode( $server->getChallenge()->getBinaryString() ), 'redirect' => wp_validate_redirect( (string) $request->get_param( 'redirect_to' ), '' ), 'remember' => rest_sanitize_boolean( $request->get_param( 'remember' ) ) ), 120 );
		return is_wp_error( $token ) ? $token : array( 'flow' => $token, 'publicKey' => $options->publicKey );
	}

	private function register( $request ) {
		$token = (string) $request->get_param( 'flow' );
		$attestation = $this->flows->attempt( $token, 'passkey_attestation' );
		if ( ! $attestation ) {
			return $this->invalid();
		}
		$enrollment_token = $attestation['data']['enrollment'];
		$request->set_param( 'flow', $enrollment_token );
		$enrollment = $this->enrollment( $request, true );
		if ( ! $enrollment || get_current_user_id() !== (int) $attestation['data']['user_id'] || count( $this->keys->all( get_current_user_id() ) ) >= 10 ) {
			return $this->invalid();
		}
		$client = PasskeyServer::decode( $request->get_param( 'clientDataJSON' ) );
		PasskeyServer::check_client( $client );
		$server = PasskeyServer::server();
		$data = $server->processCreate( $client, PasskeyServer::decode( $request->get_param( 'attestationObject' ) ), PasskeyServer::decode( $attestation['data']['challenge'] ), true, true );
		$id = $data->credentialId;
		if ( ! is_string( $id ) || strlen( $id ) > 1024 || ! hash_equals( $id, PasskeyServer::decode( $request->get_param( 'id' ) ) ) || ! $this->flows->consume( $token, 'passkey_attestation', $attestation ) || ! $this->flows->consume( $enrollment_token, 'passkey_register', $enrollment ) ) {
			return $this->invalid();
		}
		$name = trim( mb_substr( sanitize_text_field( (string) $request->get_param( 'name' ) ), 0, 80 ) );
		$name = '' === $name ? __( 'Passkey', 'anar-toolkit' ) : $name;
		$record = array( 'user_id' => get_current_user_id(), 'handle' => $attestation['data']['handle'], 'credential_id' => PasskeyServer::encode( $id ), 'public_key' => $data->credentialPublicKey, 'counter' => (int) $data->signatureCounter, 'backup_eligible' => (bool) $data->isBackupEligible, 'created' => time(), 'last_used' => 0, 'name' => $name );
		return $this->keys->create( $id, $record ) ? array( 'message' => __( 'Passkey added to your account.', 'anar-toolkit' ), 'redirect' => add_query_arg( 'action', 'anar_security', wp_login_url() ) ) : $this->invalid();
	}

	private function verify( $request ) {
		$token = (string) $request->get_param( 'flow' );
		$state = $this->flows->attempt( $token, 'passkey_login' );
		if ( ! $state ) {
			return $this->invalid();
		}
		$id = PasskeyServer::decode( $request->get_param( 'id' ) );
		if ( strlen( $id ) > 1024 ) {
			return $this->invalid();
		}
		$key = hash( 'sha256', $id );
		$record = $this->keys->get( $key );
		$user = is_array( $record ) ? get_userdata( $record['user_id'] ) : false;
		if ( ! $user || ! hash_equals( $record['handle'], (string) $request->get_param( 'userHandle' ) ) || is_wp_error( ( new RateLimiter( new Settings() ) )->guard_factor( $user->ID ) ) ) {
			return $this->invalid();
		}
		$client = PasskeyServer::decode( $request->get_param( 'clientDataJSON' ) );
		$authenticator = PasskeyServer::decode( $request->get_param( 'authenticatorData' ) );
		PasskeyServer::check_client( $client );
		$server = PasskeyServer::server();
		$server->processGet( $client, $authenticator, PasskeyServer::decode( $request->get_param( 'signature' ) ), $record['public_key'], PasskeyServer::decode( $state['data']['challenge'] ), null, true, true );
		$parsed = new \Anar\Login\Vendor\WebAuthn\Attestation\AuthenticatorData( $authenticator );
		$counter = (int) $server->getSignatureCounter();
		if ( (bool) $parsed->getIsBackupEligible() !== $record['backup_eligible'] || ( $parsed->getIsBackup() && ! $parsed->getIsBackupEligible() ) || ( ! $record['backup_eligible'] && $record['counter'] > 0 && $counter <= $record['counter'] ) ) {
			return $this->invalid();
		}
		$next = $record;
		$next['counter'] = $counter;
		$next['last_used'] = time();
		$next['revision'] = bin2hex( random_bytes( 16 ) );
		if ( ! $this->keys->update( $key, $record, $next ) || ! $this->flows->consume( $token, 'passkey_login', $state ) ) {
			return $this->invalid();
		}
		( new RateLimiter( new Settings() ) )->clear_factor( $user->ID );
		return $this->security->complete( $user, $state['data']['remember'], $state['data']['redirect'] );
	}
}
