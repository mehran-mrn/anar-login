<?php
/**
 * Optional administrator-initiated registration through Anar's partner service.
 * The affiliate credential stays on MRNWP, never in this distributed plugin.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Partners;

use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class MeliSignup {

	const REFERRAL = 'https://melipayamak.com/?aff=SMVDR';
	const COUPON = 'MPSMVDR';
	const RELAY = 'https://mrnwp.ir/wp-json/mrnwp-partners/v1/melipayamak/';

	public function products() {
		$result = $this->request( 'GET', 'products' );
		if ( is_wp_error( $result ) || true !== ( $result['ready'] ?? false ) ) {
			return array( 'ready' => false );
		}
		$products = array();
		foreach ( (array) ( $result['products'] ?? array() ) as $product ) {
			if ( ! is_array( $product ) || ! is_numeric( $product['product_code'] ?? null ) || (int) $product['product_code'] < 1 || ! is_string( $product['product_title'] ?? null ) || '' === trim( $product['product_title'] ) ) {
				continue;
			}
			$products[] = array(
				'product_code' => absint( $product['product_code'] ),
				'product_title' => sanitize_text_field( $product['product_title'] ),
				'product_price' => is_numeric( $product['product_price'] ?? null ) ? (float) $product['product_price'] : null,
				'price_per_sms' => is_numeric( $product['price_per_sms'] ?? null ) ? (float) $product['price_per_sms'] : null,
			);
			if ( count( $products ) >= 50 ) {
				break;
			}
		}
		$ticket = is_string( $result['ticket'] ?? null ) ? $result['ticket'] : '';
		if ( ! $products || ! preg_match( '/^\d{10}\.[a-f0-9]{32}\.[a-f0-9]{64}$/D', $ticket ) ) {
			return array( 'ready' => false );
		}
		return array( 'ready' => true, 'products' => $products, 'ticket' => $ticket, 'price_unit' => in_array( $result['price_unit'] ?? '', array( 'IRR', 'IRT' ), true ) ? $result['price_unit'] : '' );
	}

	public static function payload( $input ) {
		if ( ! is_array( $input ) || true !== ( $input['consent'] ?? false ) || ! empty( $input['website'] ) ) {
			return new WP_Error( 'anar_meli_consent', __( 'Please agree to sending your registration details to MRNWP and Meli Payamak.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'first_name', 'last_name', 'mobile_number', 'ticket' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) || strlen( $input[ $key ] ) > 400 ) {
				return new WP_Error( 'anar_meli_fields', __( 'Check the registration fields and try again.', 'anar-toolkit' ), array( 'status' => 400 ) );
			}
		}
		if ( 0 !== preg_match( '/\p{Nd}/u', $input['first_name'] . $input['last_name'] ) ) {
			return new WP_Error( 'anar_meli_fields', __( 'First and last names must not contain digits.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		$phone = Identity::normalize( $input['mobile_number'] );
		$last = sanitize_text_field( $input['last_name'] );
		$code = $input['product_code'] ?? null;
		if ( is_wp_error( $phone ) || 'sms' !== $phone['channel'] || '' === $last || ! is_int( $code ) || $code < 1 || ! preg_match( '/^\d{10}\.[a-f0-9]{32}\.[a-f0-9]{64}$/D', $input['ticket'] ) ) {
			return new WP_Error( 'anar_meli_fields', __( 'Enter a last name, a valid Iranian mobile number and a plan.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		return array( 'first_name' => sanitize_text_field( $input['first_name'] ), 'last_name' => $last, 'mobile_number' => Identity::local_phone( $phone['identity'] ), 'product_code' => $code, 'ticket' => $input['ticket'], 'consent' => true );
	}

	public static function invoice_url( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'sign.melipayamak.com' !== ( $parts['host'] ?? '' ) || ! empty( $parts['port'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) || ! preg_match( '~^/invoice/[0-9]+/?$~D', $parts['path'] ?? '' ) ) {
			return '';
		}
		return $url;
	}

	public function register( $input ) {
		$payload = self::payload( $input );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$result = $this->request( 'POST', 'register', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$url = self::invoice_url( $result['invoice_url'] ?? '' );
		if ( ! $url ) {
			return new WP_Error( 'anar_meli_uncertain', __( 'Registration could not be confirmed. Check with Meli Payamak before submitting again.', 'anar-toolkit' ), array( 'status' => 502 ) );
		}
		return array( 'invoice_url' => $url, 'message' => __( 'Your invoice is ready. Continue on Meli Payamak to review the amount and pay. SMS setup is completed separately.', 'anar-toolkit' ) );
	}

	private function request( $method, $path, $payload = null ) {
		$args = array( 'method' => $method, 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 65536, 'headers' => array( 'Accept' => 'application/json' ) );
		if ( null !== $payload ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $payload );
		}
		$response = wp_safe_remote_request( self::RELAY . $path, $args );
		$failed = new WP_Error( 'anar_meli_unavailable', __( 'Registration could not be confirmed. Check with Meli Payamak before submitting again.', 'anar-toolkit' ), array( 'status' => 502 ) );
		if ( is_wp_error( $response ) ) {
			return $failed;
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			if ( in_array( $status, array( 400, 422 ), true ) ) {
				return new WP_Error( 'anar_meli_fields', __( 'Check the registration fields and try again.', 'anar-toolkit' ), array( 'status' => $status ) );
			}
			if ( 429 === $status ) {
				return new WP_Error( 'anar_meli_limit', __( 'Too many registration requests. Please try again later.', 'anar-toolkit' ), array( 'status' => 429 ) );
			}
			return $failed;
		}
		return $data;
	}
}
