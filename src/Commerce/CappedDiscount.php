<?php
/**
 * Anar toolkit update: configuration, delivery and presentation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

defined( 'ABSPATH' ) || exit;
final class CappedDiscount {
	private $contexts;
	public function __construct() {
		$this->contexts = new \SplObjectStorage(); }
	public function register() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'types' ) );
		add_filter( 'woocommerce_coupon_get_discount_type', array( $this, 'effective_type' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'effective_amount' ), 10, 2 );
		add_action( 'woocommerce_before_coupon_object_save', array( $this, 'protect_storage' ) );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'prepare' ), 999, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'amount' ), 999, 5 );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'limit' ), 999, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'valid' ), 999, 2 );
	}
	public function types( $types ) {
		$types['anar_recovery_capped'] = __( 'Capped recovery discount', 'anar-toolkit' );
		return $types;
	}

	public function valid( $valid, $coupon ) {
		if ( $coupon->get_meta( '_anar_recovery_money_cap' ) && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.8', '<' ) ) {
			throw new \Exception( esc_html__( 'Capped recovery discounts require WooCommerce 8.8 or newer.', 'anar-toolkit' ) ); }
		return $valid;
	}
	public function effective_type( $type, $coupon ) {
		if ( 'anar_recovery_capped' === $type && ! $this->contexts->offsetExists( $coupon ) && (float) $coupon->get_meta( '_anar_recovery_money_cap' ) > 0 ) {
			$base = $coupon->get_meta( '_anar_recovery_kind' );
			return in_array( $base, array( 'percent', 'fixed_cart' ), true ) ? $base : $type;
		}
		return $type;
	}

	public function effective_amount( $amount, $coupon ) {
		return 'anar_recovery_capped' === $coupon->get_discount_type( 'edit' ) && (float) $coupon->get_meta( '_anar_recovery_money_cap' ) > 0 ? (string) $coupon->get_meta( '_anar_recovery_value' ) : $amount;
	}

	public function protect_storage( $coupon ) {
		if ( (float) $coupon->get_meta( '_anar_recovery_money_cap' ) > 0 && in_array( $coupon->get_meta( '_anar_recovery_kind' ), array( 'percent', 'fixed_cart' ), true ) && '' !== $coupon->get_meta( '_anar_recovery_value' ) ) {
			$coupon->set_discount_type( 'anar_recovery_capped' );
			$coupon->set_amount( 0 );
		}
	}

	public function prepare( $items, $coupon, $discounts ) {
		if ( (float) $coupon->get_meta( '_anar_recovery_money_cap' ) <= 0 || ! in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_cart' ), true ) ) {
			return $items;
		}
		$object = $discounts->get_object();
		$context = array( 'type' => $coupon->get_discount_type(), 'restore' => $coupon->get_discount_type( 'edit' ), 'total' => array_sum( wp_list_pluck( $items, 'price' ) ), 'tax' => array() );
		foreach ( $items as $item ) {
			$included = $item->price_includes_tax ?? ( is_a( $object, 'WC_Order' ) ? $object->get_prices_include_tax() : wc_prices_include_tax() );
			$exempt = is_a( $object, 'WC_Order' ) ? wc_string_to_bool( $object->get_meta( 'is_vat_exempt' ) ) : ( WC()->customer && WC()->customer->get_is_vat_exempt() );
			$rates = array();
			if ( wc_tax_enabled() && ! $included && ! $exempt && $item->product->is_taxable() ) {
				$rates = $item->tax_rates ?? ( is_a( $object, 'WC_Order' ) ? \WC_Tax::get_rates_from_location( $item->product->get_tax_class(), array_values( $object->get_taxable_location() ) ) : \WC_Tax::get_rates( $item->product->get_tax_class(), WC()->customer ) );
			}
			$context['tax'][ $item->key ] = $rates;
		}
		$this->contexts[ $coupon ] = $context;
		// Eligibility was checked with the original type. Restore it after this calculation, before serialization.
		$coupon->set_discount_type( 'anar_recovery_capped' );
		return $items;
	}

	public function amount( $discount, $price, $item, $single, $coupon ) {
		if ( ! $this->contexts->offsetExists( $coupon ) ) {
			return $discount;
		}
		$context = $this->contexts[ $coupon ];
		$value = 'percent' === $context['type'] ? $price * (float) $coupon->get_amount() / 100 : ( $context['total'] ? (float) $coupon->get_amount() * wc_add_number_precision( $price ) / $context['total'] : 0 );
		return max( 0, min( $price, $value ) );
	}

	private static function gross( $values, $tax ) {
		$total = array_sum( $values );
		foreach ( $values as $key => $value ) {
			if ( ! empty( $tax[ $key ] ) ) {
				$total += array_sum( \WC_Tax::calc_tax( $value, $tax[ $key ], false ) );
			}
		}
		return $total;
	}

	public function limit( $values, $coupon ) {
		if ( ! $this->contexts->offsetExists( $coupon ) ) {
			return $values;
		}
		$context = $this->contexts[ $coupon ];
		try {
			$cap = floor( wc_add_number_precision( $coupon->get_meta( '_anar_recovery_money_cap' ) ) );
			$total = self::gross( $values, $context['tax'] );
			if ( $total > $cap && $total > 0 ) {
				foreach ( $values as &$value ) {
					$value = floor( $value * $cap / $total );
				}
				unset( $value );
				// Tax rounding must never turn a capped discount into a larger commitment.
				while ( self::gross( $values, $context['tax'] ) > $cap && array_sum( $values ) > 0 ) {
					$largest = array_search( max( $values ), $values, true );
					$values[ $largest ] = max( 0, $values[ $largest ] - 1 );
				}
			}
			return $values;
		} finally {
			$coupon->set_discount_type( $context['restore'] );
			$this->contexts->offsetUnset( $coupon );
		}
	}
}
