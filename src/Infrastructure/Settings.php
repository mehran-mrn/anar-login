<?php
/**
 * Settings access.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Settings {
	const OPTION_KEY = 'anar_login_settings';

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		return array(
			'allow_registration'  => 1,
			'default_role'        => 'subscriber',
			'login_redirect'      => '',
			'otp_length'          => 5,
			'otp_ttl'             => 120,
			'resend_delay'        => 60,
			'max_requests_hour'   => 5,
			'max_verify_attempts' => 5,
			'sms_provider'        => 'kavenegar',
			'sms_message'         => 'کد ورود شما به {site}: {code}',
			'email_subject'       => 'کد ورود به {site}',
			'email_message'       => "کد ورود شما: {code}\nاین کد تا {minutes} دقیقه معتبر است.",
			'google_enabled'      => 0,
			'google_client_id'    => '',
			'google_secret'       => '',
			'brand_color'         => '#d81b3f',
			'panel_page_id'       => 0,
			'delete_data'         => 0,
			'debug_log'           => 0,
		);
	}

	/**
	 * Return all settings.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		$value = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( is_array( $value ) ? $value : array(), $this->defaults() );
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Return provider-specific settings.
	 *
	 * @param string $provider Provider key.
	 * @return array<string,string>
	 */
	public function provider( $provider ) {
		$settings = $this->all();
		$key      = 'provider_' . sanitize_key( $provider );
		$value    = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();

		return $value;
	}

	/**
	 * Sanitize settings before storage.
	 *
	 * @param mixed $input Raw settings.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->all();
		$defaults = $this->defaults();
		$output   = array();

		$checkboxes = array( 'allow_registration', 'google_enabled', 'delete_data', 'debug_log' );
		foreach ( $checkboxes as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$output['default_role']        = isset( $input['default_role'] ) && get_role( sanitize_key( $input['default_role'] ) ) ? sanitize_key( $input['default_role'] ) : 'subscriber';
		$output['login_redirect']      = isset( $input['login_redirect'] ) ? esc_url_raw( $input['login_redirect'] ) : '';
		$output['otp_length']          = max( 4, min( 8, absint( $input['otp_length'] ?? $defaults['otp_length'] ) ) );
		$output['otp_ttl']             = max( 60, min( 600, absint( $input['otp_ttl'] ?? $defaults['otp_ttl'] ) ) );
		$output['resend_delay']        = max( 30, min( 300, absint( $input['resend_delay'] ?? $defaults['resend_delay'] ) ) );
		$output['max_requests_hour']   = max( 2, min( 30, absint( $input['max_requests_hour'] ?? $defaults['max_requests_hour'] ) ) );
		$output['max_verify_attempts'] = max( 3, min( 10, absint( $input['max_verify_attempts'] ?? $defaults['max_verify_attempts'] ) ) );
		$output['sms_provider']        = sanitize_key( $input['sms_provider'] ?? $defaults['sms_provider'] );
		$output['sms_message']         = sanitize_textarea_field( $input['sms_message'] ?? $defaults['sms_message'] );
		$output['email_subject']       = sanitize_text_field( $input['email_subject'] ?? $defaults['email_subject'] );
		$output['email_message']       = sanitize_textarea_field( $input['email_message'] ?? $defaults['email_message'] );
		$output['google_client_id']    = sanitize_text_field( $input['google_client_id'] ?? '' );
		$brand_color                   = sanitize_hex_color( $input['brand_color'] ?? '' );
		$output['brand_color']         = $brand_color ? $brand_color : $defaults['brand_color'];
		$output['panel_page_id']       = absint( $input['panel_page_id'] ?? 0 );

		// Preserve the stored secret when the field is intentionally left blank.
		$output['google_secret'] = ! empty( $input['google_secret'] )
			? sanitize_text_field( $input['google_secret'] )
			: (string) ( $current['google_secret'] ?? '' );

		foreach ( array( 'kavenegar', 'melipayamak', 'ippanel', 'farazsms', 'smsir' ) as $provider ) {
			$key       = 'provider_' . $provider;
			$raw       = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$old       = isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? $current[ $key ] : array();
			$sanitized = array();

			foreach ( array( 'api_key', 'username', 'password', 'from', 'template_id', 'code_parameter' ) as $field ) {
				if ( in_array( $field, array( 'api_key', 'password' ), true ) && empty( $raw[ $field ] ) ) {
					$sanitized[ $field ] = (string) ( $old[ $field ] ?? '' );
				} else {
					$sanitized[ $field ] = sanitize_text_field( $raw[ $field ] ?? '' );
				}
			}

			$output[ $key ] = $sanitized;
		}

		return $output;
	}
}
