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
