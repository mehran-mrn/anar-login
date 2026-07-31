<?php
/**
 * Identity normalization helpers.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Identity {
	/**
	 * Normalize an email address or Iranian mobile number.
	 *
	 * @param string $value Raw input.
	 * @return array{identity:string,channel:string}|WP_Error
	 */
	public static function normalize( $value ) {
		$value = trim( self::latin_digits( (string) $value ) );

		if ( false !== strpos( $value, '@' ) ) {
			$email = sanitize_email( $value );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'anar_invalid_identity', __( 'نشانی ایمیل معتبر نیست.', 'anar-login' ), array( 'status' => 400 ) );
			}

			return array(
				'identity' => strtolower( $email ),
				'channel'  => 'email',
			);
		}

		$phone = preg_replace( '/[^\d+]/', '', $value );
		$phone = preg_replace( '/^(?:\+98|0098|98|0)?/', '', (string) $phone );

		if ( ! preg_match( '/^9\d{9}$/', $phone ) ) {
			return new WP_Error( 'anar_invalid_identity', __( 'شماره موبایل ایرانی معتبر نیست.', 'anar-login' ), array( 'status' => 400 ) );
		}

		$normalized = '+98' . $phone;
		$normalized = (string) apply_filters( 'anar_login_normalized_phone', $normalized, $value );

		return array(
			'identity' => $normalized,
			'channel'  => 'sms',
		);
	}

	/**
	 * Convert Persian and Arabic numerals to Latin.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	public static function latin_digits( $value ) {
		return strtr(
			$value,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
	}

	/**
	 * Convert canonical +98 number to local 09 format.
	 *
	 * @param string $phone Canonical phone.
	 * @return string
	 */
	public static function local_phone( $phone ) {
		return '0' . preg_replace( '/^\+98/', '', $phone );
	}
}
