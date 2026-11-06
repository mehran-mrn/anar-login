<?php
/**
 * Panelchi pattern API provider.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;

defined( 'ABSPATH' ) || exit;

class Panelchi extends AbstractProvider {

	/** @inheritDoc */
	public function key() {
		return 'panelchi';
	}

	/** @inheritDoc */
	public function title() {
		return 'Panelchi';
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'api_key'        => __( 'API token', 'anar-toolkit' ),
			'from'           => array(
				'label'       => __( 'Sender number', 'anar-toolkit' ),
				'placeholder' => '10001',
				'help'        => __( 'Panelchi uses source number 10001. Leave blank to use this default.', 'anar-toolkit' ),
			),
			'template_id'    => __( 'Pattern slug', 'anar-toolkit' ),
			'code_parameter' => __( 'Code variable name in the pattern', 'anar-toolkit' ),
		);
	}

	/** @inheritDoc */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'api_key', 'template_id' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$parameter = ! empty( $config['code_parameter'] ) ? $config['code_parameter'] : 'code';
		return $this->send_template( $recipient, $config['template_id'], array( $parameter => (string) $code ) );
	}

	/**
	 * Send an approved OTP or commerce pattern without sharing their settings.
	 *
	 * @param string               $recipient Canonical Iranian mobile number.
	 * @param string               $template Approved pattern slug.
	 * @param array<string,string> $variables Pattern variable values.
	 * @return true|\WP_Error
	 */
	public function send_template( $recipient, $template, $variables ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'api_key' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$token  = preg_replace( '/^Bearer\s+/i', '', trim( $config['api_key'] ) );
		$result = $this->http->request(
			'POST',
			'https://api.panelchi.com/sms/pattern',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
					'Accept'        => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'pattern'      => (string) $template,
						'variables'    => (object) $variables,
						'recipient'    => $recipient,
						'sourceNumber' => ! empty( $config['from'] ) ? $config['from'] : '10001',
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = $result['json']['data'] ?? null;
		if ( is_array( $data ) && is_string( $data['uid'] ?? null ) && '' !== trim( $data['uid'] ) && in_array( $data['status'] ?? '', array( 'CREATED', 'ACCEPTING', 'ACCEPTED', 'SENDING', 'FINISHED', 'REPORTING' ), true ) ) {
			return true;
		}
		return $this->gateway_error();
	}
}
