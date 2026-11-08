<?php
/**
 * Melipayamak provider.
 *
 * Two modes: a shared service line with an approved template (BaseServiceNumber,
 * recommended for OTP), or plain text from the account's dedicated number (SendSMS).
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Melipayamak extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'melipayamak';
	}

	/** @inheritDoc */
	public function title() {
		return __( 'Meli Payamak', 'anar-toolkit' );
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'username'    => __( 'Username', 'anar-toolkit' ),
			'password'    => __( 'Web service password', 'anar-toolkit' ),
			'template_id' => array(
				'label' => __( 'Template body ID', 'anar-toolkit' ),
				'help'  => __( 'Recommended: sends through the shared service line, so the code is delivered even to numbers that block advertising.', 'anar-toolkit' ),
			),
			'from'        => array(
				'label' => __( 'Sender number (optional)', 'anar-toolkit' ),
				'help'  => __( 'Used for messages whose delivery method is plain text. Approved-template messages use the panel service line.', 'anar-toolkit' ),
			),
		);
	}

	/** @inheritDoc */
	public function template_credentials() {
		return array( 'username', 'password' );
	}

	/** @inheritDoc */
	public function text_credentials() {
		return array( 'username', 'password', 'from' );
	}

	/**
	 * Login code: the approved template when configured, else plain text.
	 *
	 * @inheritDoc
	 */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, $this->template_credentials() );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( ! empty( $config['template_id'] ) ) {
			return $this->send_template( $recipient, (string) $config['template_id'], array( (string) $code ) );
		}
		if ( ! empty( $config['from'] ) ) {
			return $this->send_text( $recipient, $message );
		}

		return new WP_Error( 'anar_sms_not_configured', __( 'Enter a template body ID, or a sender number for plain-text messages.', 'anar-toolkit' ) );
	}

	/** @inheritDoc */
	public function send_template( $recipient, $template, $params ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, $this->template_credentials() );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		// BaseServiceNumber takes the template variables in order, separated by ";".
		return $this->post(
			'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
			array(
				'username' => $config['username'],
				'password' => $config['password'],
				'to'       => $this->local_phone( $recipient ),
				'bodyId'   => (string) $template,
				'text'     => implode( ';', array_map( 'strval', $params ) ),
			)
		);
	}

	/** @inheritDoc */
	public function send_text( $recipient, $text ) {
		$config = $this->config();
		if ( is_wp_error( $this->require_config( $config, $this->text_credentials() ) ) ) {
			return parent::send_text( $recipient, $text );
		}

		return $this->post(
			'https://rest.payamak-panel.com/api/SendSMS/SendSMS',
			array(
				'username' => $config['username'],
				'password' => $config['password'],
				'from'     => $config['from'],
				'to'       => $this->local_phone( $recipient ),
				'text'     => (string) $text,
				'isflash'  => 'false',
			)
		);
	}

	/**
	 * Post a form request and read Meli Payamak's acceptance signal.
	 *
	 * @param string               $endpoint Endpoint.
	 * @param array<string,string> $body     Form fields.
	 * @return true|WP_Error
	 */
	private function post( $endpoint, $body ) {
		$result = $this->http->request(
			'POST',
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Failures also carry a non-empty Value (an error code), so only RetStatus or a
		// recId longer than 15 digits, as documented by Meli Payamak, count as delivery.
		$json  = $result['json'];
		$value = is_array( $json ) ? (string) ( $json['Value'] ?? '' ) : trim( $result['body'], "\" \t\n\r\0\x0B" );
		if ( ( is_array( $json ) && 1 === (int) ( $json['RetStatus'] ?? 0 ) ) || ( ctype_digit( $value ) && strlen( $value ) > 15 ) ) {
			return true;
		}

		return $this->gateway_error( $result['body'] );
	}
}
