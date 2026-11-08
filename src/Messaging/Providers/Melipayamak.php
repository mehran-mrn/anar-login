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
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'username', 'password' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$template = (string) ( $config['template_id'] ?? '' );
		$from     = (string) ( $config['from'] ?? '' );
		if ( ! $template && ! $from ) {
			return new WP_Error(
				'anar_sms_not_configured',
				__( 'Enter a template body ID, or a sender number for plain-text messages.', 'anar-toolkit' )
			);
		}

		$body = array(
			'username' => $config['username'],
			'password' => $config['password'],
			'to'       => $this->local_phone( $recipient ),
		);

		if ( $template ) {
			$endpoint       = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';
			$body['text']   = $code;
			$body['bodyId'] = $template;
		} else {
			$endpoint        = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
			$body['from']    = $from;
			$body['text']    = $message;
			$body['isflash'] = 'false';
		}

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
