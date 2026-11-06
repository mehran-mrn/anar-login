<?php
/**
 * Optional commerce modules and message templates.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private $resolved;
	private $source;

	const OPTION = 'anar_toolkit_commerce';

	public function defaults() {
		$recovery = __( "{site}: Your purchase recovery code is {code} (valid for {minutes} minutes).\nEnter it here: {recovery_page}\nStop reminders: {unsubscribe_url}", 'anar-toolkit' );
		$events   = array(
			'paid_customer' => array( 'message' => __( '{site}: Payment received for order #{order_id}. Total: {total} {currency}.', 'anar-toolkit' ) ),
			'paid_admin'    => array( 'message' => __( '{site}: Order #{order_id} paid by {name}. Total: {total} {currency}.', 'anar-toolkit' ) ),
			'new_admin' => array( 'message' => __( '{site}: New order #{order_id} from {name}. Total: {total} {currency}. Status: {status}.', 'anar-toolkit' ) ),
			'failed_admin' => array( 'message' => __( '{site}: Payment failed for order #{order_id}. Total: {total} {currency}.', 'anar-toolkit' ) ),
			'shipment_customer' => array( 'message' => __( '{site}: Order #{order_id} shipped. Carrier: {carrier}. Tracking: {tracking_number}. {tracking_url}', 'anar-toolkit' ) ),
			'status_processing' => array( 'message' => __( '{site}: Your order #{order_id} is being processed.', 'anar-toolkit' ) ),
			'status_completed' => array( 'message' => __( '{site}: Your order #{order_id} is complete.', 'anar-toolkit' ) ),
			'status_on-hold' => array( 'message' => __( '{site}: Your order #{order_id} is on hold.', 'anar-toolkit' ) ),
			'status_cancelled' => array( 'message' => __( '{site}: Your order #{order_id} has been cancelled.', 'anar-toolkit' ) ),
			'status_refunded' => array( 'message' => __( '{site}: Your order #{order_id} has been refunded.', 'anar-toolkit' ) ),
		);
		foreach ( array( 1 => 30, 2 => 360, 3 => 1440 ) as $stage => $minutes ) {
			$events[ 'recovery_' . $stage ] = array( 'message' => $recovery, 'minutes' => $minutes );
		}
		foreach ( $events as &$event ) {
			$event += array( 'template' => '', 'params' => '' );
		}
		unset( $event );
		return array(
			'paid_customer' => 0,
			'paid_admin'    => 0,
			'recovery'      => 0,
			'capture_mode' => 'automatic',
			'coupon_enabled' => 0,
			'coupon_type' => 'percent',
			'coupon_amount' => '10',
			'coupon_minimum' => '0',
			'coupon_stage' => 2,
			'coupon_hours' => 48,
			'coupon_individual' => 1,
			'coupon_exclude_sale' => 1,
			'tracking_meta_key' => '',
			'new_admin' => 0,
			'failed_admin' => 0,
			'shipment_customer' => 0,
			'status_processing' => 0,
			'status_completed' => 0,
			'status_on-hold' => 0,
			'status_cancelled' => 0,
			'status_refunded' => 0,
			'provider'      => 'kavenegar',
			'admin_phones'  => '',
			'start_hour'    => 9,
			'end_hour'      => 21,
			'daily_limit'   => 3,
			'retention'     => 30,
			'link_days'     => 7,
			'code_minutes'  => 30,
			'recovery_page_id' => 0,
			'events'        => $events,
		);
	}

	public function all() {
		$saved = get_option( self::OPTION, array() );
		if ( null !== $this->resolved && $saved === $this->source ) {
			return $this->resolved; }
		$this->source = $saved;
		$defaults = $this->defaults();
		$saved    = is_array( $saved ) ? $saved : array();
		$events   = isset( $saved['events'] ) && is_array( $saved['events'] ) ? $saved['events'] : array();
		foreach ( $defaults['events'] as $key => $event ) {
			$defaults['events'][ $key ] = wp_parse_args( isset( $events[ $key ] ) && is_array( $events[ $key ] ) ? $events[ $key ] : array(), $event );
		}
		unset( $saved['events'] );
		$this->resolved = wp_parse_args( $saved, $defaults );
		return $this->resolved;
	}

	public function get( $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	public function enabled() {
		foreach ( array_merge( array( 'recovery' ), array_keys( $this->defaults()['events'] ) ) as $key ) {
			if ( 'recovery' !== $key && ! \Anar\Login\Infrastructure\Features::enabled( 'orders' ) ) {
				continue; }
			if ( $this->get( $key ) && ! is_array( $this->get( $key ) ) ) {
				return true; }
		}
		return (bool) apply_filters( 'anar_toolkit_extra_modules_enabled', false );
	}

	public function sanitize( $input ) {
		if ( is_array( $input ) && ! empty( $input['_partial'] ) ) {
			unset( $input['_partial'] );
			$input = array_replace_recursive( $this->all(), $input ); }
		$input  = is_array( $input ) ? $input : array();
		$output = $this->defaults();
		foreach ( array( 'paid_customer', 'paid_admin', 'recovery', 'new_admin', 'failed_admin', 'shipment_customer', 'status_processing', 'status_completed', 'status_on-hold', 'status_cancelled', 'status_refunded', 'coupon_enabled', 'coupon_individual', 'coupon_exclude_sale' ) as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$output['provider'] = sanitize_key( $input['provider'] ?? 'kavenegar' );
		$output['capture_mode'] = 'explicit' === ( $input['capture_mode'] ?? '' ) ? 'explicit' : 'automatic';
		$output['coupon_type'] = 'fixed_cart' === ( $input['coupon_type'] ?? '' ) ? 'fixed_cart' : 'percent';
		foreach ( array( 'coupon_amount', 'coupon_minimum' ) as $key ) {
			$raw = Identity::latin_digits( (string) ( $input[ $key ] ?? $output[ $key ] ) );
			$output[ $key ] = (string) round( max( 0, min( 1000000000, (float) $raw ) ), 2 );
		}
		if ( 'percent' === $output['coupon_type'] ) {
			$output['coupon_amount'] = (string) min( 100, (float) $output['coupon_amount'] ); }
		$output['coupon_stage'] = max( 1, min( 3, (int) ( $input['coupon_stage'] ?? 2 ) ) );
		$output['coupon_hours'] = max( 1, min( 720, (int) ( $input['coupon_hours'] ?? 48 ) ) );
		$output['tracking_meta_key'] = sanitize_key( $input['tracking_meta_key'] ?? '' );
		$phones = array();
		foreach ( preg_split( '/[\s,;]+/', (string) ( $input['admin_phones'] ?? '' ) ) as $phone ) {
			$identity = Identity::normalize( $phone );
			if ( ! is_wp_error( $identity ) && 'sms' === $identity['channel'] ) {
				$phones[] = $identity['identity'];
			}
		}
		$output['admin_phones'] = implode( "\n", array_slice( array_unique( $phones ), 0, 5 ) );
		foreach ( array( 'start_hour' => array( 0, 23 ), 'end_hour' => array( 1, 24 ), 'daily_limit' => array( 1, 10 ), 'retention' => array( 7, 90 ), 'link_days' => array( 1, 30 ), 'code_minutes' => array( 5, 1440 ) ) as $key => $bounds ) {
			$output[ $key ] = max( $bounds[0], min( $bounds[1], (int) ( $input[ $key ] ?? $output[ $key ] ) ) );
		}
		$output['link_days'] = min( $output['link_days'], $output['retention'] );
		$output['recovery_page_id'] = max( 0, (int) ( $input['recovery_page_id'] ?? 0 ) );
		if ( $output['end_hour'] <= $output['start_hour'] ) {
			$output['end_hour'] = $output['start_hour'] + 1;
		}
		$previous = 0;
		foreach ( $output['events'] as $key => &$event ) {
			$raw = isset( $input['events'][ $key ] ) && is_array( $input['events'][ $key ] ) ? $input['events'][ $key ] : array();
			foreach ( array( 'message', 'template', 'params' ) as $field ) {
				$event[ $field ] = sanitize_textarea_field( is_scalar( $raw[ $field ] ?? null ) ? $raw[ $field ] : $event[ $field ] );
			}
			if ( isset( $event['minutes'] ) ) {
				$minutes          = (int) Identity::latin_digits( (string) ( $raw['minutes'] ?? $event['minutes'] ) );
				$maximum          = min( 43200, $output['link_days'] * 1440 - 1 );
				$event['minutes'] = $minutes > 0 && $previous < $maximum ? max( $previous + 1, min( $maximum, $minutes ) ) : 0;
				$previous         = max( $previous, $event['minutes'] );
			}
		}
		unset( $event );
		return $output;
	}

	/** Timestamp of the next allowed recovery send, in the site's timezone. */
	public function allowed_time( $timestamp ) {
		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
		$start = (int) $this->get( 'start_hour' );
		$end   = (int) $this->get( 'end_hour' );
		$hour  = (int) $local->format( 'G' );
		if ( $hour < $start ) {
			return $local->setTime( $start, 0 )->getTimestamp();
		}
		if ( $hour >= $end ) {
			return $local->modify( '+1 day' )->setTime( $start, 0 )->getTimestamp();
		}
		return $timestamp;
	}
}
