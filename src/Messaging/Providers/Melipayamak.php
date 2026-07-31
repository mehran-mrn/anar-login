<?php
/**
 * Melipayamak provider.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class Melipayamak extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'melipayamak';
	}

	/** @inheritDoc */
	public function title() {
		return __( 'Meli Payamak', 'anar-login' );
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'username'    => __( 'Username', 'anar-login' ),
			'password'    => __( 'Web service password', 'anar-login' ),
			'template_id' => __( 'Template body ID', 'anar-login' ),
		);
	}

	/** @inheritDoc */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'username', 'password', 'template_id' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = $this->http->request(
			'POST',
			'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8' ),
				'body'    => array(
					'username' => $config['username'],
					'password' => $config['password'],
					'text'     => $code,
					'to'       => $this->local_phone( $recipient ),
					'bodyId'   => $config['template_id'],
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$json = $result['json'];
		if ( is_array( $json ) && ( ! empty( $json['Value'] ) || 1 === (int) ( $json['RetStatus'] ?? 0 ) ) ) {
			return true;
		}

		return is_numeric( trim( $result['body'], "\" \t\n\r\0\x0B" ) ) && 0 < (int) trim( $result['body'], '"' )
			? true
			: $this->gateway_error( $result['body'] );
	}
}
