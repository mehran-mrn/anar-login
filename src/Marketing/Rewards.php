<?php
/**
 * Idempotent customer gifts with one-use WooCommerce coupon enforcement.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Commerce\Repository as CommerceRepository;

defined( 'ABSPATH' ) || exit;

final class Rewards {
	public function coupon( $profile, $rule, $origin ) {
		if ( ! wc_coupons_enabled() || ! $profile || 'none' === $rule['discount_type'] ) {
			return ''; }
		if ( 'static' === $rule['discount_type'] ) {
			$coupon = new \WC_Coupon( wc_get_coupon_id_by_code( $rule['static_coupon'] ) );
			return $coupon->get_id() && ( ! $coupon->get_date_expires() || $coupon->get_date_expires()->getTimestamp() > time() ) ? $coupon->get_code() : '';
		}
		if ( (float) $rule['discount_amount'] <= 0 ) {
			return ''; }
		$code = 'anar-' . substr( hash_hmac( 'sha256', $profile->id . '|' . $origin, wp_salt( 'auth' ) ), 0, 18 );
		$coupon = new \WC_Coupon( wc_get_coupon_id_by_code( $code ) );
		if ( ! $coupon->get_id() ) {
			$lock = 'anar_reward_' . hash( 'sha256', $code );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $lock, time() - 300 ) );
			wp_cache_delete( $lock, 'options' );
			if ( ! add_option( $lock, time(), '', false ) ) {
				return ''; }
			try {
				$coupon = new \WC_Coupon( wc_get_coupon_id_by_code( $code ) );
				if ( ! $coupon->get_id() ) {
					$coupon->set_code( $code );
					$coupon->set_discount_type( $rule['discount_type'] );
					$coupon->set_amount( $rule['discount_amount'] );
					$coupon->set_usage_limit( 1 );
					$coupon->set_usage_limit_per_user( 1 );
					$coupon->set_individual_use( true );
					$coupon->set_exclude_sale_items( true );
					if ( isset( $rule['minimum_amount'] ) ) {
						$coupon->set_minimum_amount( $rule['minimum_amount'] );
					}
					if ( ! empty( $rule['product_ids'] ) ) {
						$coupon->set_product_ids( $rule['product_ids'] );
					}
					$coupon->set_date_expires( $rule['game_expires_at'] ?? ( time() + max( 1, min( 720, (int) $rule['discount_hours'] ) ) * HOUR_IN_SECONDS ) );
					if ( ! empty( $rule['game_money_cap'] ) ) {
						$coupon->update_meta_data( '_anar_game_money_cap', $rule['game_money_cap'] );
						$coupon->update_meta_data( '_anar_game_currency', $rule['game_currency'] );
						$coupon->update_meta_data( '_anar_game_play_id', $rule['game_play_id'] );
						$coupon->update_meta_data( '_anar_game_kind', $rule['discount_type'] );
						$coupon->update_meta_data( '_anar_game_value', $rule['discount_amount'] );
						$coupon->set_discount_type( 'anar_game_budget' );
						$coupon->set_amount( 0 );
					}
					$coupon->set_description( __( 'Anar customer gift', 'anar-toolkit' ) );
					$coupon->update_meta_data( '_anar_phone_hash', CommerceRepository::phone_hash( $profile->phone ) );
					$coupon->update_meta_data( '_anar_marketing_profile', (int) $profile->id );
					$coupon->update_meta_data( '_anar_marketing_origin', $origin );
					$coupon->save();
					if ( ! empty( $rule['game_money_cap'] ) && ! get_option( 'anar_game_budget_coupons' ) ) {
						update_option( 'anar_game_budget_coupons', 1, true );
					}
				}
			} finally {
				delete_option( $lock ); }
		}
		return $coupon->get_usage_count() < 1 && $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() > time() ? $coupon->get_code() : '';
	}

	public function revoke( $profile_id ) {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids', 'meta_key' => '_anar_marketing_profile', 'meta_value' => (int) $profile_id ) );
		foreach ( $ids as $id ) {
			if ( ! class_exists( 'WC_Coupon' ) ) {
				update_post_meta( $id, 'date_expires', time() - 1 );
				delete_post_meta( $id, '_anar_phone_hash' );
				delete_post_meta( $id, '_anar_marketing_profile' );
				continue;
			}
			$coupon = new \WC_Coupon( $id );
			$coupon->set_date_expires( time() - 1 );
			$coupon->delete_meta_data( '_anar_phone_hash' );
			$coupon->delete_meta_data( '_anar_marketing_profile' );
			$coupon->save();
		}
		return count( $ids ) < 100;
	}
}
