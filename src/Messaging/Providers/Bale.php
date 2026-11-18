<?php
/**
 * Anar toolkit update: configuration, delivery and presentation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;
use WP_Error;
defined( 'ABSPATH' ) || exit;
final class Bale extends AbstractProvider {
	public function key() {
		return 'bale'; }
	public function title() {
		return __( 'Bale', 'anar-toolkit' ); }
	public function description() {
		return __( 'Safir OTP and store messages. Existing legacy OTP connections remain supported.', 'anar-toolkit' ); }
	public function fields() {
		return array( 'api_key' => __( 'Safir API access key (v3)', 'anar-toolkit' ), 'bot_id' => __( 'Sender bot numeric ID (v3)', 'anar-toolkit' ), 'username' => __( 'Client ID', 'anar-toolkit' ), 'password' => __( 'Client secret', 'anar-toolkit' ) ); }
	public function template_credentials() {
		return ! empty( $this->config()['api_key'] ) ? array( 'api_key', 'bot_id' ) : array( 'username', 'password' ); }
	public function send( $recipient, $code, $message ) {
		return $this->send_template( $recipient, 'otp', array( 'code' => $code ) ); }
	public function text_credentials() {
		return ! empty( $this->config()['api_key'] ) ? array( 'api_key', 'bot_id' ) : null; }
	public function send_text( $recipient, $text ) {
		if ( null === $this->text_credentials() ) {
			return parent::send_text( $recipient, $text ); }
		return $this->send_v3( $recipient, array( 'message' => array( 'text' => (string) $text ) ) );
	}
	private function send_v3( $recipient, $data ) {
		$config = $this->config();
		$ready = $this->require_config( $config, array( 'api_key', 'bot_id' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready; }
		$result = $this->http->request(
			'POST',
			'https://safir.bale.ai/api/v3/send_message',
			array(
				'headers' => array( 'api-access-key' => $config['api_key'], 'Content-Type' => 'application/json' ),
				'body' => wp_json_encode( array( 'request_id' => wp_generate_uuid4(), 'bot_id' => (int) $config['bot_id'], 'phone_number' => '98' . substr( $this->local_phone( $recipient ), 1 ), 'message_data' => $data ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result; }
		$json = $result['json'];
		if ( is_array( $json ) && empty( $json['error_data'] ) && ! empty( $json['message_id'] ) ) {
			return true; }
		return new WP_Error( 'anar_bale_rejected', __( 'Bale could not deliver the verification code.', 'anar-toolkit' ), array( 'delivery_safe_to_retry' => is_array( $json ) && ! empty( $json['error_data'] ) ) );
	}

	public function send_template( $recipient, $template, $params ) {
		$config = $this->config();
		if ( ! empty( $config['api_key'] ) ) {
			$data = 'otp' === $template && isset( $params['code'] ) && 1 === count( $params ) ? array( 'otp_message' => array( 'otp' => (string) $params['code'] ) ) : array( 'template_message' => array( 'template_id' => (string) $template, 'text_fields' => (object) $params ) );
			return $this->send_v3( $recipient, $data );
		}
		$ready = $this->require_config( $config, $this->template_credentials() );
		if ( is_wp_error( $ready ) ) {
			return $ready; }
		$code = (string) ( $params['code'] ?? '' );
		if ( ! preg_match( '/^[1-9][0-9]{2,7}$/D', $code ) || count( $params ) !== 1 ) {
			return new WP_Error( 'anar_bale_code', __( 'Bale supports verification codes only.', 'anar-toolkit' ) ); }
		$cache = 'anar_bale_' . hash_hmac( 'sha256', $config['username'] . '|' . $config['password'], wp_salt( 'auth' ) );
		$token = get_transient( $cache );
		if ( ! $token ) {
			$auth = $this->http->request( 'POST', 'https://safir.bale.ai/api/v2/auth/token', array( 'body' => array( 'grant_type' => 'client_credentials', 'client_id' => $config['username'], 'client_secret' => $config['password'], 'scope' => 'read' ) ) );
			if ( is_wp_error( $auth ) ) {
				$auth->add_data( array_merge( (array) $auth->get_error_data(), array( 'delivery_safe_to_retry' => true ) ) );
				return $auth; }
			$token = $auth['json']['access_token'] ?? '';
			if ( ! is_string( $token ) || ! $token ) {
				return new WP_Error( 'anar_bale_auth', __( 'Bale authentication failed.', 'anar-toolkit' ), array( 'delivery_safe_to_retry' => true ) ); }
			set_transient( $cache, $token, max( 1, min( 43200, (int) ( $auth['json']['expires_in'] ?? 60 ) ) - 30 ) );
		}
		$response = $this->http->request( 'POST', 'https://safir.bale.ai/api/v2/send_otp', array( 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'phone' => '98' . substr( $this->local_phone( $recipient ), 1 ), 'otp' => (int) $code ) ) ) );
		if ( is_wp_error( $response ) ) {
			delete_transient( $cache );
			$data = (array) $response->get_error_data();
			$body = json_decode( $data['body'] ?? '', true );
			if ( is_array( $body ) && ! empty( $body['code'] ) ) {
				$data['delivery_safe_to_retry'] = true;
				$response->add_data( $data ); }
			return $response; }
		if ( ! is_array( $response['json'] ) || ! empty( $response['json']['error'] ) || ( isset( $response['json']['code'] ) && 0 !== (int) $response['json']['code'] ) ) {
			return new WP_Error( 'anar_bale_rejected', __( 'Bale could not deliver the verification code.', 'anar-toolkit' ), array( 'delivery_safe_to_retry' => is_array( $response['json'] ) && ( ! empty( $response['json']['error'] ) || ! empty( $response['json']['code'] ) ) ) ); }
		return true;
	}
}
