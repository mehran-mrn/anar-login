<?php
/**
 * Single-use, phone-bound WooCommerce recovery discounts.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Coupons {
	private $settings;
	private $repository;

	public function __construct( Settings $settings, Repository $repository ) {
		$this->settings = $settings;
		$this->repository = $repository;
	}

	public function register() {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'valid' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'validate_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_order' ), 20, 1 );
	}

	public function issue( $cart, $stage ) {
		$config = $this->settings->all();
		if ( ! $config['coupon_enabled'] || $stage < $config['coupon_stage'] || (float) $cart->total < (float) $config['coupon_minimum'] || (float) $config['coupon_amount'] <= 0 || ! wc_coupons_enabled() ) {
			return array( 'coupon_code' => '', 'coupon_amount' => '', 'coupon_expires' => '' );
		}
		$contact = $this->repository->contact( $cart->contact_id );
		if ( ! $contact || $contact->suppressed ) {
			return new WP_Error( 'anar_coupon_unavailable' );
		}
		$code = 'anar-' . substr( hash_hmac( 'sha256', 'discount|' . $cart->id . '|' . $cart->seed, wp_salt( 'auth' ) ), 0, 16 );
		$coupon = new \WC_Coupon( wc_get_coupon_id_by_code( $code ) );
		if ( ! $coupon->get_id() ) {
			$coupon->set_code( $code );
			$coupon->set_discount_type( $config['coupon_type'] );
			$coupon->set_amount( $config['coupon_amount'] );
			$coupon->set_minimum_amount( $config['coupon_minimum'] );
			$coupon->set_individual_use( (bool) $config['coupon_individual'] );
			$coupon->set_exclude_sale_items( (bool) $config['coupon_exclude_sale'] );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_date_expires( min( time() + $config['coupon_hours'] * HOUR_IN_SECONDS, $cart->updated_at + $config['link_days'] * DAY_IN_SECONDS ) );
			$coupon->set_description( __( 'Anar purchase recovery discount', 'anar-toolkit' ) );
			$coupon->update_meta_data( '_anar_cart_id', (int) $cart->id );
			$coupon->update_meta_data( '_anar_phone_hash', Repository::phone_hash( $contact->phone ) );
			$coupon->save();
		}
		if ( (int) $coupon->get_meta( '_anar_cart_id' ) !== (int) $cart->id || $coupon->get_usage_count() >= 1 || ! $coupon->get_date_expires() || $coupon->get_date_expires()->getTimestamp() <= time() ) {
			return array( 'coupon_code' => '', 'coupon_amount' => '', 'coupon_expires' => '' );
		}
		global $wpdb;
		$wpdb->update( $this->repository->table( 'carts' ), array( 'coupon_id' => $coupon->get_id() ), array( 'id' => $cart->id ) );
		return array( 'coupon_code' => $coupon->get_code(), 'coupon_amount' => $coupon->get_amount() . ( 'percent' === $coupon->get_discount_type() ? '%' : ' ' . $cart->currency ), 'coupon_expires' => wp_date( 'Y-m-d H:i', $coupon->get_date_expires()->getTimestamp() ) );
	}

	private function matches( $coupon, $phone ) {
		$hash = (string) $coupon->get_meta( '_anar_phone_hash' );
		if ( ! $hash ) {
			return true;
		}
		$identity = Identity::normalize( $phone );
		return ! is_wp_error( $identity ) && 'sms' === $identity['channel'] && hash_equals( $hash, Repository::phone_hash( $identity['identity'] ) );
	}

	public function valid( $valid, $coupon ) {
		if ( $valid && $coupon->get_meta( '_anar_phone_hash' ) && ! $this->matches( $coupon, WC()->customer ? WC()->customer->get_billing_phone() : '' ) ) {
			throw new \Exception( esc_html__( 'This recovery discount is valid only for the mobile number that received it. Enter that billing phone before applying it.', 'anar-toolkit' ) );
		}
		return $valid;
	}

	public function validate_order( $order ) {
		foreach ( $order->get_coupon_codes() as $code ) {
			if ( ! $this->matches( new \WC_Coupon( $code ), $order->get_billing_phone() ) ) {
				throw new \WC_Data_Exception( 'anar_coupon_phone', esc_html__( 'The recovery discount does not match the order billing phone.', 'anar-toolkit' ) );
			}
		}
	}

	/** Only after recovery-code ownership has been verified. */
	public function apply( $cart, $order = null ) {
		if ( ! $cart->coupon_id ) {
			return;
		}
		$coupon = new \WC_Coupon( (int) $cart->coupon_id );
		$contact = $this->repository->contact( $cart->contact_id );
		if ( ! $coupon->get_id() || ! $contact || ! $this->matches( $coupon, $contact->phone ) ) {
			return;
		}
		if ( WC()->customer ) {
			WC()->customer->set_billing_phone( $contact->phone );
		}
		if ( $order ) {
			if ( $this->matches( $coupon, $order->get_billing_phone() ) && $order->needs_payment() && ! in_array( $coupon->get_code(), $order->get_coupon_codes(), true ) ) {
				$order->apply_coupon( $coupon->get_code() );
			}
		} elseif ( WC()->cart && ! WC()->cart->has_discount( $coupon->get_code() ) ) {
			WC()->cart->apply_coupon( $coupon->get_code() );
		}
	}

	public function revoke( $contact_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT coupon_id FROM %i WHERE contact_id = %d AND coupon_id > 0', $this->repository->table( 'carts' ), $contact_id ) );
		foreach ( $ids as $id ) {
			if ( ! class_exists( 'WC_Coupon' ) ) {
				update_post_meta( $id, 'date_expires', time() - 1 );
				delete_post_meta( $id, '_anar_phone_hash' );
				continue; }
			$coupon = new \WC_Coupon( (int) $id );
			if ( $coupon->get_id() && $coupon->get_meta( '_anar_cart_id' ) ) {
				$coupon->set_date_expires( time() - 1 );
				$coupon->delete_meta_data( '_anar_phone_hash' );
				$coupon->save();
			}
		}
	}
}
