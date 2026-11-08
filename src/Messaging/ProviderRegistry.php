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
use Anar\Login\Messaging\Providers\MaxSms;
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
	 * Shared HTTP client.
	 *
	 * @var HttpClient|null
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings.
	 * @param HttpClient|null $http     HTTP client shared by every provider.
	 */
	/**
	 * Request-wide registry for screens that list or validate many messages.
	 *
	 * @return self
	 */
	public static function shared() {
		static $instance;
		if ( ! $instance ) {
			$instance = new self( new Settings() );
		}

		return $instance;
	}

	public function __construct( Settings $settings, ?HttpClient $http = null ) {
		$this->settings = $settings;
		$this->http     = $http;
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

		$http            = $this->http ? $this->http : new HttpClient();
		$this->providers = array();
		foreach ( array( Kavenegar::class, Melipayamak::class, IPPanel::class, Panelchi::class, MaxSms::class, FarazSms::class, SmsIr::class, CustomHttp::class ) as $class ) {
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
	public function send( $recipient, $code, $message, $context = 'login_otp' ) {
		$key       = sanitize_key( $this->settings->get( 'sms_provider', '' ) );
		$binding = MessageSettings::stored( $context );
		if ( null === $binding && 'login_otp' !== $context && isset( MessageCatalog::all()[ $context ] ) && 'auth' === MessageCatalog::all()[ $context ]['source'] ) {
			$binding = MessageSettings::stored( 'login_otp' );
		}
		if ( null !== $binding ) {
			$identity = \Anar\Login\Support\Identity::normalize( $recipient );
			if ( is_wp_error( $identity ) || 'sms' !== $identity['channel'] ) {
				return new WP_Error( 'anar_invalid_recipient', __( 'A valid mobile number is required.', 'anar-toolkit' ) );
			}
			$recipient = $identity['identity'];
			$value = MessageSettings::get( $context );
			$key = $value['provider'];
			$errors = MessageSettings::errors( $context, $value );
			if ( $errors ) {
				return new WP_Error( 'anar_message_incomplete', implode( ' ', $errors ) );
			}
			$minutes = 'login_otp' === $context || 'two_factor' === $context ? max( 1, (int) ceil( $this->settings->get( 'otp_ttl', 120 ) / 60 ) ) : 5;
			$vars = array( 'code' => $code, 'site' => get_bloginfo( 'name' ), 'domain' => wp_parse_url( home_url(), PHP_URL_HOST ), 'minutes' => $minutes );
			$providers = $this->all();
			if ( 'custom' === $key && isset( $providers['custom'] ) ) {
				// Verification messages may use {code} alone, so the custom gateway receives both.
				$result = $providers['custom']->send( $recipient, $code, \Anar\Login\Commerce\Templates::render( $value['message'], $vars ) );
			} else {
				$result = ( new NotificationSender( $this->settings, $this->http ? $this->http : new HttpClient(), $this ) )->send( $key, $recipient, $value, $vars );
			}
			do_action( 'anar_toolkit_sms_result', $recipient, sanitize_key( $context ), $key, $result );
			return $result;
		}
		$providers = $this->all();
		if ( empty( $providers[ $key ] ) ) {
			return new WP_Error( 'anar_provider_missing', __( 'The selected SMS provider is unavailable.', 'anar-toolkit' ) );
		}

		$result = $providers[ $key ]->send( $recipient, $code, $message );
		do_action( 'anar_toolkit_sms_result', $recipient, sanitize_key( $context ), $key, $result );
		return $result;
	}
}
