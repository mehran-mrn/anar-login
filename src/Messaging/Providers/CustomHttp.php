<?php
/**
 * Configurable HTTP gateway for any SMS panel with a web API.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CustomHttp extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'custom';
	}

	/** @inheritDoc */
	public function title() {
		return __( 'Custom gateway', 'anar-toolkit' );
	}

	/**
	 * Short description for the provider picker.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Any panel with a web API', 'anar-toolkit' );
	}

	/** @inheritDoc */
	public function fields() {
		$placeholders = __( 'Placeholders: {to} recipient, {code} one-time code, {message} full text, {sender} sender number.', 'anar-toolkit' );

		return array(
			'url'          => array(
				'label'       => __( 'Endpoint URL', 'anar-toolkit' ),
				'type'        => 'url',
				'placeholder' => 'https://api.example.com/v1/send',
				'help'        => $placeholders,
			),
			'method'       => array(
				'label'   => __( 'HTTP method', 'anar-toolkit' ),
				'type'    => 'select',
				'options' => array(
					'POST' => 'POST',
					'GET'  => 'GET',
				),
			),
			'body_format'  => array(
				'label'   => __( 'Body format', 'anar-toolkit' ),
				'type'    => 'select',
				'options' => array(
					'form' => __( 'Form fields', 'anar-toolkit' ),
					'json' => 'JSON',
				),
			),
			'phone_format' => array(
				'label'   => __( 'Recipient format', 'anar-toolkit' ),
				'type'    => 'select',
				'options' => array(
					'local'         => '09121234567',
					'international' => '+989121234567',
					'plain'         => '989121234567',
					'national'      => '9121234567',
				),
			),
			'params'       => array(
				'label'       => __( 'Parameters', 'anar-toolkit' ),
				'type'        => 'textarea',
				'placeholder' => "receptor={to}\ntoken={code}\ntemplate=verify",
				'help'        => __( 'One per line as name=value, or a complete JSON object for APIs with nested bodies or numeric values. Sent as the query string for GET requests.', 'anar-toolkit' ),
			),
			'headers'      => array(
				'label'       => __( 'HTTP headers', 'anar-toolkit' ),
				'type'        => 'textarea',
				'placeholder' => 'Authorization: Bearer YOUR-API-KEY',
				'help'        => __( 'Optional. One per line as Name: value.', 'anar-toolkit' ),
			),
			'from'         => __( 'Sender number', 'anar-toolkit' ),
			'success_text' => array(
				'label' => __( 'Success text in the response', 'anar-toolkit' ),
				'help'  => __( 'Optional but recommended. Delivery counts as successful only for HTTP 2xx responses that contain this text, such as "status":200.', 'anar-toolkit' ),
			),
		);
	}

	/** @inheritDoc */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'url' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$values = array(
			'{to}'      => $this->format_phone( $recipient, (string) ( $config['phone_format'] ?? 'local' ) ),
			'{code}'    => (string) $code,
			'{message}' => (string) $message,
			'{sender}'  => (string) ( $config['from'] ?? '' ),
		);
		$method = 'GET' === ( $config['method'] ?? 'POST' ) ? 'GET' : 'POST';
		$url    = strtr( (string) $config['url'], array_map( 'rawurlencode', $values ) );
		$params = trim( (string) ( $config['params'] ?? '' ) );
		$args   = array( 'headers' => $this->headers( (string) ( $config['headers'] ?? '' ), $values ) );

		if ( 0 === strpos( $params, '{' ) ) {
			$body = $this->json_template( $params, $values );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$args['body']                    = $body;
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$method                          = 'POST';
		} else {
			$fields = $this->fields_from_lines( $params, $values );
			if ( 'GET' === $method ) {
				$url = add_query_arg( array_map( 'rawurlencode', $fields ), $url );
			} elseif ( 'json' === ( $config['body_format'] ?? 'form' ) ) {
				$args['body']                    = wp_json_encode( $fields, JSON_UNESCAPED_UNICODE );
				$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			} else {
				$args['body'] = $fields;
			}
		}

		$result = $this->http->request( $method, $url, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$expected = trim( (string) ( $config['success_text'] ?? '' ) );
		if ( '' !== $expected && false === stripos( $result['body'], $expected ) ) {
			return $this->gateway_error( $result['body'] );
		}

		return true;
	}

	/**
	 * Recipient in the format the panel expects.
	 *
	 * @param string $recipient Canonical +98 phone.
	 * @param string $format    Format key.
	 * @return string
	 */
	/** @inheritDoc */
	public function text_credentials() {
		return array( 'url' );
	}

	/**
	 * Free text needs the {message} placeholder; a code-only request would drop it.
	 *
	 * @inheritDoc
	 */
	public function send_text( $recipient, $text ) {
		$config = $this->config();
		if ( false === strpos( (string) ( $config['params'] ?? '' ) . (string) ( $config['url'] ?? '' ), '{message}' ) ) {
			return new WP_Error( 'anar_custom_message_required', __( 'The custom gateway must use the {message} placeholder for commerce messages.', 'anar-toolkit' ) );
		}

		return $this->send( $recipient, '', $text );
	}

	private function format_phone( $recipient, $format ) {
		$national = (string) preg_replace( '/^\+98/', '', $recipient );

		switch ( $format ) {
			case 'international':
				return $recipient;
			case 'plain':
				return '98' . $national;
			case 'national':
				return $national;
			default:
				return $this->local_phone( $recipient );
		}
	}

	/**
	 * Parse name=value lines. Values stay strings; use a JSON template when numbers are required.
	 *
	 * @param string               $lines  Raw lines.
	 * @param array<string,string> $values Placeholder values.
	 * @return array<string,string>
	 */
	private function fields_from_lines( $lines, $values ) {
		$fields = array();

		foreach ( preg_split( '/\n+/', $lines ) as $line ) {
			$parts = explode( '=', $line, 2 );
			$name  = trim( $parts[0] );
			if ( '' === $name ) {
				continue;
			}

			$fields[ $name ] = strtr( isset( $parts[1] ) ? trim( $parts[1] ) : '', $values );
		}

		return $fields;
	}

	/**
	 * Fill a JSON body template, escaping each value for JSON.
	 *
	 * @param string               $template JSON with placeholders inside strings.
	 * @param array<string,string> $values   Placeholder values.
	 * @return string|WP_Error
	 */
	private function json_template( $template, $values ) {
		$escaped = array();
		foreach ( $values as $placeholder => $value ) {
			$escaped[ $placeholder ] = substr( (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE ), 1, -1 );
		}

		$body = strtr( $template, $escaped );
		if ( null === json_decode( $body ) ) {
			return new WP_Error( 'anar_sms_not_configured', __( 'The custom gateway JSON parameters are not valid JSON.', 'anar-toolkit' ) );
		}

		return $body;
	}

	/**
	 * Parse "Name: value" header lines.
	 *
	 * @param string               $lines  Raw lines.
	 * @param array<string,string> $values Placeholder values.
	 * @return array<string,string>
	 */
	private function headers( $lines, $values ) {
		$headers = array();

		foreach ( preg_split( '/\n+/', $lines ) as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
				$headers[ trim( $parts[0] ) ] = strtr( trim( $parts[1] ), $values );
			}
		}

		return $headers;
	}
}
