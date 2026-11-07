<?php
/**
 * Existing sender accounts, without copying credentials into balance options.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Balance;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Messaging\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class Accounts {

	public static function all() {
		$settings = new Settings();
		$registry = new ProviderRegistry( $settings );
		$flags = Features::all();
		$commerce = (array) get_option( 'anar_toolkit_commerce', array() );
		$marketing = (array) get_option( 'anar_toolkit_marketing', array() );
		$uses = array();
		if ( $flags['login'] || $flags['reset'] || $flags['two_factor'] ) {
			$uses[ $settings->get( 'sms_provider' ) ][] = __( 'Sign-in and recovery', 'anar-toolkit' );
		}
		if ( $flags['orders'] || $flags['recovery'] ) {
			$uses[ $commerce['provider'] ?? 'kavenegar' ][] = __( 'Orders and abandoned carts', 'anar-toolkit' );
		}
		if ( Features::queue_needed() && Features::marketing() ) {
			$uses[ ( $marketing['provider'] ?? '' ) ?: ( $commerce['provider'] ?? 'kavenegar' ) ][] = __( 'Marketing messages', 'anar-toolkit' );
		}
		$accounts = array();
		foreach ( $registry->all() as $key => $provider ) {
			$config = $settings->provider( $key );
			$supported = in_array( $key, Gateways::providers(), true );
			$accounts[ $key ] = array( 'title' => $provider->title(), 'supported' => $supported, 'ready' => Gateways::configured( $key, $config ), 'uses' => $uses[ $key ] ?? array() );
		}
		uasort(
			$accounts,
			static function ( $left, $right ) {
				return (int) ! empty( $right['uses'] ) <=> (int) ! empty( $left['uses'] );
			}
		);
		return $accounts;
	}

	public static function fingerprint( $provider, $config = null ) {
		$config = null === $config ? ( new Settings() )->provider( $provider ) : $config;
		if ( ! Gateways::configured( $provider, $config ) ) {
			return '';
		}
		$credentials = array_intersect_key( $config, array_flip( Gateways::fields( $provider ) ) );
		ksort( $credentials );
		return hash_hmac( 'sha256', $provider . '|' . wp_json_encode( $credentials ), wp_salt( 'auth' ) );
	}

	public static function portal( $provider ) {
		$urls = array( 'melipayamak' => 'https://panel.melipayamak.com/', 'kavenegar' => 'https://console.kavenegar.com/', 'ippanel' => 'https://ippanel.com/', 'panelchi' => 'https://panelchi.com/', 'maxsms' => 'https://maxsms.co/', 'smsir' => 'https://app.sms.ir/', 'farazsms' => 'https://panel.farazsms.com/' );
		return $urls[ $provider ] ?? '';
	}
}
