<?php
/**
 * Activation and scheduled cleanup.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Activator {
	/**
	 * Install database structures.
	 *
	 * @return void
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'anar_login_otp';
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			identity_hash char(64) NOT NULL,
			channel varchar(10) NOT NULL,
			code_hash varchar(255) NOT NULL,
			expires_at datetime NOT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			consumed_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY identity_channel (identity_hash, channel),
			KEY expires_at (expires_at)
		) {$charset};";

		dbDelta( $sql );
		$limits = $wpdb->prefix . 'anar_login_limits';
		dbDelta(
			"CREATE TABLE {$limits} (
				limit_key varchar(191) NOT NULL,
				attempts int unsigned NOT NULL DEFAULT 0,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (limit_key),
				KEY expires_at (expires_at)
			) {$charset};"
		);
		\Anar\Login\Commerce\Repository::install();
			\Anar\Login\Marketing\Repository::install();
		// Autoloaded: the version is compared on every request, so it must not cost its own query.
		update_option( 'anar_login_db_version', ANAR_LOGIN_VERSION, true );
		update_option( 'anar_game_money_schema', '1', true );
		// Capped game coupons keep their WooCommerce filters after the games module stops;
		// stores that never issued one skip those filters entirely.
		update_option( 'anar_game_budget_coupons', (int) (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE meta_key = %s LIMIT 1', $wpdb->postmeta, '_anar_game_money_cap' ) ), true );

		if ( ! wp_next_scheduled( 'anar_login_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'anar_login_daily_cleanup' );
		}

		add_option( Settings::OPTION_KEY, ( new Settings() )->defaults(), '', false );
		add_option( 'anar_toolkit_commerce', array(), '', false );
		add_option( 'anar_toolkit_marketing', array(), '', false );
		foreach ( array( Settings::OPTION_KEY, 'anar_toolkit_commerce', 'anar_toolkit_marketing' ) as $option ) {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $option, false ); } else {
				$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $option ) );
				wp_cache_delete( 'alloptions', 'options' ); }
		}
		Features::refresh();
		\Anar\Login\Balance\Monitor::sync();
	}

	/**
	 * Unschedule cleanup without deleting user data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
		wp_clear_scheduled_hook( 'anar_marketing_import_batch' );
		wp_unschedule_hook( 'anar_sms_balance_refresh' );
		\Anar\Login\Commerce\Worker::unschedule();
	}
}
