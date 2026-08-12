<?php
/**
 * SMS.ir v2 provider.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class SmsIr extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'smsir';
	}

	/** @inheritDoc */
	public function title() {
		return 'SMS.ir';
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'api_key'        => __( 'API key', 'anar-login' ),
			'template_id'    => __( 'Template ID', 'anar-login' ),
			'code_parameter' => __( 'Code parameter name', 'anar-login' ),
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
		$result    = $this->http->request(
			'POST',
			'https://api.sms.ir/v1/send/verify',
			array(
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'X-API-KEY'    => $config['api_key'],
				),
				'body'    => wp_json_encode(
					array(
						'mobile'     => $this->local_phone( $recipient ),
						'templateId' => absint( $config['template_id'] ),
						'parameters' => array(
							array(
								'name'  => $parameter,
								'value' => $code,
							),
						),
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_array( $result['json'] ) && ( 1 === (int) ( $result['json']['status'] ?? 0 ) || ! empty( $result['json']['data']['messageId'] ) )
			? true
			: $this->gateway_error( $result['body'] );
	}
}
