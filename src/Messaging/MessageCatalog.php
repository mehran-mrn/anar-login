<?php
/**
 * Purpose-specific message definitions and legacy configuration adapters.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Commerce\Settings as CommerceSettings;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Marketing\Settings as MarketingSettings;

defined( 'ABSPATH' ) || exit;

final class MessageCatalog {
	public static function variable_labels() {
		return array( 'code' => __( 'Verification code', 'anar-toolkit' ), 'site' => __( 'Store name', 'anar-toolkit' ), 'domain' => __( 'Site domain', 'anar-toolkit' ), 'minutes' => __( 'Code validity in minutes', 'anar-toolkit' ), 'name' => __( 'Customer name', 'anar-toolkit' ), 'order_id' => __( 'Order number', 'anar-toolkit' ), 'total' => __( 'Order total', 'anar-toolkit' ), 'currency' => __( 'Store currency', 'anar-toolkit' ), 'status' => __( 'Order status', 'anar-toolkit' ), 'tracking_number' => __( 'Tracking number', 'anar-toolkit' ), 'tracking_url' => __( 'Tracking URL', 'anar-toolkit' ), 'carrier' => __( 'Shipping carrier', 'anar-toolkit' ), 'message' => __( 'Campaign content', 'anar-toolkit' ), 'product_name' => __( 'Product name', 'anar-toolkit' ), 'product_url' => __( 'Product URL', 'anar-toolkit' ), 'coupon_code' => __( 'Coupon code', 'anar-toolkit' ), 'coupon_amount' => __( 'Coupon amount', 'anar-toolkit' ), 'coupon_expires' => __( 'Coupon expiry', 'anar-toolkit' ), 'action_url' => __( 'Action URL', 'anar-toolkit' ), 'recovery_page' => __( 'Recovery page URL', 'anar-toolkit' ), 'unsubscribe_url' => __( 'Unsubscribe URL', 'anar-toolkit' ), 'prediction_date' => __( 'Predicted purchase date', 'anar-toolkit' ), 'confidence' => __( 'Prediction confidence', 'anar-toolkit' ) );
	}

	public static function groups() {
		return array( 'auth' => __( 'Sign-in and verification', 'anar-toolkit' ), 'orders' => __( 'Order notifications', 'anar-toolkit' ), 'recovery' => __( 'Abandoned carts', 'anar-toolkit' ), 'marketing' => __( 'Customer campaigns', 'anar-toolkit' ) );
	}

	public static function all() {
		$items = array();
		foreach ( array( 'login_otp' => __( 'Sign-in code', 'anar-toolkit' ), 'two_factor' => __( 'Two-step verification', 'anar-toolkit' ), 'password_reset' => __( 'SMS password recovery', 'anar-toolkit' ), 'game_phone' => __( 'Game phone verification', 'anar-toolkit' ), 'stock_verification' => __( 'Stock request verification', 'anar-toolkit' ) ) as $key => $label ) {
			$items[ $key ] = array( 'label' => $label, 'group' => 'auth', 'module' => array( 'login_otp' => 'login', 'two_factor' => 'two_factor', 'password_reset' => 'reset', 'game_phone' => 'games', 'stock_verification' => 'stock' )[ $key ], 'source' => 'auth', 'key' => $key, 'variables' => array( 'code', 'site', 'domain', 'minutes' ), 'required' => array( 'code' ) );
		}
		$labels = \Anar\Login\Commerce\Dashboard::labels();
		foreach ( ( new CommerceSettings() )->all()['events'] as $key => $event ) {
			$recovery = isset( $event['minutes'] );
			$variables = array( 'site', 'name', 'order_id', 'total', 'currency', 'status' );
			$variables = array_merge( $variables, $recovery ? array( 'code', 'minutes', 'recovery_page', 'unsubscribe_url', 'coupon_code', 'coupon_amount', 'coupon_expires' ) : array( 'tracking_number', 'carrier', 'tracking_url' ) );
			$items[ $key ] = array( 'label' => $labels[ $key ], 'group' => $recovery ? 'recovery' : 'orders', 'module' => $recovery ? 'recovery' : 'orders', 'source' => 'commerce', 'key' => $key, 'variables' => $variables, 'required' => $recovery ? array( 'code', 'recovery_page', 'unsubscribe_url' ) : array() );
		}
		$items['recovery_request'] = array_replace( $items['recovery_1'], array( 'label' => __( 'Requested recovery code', 'anar-toolkit' ), 'key' => 'recovery_request' ) );
		foreach ( ( new MarketingSettings() )->all()['rules'] as $key => $rule ) {
			$variables = array( 'site', 'name', 'message', 'unsubscribe_url' );
			if ( 'bulk' === $key ) {
				$variables[] = 'message';
			}
			if ( in_array( $key, array( 'smart', 'stock' ), true ) || 0 === strpos( $key, 'prediction_' ) ) {
				$variables = array_merge( $variables, array( 'product_name', 'product_url' ) );
			}
			if ( 0 === strpos( $key, 'prediction_' ) ) {
				$variables = array_merge( $variables, array( 'prediction_date', 'confidence' ) );
			}
			if ( in_array( $key, array( 'survey', 'invoice' ), true ) ) {
				$variables = array_merge( $variables, array( 'order_id', 'action_url' ) );
			}
			$variables[] = 'coupon_code';
			$required = array( 'unsubscribe_url' );
			if ( 'bulk' === $key ) {
				$required[] = 'message';
			}
			if ( in_array( $key, array( 'survey', 'invoice' ), true ) ) {
				$required[] = 'action_url';
			}
			$items[ 'mkt_' . $key ] = array( 'label' => \Anar\Login\Marketing\Engine::label( $key ), 'group' => 'marketing', 'module' => \Anar\Login\Marketing\Engine::module( $key ), 'source' => 'marketing', 'key' => $key, 'variables' => $variables, 'required' => $required );
		}
		return $items;
	}

	public static function legacy( $id ) {
		$item = self::all()[ $id ] ?? null;
		if ( ! $item ) {
			return array();
		}
		$login = new LoginSettings();
		if ( 'auth' === $item['source'] ) {
			$provider = $login->get( 'sms_provider' );
			$config = $login->provider( $provider );
			$name = 'kavenegar' === $provider ? 'token' : ( $config['code_parameter'] ?? 'code' );
			return array( 'provider' => $provider, 'mode' => 'custom' === $provider || ( 'melipayamak' === $provider && empty( $config['template_id'] ) ) ? 'text' : 'template', 'template' => $config['template_id'] ?? '', 'params' => $name . '={code}', 'message' => $login->get( 'sms_message' ), 'inherit' => 'login_otp' !== $id );
		}
		if ( 'commerce' === $item['source'] ) {
			$settings = ( new CommerceSettings() )->all();
			$key = 'recovery_request' === $id ? 'recovery_1' : $item['key'];
			$event = $settings['events'][ $key ];
			$provider = $settings['provider'];
		} else {
			$settings = ( new MarketingSettings() )->all();
			$event = $settings['rules'][ $item['key'] ];
			$provider = $settings['provider'] ?: ( new CommerceSettings() )->get( 'provider' );
		}
		return array_merge( $event, array( 'provider' => $provider, 'mode' => 'custom' === $provider ? 'text' : 'template', 'inherit' => false ) );
	}

	public static function required( $id, $variant = null ) {
		$item = self::all()[ $id ] ?? null;
		if ( ! $item ) {
			return array();
		}
		$required = $item['required'];
		if ( 'marketing' === $item['source'] ) {
			$rule = ( new MarketingSettings() )->all()['rules'][ $item['key'] ];
			if ( 'survey' !== $item['key'] && 'none' !== $rule['discount_type'] ) {
				$required[] = 'coupon_code';
			}
		}
		if ( 'recovery' === $item['group'] ) {
			$config = ( new CommerceSettings() )->all();
			$stage = 'recovery_request' === $id ? 1 : (int) substr( $id, -1 );
			if ( $config['coupon_enabled'] && $stage >= $config['coupon_stage'] ) {
				$required[] = 'coupon_code';
			}
		}
		if ( null !== $variant ) {
			$required = array_values( array_diff( $required, array( 'coupon_code' ) ) );
			if ( 'gift' === $variant && self::variants( $id ) ) {
				$required[] = 'coupon_code';
			}
		}
		return $required;
	}

	public static function variants( $id ) {
		$item = self::all()[ $id ] ?? array();
		return 'recovery' === ( $item['group'] ?? '' ) || ( 'marketing' === ( $item['source'] ?? '' ) && 'survey' !== $item['key'] );
	}
}
