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
	 * Memoized defaults, so translations are looked up once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private $defaults;

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		if ( null !== $this->defaults ) {
			return $this->defaults;
		}

		$this->defaults = array(
			'allow_registration'  => 1,
			'identity_mode'       => 'both',
			'default_role'        => 'subscriber',
			'login_redirect'      => '',
			'otp_length'          => 5,
			'otp_ttl'             => 120,
			'resend_delay'        => 60,
			'max_requests_hour'   => 5,
			'max_verify_attempts' => 5,
			'sms_provider'        => 'kavenegar',
			'sms_message'         => __( 'Your {site} login code is: {code}', 'anar-toolkit' ),
			'email_subject'       => __( 'Your login code for {site}', 'anar-toolkit' ),
			'email_message'       => __( "Your login code is: {code}\nThis code is valid for {minutes} minutes.", 'anar-toolkit' ),
			'google_enabled'      => 0,
			'google_client_id'    => '',
			'google_secret'       => '',
			'brand_color'         => '#d81b3f',
			'panel_page_id'       => 0,
			'delete_data'         => 0,
			'debug_log'           => 0,
			'popup_enabled'       => 0,
			'replace_theme_login' => 1,
			'replace_wp_login'    => 0,
			'form_logo'           => 'site',
			'form_logo_url'       => '',
			'two_factor'          => 'off',
			'password_reset_enabled' => 1,
			'wc_account_mode'     => 'replace',
			'wc_checkout_login'   => 1,
			'wc_sync_phone'       => 1,
			'wc_customer_role'    => 1,
		);

		return $this->defaults;
	}

	/**
	 * Normalize a provider field definition.
	 *
	 * @param string|array<string,mixed> $definition Label string or definition array.
	 * @return array{label:string,type:string,options:array<string,string>,placeholder:string,help:string}
	 */
	public static function field_definition( $definition ) {
		$definition = is_array( $definition ) ? $definition : array( 'label' => (string) $definition );
		$type       = isset( $definition['type'] ) ? (string) $definition['type'] : 'text';

		return array(
			'label'       => (string) ( $definition['label'] ?? '' ),
			'type'        => in_array( $type, array( 'text', 'url', 'textarea', 'select' ), true ) ? $type : 'text',
			'options'     => isset( $definition['options'] ) ? (array) $definition['options'] : array(),
			'placeholder' => (string) ( $definition['placeholder'] ?? '' ),
			'help'        => (string) ( $definition['help'] ?? '' ),
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
	 * Provider field names that are write-only secrets in the admin UI.
	 *
	 * @return string[]
	 */
	public function secret_fields() {
		/**
		 * Filter provider field names that are never displayed again once saved.
		 *
		 * @param string[] $fields Field names.
		 */
		return (array) apply_filters( 'anar_login_secret_provider_fields', array( 'api_key', 'password', 'token', 'secret', 'balance_api_key' ) );
	}

	/**
	 * Whether a role is safe to hand out to self-registered users.
	 *
	 * Only the built-in subscriber/customer roles with basic read access are allowed.
	 * Check the actual capabilities too: a role editor may have elevated either role.
	 *
	 * @param string $role Role slug.
	 * @return bool
	 */
	public static function is_assignable_role( $role ) {
		if ( ! is_string( $role ) || ! in_array( $role, array( 'subscriber', 'customer' ), true ) ) {
			return false;
		}

		$object = get_role( $role );
		if ( ! $object || ! $object->has_cap( 'read' ) ) {
			return false;
		}

		foreach ( $object->capabilities as $capability => $granted ) {
			if ( $granted && ! in_array( $capability, array( 'read', 'level_0' ), true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sanitize settings before storage.
	 *
	 * @param mixed                                                    $input     Raw settings.
	 * @param array<string,\Anar\Login\Messaging\SmsProviderInterface> $providers Registered providers, so custom provider fields are kept.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input, $providers = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = $this->all();
		$defaults = $this->defaults();
		$output   = array();

		$checkboxes = array( 'allow_registration', 'google_enabled', 'delete_data', 'debug_log', 'popup_enabled', 'replace_theme_login', 'replace_wp_login', 'password_reset_enabled' );
		foreach ( $checkboxes as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$form_logo               = sanitize_key( $input['form_logo'] ?? $defaults['form_logo'] );
		$output['form_logo']     = in_array( $form_logo, array( 'site', 'custom', 'icon', 'none' ), true ) ? $form_logo : 'site';
		$output['form_logo_url'] = esc_url_raw( (string) ( $input['form_logo_url'] ?? '' ) );

		// The WooCommerce tab only renders while WooCommerce is active; keep its values otherwise.
		$wc_present = ! empty( $input['wc_present'] );
		foreach ( array( 'wc_checkout_login', 'wc_sync_phone', 'wc_customer_role' ) as $key ) {
			$output[ $key ] = $wc_present ? ( empty( $input[ $key ] ) ? 0 : 1 ) : (int) $current[ $key ];
		}
		$wc_account_mode           = $wc_present ? sanitize_key( $input['wc_account_mode'] ?? '' ) : (string) $current['wc_account_mode'];
		$output['wc_account_mode'] = in_array( $wc_account_mode, array( 'replace', 'above', 'off' ), true ) ? $wc_account_mode : 'replace';

		$two_factor           = sanitize_key( $input['two_factor'] ?? $defaults['two_factor'] );
		$output['two_factor'] = in_array( $two_factor, array( 'off', 'privileged', 'all' ), true ) ? $two_factor : 'off';

		$identity_mode                = sanitize_key( $input['identity_mode'] ?? $defaults['identity_mode'] );
		$output['identity_mode']       = in_array( $identity_mode, array( 'both', 'phone', 'email' ), true ) ? $identity_mode : 'both';
		$output['default_role']        = isset( $input['default_role'] ) && self::is_assignable_role( $input['default_role'] ) ? sanitize_key( $input['default_role'] ) : 'subscriber';
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

		$provider_fields = array();
		foreach ( $providers as $provider_key => $provider ) {
			$provider_fields[ sanitize_key( $provider_key ) ] = (array) $provider->fields();
		}
		if ( empty( $provider_fields ) ) {
			$builtin_fields  = array_fill_keys( array( 'api_key', 'username', 'password', 'from', 'template_id', 'code_parameter', 'balance_api_key' ), '' );
			$provider_fields = array_fill_keys( array( 'kavenegar', 'melipayamak', 'ippanel', 'panelchi', 'maxsms', 'farazsms', 'smsir' ), $builtin_fields );
		}

		$secret_fields = $this->secret_fields();

		foreach ( $provider_fields as $provider => $fields ) {
			$key       = 'provider_' . $provider;
			$raw       = isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$old       = isset( $current[ $key ] ) && is_array( $current[ $key ] ) ? $current[ $key ] : array();
			$sanitized = array();

			foreach ( $fields as $field => $definition ) {
				$field = sanitize_key( $field );
				if ( in_array( $field, $secret_fields, true ) && empty( $raw[ $field ] ) ) {
					$sanitized[ $field ] = (string) ( $old[ $field ] ?? '' );
				} else {
					$sanitized[ $field ] = $this->sanitize_field( $raw[ $field ] ?? '', self::field_definition( $definition ) );
				}
			}

			$output[ $key ] = $sanitized;
		}

		return $output;
	}

	/**
	 * Sanitize one provider field by its type.
	 *
	 * URLs keep {placeholder} braces, which esc_url_raw() would strip.
	 *
	 * @param mixed               $value      Raw value.
	 * @param array<string,mixed> $definition Normalized definition.
	 * @return string
	 */
	private function sanitize_field( $value, $definition ) {
		// options.php has already unslashed the input; doing it again would break JSON escapes.
		$value = is_scalar( $value ) ? (string) $value : '';

		switch ( $definition['type'] ) {
			case 'select':
				$options = array_map( 'strval', array_keys( $definition['options'] ) );

				return in_array( $value, $options, true ) ? $value : (string) reset( $options );
			case 'textarea':
				return trim( str_replace( "\r", '', wp_strip_all_tags( $value ) ) );
			case 'url':
				$value = trim( wp_strip_all_tags( $value ) );

				return preg_match( '#^https?://#i', $value ) ? preg_replace( '/\s+/', '', $value ) : '';
			default:
				return sanitize_text_field( $value );
		}
	}
}
