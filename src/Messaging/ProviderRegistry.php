<?php
/**
 * SMS provider registry.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\Providers\FarazSms;
use Anar\Login\Messaging\Providers\IPPanel;
use Anar\Login\Messaging\Providers\Kavenegar;
use Anar\Login\Messaging\Providers\Melipayamak;
use Anar\Login\Messaging\Providers\SmsIr;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	/**
	 * Providers.
	 *
	 * @var array<string,SmsProviderInterface>
	 */
	private $providers;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$http           = new HttpClient();
		$providers      = array(
			new Kavenegar( $settings, $http ),
			new Melipayamak( $settings, $http ),
			new IPPanel( $settings, $http ),
			new FarazSms( $settings, $http ),
			new SmsIr( $settings, $http ),
		);
		$this->providers = array();

		foreach ( $providers as $provider ) {
			$this->providers[ $provider->key() ] = $provider;
		}

		/**
		 * Filter all registered SMS providers.
		 *
		 * @param array<string,SmsProviderInterface> $providers Providers by key.
		 * @param Settings                           $settings  Settings service.
		 */
		$this->providers = apply_filters( 'anar_login_sms_providers', $this->providers, $settings );
	}

	/**
	 * All providers.
	 *
	 * @return array<string,SmsProviderInterface>
	 */
	public function all() {
		return $this->providers;
	}

	/**
	 * Send with active provider.
	 *
	 * @param string $recipient Recipient.
	 * @param string $code      OTP.
	 * @param string $message   Message.
	 * @return true|WP_Error
	 */
	public function send( $recipient, $code, $message ) {
		$key = sanitize_key( $this->settings->get( 'sms_provider', '' ) );
		if ( empty( $this->providers[ $key ] ) ) {
			return new WP_Error( 'anar_provider_missing', __( 'The selected SMS provider is unavailable.', 'anar-login' ) );
		}

		return $this->providers[ $key ]->send( $recipient, $code, $message );
	}
}
