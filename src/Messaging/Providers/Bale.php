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
		return __( 'Bale OTP', 'anar-toolkit' ); }
	public function description() {
		return __( 'Receive verification codes inside Bale.', 'anar-toolkit' ); }
	public function fields() {
		return array( 'username' => __( 'Client ID', 'anar-toolkit' ), 'password' => __( 'Client secret', 'anar-toolkit' ) ); }
	public function template_credentials() {
		return array( 'username', 'password' ); }
	public function send( $recipient, $code, $message ) {
		return $this->send_template( $recipient, 'otp', array( 'code' => $code ) ); }
	public function send_template( $recipient, $template, $params ) {
		$config = $this->config();
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
				return $auth; }
			$token = $auth['json']['access_token'] ?? '';
			if ( ! is_string( $token ) || ! $token ) {
				return new WP_Error( 'anar_bale_auth', __( 'Bale authentication failed.', 'anar-toolkit' ) ); }
			set_transient( $cache, $token, max( 1, min( 43200, (int) ( $auth['json']['expires_in'] ?? 60 ) ) - 30 ) );
		}
		$response = $this->http->request( 'POST', 'https://safir.bale.ai/api/v2/send_otp', array( 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'phone' => '98' . substr( $this->local_phone( $recipient ), 1 ), 'otp' => (int) $code ) ) ) );
		if ( is_wp_error( $response ) ) {
			delete_transient( $cache );
			return $response; }
		if ( ! is_array( $response['json'] ) || ! empty( $response['json']['error'] ) || ( isset( $response['json']['code'] ) && 0 !== (int) $response['json']['code'] ) ) {
			return new WP_Error( 'anar_bale_rejected', __( 'Bale could not deliver the verification code.', 'anar-toolkit' ) ); }
		return true;
	}
}
