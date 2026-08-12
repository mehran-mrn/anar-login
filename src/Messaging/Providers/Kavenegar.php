<?php
/**
 * Kavenegar provider.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;

defined( 'ABSPATH' ) || exit;

final class Kavenegar extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'kavenegar';
	}

	/** @inheritDoc */
	public function title() {
		return __( 'Kavenegar', 'anar-login' );
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'api_key'     => __( 'API key', 'anar-login' ),
			'template_id' => __( 'Verify Lookup template name', 'anar-login' ),
		);
	}

	/** @inheritDoc */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array( 'api_key', 'template_id' ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$url = sprintf(
			'https://api.kavenegar.com/v1/%s/verify/lookup.json?%s',
			rawurlencode( $config['api_key'] ),
			http_build_query(
				array(
					'receptor' => $this->local_phone( $recipient ),
					'token'    => $code,
					'template' => $config['template_id'],
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			)
		);

		$result = $this->http->request( 'GET', $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['json']['return']['status'] ) && 200 === (int) $result['json']['return']['status']
			? true
			: $this->gateway_error( $result['body'] );
	}
}
