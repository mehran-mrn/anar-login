<?php
/**
 * Local marketing facts, subscriptions, rewards and bounded delivery jobs.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Commerce\Repository as CommerceRepository;
use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Repository {
	public function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'anar_marketing_' . $name; }

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables = array(
			'profiles' => "id bigint unsigned NOT NULL AUTO_INCREMENT, phone varchar(32) NOT NULL, phone_hash char(64) NOT NULL, email varchar(191) NOT NULL DEFAULT '', user_id bigint unsigned NOT NULL DEFAULT 0, name varchar(191) NOT NULL DEFAULT '', birthday varchar(10) NOT NULL DEFAULT '', calendar varchar(12) NOT NULL DEFAULT 'gregorian', created_at bigint unsigned NOT NULL, updated_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY phone_hash (phone_hash), KEY user_id (user_id), KEY updated_at (updated_at)",
			'orders' => 'order_id bigint unsigned NOT NULL, profile_id bigint unsigned NOT NULL, paid_at bigint unsigned NOT NULL, total decimal(26,8) NOT NULL DEFAULT 0, currency varchar(8) NOT NULL, paid tinyint NOT NULL DEFAULT 1, items longtext NOT NULL, PRIMARY KEY  (order_id), KEY profile_paid (profile_id, paid_at), KEY paid_at (paid_at)',
			'purchases' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, profile_id bigint unsigned NOT NULL, product_id bigint unsigned NOT NULL, paid_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY order_product (order_id, product_id), KEY customer_product (profile_id, product_id, paid_at), KEY product_date (product_id, paid_at)',
			'jobs' => "id bigint unsigned NOT NULL AUTO_INCREMENT, dedupe char(64) NOT NULL, profile_id bigint unsigned NOT NULL, event varchar(32) NOT NULL, source_id bigint unsigned NOT NULL DEFAULT 0, variables longtext NOT NULL, converted_order bigint unsigned NOT NULL DEFAULT 0, converted_total decimal(26,8) NOT NULL DEFAULT 0, converted_currency varchar(8) NOT NULL DEFAULT '', created_at bigint unsigned NOT NULL, expires_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY dedupe (dedupe), KEY profile_event (profile_id, event)",
			'stock' => "id bigint unsigned NOT NULL AUTO_INCREMENT, profile_id bigint unsigned NOT NULL, product_id bigint unsigned NOT NULL, state varchar(20) NOT NULL DEFAULT 'waiting', created_at bigint unsigned NOT NULL, updated_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY profile_product (profile_id, product_id), KEY product_state (product_id, state)",
			'feedback' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, order_id bigint unsigned NOT NULL, profile_id bigint unsigned NOT NULL, rating tinyint unsigned NOT NULL, comment text NOT NULL, comment_id bigint unsigned NOT NULL DEFAULT 0, created_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY order_id (order_id), KEY profile_id (profile_id)',
			'plays' => "id bigint unsigned NOT NULL AUTO_INCREMENT, play_key char(64) NOT NULL, profile_id bigint unsigned NOT NULL, game varchar(20) NOT NULL, payload longtext NOT NULL, state varchar(20) NOT NULL DEFAULT 'started', coupon_id bigint unsigned NOT NULL DEFAULT 0, created_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY play_key (play_key), KEY profile_date (profile_id, created_at)",
			'referrals' => "id bigint unsigned NOT NULL AUTO_INCREMENT, referrer bigint unsigned NOT NULL, referred bigint unsigned NOT NULL, order_id bigint unsigned NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', coupon_id bigint unsigned NOT NULL DEFAULT 0, created_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY referred (referred), UNIQUE KEY order_id (order_id), KEY status_created (status, created_at)",
			'bulk' => "id bigint unsigned NOT NULL AUTO_INCREMENT, title varchar(191) NOT NULL, state varchar(20) NOT NULL DEFAULT 'draft', filters longtext NOT NULL, cursor_id bigint unsigned NOT NULL DEFAULT 0, scheduled_at bigint unsigned NOT NULL, created_at bigint unsigned NOT NULL, PRIMARY KEY  (id), KEY state_scheduled (state, scheduled_at)",
			'visits' => "id bigint unsigned NOT NULL AUTO_INCREMENT, visit_key char(64) NOT NULL, day varchar(10) NOT NULL, source varchar(191) NOT NULL DEFAULT '', views int unsigned NOT NULL DEFAULT 1, updated_at bigint unsigned NOT NULL, PRIMARY KEY  (id), UNIQUE KEY visit_key (visit_key), KEY day (day)",
		);
		foreach ( $tables as $name => $columns ) {
			$table = $wpdb->prefix . 'anar_marketing_' . $name;
			// dbDelta requires each column on a separate line; none of these definitions contain enum commas.
			$columns = preg_replace( '/, (?=[a-z_]+ (?:bigint|varchar|char|decimal|tinyint|longtext|text|int)|PRIMARY|UNIQUE KEY|KEY)/', ",\n", $columns );
			dbDelta( "CREATE TABLE {$table} (\n{$columns}\n) " . $wpdb->get_charset_collate() . ';' );
		}
	}

	public function profile( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'profiles' ), $id ) ); }

	public function remember( $raw_phone, $email = '', $name = '', $user_id = 0 ) {
		global $wpdb;
		$phone = Identity::normalize( $raw_phone );
		if ( is_wp_error( $phone ) || 'sms' !== $phone['channel'] ) {
			return 0; }
		$hash = CommerceRepository::phone_hash( $phone['identity'] );
		// Billing details alone never establish ownership of a verified account.
		$verified = $user_id ? Identity::normalize( get_user_meta( $user_id, '_anar_phone', true ) ) : null;
		$user_id = is_array( $verified ) && 'sms' === $verified['channel'] && $verified['identity'] === $phone['identity'] ? $user_id : 0;
		// Only a verified account links an email to a whole phone-based history for privacy exports.
		$email = $user_id ? $email : '';
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (phone, phone_hash, email, name, user_id, created_at, updated_at) VALUES (%s, %s, %s, %s, %d, %d, %d) ON DUPLICATE KEY UPDATE email = IF(VALUES(email) = '', email, VALUES(email)), name = IF(VALUES(name) = '', name, VALUES(name)), user_id = IF(VALUES(user_id) = 0, user_id, VALUES(user_id)), updated_at = VALUES(updated_at)", $this->table( 'profiles' ), $phone['identity'], $hash, sanitize_email( $email ), sanitize_text_field( $name ), $user_id, time(), time() ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE phone_hash = %s', $this->table( 'profiles' ), $hash ) );
	}

	public function allowed( $profile ) {
		$contact = $profile ? ( new CommerceRepository() )->contact_by_phone( $profile->phone ) : null;
		return $profile && ( ! $contact || ! $contact->suppressed );
	}

	public function facts( $id ) {
		global $wpdb;
		$facts = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) orders, COALESCE(SUM(total),0) spent, MIN(paid_at) first_paid, MAX(paid_at) last_paid FROM %i WHERE profile_id = %d AND paid = 1 AND currency = %s', $this->table( 'orders' ), $id, get_woocommerce_currency() ) );
		$facts->last_order = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT order_id FROM %i WHERE profile_id = %d AND paid = 1 AND currency = %s ORDER BY paid_at DESC, order_id DESC LIMIT 1', $this->table( 'orders' ), $id, get_woocommerce_currency() ) );
		return $facts;
	}

	public function order( $order ) {
		global $wpdb;
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( ! $order || ! $order->get_date_paid() ) {
			return 0; }
		$id = $this->remember( $order->get_billing_phone(), $order->get_billing_email(), $order->get_billing_first_name(), $order->get_customer_id() );
		if ( ! $id ) {
			return 0; }
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_variation_id() ?: $item->get_product_id(); }
		$paid = $order->is_paid() && (float) $order->get_total() > (float) $order->get_total_refunded();
		$wpdb->replace( $this->table( 'orders' ), array( 'order_id' => $order->get_id(), 'profile_id' => $id, 'paid_at' => $order->get_date_paid()->getTimestamp(), 'total' => max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() ), 'currency' => $order->get_currency(), 'paid' => $paid ? 1 : 0, 'items' => wp_json_encode( $items ) ) );
		$wpdb->delete( $this->table( 'purchases' ), array( 'order_id' => $order->get_id() ) );
		if ( $paid ) {
			foreach ( array_unique( $items ) as $product ) {
				$wpdb->insert( $this->table( 'purchases' ), array( 'order_id' => $order->get_id(), 'profile_id' => $id, 'product_id' => $product, 'paid_at' => $order->get_date_paid()->getTimestamp() ) ); }
		}
		return $id;
	}

	public function enqueue( $event, $profile, $variables, $source_id, $bucket, $scheduled = 0 ) {
		global $wpdb;
		if ( ! $this->allowed( $profile ) ) {
			return false; }
		$dedupe = hash( 'sha256', $event . '|' . $profile->id . '|' . $bucket );
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (dedupe, profile_id, event, source_id, variables, created_at, expires_at) VALUES (%s,%d,%s,%d,%s,%d,%d)', $this->table( 'jobs' ), $dedupe, $profile->id, $event, $source_id, wp_json_encode( $variables ), time(), max( time(), $scheduled ) + 30 * DAY_IN_SECONDS ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE dedupe = %s', $this->table( 'jobs' ), $dedupe ) );
		return $id && ( new CommerceRepository() )->enqueue( 'mkt_' . $event, $id, $profile->phone, max( time(), $scheduled ), $bucket );
	}
}
