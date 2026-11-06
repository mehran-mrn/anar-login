<?php
/**
 * Optional uninstall cleanup.
 *
 * @package AnarLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
wp_clear_scheduled_hook( 'anar_toolkit_process_queue' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'anar_toolkit_process_queue', array(), 'anar-toolkit' );
}

$anar_login_settings = get_option( 'anar_login_settings', array() );
if ( empty( $anar_login_settings['delete_data'] ) ) {
	return;
}

global $wpdb;

$anar_login_table = $wpdb->prefix . 'anar_login_otp';
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $anar_login_table ) );
$anar_login_limits = $wpdb->prefix . 'anar_login_limits';
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $anar_login_limits ) );
foreach ( array( 'contacts', 'carts', 'messages' ) as $anar_toolkit_name ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'anar_toolkit_' . $anar_toolkit_name ) );
}
delete_option( 'anar_toolkit_commerce' );
delete_option( 'anar_toolkit_worker_lock' );
delete_option( 'anar_toolkit_scan_cursor' );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_phone' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_google_sub' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_google_avatar' ), array( '%s' ) );
delete_option( 'anar_login_settings' );
delete_option( 'anar_login_db_version' );
delete_option( 'anar_login_theme_logo' );
wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
