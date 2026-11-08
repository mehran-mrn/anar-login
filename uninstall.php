<?php
/**
 * Optional uninstall cleanup.
 *
 * @package AnarLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
wp_clear_scheduled_hook( 'anar_toolkit_process_queue' );
wp_clear_scheduled_hook( 'anar_marketing_import_batch' );
wp_unschedule_hook( 'anar_sms_balance_refresh' );
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
delete_option( 'anar_toolkit_features' );
delete_option( 'anar_toolkit_sms_balance' );
foreach ( array( 'melipayamak', 'kavenegar', 'ippanel', 'panelchi', 'maxsms', 'smsir', 'farazsms' ) as $anar_balance_provider ) {
	delete_option( 'anar_sms_balance_state_' . $anar_balance_provider );
	delete_option( 'anar_sms_balance_lock_' . $anar_balance_provider );
}
delete_option( 'anar_marketing_import_lock' );
delete_option( 'anar_game_pool_migrated' );
delete_option( 'anar_game_budget_scan' );
delete_option( 'anar_game_budget_cursor' );
delete_option( 'anar_game_money_schema' );
delete_option( 'anar_game_coupon_storage' );
delete_option( 'anar_toolkit_worker_lock' );
delete_option( 'anar_toolkit_scan_cursor' );
foreach ( array( 'profiles', 'orders', 'purchases', 'jobs', 'stock', 'feedback', 'plays', 'game_awards', 'referrals', 'bulk', 'visits' ) as $anar_marketing_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'anar_marketing_' . $anar_marketing_table ) );
}
foreach ( array( 'anar_toolkit_marketing', 'anar_marketing_profile_cursor', 'anar_marketing_import_active', 'anar_marketing_import_page' ) as $anar_marketing_option ) {
	delete_option( $anar_marketing_option ); }
foreach ( array( 'anar_toolkit_messages_', 'anar_reward_', 'anar_bulk_launch_', '_transient_anar_bulk_preview_', '_transient_timeout_anar_bulk_preview_' ) as $anar_marketing_prefix ) {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $anar_marketing_prefix ) . '%' ) );
}
// Preserve WooCommerce financial records, expire personal gifts and remove targeting metadata.
$anar_coupon_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_anar_phone_hash' ) );
foreach ( $anar_coupon_ids as $anar_coupon_id ) {
	update_post_meta( $anar_coupon_id, 'date_expires', time() - 1 );
	foreach ( array( '_anar_phone_hash', '_anar_marketing_profile', '_anar_marketing_origin', '_anar_cart_id' ) as $anar_coupon_key ) {
		delete_post_meta( $anar_coupon_id, $anar_coupon_key ); }
}
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_birthday' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_referral_code' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_phone' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_google_sub' ), array( '%s' ) );
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_anar_google_avatar' ), array( '%s' ) );
delete_option( 'anar_login_settings' );
delete_option( 'anar_login_db_version' );
delete_option( 'anar_login_theme_logo' );
wp_clear_scheduled_hook( 'anar_login_daily_cleanup' );
