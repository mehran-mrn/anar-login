<?php
/**
 * Faraz SMS provider (IPPanel-compatible pattern API).
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class FarazSms extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'farazsms';
	}

	/** @inheritDoc */
	public function title() {
		return __( 'Faraz SMS', 'anar-login' );
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'username'       => __( 'Username', 'anar-login' ),
			'password'       => __( 'Web service password', 'anar-login' ),
			'from'           => __( 'Sender number', 'anar-login' ),
			'template_id'    => __( 'Pattern code', 'anar-login' ),
			'code_parameter' => __( 'Code variable name in the pattern', 'anar-login' ),
		);
	}

	/** @inheritDoc */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'username', 'password', 'from', 'template_id' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$parameter = ! empty( $config['code_parameter'] ) ? $config['code_parameter'] : 'code';
		$result    = $this->http->request(
			'POST',
			'https://ippanel.com/patterns/pattern',
			array(
				'body' => array(
					'username'     => $config['username'],
					'password'     => $config['password'],
					'from'         => $config['from'],
					'to'           => wp_json_encode( array( $this->local_phone( $recipient ) ) ),
					'input_data'   => wp_json_encode( array( $parameter => $code ), JSON_UNESCAPED_UNICODE ),
					'pattern_code' => $config['template_id'],
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = trim( $result['body'], "\" \t\n\r\0\x0B" );

		return is_numeric( $body ) && 0 < (int) $body ? true : $this->gateway_error( $result['body'] );
	}
}
