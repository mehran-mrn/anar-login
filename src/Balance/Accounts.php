<?php
/**
 * Existing sender accounts, without copying credentials into balance options.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Balance;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class Accounts {

	public static function all() {
		$settings = new Settings();
		$registry = new ProviderRegistry( $settings );
		$uses = array();
		foreach ( \Anar\Login\Messaging\ProviderUsage::all() as $assignment ) {
			if ( $assignment['active'] ) {
				foreach ( $assignment['keys'] as $provider_key ) {
					$uses[ $provider_key ][] = $assignment['label'];
				}
			}
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
