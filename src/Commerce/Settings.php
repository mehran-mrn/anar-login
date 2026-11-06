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

	const OPTION = 'anar_toolkit_commerce';

	public function defaults() {
		$recovery = __( "{site}: Your purchase recovery code is {code} (valid for {minutes} minutes).\nEnter it here: {recovery_page}\nStop reminders: {unsubscribe_url}", 'anar-toolkit' );
		$events   = array(
			'paid_customer' => array( 'message' => __( '{site}: Payment received for order #{order_id}. Total: {total} {currency}.', 'anar-toolkit' ) ),
			'paid_admin'    => array( 'message' => __( '{site}: Order #{order_id} paid by {name}. Total: {total} {currency}.', 'anar-toolkit' ) ),
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
		$defaults = $this->defaults();
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$events   = isset( $saved['events'] ) && is_array( $saved['events'] ) ? $saved['events'] : array();
		foreach ( $defaults['events'] as $key => $event ) {
			$defaults['events'][ $key ] = wp_parse_args( isset( $events[ $key ] ) && is_array( $events[ $key ] ) ? $events[ $key ] : array(), $event );
		}
		unset( $saved['events'] );
		return wp_parse_args( $saved, $defaults );
	}

	public function get( $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	public function enabled() {
		return $this->get( 'recovery' ) || $this->get( 'paid_customer' ) || $this->get( 'paid_admin' );
	}

	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = $this->defaults();
		foreach ( array( 'paid_customer', 'paid_admin', 'recovery' ) as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		$output['provider'] = sanitize_key( $input['provider'] ?? 'kavenegar' );
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
