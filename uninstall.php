<?php
/**
 * Optional uninstall cleanup.
 *
 * @package AnarLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'anar_login_settings', array() );
if ( empty( $settings['delete_data'] ) ) {
	return;
}

global $wpdb;

$table = $wpdb->prefix . 'anar_login_otp';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_phone' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_google_sub' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_google_avatar' ), array( '%s' ) );
delete_option( 'anar_login_settings' );
delete_option( 'anar_login_db_version' );
wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
