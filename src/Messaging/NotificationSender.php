<?php
/**
 * Multi-variable commerce messages; OTP provider contracts remain compatible.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Commerce\Templates;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class NotificationSender {

	private $settings;
	private $http;

	public function __construct( Settings $settings, HttpClient $http ) {
		$this->settings = $settings;
		$this->http     = $http;
	}

	public function send( $provider, $recipient, $event, $variables, $recovery = false ) {
		$identity = Identity::normalize( $recipient );
		if ( is_wp_error( $identity ) || 'sms' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_recipient', __( 'A valid mobile number is required.', 'anar-toolkit' ) );
		}
		$recipient = $identity['identity'];
		$config    = $this->settings->provider( $provider );
		$message   = Templates::render( $event['message'], $variables );
		$mapping   = 'custom' === $provider ? $event['message'] : $event['params'];
		if ( $recovery && ( false === strpos( $mapping, '{code}' ) || false === strpos( $mapping, '{recovery_page}' ) || false === strpos( $mapping, '{unsubscribe_url}' ) ) ) {
			return new WP_Error( 'anar_recovery_code_required', __( 'Recovery messages must include the code, recovery page and unsubscribe link.', 'anar-toolkit' ) );
		}
		if ( 'custom' === $provider ) {
			if ( false === strpos( (string) ( $config['params'] ?? '' ) . (string) ( $config['url'] ?? '' ), '{message}' ) ) {
				return new WP_Error( 'anar_custom_message_required', __( 'The custom gateway must use the {message} placeholder for commerce messages.', 'anar-toolkit' ) );
			}
			return ( new Providers\CustomHttp( $this->settings, $this->http ) )->send( $recipient, '', $message );
		}
		$params   = Templates::parameters( $event['params'], $variables );
		$template = trim( (string) $event['template'] );
		if ( '' === $template || empty( $params ) ) {
			return new WP_Error( 'anar_notification_template_required', __( 'Configure an approved commerce template and its parameter mapping.', 'anar-toolkit' ) );
		}
		if ( $recovery && ( ! in_array( $variables['code'], $params, true ) || ! in_array( $variables['recovery_page'], $params, true ) || ! in_array( $variables['unsubscribe_url'], $params, true ) ) ) {
			return new WP_Error( 'anar_recovery_code_required', __( 'Recovery messages must include the code, recovery page and unsubscribe link.', 'anar-toolkit' ) );
		}
		if ( 'panelchi' === $provider ) {
			return ( new Providers\Panelchi( $this->settings, $this->http ) )->send_template( $recipient, $template, $params );
		}
		$required = array(
			'kavenegar'   => array( 'api_key' ),
			'ippanel'     => array( 'api_key', 'from' ),
			'farazsms'    => array( 'username', 'password', 'from' ),
			'melipayamak' => array( 'username', 'password' ),
			'smsir'       => array( 'api_key' ),
		);
		if ( ! isset( $required[ $provider ] ) ) {
			return new WP_Error( 'anar_provider_missing', __( 'The selected commerce SMS provider is unavailable.', 'anar-toolkit' ) );
		}
		foreach ( $required[ $provider ] as $field ) {
			if ( empty( $config[ $field ] ) ) {
				return new WP_Error( 'anar_sms_not_configured', __( 'Complete the SMS provider credentials in the login settings.', 'anar-toolkit' ) );
			}
		}
		$body    = array();
		$headers = array();
		$phone   = Identity::local_phone( $recipient );
		switch ( $provider ) {
			case 'kavenegar':
				if ( array_diff( array_keys( $params ), array( 'token', 'token2', 'token3', 'token10', 'token20' ) ) ) {
					return new WP_Error( 'anar_invalid_template_parameters', __( 'Kavenegar accepts token, token2, token3, token10 and token20.', 'anar-toolkit' ) );
				}
				foreach ( $params as $name => $value ) {
					$spaces = 'token10' === $name ? 5 : ( 'token20' === $name ? 8 : 0 );
					if ( preg_match_all( '/./us', $value ) > 100 || substr_count( $value, ' ' ) > $spaces || preg_match( '/[\r\n\t]/', $value ) ) {
						return new WP_Error( 'anar_kavenegar_token_invalid', __( 'A Kavenegar token exceeds 100 characters or its whitespace limit. Shorten its value or use another approved transport.', 'anar-toolkit' ) );
					}
				}
				$url  = 'https://api.kavenegar.com/v1/' . rawurlencode( $config['api_key'] ) . '/verify/lookup.json';
				$body = array_merge( $params, array( 'receptor' => $phone, 'template' => $template ) );
				break;
			case 'ippanel':
				$url     = 'https://edge.ippanel.com/v1/api/send';
				$headers = array( 'Authorization' => $config['api_key'] );
				$body    = array( 'sending_type' => 'pattern', 'from_number' => $config['from'], 'code' => $template, 'recipients' => array( $recipient ), 'params' => $params );
				break;
			case 'farazsms':
				$url  = 'https://ippanel.com/patterns/pattern';
				$body = array( 'username' => $config['username'], 'password' => $config['password'], 'from' => $config['from'], 'to' => wp_json_encode( array( $phone ) ), 'pattern_code' => $template, 'input_data' => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ) );
				break;
			case 'melipayamak':
				$url  = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
				$body = array( 'username' => $config['username'], 'password' => $config['password'], 'to' => $phone, 'bodyId' => $template, 'text' => implode( ';', $params ) );
				break;
			case 'smsir':
				$url     = 'https://api.sms.ir/v1/send/verify';
				$headers = array( 'X-API-KEY' => $config['api_key'] );
				$values  = array();
				foreach ( $params as $name => $value ) {
					$values[] = array( 'name' => $name, 'value' => $value );
				}
				$body = array( 'mobile' => $phone, 'templateId' => absint( $template ), 'parameters' => $values );
				break;
		}
		if ( in_array( $provider, array( 'ippanel', 'smsir' ), true ) ) {
			$headers['Content-Type'] = 'application/json; charset=utf-8';
			$body                    = wp_json_encode( $body, JSON_UNESCAPED_UNICODE );
		}
		$result = $this->http->request( 'POST', $url, array( 'headers' => $headers, 'body' => $body ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$json = $result['json'];
		$raw  = trim( $result['body'], "\" \t\n\r\0\x0B" );
		$ok   = false;
		switch ( $provider ) {
			case 'kavenegar':
				$ok = 200 === (int) ( $json['return']['status'] ?? 0 );
				break;
			case 'ippanel':
				$ok = true === ( $json['meta']['status'] ?? false ) && ! empty( $json['data']['message_outbox_ids'] );
				break;
			case 'farazsms':
				$ok = is_numeric( $raw ) && (int) $raw > 0;
				break;
			case 'melipayamak':
				$value = is_array( $json ) ? (string) ( $json['Value'] ?? '' ) : $raw;
				$ok    = 1 === (int) ( $json['RetStatus'] ?? 0 ) || ( ctype_digit( $value ) && strlen( $value ) > 15 );
				break;
			case 'smsir':
				$ok = 1 === (int) ( $json['status'] ?? 0 ) && ! empty( $json['data']['messageId'] );
				break;
		}
		return $ok ? true : new WP_Error( 'anar_sms_rejected', __( 'The SMS provider did not accept this message.', 'anar-toolkit' ) );
	}
}
