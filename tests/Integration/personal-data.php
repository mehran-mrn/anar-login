<?php
/**
 * Local-only erasure: no mutation before preview and explicit confirmation.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\PersonalData;
use Anar\Login\Commerce\Privacy;
use Anar\Login\Commerce\Repository;
use Anar\Login\Commerce\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$original_user = get_current_user_id();
wp_set_current_user( 1 );
$commerce = new Repository();
$marketing = new \Anar\Login\Marketing\Repository();
$privacy = new Privacy( $commerce, new Settings() );
$marketing_privacy = new \Anar\Login\Marketing\Privacy();
add_action( 'anar_toolkit_erase_phone', array( $marketing_privacy, 'erase_phone' ) );
$phone = '+989000088871';
$other_phone = '+989000088872';
$failure_phone = '+989000088874';
$user_ids = array();
$order_id = 0;
$tokens = array();
$checks = 0;
$fixture_started = false;
$block_delete = null;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	++$checks;
};
$request = static function ( $values ) use ( $privacy, &$tokens ) {
	$result = PersonalData::process( array_merge( array( '_wpnonce' => wp_create_nonce( 'anar_personal_data' ) ), $values ), $privacy );
	if ( isset( $result['preview']['token'] ) ) {
		$tokens[] = array( get_current_user_id(), $result['preview']['token'] );
	}
	return $result;
};
try {
	$assert( ! $privacy->phone_summary( $phone )['found'] && ! $privacy->phone_summary( $other_phone )['found'], 'The test numbers must have no existing local records.' );
	$assert( ! $privacy->phone_summary( $failure_phone )['found'], 'The failure-test number must have no existing local records.' );
	$fixture_started = true;
	$user_id = wp_insert_user( array( 'user_login' => 'anar-privacy-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	$assert( ! is_wp_error( $user_id ), 'Could not create an isolated account.' );
	$user_ids[] = $user_id;
	update_user_meta( $user_id, '_anar_phone', $phone );
	$contact_id = $commerce->capture_contact( $phone, '', 'account' );
	$commerce->save_cart( wp_generate_password( 32, false ), $contact_id, array(), 100, 'IRR' );
	$commerce->sms_result( $phone, 'login_otp', 'test', true );
	$profile_id = $marketing->remember( $phone, '', 'Privacy fixture', $user_id );
	$commerce->capture_contact( $other_phone, '', 'account' );
	global $wpdb;
	$wpdb->insert( $marketing->table( 'stock' ), array( 'profile_id' => $profile_id, 'product_id' => 987654, 'state' => 'waiting', 'created_at' => time(), 'updated_at' => time() ) );
	if ( function_exists( 'wc_create_order' ) ) {
		$fixture_order = wc_create_order( array( 'customer_id' => $user_id ) );
		$fixture_order->set_billing_phone( $phone );
		$order_id = $fixture_order->save();
	}
	$before = $privacy->phone_summary( $phone );
	$assert( array( 'contact' => 1, 'carts' => 1, 'messages' => 1, 'profile' => 1, 'campaigns' => 1 ) === $before['counts'], 'Preview omitted a local data category.' );
	wp_set_current_user( $user_id );
	$result = $request( array( 'anar_privacy_action' => 'search', 'phone' => $phone ) );
	$assert( 'error' === $result['type'], 'A subscriber could inspect private records.' );
	wp_set_current_user( 1 );
	$commerce->sms_result( $failure_phone, 'login_otp', 'test', true );
	$result = $request( array( 'anar_privacy_action' => 'search', 'phone' => $failure_phone ) );
	$failure_preview = $result['preview'];
	$block_delete = static function ( $query ) use ( $failure_phone, $commerce ) {
		if ( 0 === stripos( $query, 'DELETE' ) && strpos( $query, $commerce->table( 'messages' ) ) !== false && strpos( $query, $failure_phone ) !== false ) {
			return 'SELECT 1';
		}
		return $query;
	};
	add_filter( 'query', $block_delete );
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $failure_preview['token'], 'confirmed' => '1' ) );
	remove_filter( 'query', $block_delete );
	$assert( 'error' === $result['type'] && $privacy->phone_summary( $failure_phone )['found'], 'Incomplete deletion was incorrectly reported as success.' );
	$result = $request( array( 'anar_privacy_action' => 'search', 'phone' => 'invalid' ) );
	$assert( 'error' === $result['type'], 'Invalid numbers need visible feedback.' );
	$result = $request( array( 'anar_privacy_action' => 'search', 'phone' => '+989000088873' ) );
	$assert( 'info' === $result['type'] && ! isset( $result['preview'] ), 'An absent number must not offer deletion.' );
	$result = $request( array( 'anar_privacy_action' => 'search', 'phone' => '۰۹۰۰۰۰۸۸۸۷۱' ) );
	$assert( isset( $result['preview'] ) && $phone === $result['preview']['phone'], 'Persian phone normalization or search failed.' );
	$assert( $before === $privacy->phone_summary( $phone ), 'Searching changed local data.' );
	$erasure_preview = $result['preview'];
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $erasure_preview['token'] ) );
	$assert( 'error' === $result['type'] && $before === $privacy->phone_summary( $phone ), 'Unconfirmed erasure changed records.' );
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $erasure_preview['token'], 'confirmed' => '1', '_wpnonce' => 'invalid' ) );
	$assert( 'error' === $result['type'] && $before === $privacy->phone_summary( $phone ), 'An invalid nonce changed records.' );
	$other_admin = wp_insert_user( array( 'user_login' => 'anar-privacy-admin-' . wp_generate_password( 12, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );
	$user_ids[] = $other_admin;
	wp_set_current_user( $other_admin );
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $erasure_preview['token'], 'confirmed' => '1' ) );
	$assert( 'error' === $result['type'] && $before === $privacy->phone_summary( $phone ), 'Another administrator used a private preview.' );
	wp_set_current_user( 1 );
	$commerce->sms_result( $phone, 'login_otp', 'test', true );
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $erasure_preview['token'], 'confirmed' => '1' ) );
	$assert( 'error' === $result['type'] && 2 === $privacy->phone_summary( $phone )['counts']['messages'], 'A stale preview erased changed data.' );
	$result = $request( array( 'anar_privacy_action' => 'search', 'phone' => $phone ) );
	$erasure_preview = $result['preview'];
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $erasure_preview['token'], 'confirmed' => '1', 'phone' => $other_phone ) );
	$assert( 'success' === $result['type'] && ! $privacy->phone_summary( $phone )['found'], 'Confirmed erasure did not finish or report success.' );
	$assert( $privacy->phone_summary( $other_phone )['found'], 'A tampered phone input changed another number.' );
	$assert( get_userdata( $user_id ) && get_user_meta( $user_id, '_anar_phone', true ) === $phone, 'Erasure changed the WordPress account or login number.' );
	$assert( ! $order_id || wc_get_order( $order_id ), 'Erasure deleted a WooCommerce order.' );
	$tombstone = $commerce->contact_by_phone( $phone );
	$assert( $tombstone && ! $tombstone->phone && $tombstone->suppressed, 'Erasure did not keep only the suppression marker.' );
	$result = $request( array( 'anar_privacy_action' => 'erase', 'token' => $erasure_preview['token'], 'confirmed' => '1' ) );
	$assert( 'error' === $result['type'], 'A consumed preview allowed repeated deletion.' );
	WP_CLI::success( $checks . ' personal-data checks passed.' );
} finally {
	wp_set_current_user( 1 );
	if ( $block_delete ) {
		remove_filter( 'query', $block_delete );
	}
	foreach ( $tokens as $token ) {
		$key = 'anar_privacy_' . $token[0] . '_' . hash( 'sha256', $token[1] );
		delete_transient( $key );
		delete_option( $key );
	}
	foreach ( $fixture_started ? array( $phone, $other_phone, $failure_phone ) : array() as $number ) {
		$privacy->erase_phone( $number );
		$wpdb->delete( $commerce->table( 'contacts' ), array( 'phone_hash' => Repository::phone_hash( $number ) ) );
	}
	if ( $order_id && wc_get_order( $order_id ) ) {
		wc_get_order( $order_id )->delete( true );
	}
	foreach ( $user_ids as $fixture_id ) {
		wp_delete_user( $fixture_id );
	}
	remove_action( 'anar_toolkit_erase_phone', array( $marketing_privacy, 'erase_phone' ) );
	wp_set_current_user( $original_user );
}
