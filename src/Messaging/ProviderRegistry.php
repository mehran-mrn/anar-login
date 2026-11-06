<?php
/**
 * SMS provider registry.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\Providers\CustomHttp;
use Anar\Login\Messaging\Providers\FarazSms;
use Anar\Login\Messaging\Providers\IPPanel;
use Anar\Login\Messaging\Providers\Kavenegar;
use Anar\Login\Messaging\Providers\Melipayamak;
use Anar\Login\Messaging\Providers\Panelchi;
use Anar\Login\Messaging\Providers\SmsIr;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ProviderRegistry {
	/**
	 * Providers.
	 *
	 * @var array<string,SmsProviderInterface>|null
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
	}

	/**
	 * All providers, built on first use.
	 *
	 * Lazy loading keeps ordinary page views free of provider objects and lets
	 * themes register providers from functions.php, which loads after plugins.
	 *
	 * @return array<string,SmsProviderInterface>
	 */
	public function all() {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$http            = new HttpClient();
		$this->providers = array();
		foreach ( array( Kavenegar::class, Melipayamak::class, IPPanel::class, Panelchi::class, FarazSms::class, SmsIr::class, CustomHttp::class ) as $class ) {
			$provider                            = new $class( $this->settings, $http );
			$this->providers[ $provider->key() ] = $provider;
		}

		/**
		 * Filter all registered SMS providers.
		 *
		 * @param array<string,SmsProviderInterface> $providers Providers by key.
		 * @param Settings                           $settings  Settings service.
		 */
		$this->providers = (array) apply_filters( 'anar_login_sms_providers', $this->providers, $this->settings );

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
		$key       = sanitize_key( $this->settings->get( 'sms_provider', '' ) );
		$providers = $this->all();
		if ( empty( $providers[ $key ] ) ) {
			return new WP_Error( 'anar_provider_missing', __( 'The selected SMS provider is unavailable.', 'anar-toolkit' ) );
		}

		return $providers[ $key ]->send( $recipient, $code, $message );
	}
}
