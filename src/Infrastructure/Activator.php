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
		update_option( 'anar_login_db_version', ANAR_LOGIN_VERSION, false );

		if ( ! wp_next_scheduled( 'anar_login_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'anar_login_daily_cleanup' );
		}

		add_option( Settings::OPTION_KEY, ( new Settings() )->defaults(), '', false );
	}

	/**
	 * Unschedule cleanup without deleting user data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
	}
}
