<?php
/**
 * IPPanel provider.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;

defined( 'ABSPATH' ) || exit;

class IPPanel extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'ippanel';
	}

	/** @inheritDoc */
	public function title() {
		return 'IPPanel';
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'api_key'        => __( 'توکن یا کلید دسترسی Edge API', 'anar-login' ),
			'from'           => __( 'شماره فرستنده', 'anar-login' ),
			'template_id'    => __( 'کد پترن', 'anar-login' ),
			'code_parameter' => __( 'نام متغیر کد در پترن', 'anar-login' ),
		);
	}

	/** @inheritDoc */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'api_key', 'from', 'template_id' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$parameter = ! empty( $config['code_parameter'] ) ? $config['code_parameter'] : 'code';

		$result = $this->http->request(
			'POST',
			'https://edge.ippanel.com/v1/api/send',
			array(
				'headers' => array(
					'Authorization' => $config['api_key'],
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'sending_type' => 'pattern',
						'from_number'  => $config['from'],
						'code'         => $config['template_id'],
						'recipients'   => array( $recipient ),
						'params'       => array( $parameter => $code ),
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result['json'] ) && ! empty( $result['json']['meta']['status'] ) && ! empty( $result['json']['data']['message_outbox_ids'] ) ) {
			return true;
		}

		return $this->gateway_error( $result['body'] );
	}
}
