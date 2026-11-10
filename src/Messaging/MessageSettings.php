<?php
/**
 * One saved binding per message; existing settings remain a read-only fallback.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Commerce\Templates;
use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class MessageSettings {
	const OPTION = 'anar_toolkit_messages';

	public static function variant( $id ) {
		return in_array( 'coupon_code', MessageCatalog::required( $id ), true ) ? 'gift' : 'plain';
	}

	private static function name( $id, $variant ) {
		$variant = $variant ?? self::variant( $id );
		return self::OPTION . '_' . sanitize_key( $id ) . ( 'gift' === $variant && MessageCatalog::variants( $id ) ? '_gift' : '' );
	}

	public static function stored( $id, $variant = null ) {
		$value = get_option( self::name( $id, $variant ), null );
		return is_array( $value ) ? $value : null;
	}

	public static function save( $id, $value, $expected, $variant = null ) {
		global $wpdb;
		if ( ! isset( MessageCatalog::all()[ $id ] ) || ! hash_equals( self::fingerprint( self::get( $id, $variant ) ), $expected ) ) {
			return false;
		}
		$name = self::name( $id, $variant );
		$value['account'] = self::account( $value['provider'] );
		$old = self::stored( $id, $variant );
		if ( null === $old ) {
			return add_option( $name, $value, '', false );
		}
		if ( $old === $value ) {
			return true;
		}
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, maybe_serialize( $value ), $name, maybe_serialize( $old ) ) );
		wp_cache_delete( $name, 'options' );
		return 1 === $updated;
	}

	public static function get( $id, $variant = null ) {
		$legacy = MessageCatalog::legacy( $id );
		$variant = $variant ?? self::variant( $id );
		$saved = self::stored( $id, $variant );
		$value = array_replace( $legacy, $saved ?? array() );
		$value['_variant'] = $variant;
		$value['account_changed'] = isset( $value['account'] ) && ! hash_equals( $value['account'], self::account( $value['provider'] ) );
		if ( ! empty( $value['inherit'] ) && 'login_otp' !== $id ) {
			$value = array_replace( $value, self::get( 'login_otp' ) );
			$value['inherit'] = true;
		}
		return $value;
	}

	public static function apply( $id, $event ) {
		if ( ! isset( MessageCatalog::all()[ $id ] ) ) {
			return $event;
		}
		return array_replace( $event, self::get( $id ) );
	}

	public static function sanitize( $raw ) {
		$value = array();
		foreach ( array( 'provider', 'mode', 'template', 'params', 'message' ) as $key ) {
			$value[ $key ] = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_textarea_field( (string) $raw[ $key ] ) : '';
		}
		$value['provider'] = sanitize_key( $value['provider'] );
		$value['mode'] = 'text' === $value['mode'] ? 'text' : 'template';
		$value['inherit'] = ! empty( $raw['inherit'] );
		return $value;
	}

	public static function fingerprint( $value ) {
		$config = self::configuration( $value['provider'] ?? '' );
		return hash_hmac( 'sha256', wp_json_encode( array( $value, $config ) ), wp_salt( 'auth' ) );
	}

	private static function account( $provider ) {
		return hash_hmac( 'sha256', wp_json_encode( self::configuration( $provider ) ), wp_salt( 'auth' ) );
	}

	private static function configuration( $provider ) {
		$config = ( new Settings() )->provider( $provider );
		unset( $config['template_id'], $config['code_parameter'] );
		ksort( $config );
		return $config;
	}

	public static function uses_custom_code_only( $config ) {
		$fields = ( $config['params'] ?? '' ) . ( $config['url'] ?? '' );
		return false !== strpos( $fields, '{code}' ) && false === strpos( $fields . ( $config['headers'] ?? '' ), '{message}' );
	}

	public static function errors( $id, $value ) {
		$errors = array();
		$item = MessageCatalog::all()[ $id ] ?? null;
		if ( ! $item ) {
			return array( __( 'This message is unavailable.', 'anar-toolkit' ) );
		}
		$registry = ProviderRegistry::shared()->all();
		if ( ! empty( $value['account_changed'] ) ) {
			$errors[] = __( 'The provider account changed. Review and save this message again.', 'anar-toolkit' );
		}
		$transport = $registry[ $value['provider'] ?? '' ] ?? null;
		$text_credentials = $transport instanceof AbstractProvider ? $transport->text_credentials() : null;
		$credentials = $transport instanceof AbstractProvider ? ( 'text' === ( $value['mode'] ?? '' ) ? (array) $text_credentials : $transport->template_credentials() ) : array();
		$parameter_names = $transport instanceof AbstractProvider ? $transport->parameter_names() : null;
		$config = ( new Settings() )->provider( $value['provider'] ?? '' );
		if ( 'auth' === $item['source'] && 'custom' === ( $value['provider'] ?? '' ) && false === strpos( ( $config['params'] ?? '' ) . ( $config['url'] ?? '' ), '{message}' ) && false === strpos( ( $config['params'] ?? '' ) . ( $config['url'] ?? '' ), '{code}' ) ) {
			$errors[] = __( 'The custom gateway must include {code} or {message} for verification messages.', 'anar-toolkit' );
		}
		if ( 'auth' !== $item['source'] && 'custom' === ( $value['provider'] ?? '' ) && false === strpos( ( $config['params'] ?? '' ) . ( $config['url'] ?? '' ), '{message}' ) ) {
			$errors[] = __( 'The custom gateway must use the {message} placeholder for commerce messages.', 'anar-toolkit' );
		}
		foreach ( 'text' === ( $value['mode'] ?? '' ) ? array_diff( $credentials, array( 'from' ) ) : $credentials as $credential ) {
			if ( empty( $config[ $credential ] ) ) {
				$errors[] = __( 'Complete the provider connection settings first.', 'anar-toolkit' );
				break;
			}
		}
		if ( 'text' === ( $value['mode'] ?? '' ) && in_array( 'from', $credentials, true ) && empty( $config['from'] ) ) {
			$errors[] = __( 'Plain-text delivery needs a supported provider and sender number.', 'anar-toolkit' );
		}
		if ( ! isset( $registry[ $value['provider'] ?? '' ] ) ) {
			$errors[] = __( 'The selected SMS provider is unavailable.', 'anar-toolkit' );
		}
		if ( 'text' === ( $value['mode'] ?? '' ) && $transport && null === $text_credentials ) {
			$errors[] = __( 'This provider does not support plain-text delivery here.', 'anar-toolkit' );
		}
		if ( 'template' === ( $value['mode'] ?? '' ) && ( ! trim( $value['template'] ?? '' ) || ! trim( $value['params'] ?? '' ) ) ) {
			$errors[] = __( 'Enter the approved template and its variables.', 'anar-toolkit' );
		}
		if ( 'custom' === $value['provider'] && 'template' === $value['mode'] ) {
			$errors[] = __( 'The custom gateway uses the message text.', 'anar-toolkit' );
		}
		if ( 'auth' === $item['source'] && 'custom' === $value['provider'] && 'text' === $value['mode'] && self::uses_custom_code_only( $config ) ) {
			return array_unique( $errors );
		}
		$text = 'text' === ( $value['mode'] ?? '' ) ? ( $value['message'] ?? '' ) : ( $value['params'] ?? '' );
		foreach ( MessageCatalog::required( $id, $value['_variant'] ?? null ) as $required ) {
			if ( false === strpos( $text, '{' . $required . '}' ) ) {
				$errors[] = sprintf( /* translators: %s: required placeholder. */ __( 'Include the required variable %s.', 'anar-toolkit' ), '{' . $required . '}' );
			}
		}
		if ( 'template' === ( $value['mode'] ?? '' ) ) {
			$names = array();
			foreach ( preg_split( '/\r?\n/', trim( $text ) ) as $line ) {
				if ( ! $line ) {
					continue;
				}
				if ( ! preg_match( '/^([a-zA-Z0-9_]{1,40})=(.+)$/', trim( $line ), $match ) || isset( $names[ $match[1] ?? '' ] ) ) {
					$errors[] = __( 'Each variable needs a unique panel name and a value.', 'anar-toolkit' );
					continue;
				}
				$names[ $match[1] ] = true;
				if ( null !== $parameter_names && ! in_array( $match[1], $parameter_names, true ) ) {
					$errors[] = __( 'Kavenegar accepts token, token2, token3, token10 and token20.', 'anar-toolkit' );
				}
			}
		}
		preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $text, $matches );
		foreach ( array_unique( $matches[1] ) as $name ) {
			if ( ! in_array( $name, $item['variables'], true ) ) {
				$errors[] = sprintf( /* translators: %s: unknown placeholder. */ __( 'This message does not provide %s.', 'anar-toolkit' ), '{' . $name . '}' );
			}
		}
		if ( 'text' === ( $value['mode'] ?? '' ) && ! trim( $text ) ) {
			$errors[] = __( 'Enter the message text.', 'anar-toolkit' );
		}
		return array_unique( $errors );
	}

	public static function preview( $id, $value ) {
		$vars = array_fill_keys( MessageCatalog::all()[ $id ]['variables'] ?? array(), __( 'Sample', 'anar-toolkit' ) );
		$vars = array_replace( $vars, array( 'code' => '123456', 'site' => get_bloginfo( 'name' ), 'domain' => 'example.test', 'minutes' => '5', 'name' => __( 'Customer', 'anar-toolkit' ), 'order_id' => '1042', 'coupon_code' => 'SAMPLE-10', 'unsubscribe_url' => 'https://example.test/stop', 'action_url' => 'https://example.test/action', 'recovery_page' => 'https://example.test/cart', 'message' => __( 'Sample campaign content', 'anar-toolkit' ) ) );
		return array( 'variables' => $vars, 'text' => Templates::render( $value['message'], $vars ), 'params' => Templates::parameters( $value['params'], $vars ) );
	}
}
