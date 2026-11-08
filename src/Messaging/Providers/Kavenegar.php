<?php
/**
 * Kavenegar provider.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

use Anar\Login\Messaging\AbstractProvider;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Kavenegar extends AbstractProvider {
	/** @inheritDoc */
	public function key() {
		return 'kavenegar';
	}

	/** @inheritDoc */
	public function title() {
		return __( 'Kavenegar', 'anar-toolkit' );
	}

	/** @inheritDoc */
	public function fields() {
		return array(
			'api_key'     => __( 'API key', 'anar-toolkit' ),
			'template_id' => __( 'Verify Lookup template name', 'anar-toolkit' ),
		);
	}

	/** @inheritDoc */
	public function template_credentials() {
		return array( 'api_key' );
	}

	/** @inheritDoc */
	public function parameter_names() {
		return array( 'token', 'token2', 'token3', 'token10', 'token20' );
	}

	/** Verify Lookup always carries the code in the first token. */
	protected function code_parameter() {
		return 'token';
	}

	/** @inheritDoc */
	public function send_template( $recipient, $template, $params ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, $this->template_credentials() );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		if ( array_diff( array_keys( $params ), $this->parameter_names() ) ) {
			return new WP_Error( 'anar_invalid_template_parameters', __( 'Kavenegar accepts token, token2, token3, token10 and token20.', 'anar-toolkit' ) );
		}
		foreach ( $params as $name => $value ) {
			$spaces = 'token10' === $name ? 5 : ( 'token20' === $name ? 8 : 0 );
			if ( preg_match_all( '/./us', (string) $value ) > 100 || substr_count( (string) $value, ' ' ) > $spaces || preg_match( '/[\r\n\t]/', (string) $value ) ) {
				return new WP_Error( 'anar_kavenegar_token_invalid', __( 'A Kavenegar token exceeds 100 characters or its whitespace limit. Shorten its value or use another approved transport.', 'anar-toolkit' ) );
			}
		}

		$result = $this->http->request(
			'POST',
			'https://api.kavenegar.com/v1/' . rawurlencode( $config['api_key'] ) . '/verify/lookup.json',
			array(
				'body' => array_merge(
					array_map( 'strval', $params ),
					array(
						'receptor' => $this->local_phone( $recipient ),
						'template' => (string) $template,
					)
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return 200 === (int) ( $result['json']['return']['status'] ?? 0 ) ? true : $this->gateway_error( $result['body'] );
	}
}
