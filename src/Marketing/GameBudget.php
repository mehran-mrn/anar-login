<?php
/**
 * Money reservations and a capped WooCommerce calculation shared by cart and order checkout.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class GameBudget {
	private $contexts;

	public function __construct() {
		$this->contexts = new \SplObjectStorage();
	}

	public function register() {
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'types' ) );
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'prepare' ), 999, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'amount' ), 999, 5 );
		add_filter( 'woocommerce_coupon_custom_discounts_array', array( $this, 'limit' ), 999, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'valid' ), 999, 3 );
		foreach ( array( 'woocommerce_checkout_order_created', 'woocommerce_store_api_checkout_order_processed', 'woocommerce_before_pay_action' ) as $hook ) {
			add_action( $hook, array( $this, 'bind' ), 5 );
		}
		add_action( 'woocommerce_order_status_changed', array( $this, 'status' ), 30, 4 );
		add_action( 'woocommerce_payment_complete', array( $this, 'payment' ), 30 );
		add_action( 'anar_login_daily_cleanup', array( self::class, 'sweep' ) );
	}

	public static function cap( $prize ) {
		$cap = max( 0, (float) ( $prize['cap'] ?? 0 ) );
		// A fixed amount is bounded before tax; require an explicit gross ceiling for monetary budgets too.
		return round( $cap, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	}

	public static function fits( $config, $prize, $used ) {
		$cap = self::cap( $prize );
		if ( $cap > 0 && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.8', '<' ) ) {
			return false;
		}
		$budget = (float) ( $config['game_money_budget'] ?? 0 );
		return $budget <= 0 || ( $cap > 0 && round( $used + $cap, 8 ) <= round( $budget, 8 ) );
	}

	public static function terms( $rule ) {
		return empty( $rule['game_money_cap'] ) ? '' : sprintf( /* translators: 1: monetary ceiling, 2: store currency symbol. */ __( 'Maximum saving: %1$s %2$s, including tax.', 'anar-toolkit' ), self::money_number( $rule['game_money_cap'] ), html_entity_decode( get_woocommerce_currency_symbol( $rule['game_currency'] ), ENT_QUOTES, 'UTF-8' ) );
	}

	public static function money_number( $amount ) {
		// Currency separators belong to the store settings, which may differ from WordPress's locale.
		return number_format( (float) $amount, wc_get_price_decimals(), wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
	}

	public function types( $types ) {
		$types['anar_game_capped'] = __( 'Capped game discount', 'anar-toolkit' );
		return $types;
	}

	public function prepare( $items, $coupon, $discounts ) {
		if ( (float) $coupon->get_meta( '_anar_game_money_cap' ) <= 0 || ! in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_cart' ), true ) ) {
			return $items;
		}
		$object = $discounts->get_object();
		$context = array( 'type' => $coupon->get_discount_type(), 'total' => array_sum( wp_list_pluck( $items, 'price' ) ), 'tax' => array() );
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
		$coupon->set_discount_type( 'anar_game_capped' );
		return $items;
	}

	public function amount( $discount, $price, $item, $single, $coupon ) {
		if ( ! $this->contexts->contains( $coupon ) ) {
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
		if ( ! $this->contexts->contains( $coupon ) ) {
			return $values;
		}
		$context = $this->contexts[ $coupon ];
		try {
			$cap = floor( wc_add_number_precision( $coupon->get_meta( '_anar_game_money_cap' ) ) );
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
			$coupon->set_discount_type( $context['type'] );
			$this->contexts->detach( $coupon );
		}
	}

	private static function row( $coupon_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE coupon_id = %d', ( new Repository() )->table( 'game_awards' ), $coupon_id ) );
	}

	public function valid( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || (float) $coupon->get_meta( '_anar_game_money_cap' ) <= 0 ) {
			return $valid;
		}
		$row = self::row( $coupon->get_id() );
		$snapshot = $row ? json_decode( $row->snapshot, true ) : array();
		$order = $discounts ? $discounts->get_object() : null;
		$same_order = is_a( $order, 'WC_Order' ) && $row && (int) $row->order_id === $order->get_id();
		if ( ! $row || (float) $coupon->get_meta( '_anar_game_money_cap' ) > (float) ( $snapshot['game_money_cap'] ?? 0 ) || get_woocommerce_currency() !== $row->currency || 'expired' === $row->money_state || ( in_array( $row->money_state, array( 'held', 'consumed' ), true ) && ! $same_order ) || ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.8', '<' ) ) ) {
			throw new \Exception( esc_html__( 'This gift cannot be used in a new order. Check its expiry or continue the order where it was applied.', 'anar-toolkit' ) );
		}
		return $valid;
	}

	/**
	 * Bind before either checkout starts payment; saved unpaid orders keep their commitment.
	 *
	 * @throws \WC_Data_Exception When a reservation cannot cover this order.
	 */
	public function bind( $order ) {
		global $wpdb;
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			$coupon = new \WC_Coupon( $item->get_code() );
			$cap = (float) $coupon->get_meta( '_anar_game_money_cap' );
			if ( $cap <= 0 ) {
				continue;
			}
			$lock = GameCampaign::lock( 'prizes' );
			if ( ! $lock ) {
				throw new \WC_Data_Exception( 'anar_budget_busy', esc_html__( 'Your discount is being checked. Please try payment again.', 'anar-toolkit' ) );
			}
			try {
				$row = self::row( $coupon->get_id() );
				$actual = round( (float) $item->get_discount() + (float) $item->get_discount_tax(), wc_get_price_decimals() );
				$covered = $row ? ( 'consumed' === $row->money_state ? (float) $row->money_spent : (float) $row->money_reserved ) : 0;
				if ( ! $row || ! $order->get_id() || $row->currency !== $order->get_currency() || 'expired' === $row->money_state || ( $row->order_id && (int) $row->order_id !== $order->get_id() ) || $actual > min( $cap, $covered ) + 0.00000001 || ( ! $row->order_id && $row->expires_at <= time() ) ) {
					throw new \WC_Data_Exception( 'anar_budget_invalid', esc_html__( 'The game discount is no longer valid for this order. Recheck the coupon before payment.', 'anar-toolkit' ) );
				}
				if ( 'consumed' !== $row->money_state ) {
					$ok = $wpdb->update( ( new Repository() )->table( 'game_awards' ), array( 'order_id' => $order->get_id(), 'money_state' => 'held' ), array( 'play_id' => $row->play_id ) );
					if ( false === $ok ) {
						throw new \WC_Data_Exception( 'anar_budget_save', esc_html__( 'Your discount is being checked. Please try payment again.', 'anar-toolkit' ) );
					}
				}
			} finally {
				GameCampaign::unlock( $lock );
			}
		}
	}

	public function payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$this->status( $order_id, '', $order->get_status(), $order );
		}
	}

	public function status( $order_id, $from, $to, $order ) {
		if ( ! $order->is_paid() ) {
			return;
		}
		global $wpdb;
		$lock = GameCampaign::lock( 'prizes' );
		if ( ! $lock ) {
			return; // The full reservation remains until the scheduled reconciliation.
		}
		try {
			foreach ( $order->get_items( 'coupon' ) as $item ) {
				$coupon = new \WC_Coupon( $item->get_code() );
				if ( (float) $coupon->get_meta( '_anar_game_money_cap' ) <= 0 ) {
					continue;
				}
				$row = self::row( $coupon->get_id() );
				if ( ! $row || 'consumed' === $row->money_state || 'expired' === $row->money_state || ( $row->order_id && (int) $row->order_id !== (int) $order_id ) ) {
					continue;
				}
				// Never free more than the proven liability; refunds do not reactivate this one-use gift.
				$actual = max( 0, (float) $item->get_discount() + (float) $item->get_discount_tax() );
				$wpdb->update( ( new Repository() )->table( 'game_awards' ), array( 'money_reserved' => 0, 'money_spent' => max( $actual, (float) $row->money_spent ), 'money_state' => 'consumed', 'order_id' => $order_id ), array( 'play_id' => $row->play_id ) );
			}
		} finally {
			GameCampaign::unlock( $lock );
		}
	}

	public static function sweep( $force = true ) {
		global $wpdb;
		if ( ! $force && (int) get_option( 'anar_game_budget_scan', 0 ) > time() - 300 ) {
			return;
		}
		$lock = GameCampaign::lock( 'prizes' );
		if ( ! $lock ) {
			return;
		}
		try {
			$table = ( new Repository() )->table( 'game_awards' );
			$cursor = (int) get_option( 'anar_game_budget_cursor', 0 );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE play_id > %d AND money_reserved > 0 AND money_state IN ('reserved','issued','held') ORDER BY play_id LIMIT 100", $table, $cursor ) );
			if ( $wpdb->last_error ) {
				return;
			}
			update_option( 'anar_game_budget_scan', time(), false );
			$last = $rows ? end( $rows )->play_id : 0;
			update_option( 'anar_game_budget_cursor', count( $rows ) < 100 ? 0 : $last, false );
			foreach ( $rows as $row ) {
				$coupon = $row->coupon_id ? new \WC_Coupon( $row->coupon_id ) : null;
				$orders = $row->order_id ? array( $row->order_id ) : ( $coupon ? $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM %i WHERE order_item_type = 'coupon' AND order_item_name = %s", $wpdb->prefix . 'woocommerce_order_items', $coupon->get_code() ) ) : array() );
				if ( $wpdb->last_error ) {
					continue; // Unknown order history cannot justify releasing money.
				}
				if ( $orders ) {
					// Even cancelled/failed orders may receive a late payment callback. Keep their reservation.
					foreach ( $orders as $order_id ) {
						$order = wc_get_order( $order_id );
						if ( ! $order ) {
							continue;
						}
						if ( $order->is_paid() ) {
							foreach ( $order->get_items( 'coupon' ) as $item ) {
								if ( $coupon && wc_is_same_coupon( $item->get_code(), $coupon->get_code() ) ) {
									$wpdb->update( $table, array( 'money_reserved' => 0, 'money_spent' => (float) $item->get_discount() + (float) $item->get_discount_tax(), 'money_state' => 'consumed', 'order_id' => $order_id ), array( 'play_id' => $row->play_id ) );
								}
							}
						} else {
							$wpdb->update( $table, array( 'order_id' => $order_id, 'money_state' => 'held' ), array( 'play_id' => $row->play_id ) );
						}
						break;
					}
				} elseif ( $row->expires_at && $row->expires_at <= time() ) {
					$wpdb->update( $table, array( 'money_reserved' => 0, 'money_state' => 'expired' ), array( 'play_id' => $row->play_id ) );
				}
			}
		} finally {
			GameCampaign::unlock( $lock );
		}
	}
}
