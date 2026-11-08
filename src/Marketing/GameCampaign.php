<?php
/**
 * Campaign configuration and serialized game operations.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class GameCampaign {
	/** Carry earlier issued coupons into the shared pool without changing the coupons. */
	public static function migrate() {
		global $wpdb;
		if ( get_option( 'anar_game_pool_migrated' ) ) {
			return true;
		}
		$lock = self::lock( 'prizes' );
		if ( ! $lock || ! class_exists( 'WC_Coupon' ) ) {
			if ( $lock ) {
				self::unlock( $lock );
			}
			return false;
		}
		try {
			$repo = new Repository();
			$plays = $wpdb->get_results( $wpdb->prepare( 'SELECT p.id, p.profile_id, p.coupon_id, p.created_at FROM %i p LEFT JOIN %i a ON a.play_id = p.id WHERE p.coupon_id > 0 AND a.play_id IS NULL ORDER BY p.id LIMIT 100', $repo->table( 'plays' ), $repo->table( 'game_awards' ) ) );
			if ( $wpdb->last_error ) {
				return false;
			}
			foreach ( $plays as $play ) {
				$coupon = new \WC_Coupon( $play->coupon_id );
				$rule = array( 'discount_type' => $coupon->get_discount_type(), 'discount_amount' => $coupon->get_amount(), 'discount_hours' => 48, 'label' => __( 'Store discount', 'anar-toolkit' ) );
				if ( ! $wpdb->insert( $repo->table( 'game_awards' ), array( 'play_id' => $play->id, 'profile_id' => $play->profile_id, 'pool' => 'shared', 'prize' => 'legacy', 'percent_units' => 'percent' === $coupon->get_discount_type() ? $coupon->get_amount() : 0, 'snapshot' => wp_json_encode( $rule ), 'coupon_id' => $play->coupon_id, 'created_at' => $play->created_at ) ) ) {
					return false;
				}
			}
			if ( count( $plays ) < 100 ) {
				update_option( 'anar_game_pool_migrated', 1, false );
				return true;
			}
			return false;
		} finally {
			self::unlock( $lock );
		}
	}

	public static function defaults() {
		return array( 'game_quota_mode' => 'shared', 'games_total_limit' => 5, 'game_limits' => array(), 'game_pool_scopes' => array(), 'game_prizes' => array(), 'game_max_winners' => 0, 'game_percent_budget' => '0', 'game_money_budget' => '0', 'game_reward_cap' => '0', 'game_start' => '', 'game_end' => '', 'game_skin' => 'berry', 'game_color' => '', 'game_pool_empty' => 'stop' );
	}

	public static function sanitize( $raw ) {
		$out = self::defaults();
		foreach ( array( 'game_quota_mode' => array( 'shared', 'independent', 'combined' ), 'game_skin' => array( 'berry', 'ocean', 'forest' ), 'game_pool_empty' => array( 'stop', 'continue' ) ) as $key => $allowed ) {
			$out[ $key ] = in_array( $raw[ $key ] ?? '', $allowed, true ) ? $raw[ $key ] : $out[ $key ];
		}
		foreach ( array( 'games_total_limit' => 50, 'game_max_winners' => 1000000 ) as $key => $max ) {
			$out[ $key ] = max( 'games_total_limit' === $key ? 1 : 0, min( $max, (int) ( $raw[ $key ] ?? $out[ $key ] ) ) );
		}
		$out['game_percent_budget'] = (string) max( 0, min( 100000000, round( (float) ( $raw['game_percent_budget'] ?? 0 ), 2 ) ) );
		foreach ( array( 'game_money_budget', 'game_reward_cap' ) as $money_key ) {
			$out[ $money_key ] = (string) max( 0, min( 1000000000000, round( (float) ( $raw[ $money_key ] ?? 0 ), 2 ) ) );
		}
		$color = is_string( $raw['game_color'] ?? null ) ? $raw['game_color'] : '';
		$out['game_color'] = preg_match( '/^#[0-9a-f]{6}$/iD', $color ) ? $color : '';
		foreach ( array( 'game_start', 'game_end' ) as $key ) {
			$value = is_string( $raw[ $key ] ?? null ) ? $raw[ $key ] : '';
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
			$out[ $key ] = $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
		}
		foreach ( Games::types() as $game => $label ) {
			$out['game_limits'][ $game ] = max( 1, min( 50, (int) ( $raw['game_limits'][ $game ] ?? $raw['games_limit'] ?? 1 ) ) );
			$out['game_pool_scopes'][ $game ] = 'dedicated' === ( $raw['game_pool_scopes'][ $game ] ?? '' ) ? 'dedicated' : 'shared';
		}
		foreach ( array_slice( (array) ( $raw['game_prizes'] ?? array() ), 0, 8, true ) as $key => $prize ) {
			if ( ! is_array( $prize ) || ! is_scalar( $prize['label'] ?? null ) || ! trim( (string) $prize['label'] ) ) {
				continue;
			}
			$id = sanitize_key( (string) $key );
			if ( ! preg_match( '/^p[1-8]$/D', $id ) ) {
				continue;
			}
			$type = 'fixed_cart' === ( $prize['type'] ?? '' ) ? 'fixed_cart' : 'percent';
			$out['game_prizes'][ $id ] = array(
				'label' => sanitize_text_field( $prize['label'] ),
				'type' => $type,
				'amount' => (string) max( 0.01, min( 'percent' === $type ? 100 : 1000000000, round( (float) ( $prize['amount'] ?? 5 ), 2 ) ) ),
				'cap' => (string) max( 0, min( 1000000000000, round( (float) ( $prize['cap'] ?? 0 ), 2 ) ) ),
				'weight' => max( 1, min( 1000, (int) ( $prize['weight'] ?? 1 ) ) ),
				'stock' => max( 0, min( 1000000, (int) ( $prize['stock'] ?? 0 ) ) ),
				'customer_limit' => max( 0, min( 1000, (int) ( $prize['customer_limit'] ?? 0 ) ) ),
				'hours' => max( 1, min( 720, (int) ( $prize['hours'] ?? 48 ) ) ),
				'minimum' => (string) max( 0, min( 1000000000, (float) ( $prize['minimum'] ?? 0 ) ) ),
				'products' => implode( ',', wp_parse_id_list( $prize['products'] ?? '' ) ),
			);
		}
		return $out;
	}

	public static function prizes( $config ) {
		if ( ! empty( $config['game_prizes'] ) ) {
			return $config['game_prizes'];
		}
		return array( 'legacy' => array( 'label' => __( 'Store discount', 'anar-toolkit' ), 'type' => $config['game_reward_type'], 'amount' => $config['game_reward_amount'], 'weight' => 1, 'stock' => 0, 'customer_limit' => 0, 'hours' => 48, 'cap' => $config['game_reward_cap'] ?? '0', 'minimum' => '0', 'products' => '' ) );
	}

	public static function active( $config ) {
		$today = wp_date( 'Y-m-d' );
		return ( ! $config['game_start'] || $today >= $config['game_start'] ) && ( ! $config['game_end'] || $today <= $config['game_end'] );
	}

	/** Lease ownership is checked again before releasing; a crashed worker fails closed. */
	public static function lock( $scope ) {
		global $wpdb;
		$table = $wpdb->prefix . 'anar_login_limits';
		$key = hash( 'sha256', 'anar-game:' . $scope );
		$owner = random_int( 1, 2147483647 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND expires_at <= %d', $table, $key, time() ) );
		if ( 1 !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (limit_key, attempts, expires_at) VALUES (%s,%d,%d)', $table, $key, $owner, time() + 300 ) ) ) {
			return false;
		}
		return array( $key, $owner );
	}

	public static function unlock( $lock ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND attempts = %d', $wpdb->prefix . 'anar_login_limits', $lock[0], $lock[1] ) );
	}

	public static function availability( $config, $game, $profile_id = 0 ) {
		global $wpdb;
		$table = ( new Repository() )->table( 'game_awards' );
		$pool = 'dedicated' === ( $config['game_pool_scopes'][ $game ] ?? '' ) ? $game : 'shared';
		$totals = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS winners, COALESCE(SUM(percent_units),0) AS units, COALESCE(SUM(CASE WHEN currency = %s THEN money_reserved + money_spent ELSE 0 END),0) AS money FROM %i WHERE pool = %s', get_woocommerce_currency(), $table, $pool ) );
		if ( ! $totals ) {
			return array( 'pool' => $pool, 'prizes' => array(), 'winners' => 0, 'units' => 0 );
		}
		$prizes = self::prizes( $config );
		foreach ( $prizes as $id => $prize ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pool = %s AND prize = %s', $table, $pool, $id ) );
			if ( $wpdb->last_error ) {
				return array( 'pool' => $pool, 'prizes' => array(), 'winners' => 0, 'units' => 0 );
			}
			$personal = $profile_id && $prize['customer_limit'] ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pool = %s AND prize = %s AND profile_id = %d', $table, $pool, $id, $profile_id ) ) : 0;
			if ( $wpdb->last_error ) {
				return array( 'pool' => $pool, 'prizes' => array(), 'winners' => 0, 'units' => 0 );
			}
			if ( ! GameBudget::fits( $config, $prize, $totals->money ) ) {
				unset( $prizes[ $id ] );
				continue;
			}
			if ( ( $config['game_max_winners'] && (int) $totals->winners >= $config['game_max_winners'] ) || ( $prize['stock'] && $used >= $prize['stock'] ) || ( $prize['customer_limit'] && $personal >= $prize['customer_limit'] ) || ( 'percent' === $prize['type'] && (float) $config['game_percent_budget'] > 0 && (float) $totals->units + (float) $prize['amount'] > (float) $config['game_percent_budget'] ) ) {
				unset( $prizes[ $id ] );
			}
		}
		return array( 'pool' => $pool, 'prizes' => $prizes, 'winners' => (int) $totals->winners, 'units' => (float) $totals->units, 'money' => (float) $totals->money );
	}

	/** Persist the reservation before issuing a coupon; retries use the same snapshot. */
	public static function award( $profile, $game, $play_id, $config ) {
		global $wpdb;
		$table = ( new Repository() )->table( 'game_awards' );
		$lock = self::lock( 'prizes' );
		if ( ! $lock ) {
			return new \WP_Error( 'anar_game_busy', __( 'Your game is being processed. Please try again.', 'anar-toolkit' ) );
		}
		try {
			$award = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_id = %d', $table, $play_id ) );
			if ( ! $award ) {
				$available = self::availability( $config, $game, $profile->id );
				if ( ! $available['prizes'] ) {
					return array( 'coupon' => '', 'prize_id' => '', 'prize_label' => '', 'exhausted' => true );
				}
				$draw = random_int( 1, array_sum( array_column( $available['prizes'], 'weight' ) ) );
				foreach ( $available['prizes'] as $id => $prize ) {
					$draw -= $prize['weight'];
					if ( $draw <= 0 ) {
						break;
					}
				}
				$rule = array( 'discount_type' => $prize['type'], 'discount_amount' => $prize['amount'], 'discount_hours' => $prize['hours'], 'minimum_amount' => $prize['minimum'], 'product_ids' => wp_parse_id_list( $prize['products'] ), 'label' => $prize['label'], 'game_money_cap' => GameBudget::cap( $prize ), 'game_currency' => get_woocommerce_currency(), 'game_play_id' => $play_id, 'game_expires_at' => time() + $prize['hours'] * HOUR_IN_SECONDS );
				if ( ! $wpdb->insert( $table, array( 'play_id' => $play_id, 'profile_id' => $profile->id, 'pool' => $available['pool'], 'prize' => $id, 'percent_units' => 'percent' === $prize['type'] ? $prize['amount'] : 0, 'money_reserved' => GameBudget::cap( $prize ), 'currency' => get_woocommerce_currency(), 'expires_at' => time() + $prize['hours'] * HOUR_IN_SECONDS, 'money_state' => 'reserved', 'snapshot' => wp_json_encode( $rule ), 'created_at' => time() ) ) ) {
					return new \WP_Error( 'anar_game_reservation', __( 'Your prize could not be reserved. Please try again.', 'anar-toolkit' ) );
				}
				$award = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE play_id = %d', $table, $play_id ) );
			}
		} finally {
			self::unlock( $lock );
		}
		$rule = json_decode( $award->snapshot, true );
		if ( 'expired' === $award->money_state ) {
			return new \WP_Error( 'anar_game_coupon_expired', __( 'This reserved gift has expired. Start again when another attempt is available.', 'anar-toolkit' ) );
		}
		if ( $award->coupon_id ) {
			$existing = new \WC_Coupon( $award->coupon_id );
			return array( 'coupon' => $existing->get_code(), 'prize_id' => $award->prize, 'prize_label' => $rule['label'], 'prize_terms' => GameBudget::terms( $rule ), 'exhausted' => false );
		}
		try {
			$code = ( new Rewards() )->coupon( $profile, $rule, 'game:' . $play_id );
		} catch ( \Throwable $error ) {
			$code = '';
		}
		if ( ! $code ) {
			// Keep the reservation: a lost response or interrupted coupon save must never overspend stock.
			return new \WP_Error( 'anar_game_coupon', __( 'Your prize is reserved. Retry to receive the same coupon.', 'anar-toolkit' ) );
		}
		$wpdb->update( $table, array( 'coupon_id' => wc_get_coupon_id_by_code( $code ), 'money_state' => 'issued' ), array( 'play_id' => $play_id ) );
		return array( 'coupon' => $code, 'prize_id' => $award->prize, 'prize_label' => $rule['label'], 'prize_terms' => GameBudget::terms( $rule ), 'exhausted' => false );
	}
}
