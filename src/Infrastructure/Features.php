<?php
/**
 * Lightweight feature switches. Reads autoloaded options, never customer tables.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Features {
	const OPTION = 'anar_toolkit_features';

	private static $refreshing = false;

	private static function empty_flags() {
		return array_fill_keys( array_merge( array( 'login', 'reset', 'two_factor', 'login_log', 'recovery', 'orders', '_invoice_sms' ), self::marketing_keys() ), false );
	}

	public static function all() {
		$saved = (array) get_option( self::OPTION, array() );
		if ( ! empty( $saved['_snapshot'] ) && isset( $saved['enabled'] ) && is_array( $saved['enabled'] ) ) {
			return array_replace( self::empty_flags(), $saved['enabled'] ); }
		// Upgrade/test fallback; normal requests use only the small autoloaded snapshot.
		return self::derive( $saved )['enabled'];
	}

	private static function derive( $saved ) {
		$core = isset( $saved['switches'] ) && is_array( $saved['switches'] ) ? $saved['switches'] : $saved;
		$switches = array_replace( array( 'login' => 1, 'reset' => 1, 'two_factor' => 1, 'login_log' => 0, 'orders' => 1 ), array_intersect_key( $core, array_flip( array( 'login', 'reset', 'two_factor', 'login_log', 'orders' ) ) ) );
		$login = (array) get_option( 'anar_login_settings', array() );
		$commerce = (array) get_option( 'anar_toolkit_commerce', array() );
		$marketing = (array) get_option( 'anar_toolkit_marketing', array() );
		$enabled = self::empty_flags();
		$enabled['login'] = ! empty( $switches['login'] );
		$enabled['reset'] = ! empty( $switches['reset'] ) && ( ! isset( $login['password_reset_enabled'] ) || ! empty( $login['password_reset_enabled'] ) );
		$enabled['two_factor'] = ! empty( $switches['two_factor'] ) && 'off' !== ( $login['two_factor'] ?? 'off' );
		$enabled['login_log'] = ! empty( $switches['login_log'] );
		$enabled['recovery'] = ! empty( $commerce['recovery'] );
		foreach ( self::order_events() as $event ) {
			$enabled['orders'] = $enabled['orders'] || ( ! empty( $switches['orders'] ) && ! empty( $commerce[ $event ] ) ); }
		foreach ( self::marketing_keys() as $key ) {
			$enabled[ $key ] = ! empty( $marketing['modules'][ $key ] ); }
		$enabled['_invoice_sms'] = $enabled['invoice'] && ! empty( $marketing['rules']['invoice']['enabled'] );
		return array( '_snapshot' => 1, 'switches' => $switches, 'enabled' => $enabled );
	}

	public static function refresh( $core = null ) {
		if ( self::$refreshing ) {
			return (array) get_option( self::OPTION, array() ); }
		self::$refreshing = true;
		try {
			$snapshot = self::derive( null === $core ? (array) get_option( self::OPTION, array() ) : (array) $core );
			update_option( self::OPTION, $snapshot, true );
			return $snapshot;
		} finally {
			self::$refreshing = false; }
	}

	public static function enabled( $key ) {
		return ! empty( self::all()[ $key ] ); }

	public static function order_events() {
		return array( 'paid_customer', 'paid_admin', 'new_admin', 'failed_admin', 'shipment_customer', 'status_processing', 'status_completed', 'status_on-hold', 'status_cancelled', 'status_refunded' );
	}

	public static function marketing_keys() {
		return array( 'lifecycle', 'prediction', 'smart', 'bulk', 'stock', 'survey', 'next_discount', 'birthday', 'invoice', 'games', 'referral', 'traffic' );
	}

	public static function marketing() {
		return (bool) array_filter( array_intersect_key( self::all(), array_flip( self::marketing_keys() ) ) );
	}

	public static function queue_needed() {
		$flags = self::all();
		foreach ( array( 'recovery', 'orders', 'lifecycle', 'prediction', 'smart', 'bulk', 'stock', 'survey', 'next_discount', 'birthday', 'referral' ) as $key ) {
			if ( $flags[ $key ] ) {
				return true; }
		}
		return ! empty( $flags['_invoice_sms'] );
	}

	public static function facts_needed() {
		$flags = self::all();
		foreach ( array( 'lifecycle', 'prediction', 'smart', 'bulk', 'survey', 'next_discount', 'birthday', 'games', 'referral', 'traffic' ) as $key ) {
			if ( $flags[ $key ] ) {
				return true; }
		}
		return ! empty( $flags['_invoice_sms'] );
	}

	public static function workspace_tabs() {
		$flags = self::all();
		$tabs = array();
		if ( $flags['recovery'] ) {
			$tabs['overview'] = __( 'Overview', 'anar-toolkit' );
			$tabs['carts'] = __( 'Abandoned carts', 'anar-toolkit' ); }
		if ( self::queue_needed() || $flags['login_log'] ) {
			$tabs['messages'] = __( 'Messages', 'anar-toolkit' ); }
		if ( self::marketing() ) {
			$active = array_keys( array_filter( array_intersect_key( $flags, array_flip( self::marketing_keys() ) ) ) );
			$tabs['campaigns'] = 1 === count( $active ) ? \Anar\Login\Marketing\Settings::modules()[ $active[0] ] : __( 'Campaigns & CRM', 'anar-toolkit' ); }
		if ( $flags['recovery'] || $flags['orders'] ) {
			$tabs['settings'] = __( 'Automations & discounts', 'anar-toolkit' );
			$tabs['templates'] = __( 'Message templates', 'anar-toolkit' ); }
		$tabs['privacy'] = __( 'Data controls', 'anar-toolkit' );
		return $tabs;
	}

	public static function current_tab() {
		if ( self::current_module() ) {
			return 'campaigns'; }
		$tabs = self::workspace_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator navigation.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
	}

	public static function current_module() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator page selection.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$key = 0 === strpos( $page, 'anar-toolkit-' ) ? substr( $page, strlen( 'anar-toolkit-' ) ) : '';
		return in_array( $key, self::marketing_keys(), true ) && self::enabled( $key ) ? $key : '';
	}

	public static function changed( $option, $old, $value ) {
		if ( self::$refreshing || ! in_array( $option, array( self::OPTION, 'anar_login_settings', 'anar_toolkit_commerce', 'anar_toolkit_marketing' ), true ) || $old === $value ) {
			return; }
		$previous = self::OPTION === $option ? (array) $old : (array) get_option( self::OPTION, array() );
		$snapshot = self::refresh( self::OPTION === $option ? $value : null );
		if ( in_array( $option, array( self::OPTION, 'anar_login_settings' ), true ) && ( $previous['enabled'] ?? null ) === $snapshot['enabled'] ) {
			return; }

		if ( ! self::facts_needed() ) {
			wp_clear_scheduled_hook( 'anar_marketing_import_batch' );
			update_option( 'anar_marketing_import_active', 0, false ); }
		if ( self::queue_needed() && class_exists( 'WooCommerce' ) ) {
			$settings = new \Anar\Login\Commerce\Settings();
			$repo = new \Anar\Login\Commerce\Repository();
			$worker = new \Anar\Login\Commerce\Worker( $settings, $repo, new \Anar\Login\Commerce\Module( $settings, $repo ), new \Anar\Login\Messaging\NotificationSender( new Settings(), new \Anar\Login\Messaging\HttpClient() ) );
			$worker->schedule();
		} else {
			\Anar\Login\Commerce\Worker::unschedule(); }
		global $wpdb;
		$flags = self::all();
		$commerce = (array) get_option( 'anar_toolkit_commerce', array() );
		$events = array();
		foreach ( array_merge( self::order_events(), array( 'recovery_1', 'recovery_2', 'recovery_3', 'recovery_request' ) ) as $event ) {
			if ( 0 === strpos( $event, 'recovery_' ) ? ! $flags['recovery'] : ( ! $flags['orders'] || empty( $commerce[ $event ] ) ) ) {
				$events[] = $event; }
		}
		$marketing = (array) get_option( 'anar_toolkit_marketing', array() );
		foreach ( array( 'welcome' => 'lifecycle', 'vip' => 'lifecycle', 'return_1' => 'lifecycle', 'return_2' => 'lifecycle', 'return_3' => 'lifecycle', 'prediction_before' => 'prediction', 'prediction_on' => 'prediction', 'prediction_after' => 'prediction', 'smart' => 'smart', 'bulk' => 'bulk', 'stock' => 'stock', 'survey' => 'survey', 'next_discount' => 'next_discount', 'birthday' => 'birthday', 'invoice' => 'invoice' ) as $event => $module ) {
			if ( ! $flags[ $module ] || ( isset( $marketing['rules'][ $event ]['enabled'] ) && ! $marketing['rules'][ $event ]['enabled'] ) ) {
				$events[] = 'mkt_' . $event; }
		}
		foreach ( $events as $event ) {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', error_code = 'module_disabled' WHERE status = 'pending' AND event = %s", $wpdb->prefix . 'anar_toolkit_messages', $event ) ); }
	}
}
