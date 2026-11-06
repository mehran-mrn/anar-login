<?php
/**
 * Explainable repeat-purchase intervals; insufficient data produces no prediction.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class Prediction {
	public static function median( $values ) {
		if ( ! $values ) {
			return 0.0; }
		sort( $values, SORT_NUMERIC );
		$middle = (int) floor( count( $values ) / 2 );
		return count( $values ) % 2 ? (float) $values[ $middle ] : ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
	}

	public static function estimate( $dates, $minimum = 3 ) {
		$dates = array_values( array_unique( array_map( 'intval', $dates ) ) );
		sort( $dates, SORT_NUMERIC );
		if ( count( $dates ) < $minimum ) {
			return null; }
		$intervals = array();
		$length = count( $dates );
		for ( $i = 1; $i < $length; ++$i ) {
			$gap = ( $dates[ $i ] - $dates[ $i - 1 ] ) / DAY_IN_SECONDS;
			if ( $gap >= 1 && $gap <= 730 ) {
				$intervals[] = $gap; }
		}
		return self::intervals( $intervals, max( 2, $minimum - 1 ) );
	}

	public static function intervals( $intervals, $minimum = 2 ) {
		if ( count( $intervals ) < $minimum ) {
			return null; }
		$median = self::median( $intervals );
		$deviation = self::median(
			array_map(
				static function ( $n ) use ( $median ) {
					return abs( $n - $median );
				},
				$intervals
			)
		);
		return array( 'days' => max( 1, min( 365, (int) round( $median ) ) ), 'confidence' => (int) round( max( 0, 1 - $deviation / max( 1, $median ) ) * min( 1, count( $intervals ) / 4 ) * 100 ), 'samples' => count( $intervals ), 'error_days' => $deviation );
	}

	public function for_product( $profile_id, $product_id, $config ) {
		global $wpdb;
		$repo = new Repository();
		$dates = $wpdb->get_col( $wpdb->prepare( 'SELECT paid_at FROM %i WHERE profile_id = %d AND product_id = %d ORDER BY paid_at DESC LIMIT 50', $repo->table( 'purchases' ), $profile_id, $product_id ) );
		if ( ! $dates ) {
			return null; }
		$personal = self::estimate( $dates, $config['prediction_minimum'] );
		$product = null;
		if ( 'personal' !== $config['prediction_mode'] ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT profile_id, paid_at FROM %i WHERE product_id = %d ORDER BY paid_at DESC LIMIT 300', $repo->table( 'purchases' ), $product_id ) );
			$histories = array();
			foreach ( $rows as $row ) {
				$histories[ $row->profile_id ][] = (int) $row->paid_at; }
			$gaps = array();
			foreach ( $histories as $history ) {
				sort( $history );
				$length = count( $history ); for ( $i = 1; $i < $length; ++$i ) {
					$gap = ( $history[ $i ] - $history[ $i - 1 ] ) / DAY_IN_SECONDS;
					if ( $gap >= 1 && $gap <= 730 ) {
						$gaps[] = $gap; }
				}
			}
			$product = self::intervals( $gaps, max( 5, $config['prediction_minimum'] ) );
		}
		$result = 'product' === $config['prediction_mode'] ? $product : $personal;
		if ( 'hybrid' === $config['prediction_mode'] ) {
			$result = $personal ?: $product;
			if ( $personal && $product ) {
				$result['days'] = (int) round( ( $personal['days'] * 2 + $product['days'] ) / 3 );
				$result['confidence'] = min( $personal['confidence'], $product['confidence'] ); }
		}
		if ( ! $result || $result['confidence'] < $config['prediction_confidence'] ) {
			return null; }
		$result['last_paid'] = max( $dates );
		$result['timestamp'] = $result['last_paid'] + $result['days'] * DAY_IN_SECONDS;
		return $result;
	}
}
