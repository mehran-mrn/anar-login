<?php
/**
 * Read-only balance adapters. Sending settings and affiliate credentials stay separate.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Balance;

use Anar\Login\Messaging\HttpClient;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Gateways {

	public static function providers() {
		return array( 'melipayamak', 'kavenegar', 'ippanel', 'panelchi', 'maxsms', 'smsir', 'farazsms' );
	}

	public static function fields( $provider ) {
		if ( 'melipayamak' === $provider ) {
			return array( 'username', 'password' );
		}
		return 'farazsms' === $provider ? array( 'balance_api_key' ) : array( 'api_key' );
	}

	public static function configured( $provider, $config ) {
		if ( ! in_array( $provider, self::providers(), true ) ) {
			return false;
		}
		foreach ( self::fields( $provider ) as $field ) {
			if ( ! is_string( $config[ $field ] ?? null ) || '' === trim( $config[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function number( $value ) {
		if ( is_bool( $value ) || ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		$value = (float) $value;
		return is_finite( $value ) && abs( $value ) <= 1000000000000000 ? $value : null;
	}

	public static function parse( $provider, $data ) {
		$value = null;
		$unit = 'CREDIT';
		if ( ! is_array( $data ) ) {
			return self::invalid();
		}
		switch ( $provider ) {
			case 'melipayamak':
				if ( 1 === (int) ( $data['RetStatus'] ?? 0 ) ) {
					$value = self::number( $data['Value'] ?? null );
				}
				break;
			case 'kavenegar':
				$unit = 'IRR';
				if ( 200 === (int) ( $data['return']['status'] ?? 0 ) ) {
					$value = self::number( $data['entries']['remaincredit'] ?? null );
				}
				break;
			case 'ippanel':
				if ( true === ( $data['meta']['status'] ?? false ) ) {
					$value = self::number( $data['data']['credit'] ?? null );
				}
				break;
			case 'panelchi':
			case 'maxsms':
				$wallet = $data['data']['wallet'] ?? array();
				$unit = $wallet['unit'] ?? '';
				if ( in_array( $unit, array( 'SMS_UNIT', 'IR' ), true ) ) {
					$value = self::number( $wallet['availableBalance'] ?? null );
				}
				break;
			case 'smsir':
				if ( 1 === (int) ( $data['status'] ?? 0 ) ) {
					$value = self::number( $data['data'] ?? null );
				}
				break;
			case 'farazsms':
				$unit = 'IRT';
				if ( 'success' === ( $data['status'] ?? '' ) ) {
					$value = self::number( $data['data']['balanceAmount'] ?? null );
				}
				break;
		}
		return null === $value ? self::invalid() : array( 'value' => $value, 'unit' => $unit );
	}

	private static function invalid() {
		return new WP_Error( 'anar_balance_invalid', __( 'The provider did not return a valid balance. Check the connection settings.', 'anar-toolkit' ) );
	}

	public function fetch( $provider, $config ) {
		if ( ! self::configured( $provider, $config ) ) {
			return new WP_Error( 'anar_balance_setup', __( 'Complete the SMS account connection settings first.', 'anar-toolkit' ) );
		}
		$method = 'GET';
		$args = array( 'timeout' => 10, 'limit_response_size' => 32768, 'headers' => array( 'Accept' => 'application/json' ) );
		switch ( $provider ) {
			case 'melipayamak':
				$url = 'https://rest.payamak-panel.com/api/SendSMS/GetCredit';
				$method = 'POST';
				$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
				$args['body'] = array( 'username' => $config['username'], 'password' => $config['password'] );
				break;
			case 'kavenegar':
				$url = 'https://api.kavenegar.com/v1/' . rawurlencode( trim( $config['api_key'] ) ) . '/account/info.json';
				break;
			case 'ippanel':
				$url = 'https://edge.ippanel.com/v1/api/payment/credit/mine';
				$args['headers']['Authorization'] = preg_replace( '/^Bearer\s+/i', '', trim( $config['api_key'] ) );
				break;
			case 'panelchi':
			case 'maxsms':
				$url = 'https://api.panelchi.com/account/info';
				$args['headers']['Authorization'] = 'Bearer ' . preg_replace( '/^Bearer\s+/i', '', trim( $config['api_key'] ) );
				break;
			case 'smsir':
				$url = 'https://api.sms.ir/v1/credit';
				$args['headers']['X-API-KEY'] = trim( $config['api_key'] );
				break;
			case 'farazsms':
				$url = 'https://api.iranpayamak.com/ws/v1/account/balance';
				$args['headers']['Api-Key'] = trim( $config['balance_api_key'] );
				break;
		}
		$result = ( new HttpClient() )->request( $method, $url, $args );
		if ( is_wp_error( $result ) ) {
			// Never persist or render provider bodies, URLs containing keys, or transport details.
			return new WP_Error( 'anar_balance_connection', __( 'Could not retrieve the balance. Check the account connection or try again later.', 'anar-toolkit' ) );
		}
		return self::parse( $provider, $result['json'] );
	}
}
