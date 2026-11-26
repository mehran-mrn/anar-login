<?php
/**
 * Account migration on disposable WordPress: ambiguity, stale previews and batching.
 *
 * Threat model: a billing field, skipped verification, conflicting source or stale
 * preview must never replace an account identity. No account or order is recreated.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\UserTools;
use Anar\Login\Admin\DigitsNotice;
use Anar\Login\Support\PhoneDirectory;
use Anar\Login\Support\UserMigration;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_set_current_user( 1 );
$original = get_option( UserMigration::COMPLETE, null );
$original_detection = get_option( DigitsNotice::DETECTED, null );
$original_seen = get_user_meta( 1, DigitsNotice::SEEN, true );
$original_claim = get_option( DigitsNotice::CLAIM . '1', null );
$ids = array();
$checks = 0;
$assert = static function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
	++$checks;
};
$create = static function ( $meta ) use ( &$ids ) {
	$id = wp_insert_user( array( 'user_login' => 'anar-migration-' . wp_generate_password( 14, false ), 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Could not create a test account.' );
	}
	$ids[] = $id;
	foreach ( $meta as $key => $value ) {
		update_user_meta( $id, $key, $value );
	}
	return $id;
};
$scan = static function () {
	$job = UserMigration::start();
	do {
		$job = UserMigration::scan( $job );
	} while ( ! $job['done'] );
	return $job;
};
try {
	delete_option( UserMigration::COMPLETE );
	delete_option( DigitsNotice::DETECTED );
	delete_user_meta( 1, DigitsNotice::SEEN );
	delete_option( DigitsNotice::CLAIM . '1' );
	$assert( ! UserMigration::available(), 'Use a clean local installation without pre-existing Digits source records.' );
	global $wpdb;
	$queries = $wpdb->num_queries;
	$tools = new UserTools();
	$tools->register();
	$tools->assets( 'dashboard' );
	$assert( $queries === $wpdb->num_queries, 'Registering the tools or visiting another admin screen must not query account data.' );
	$assert( ! wp_script_is( 'anar-user-tools', 'enqueued' ), 'The tools script leaked onto another admin screen.' );
	$notice = new DigitsNotice();
	ob_start();
	$notice->render();
	$assert( '' === ob_get_clean() && 'no' === get_option( DigitsNotice::DETECTED ), 'Empty source detection should be cached without a reminder.' );
	$queries = $wpdb->num_queries;
	ob_start();
	$notice->render();
	ob_end_clean();
	$assert( $queries === $wpdb->num_queries, 'A cached negative reminder check queried account data again.' );
	$users = array();
	for ( $i = 0; $i < 201; ++$i ) {
		$phone = '+989' . str_pad( (string) $i, 9, '0', STR_PAD_LEFT );
		$users[] = $create( array( 'digits_phone' => $phone ) );
	}
	$duplicate = $create( array( '_anar_phone' => '+989000000000' ) );
	$split = $create( array( 'digt_countrycode' => '+98', 'digits_phone_no' => '9000012345' ) );
	$bad = $create( array( 'digits_phone' => 'not a mobile number' ) );
	$skipped = $create( array( 'digits_phone' => '+989000012346', 'digits_phone_verification_skipped' => '1' ) );
	$different = $create( array( '_anar_phone' => '+989000012347', 'digits_phone' => '+989000012348' ) );
	$billing = $create( array( 'billing_phone' => '09000012349' ) );
	$source = get_user_meta( $users[3], 'digits_phone', true );
	$account = get_userdata( $users[3] );
	$before = array( $account->user_login, $account->user_pass, $account->roles );
	$assert( UserMigration::available(), 'Digits records were not detected.' );
	$assert( null === get_option( DigitsNotice::DETECTED, null ), 'Source metadata writes did not invalidate the cached negative detection.' );
	$notice->activated( 'digits/digit.php' );
	ob_start();
	$notice->render();
	$assert( false !== strpos( ob_get_clean(), 'data-anar-digits-reminder' ), 'The first detection did not show the reminder.' );
	$assert( 1 === (int) get_user_meta( 1, DigitsNotice::SEEN, true ), 'The first display did not persist its seen marker.' );
	$queries = $wpdb->num_queries;
	ob_start();
	$notice->render();
	$assert( '' === ob_get_clean() && $queries === $wpdb->num_queries, 'A reminder was repeated or queried source data after being seen.' );
	delete_user_meta( 1, DigitsNotice::SEEN );
	ob_start();
	$notice->render();
	$assert( '' === ob_get_clean(), 'An existing atomic claim must prevent repeated display after a user-meta cache reset.' );
	$job = $scan();
	$assert( $job['scanned'] > UserMigration::BATCH, 'The fixture did not cross a scan chunk boundary.' );
	$assert( 'conflict' === $job['rows'][ $users[0] ]['status'] && 'conflict' === $job['rows'][ $duplicate ]['status'], 'A duplicate in a later chunk was missed.' );
	$assert( 'invalid' === $job['rows'][ $bad ]['status'], 'Invalid Digits input was eligible.' );
	$assert( 'unverified' === $job['rows'][ $skipped ]['status'], 'Skipped Digits verification was trusted.' );
	$assert( 'different' === $job['rows'][ $different ]['status'], 'A canonical/source mismatch was not held for review.' );
	$assert( ! isset( $job['rows'][ $billing ] ), 'Billing-only data entered the identity audit.' );
	$assert( '' === get_user_meta( $users[3], '_anar_phone', true ), 'Scanning changed account data.' );
	update_user_meta( $users[1], 'digits_phone', '+989000099991' );
	$late = $create( array( '_anar_phone' => '+989000000002' ) );
	do {
		$job = UserMigration::apply( $job );
	} while ( ! $job['applied'] );
	$assert( 'changed' === $job['rows'][ $users[1] ]['status'] && '' === get_user_meta( $users[1], '_anar_phone', true ), 'A changed source was transferred from a stale preview.' );
	$assert( 'changed' === $job['rows'][ $users[2] ]['status'] && '' === get_user_meta( $users[2], '_anar_phone', true ), 'A newly claimed number was reassigned.' );
	$assert( '' === get_user_meta( $users[0], '_anar_phone', true ), 'An ambiguous account was migrated.' );
	$assert( get_user_meta( $users[3], '_anar_phone', true ) === $source && get_user_meta( $users[3], 'digits_phone', true ) === $source, 'A valid number was not linked additively.' );
	$assert( '+989000012345' === get_user_meta( $split, '_anar_phone', true ), 'Split Digits metadata was not migrated.' );
	$account = get_userdata( $users[3] );
	$after = array( $account->user_login, $account->user_pass, $account->roles );
	$assert( $before === $after, 'Import changed credentials or roles.' );
	$assert( array( $users[3] => '_anar_phone' ) === PhoneDirectory::find( $source, 1 ), 'The existing account no longer resolves through Anar.' );
	$assert( false === get_option( UserMigration::COMPLETE, false ), 'A partial transfer was marked complete while unresolved records remained.' );
	$assert( '+989000012347' === get_user_meta( $different, '_anar_phone', true ), 'An existing canonical number was replaced.' );
	foreach ( $ids as $fixture_id ) {
		wp_delete_user( $fixture_id );
	}
	$ids = array();
	$single = $create( array( 'digits_phone' => '+989000000999' ) );
	$job = UserMigration::apply( $scan() );
	$assert( $job['applied'] && get_option( UserMigration::COMPLETE, false ), 'A complete migration did not save its receipt.' );
	$queries = $wpdb->num_queries;
	$assert( ! UserMigration::available() && $queries === $wpdb->num_queries, 'Completed detection should return without any source query.' );
	$extra = $create( array( 'digits_phone' => '+989000000998' ) );
	$assert( ! UserMigration::available(), 'A completed migration was automatically re-enabled by new source metadata.' );
	delete_user_meta( 1, DigitsNotice::SEEN );
	delete_option( DigitsNotice::CLAIM . '1' );
	ob_start();
	$notice->render();
	$assert( '' === ob_get_clean(), 'Completed migration displayed a reminder to an administrator who had not seen it.' );
	WP_CLI::success( 'User tools: ' . $checks . ' checks passed, including multi-chunk transfer and conditional loading.' );
} finally {
	foreach ( $ids as $fixture_id ) {
		wp_delete_user( $fixture_id );
	}
	if ( null === $original ) {
		delete_option( UserMigration::COMPLETE );
	} else {
		update_option( UserMigration::COMPLETE, $original, false );
	}
	if ( null === $original_detection ) {
		delete_option( DigitsNotice::DETECTED );
	} else {
		update_option( DigitsNotice::DETECTED, $original_detection, true );
	}
	if ( '' === $original_seen ) {
		delete_user_meta( 1, DigitsNotice::SEEN );
	} else {
		update_user_meta( 1, DigitsNotice::SEEN, $original_seen );
	}
	if ( null === $original_claim ) {
		delete_option( DigitsNotice::CLAIM . '1' );
	} else {
		update_option( DigitsNotice::CLAIM . '1', $original_claim, false );
	}
}
