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
			'api_key'        => __( 'Edge API token or access key', 'anar-toolkit' ),
			'from'           => __( 'Sender number', 'anar-toolkit' ),
			'template_id'    => __( 'Pattern code', 'anar-toolkit' ),
			'code_parameter' => __( 'Code variable name in the pattern', 'anar-toolkit' ),
		);
	}

	/** @inheritDoc */
	public function template_credentials() {
		return array( 'api_key', 'from' );
	}

	/** @inheritDoc */
	public function send_template( $recipient, $template, $params ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, $this->template_credentials() );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

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
						'code'         => (string) $template,
						'recipients'   => array( $recipient ),
						'params'       => $params,
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result['json'] ) && true === ( $result['json']['meta']['status'] ?? false ) && ! empty( $result['json']['data']['message_outbox_ids'] ) ) {
			return true;
		}

		return $this->gateway_error( $result['body'] );
	}
}
