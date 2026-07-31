<?php
/**
 * WordPress HTTP API wrapper.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class HttpClient {
	/**
	 * Execute a request and normalize errors.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $url     Endpoint.
	 * @param array<string,mixed> $args    Request args.
	 * @return array{status:int,body:string,json:mixed}|WP_Error
	 */
	public function request( $method, $url, $args = array() ) {
		$args['method']      = strtoupper( $method );
		$args['timeout']     = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : 15;
		$args['redirection'] = 2;
		$args['user-agent']  = 'Anar-Login/' . ANAR_LOGIN_VERSION . '; ' . home_url( '/' );

		$response = wp_safe_remote_request( esc_url_raw( $url ), $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'anar_sms_network', __( 'ارتباط با سرویس پیامک برقرار نشد.', 'anar-login' ), array( 'detail' => $response->get_error_message() ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'anar_sms_http',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'سرویس پیامک پاسخ ناموفق داد (HTTP %d).', 'anar-login' ),
					$status
				),
				array(
					'status' => $status,
					'body'   => substr( wp_strip_all_tags( $body ), 0, 300 ),
				)
			);
		}

		return array(
			'status' => $status,
			'body'   => $body,
			'json'   => $json,
		);
	}
}
