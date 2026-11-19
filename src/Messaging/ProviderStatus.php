<?php
/**
 * Local connection completeness, without contacting an external service.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderStatus {
	public static function ready( $provider, ?Settings $settings = null ) {
		$settings = $settings ?: new Settings();
		$config = $settings->provider( $provider->key() );
		return self::ready_config( $provider, $config );
	}

	/** Validate draft credentials without replacing the site's active option. */
	public static function ready_config( $provider, $config ) {
		if ( $provider instanceof AbstractProvider ) {
			$required = 'custom' === $provider->key() ? array( 'url' ) : $provider->template_credentials();
		} else {
			$required = array_diff( array_keys( $provider->fields() ), array( 'template_id', 'code_parameter', 'balance_api_key' ) );
		}
		$ready = true;
		foreach ( $required as $field ) {
			if ( '' === trim( (string) ( $config[ $field ] ?? '' ) ) ) {
				$ready = false;
			}
		}
		if ( 'bale' === $provider->key() && ! empty( $config['api_key'] ) && ! preg_match( '/^[1-9][0-9]*$/D', (string) ( $config['bot_id'] ?? '' ) ) ) {
			$ready = false;
		}
		if ( 'custom' === $provider->key() ) {
			$ready = $ready && (bool) preg_match( '#^https?://[^/\s]+#i', $config['url'] ?? '' );
		}
		return (bool) apply_filters( 'anar_toolkit_provider_connection_ready', $ready, $provider, $config );
	}

	/** Stable grouping: configured connections always precede incomplete ones. */
	public static function sorted( $providers, $sms_only = true ) {
		$ready = array();
		$incomplete = array();
		foreach ( $providers as $key => $provider ) {
			if ( $sms_only && 'bale' === $key ) {
				continue;
			}
			if ( self::ready( $provider ) ) {
				$ready[ $key ] = $provider;
			} else {
				$incomplete[ $key ] = $provider;
			}
		}
		return $ready + $incomplete;
	}

	public static function label( $provider ) {
		return $provider->title() . ( self::ready( $provider ) ? '' : ' — ⚠ ' . __( 'Connection incomplete', 'anar-toolkit' ) );
	}
}
