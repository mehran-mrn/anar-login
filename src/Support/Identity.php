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
				return new WP_Error( 'anar_invalid_identity', __( 'Please enter a valid email address.', 'anar-toolkit' ), array( 'status' => 400 ) );
			}

			return array(
				'identity' => strtolower( $email ),
				'channel'  => 'email',
			);
		}

		$phone = (string) preg_replace( '/[^\d+]/', '', $value );

		// The prefix is optional, so a bare 10-digit number such as 989xxxxxxxx is never truncated.
		if ( ! preg_match( '/^(?:\+98|0098|98|0)?(9\d{9})$/', $phone, $matches ) ) {
			return new WP_Error( 'anar_invalid_identity', __( 'Please enter a valid Iranian mobile number.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}

		$normalized = '+98' . $matches[1];
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

	/**
	 * Mask an identity for display, e.g. 0912•••4567 or j•••@example.com.
	 *
	 * @param string $identity Canonical phone or email.
	 * @return string
	 */
	public static function mask( $identity ) {
		if ( false !== strpos( $identity, '@' ) ) {
			list( $local, $domain ) = explode( '@', $identity, 2 );

			return substr( $local, 0, 1 ) . '•••@' . $domain;
		}

		$local = self::local_phone( $identity );

		return substr( $local, 0, 4 ) . '•••' . substr( $local, -4 );
	}
}
