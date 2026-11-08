<?php
/**
 * Shared provider behavior.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

abstract class AbstractProvider implements SmsProviderInterface {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	protected $http;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings.
	 * @param HttpClient $http     HTTP client.
	 */
	public function __construct( Settings $settings, HttpClient $http ) {
		$this->settings = $settings;
		$this->http     = $http;
	}

	/**
	 * Short description for the provider picker.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Service template delivery', 'anar-toolkit' );
	}

	/**
	 * Send a login code through the configured approved template.
	 *
	 * Every built-in transport goes through send_template(), so OTP and commerce
	 * messages share one request/response implementation per provider.
	 *
	 * @param string $recipient Canonical phone.
	 * @param string $code      OTP.
	 * @param string $message   Rendered fallback message.
	 * @return true|WP_Error
	 */
	public function send( $recipient, $code, $message ) {
		$config = $this->config();
		$ready  = $this->require_config( $config, array_merge( $this->template_credentials(), array( 'template_id' ) ) );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return $this->send_template( $recipient, (string) $config['template_id'], array( $this->code_parameter() => (string) $code ) );
	}

	/**
	 * Send through an approved panel template (pattern).
	 *
	 * @param string               $recipient Canonical phone.
	 * @param string               $template  Template/pattern identifier.
	 * @param array<string,string> $params    Template variables by panel name.
	 * @return true|WP_Error
	 */
	public function send_template( $recipient, $template, $params ) {
		return new WP_Error( 'anar_template_unavailable', __( 'This provider does not support approved-template delivery.', 'anar-toolkit' ) );
	}

	/**
	 * Send free text from the account's own sender line.
	 *
	 * @param string $recipient Canonical phone.
	 * @param string $text      Message text.
	 * @return true|WP_Error
	 */
	public function send_text( $recipient, $text ) {
		return new WP_Error( 'anar_text_unavailable', __( 'Plain-text delivery needs a supported provider and sender number.', 'anar-toolkit' ) );
	}

	/**
	 * Credentials required for approved-template delivery.
	 *
	 * @return string[]
	 */
	public function template_credentials() {
		return array();
	}

	/**
	 * Credentials required for plain-text delivery, or null when unsupported.
	 *
	 * @return string[]|null
	 */
	public function text_credentials() {
		return null;
	}

	/**
	 * Allowed template variable names, or null when the panel accepts any name.
	 *
	 * @return string[]|null
	 */
	public function parameter_names() {
		return null;
	}

	/**
	 * Template variable that carries the login code.
	 *
	 * @return string
	 */
	protected function code_parameter() {
		$config = $this->config();

		return ! empty( $config['code_parameter'] ) ? (string) $config['code_parameter'] : 'code';
	}

	/**
	 * Provider settings.
	 *
	 * @return array<string,string>
	 */
	protected function config() {
		return $this->settings->provider( $this->key() );
	}

	/**
	 * Validate required configuration values.
	 *
	 * @param array<string,string> $config Configuration.
	 * @param string[]             $required Required keys.
	 * @return true|WP_Error
	 */
	protected function require_config( $config, $required ) {
		foreach ( $required as $field ) {
			if ( empty( $config[ $field ] ) ) {
				return new WP_Error(
					'anar_sms_not_configured',
					sprintf(
						/* translators: %s: provider title */
						__( 'The %s provider settings are incomplete.', 'anar-toolkit' ),
						$this->title()
					)
				);
			}
		}

		return true;
	}

	/**
	 * Convert canonical phone to Iranian local format.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	protected function local_phone( $phone ) {
		return Identity::local_phone( $phone );
	}

	/**
	 * Generic gateway error.
	 *
	 * @param string $detail Provider response.
	 * @return WP_Error
	 */
	protected function gateway_error( $detail = '' ) {
		return new WP_Error(
			'anar_sms_rejected',
			sprintf(
				/* translators: %s: provider title */
				__( 'The SMS message was not accepted by %s.', 'anar-toolkit' ),
				$this->title()
			),
			array( 'detail' => substr( wp_strip_all_tags( (string) $detail ), 0, 300 ) )
		);
	}
}
