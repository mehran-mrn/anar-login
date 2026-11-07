<?php
/**
 * Opt-in periodic credit checks and cached administrator alerts.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Balance;

use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Monitor {
	const OPTION = 'anar_toolkit_sms_balance';
	const HOOK = 'anar_sms_balance_refresh';
	const INTERVAL = 1800;
	const FRESH = 3600;
	const STATE = 'anar_sms_balance_state_';
	const LOCK = 'anar_sms_balance_lock_';

	public static function settings() {
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	public static function account( $provider ) {
		$settings = self::settings();
		$saved = $settings['accounts'][ $provider ] ?? array();
		return array_replace( array( 'monitor' => 0, 'warn' => 0, 'threshold' => '', 'unit' => '' ), is_array( $saved ) ? $saved : array() );
	}

	public static function threshold( $raw ) {
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$raw = trim( Identity::latin_digits( (string) $raw ) );
		if ( preg_match( '/^[0-9]{1,3}(?:[,٬][0-9]{3})+(?:[.٫][0-9]{1,4})?$/uD', $raw ) ) {
			$raw = str_replace( array( ',', '٬' ), '', $raw );
		}
		$raw = str_replace( '٫', '.', $raw );
		$value = preg_match( '/^[0-9]+(?:\.[0-9]{1,4})?$/D', $raw ) ? Gateways::number( $raw ) : null;
		return null !== $value && $value <= 1000000000000 ? $value : null;
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$settings = array( 'enabled' => empty( $input['enabled'] ) ? 0 : 1, 'accounts' => array() );
		foreach ( Gateways::providers() as $provider ) {
			$raw = $input['accounts'][ $provider ] ?? array();
			$raw = is_array( $raw ) ? $raw : array();
			$old = self::account( $provider );
			$monitor = ! empty( $raw['monitor'] ) && '' !== Accounts::fingerprint( $provider );
			$warn = $monitor && ! empty( $raw['warn'] );
			$threshold = self::threshold( $raw['threshold'] ?? $old['threshold'] );
			$state = self::state( $provider );
			if ( $warn && ( null === $threshold || empty( $state['unit'] ) || ! is_string( $raw['unit'] ?? null ) || $state['unit'] !== $raw['unit'] ) ) {
				return new WP_Error( 'anar_balance_threshold', __( 'Retrieve the account balance first, then enter a valid alert threshold in the displayed unit.', 'anar-toolkit' ) );
			}
			$settings['accounts'][ $provider ] = array( 'monitor' => (int) $monitor, 'warn' => (int) $warn, 'threshold' => null === $threshold ? '' : (string) $threshold, 'unit' => $warn ? $state['unit'] : (string) $old['unit'] );
		}
		return $settings;
	}

	public static function state( $provider ) {
		if ( ! in_array( $provider, Gateways::providers(), true ) ) {
			return array();
		}
		$state = get_option( self::STATE . $provider, array() );
		$fingerprint = Accounts::fingerprint( $provider );
		return $fingerprint && is_array( $state ) && hash_equals( $fingerprint, (string) ( $state['account'] ?? '' ) ) ? $state : array();
	}

	public static function low( $provider, $now = null ) {
		if ( ! Features::enabled( 'balance' ) ) {
			return false;
		}
		$config = self::account( $provider );
		if ( empty( $config['monitor'] ) || empty( $config['warn'] ) ) {
			return false;
		}
		$state = self::state( $provider );
		$threshold = self::threshold( $config['threshold'] );
		if ( null === $threshold || ! empty( $state['error'] ) || ! isset( $state['value'], $state['unit'] ) || empty( $state['success_at'] ) || empty( $config['unit'] ) ) {
			return false;
		}
		$age = ( null === $now ? time() : $now ) - $state['success_at'];
		return self::FRESH >= $age && $config['unit'] === $state['unit'] && $state['value'] <= $threshold;
	}

	public static function sync() {
		if ( ! Features::enabled( 'balance' ) ) {
			wp_unschedule_hook( self::HOOK );
			return;
		}
		$slot = 0;
		foreach ( Gateways::providers() as $provider ) {
			$args = array( $provider );
			if ( empty( self::account( $provider )['monitor'] ) || ! Accounts::fingerprint( $provider ) ) {
				wp_clear_scheduled_hook( self::HOOK, $args );
				continue;
			}
			if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
				wp_schedule_single_event( time() + 60 + $slot * 15, self::HOOK, $args );
			}
			++$slot;
		}
	}

	public function register() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	public function run( $provider ) {
		if ( ! in_array( $provider, Gateways::providers(), true ) || ! Features::enabled( 'balance' ) || empty( self::account( $provider )['monitor'] ) ) {
			return;
		}
		$this->refresh( $provider, false );
		if ( Features::enabled( 'balance' ) && ! empty( self::account( $provider )['monitor'] ) && Accounts::fingerprint( $provider ) && ! wp_next_scheduled( self::HOOK, array( $provider ) ) ) {
			wp_schedule_single_event( time() + self::INTERVAL, self::HOOK, array( $provider ) );
		}
	}

	private static function release( $provider, $lease ) {
		global $wpdb;
		$key = self::LOCK . $provider;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => maybe_serialize( $lease ) ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	public function refresh( $provider, $manual = true ) {
		if ( ! Features::enabled( 'balance' ) || empty( self::settings()['enabled'] ) || ! in_array( $provider, Gateways::providers(), true ) || ( ! $manual && empty( self::account( $provider )['monitor'] ) ) ) {
			return new WP_Error( 'anar_balance_off', __( 'Enable SMS credit monitoring before checking an account.', 'anar-toolkit' ) );
		}
		$config = ( new Settings() )->provider( $provider );
		$fingerprint = Accounts::fingerprint( $provider, $config );
		if ( ! $fingerprint ) {
			return new WP_Error( 'anar_balance_setup', __( 'Complete the SMS account connection settings first.', 'anar-toolkit' ) );
		}
		$previous = self::state( $provider );
		if ( ! empty( $previous['checked_at'] ) && time() - $previous['checked_at'] < 60 ) {
			return new WP_Error( 'anar_balance_wait', __( 'Please wait one minute before checking this account again.', 'anar-toolkit' ) );
		}
		$key = self::LOCK . $provider;
		$old = get_option( $key );
		if ( is_array( $old ) && ( $old['expires'] ?? 0 ) < time() ) {
			self::release( $provider, $old );
		}
		$lease = array( 'expires' => time() + 60, 'id' => wp_generate_uuid4() );
		if ( ! add_option( $key, $lease, '', false ) ) {
			return new WP_Error( 'anar_balance_busy', __( 'A balance check is already in progress.', 'anar-toolkit' ) );
		}
		try {
			try {
				$result = ( new Gateways() )->fetch( $provider, $config );
			} catch ( \Throwable $exception ) {
				$result = new WP_Error( 'anar_balance_connection', __( 'Could not retrieve the balance. Check the account connection or try again later.', 'anar-toolkit' ) );
			}
			if ( ! Features::enabled( 'balance' ) || ! hash_equals( $fingerprint, Accounts::fingerprint( $provider ) ) ) {
				return new WP_Error( 'anar_balance_changed', __( 'The account settings changed. Check the balance again.', 'anar-toolkit' ) );
			}
			$record = array_merge( $previous, array( 'account' => $fingerprint, 'checked_at' => time(), 'error' => '' ) );
			if ( is_wp_error( $result ) ) {
				$record['error'] = $result->get_error_code();
			} else {
				$record = array_merge( $record, $result, array( 'success_at' => time() ) );
			}
			update_option( self::STATE . $provider, $record, false );
			return $result;
		} finally {
			self::release( $provider, $lease );
		}
	}

	public static function unit_label( $unit ) {
		$labels = array( 'IRR' => __( 'rial', 'anar-toolkit' ), 'IRT' => __( 'toman', 'anar-toolkit' ), 'SMS_UNIT' => __( 'SMS units', 'anar-toolkit' ), 'IR' => __( 'credit (IR)', 'anar-toolkit' ), 'CREDIT' => __( 'provider credit units', 'anar-toolkit' ) );
		return $labels[ $unit ] ?? __( 'provider credit units', 'anar-toolkit' );
	}

	public static function formatted( $state ) {
		return self::amount( $state['value'] ) . ' ' . self::unit_label( $state['unit'] );
	}

	public static function amount( $value ) {
		$value = (float) $value;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.number_format_number_format -- Apply distinct financial separators explicitly, including installations with incomplete locale metadata.
		$number = number_format( $value, floor( $value ) === $value ? 0 : 2, '.', ',' );
		if ( 0 === strpos( determine_locale(), 'fa' ) ) {
			$number = strtr( $number, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬', '.' => '٫' ) );
		}
		return $number;
	}
}
