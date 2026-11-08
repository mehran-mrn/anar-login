<?php
/**
 * Marketing data export and erasure without deleting WooCommerce orders.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Commerce\Repository as CommerceRepository;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	public function register() {
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( $exporters ) {
				$exporters['anar-marketing'] = array( 'exporter_friendly_name' => __( 'Anar customer campaigns', 'anar-toolkit' ), 'callback' => array( $this, 'export' ) );
				return $exporters;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( $erasers ) {
				$erasers['anar-marketing'] = array( 'eraser_friendly_name' => __( 'Anar customer campaigns', 'anar-toolkit' ), 'callback' => array( $this, 'erase' ) );
				return $erasers;
			}
		);
		add_action( 'anar_toolkit_erase_phone', array( $this, 'erase_phone' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	public function policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Anar customer campaigns', '<p>' . esc_html__( 'When enabled by this store, Anar saves checkout/account mobile numbers and cart contents for reminders without a separate checkbox. A notice is shown at cart/checkout; every marketing message includes an unsubscribe link. Optional campaigns keep customer contact details, paid-order/product facts, stock requests, honest purchase feedback, birthday month/day and calendar, referral relationships, game attempts and personal coupon references locally. No birth year is requested. Coupon gifts are single-use and phone-bound; survey gifts do not depend on a positive rating. Provider credentials and plaintext login/reset codes are never written to campaign reports. The selected SMS provider receives recipient numbers and configured message variables. Optional traffic analytics use a first-party random browser identifier lasting up to 30 days, daily keyed hashes and referring hostnames, excluding checkout/account pages and administrator visits; no raw IP or full referrer URL is retained in traffic reports. Configure and disclose the selected modules, provider, purposes and retention period here. WordPress personal-data tools and administrator phone erasure remove local marketing records and revoke unused personal gifts without deleting WooCommerce orders. Minimal suppression hashes may remain to remember stopped messages.', 'anar-toolkit' ) . '</p>' ); }
	}

	public function export( $email, $page = 1 ) {
		global $wpdb;
		$repo = new Repository();
		$email = sanitize_email( $email );
		$data = array();
		$done = true;
		if ( ! is_email( $email ) ) {
			return array( 'data' => $data, 'done' => true ); }
		$offset = ( max( 1, (int) $page ) - 1 ) * 100;
		$datasets = array(
			'profiles' => array( 'phone', 'email', 'name', 'birthday', 'calendar', 'created_at', 'updated_at' ),
			'orders' => array( 'order_id', 'paid_at', 'total', 'currency', 'paid', 'items' ),
			'purchases' => array( 'order_id', 'product_id', 'paid_at' ),
			'stock' => array( 'product_id', 'state', 'created_at' ),
			'feedback' => array( 'order_id', 'rating', 'comment', 'created_at' ),
			'plays' => array( 'game', 'state', 'created_at', 'coupon_id' ),
			'jobs' => array( 'event', 'source_id', 'created_at', 'expires_at', 'converted_order' ),
			'referrals' => array( 'order_id', 'status', 'coupon_id', 'created_at' ),
		);
		foreach ( $datasets as $table => $columns ) {
			if ( 'profiles' === $table ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 100 OFFSET %d', $repo->table( $table ), $email, $offset ) );
			} elseif ( 'referrals' === $table ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE referrer IN (SELECT id FROM %i WHERE email = %s) OR referred IN (SELECT id FROM %i WHERE email = %s) ORDER BY id LIMIT 100 OFFSET %d', $repo->table( $table ), $repo->table( 'profiles' ), $email, $repo->table( 'profiles' ), $email, $offset ) );
			} else {
				$key = 'orders' === $table ? 'order_id' : 'id';
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE profile_id IN (SELECT id FROM %i WHERE email = %s) ORDER BY %i LIMIT 100 OFFSET %d', $repo->table( $table ), $repo->table( 'profiles' ), $email, $key, $offset ) );
			}
			$done = $done && count( $rows ) < 100;
			foreach ( $rows as $record ) {
				$fields = array();
				foreach ( $columns as $key ) {
					$fields[] = array( 'name' => $key, 'value' => (string) $record->$key ); }
				$data[] = array( 'group_id' => 'anar-marketing', 'group_label' => __( 'Anar customer campaigns', 'anar-toolkit' ), 'item_id' => 'anar-' . $table . '-' . ( $record->id ?? $record->order_id ), 'data' => $fields );
			}
		}
		return array( 'data' => $data, 'done' => $done );
	}

	public function erase_profile( $profile, $suppress = false ) {
		global $wpdb;
		$repo = new Repository();
		if ( $suppress ) {
			$contacts = new CommerceRepository();
			$contact = $contacts->contact_by_phone( $profile->phone );
			$contact_id = $contact ? $contact->id : $contacts->capture_contact( $profile->phone, '', 'account' );
			$contacts->suppress( $contact_id );
			$wpdb->update( $contacts->table( 'contacts' ), array( 'phone' => '', 'email' => '', 'consent_text' => '', 'capture_source' => '', 'suppressed' => 1 ), array( 'id' => $contact_id ) );
		}
		if ( ! ( new Rewards() )->revoke( $profile->id ) ) {
			return false; }
		// Keep anonymous issuance totals so erasure cannot replenish a campaign's budget.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET profile_id = 0, coupon_id = 0, order_id = 0, money_state = CASE WHEN money_reserved > 0 THEN 'retained' ELSE money_state END WHERE profile_id = %d", $repo->table( 'game_awards' ), $profile->id ) );
		foreach ( array( 'stock', 'feedback', 'plays', 'jobs', 'orders', 'purchases' ) as $table ) {
			$wpdb->delete( $repo->table( $table ), array( 'profile_id' => $profile->id ) ); }
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE referrer = %d OR referred = %d', $repo->table( 'referrals' ), $profile->id, $profile->id ) );
		$wpdb->delete( ( new CommerceRepository() )->table( 'messages' ), array( 'recipient' => $profile->phone ) );
		$wpdb->delete( $repo->table( 'profiles' ), array( 'id' => $profile->id ) );
		if ( $profile->user_id ) {
			delete_user_meta( $profile->user_id, '_anar_birthday' ); }
		return true;
	}

	public function erase( $email ) {
		if ( ! is_email( $email ) ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }
		global $wpdb;
		$repo = new Repository();
		$profiles = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 20', $repo->table( 'profiles' ), sanitize_email( $email ) ) );
		$removed = false;
		$remaining = false;
		foreach ( $profiles as $profile ) {
			$done = $this->erase_profile( $profile, true );
			$removed = $removed || $done;
			$remaining = $remaining || ! $done; }
		return array( 'items_removed' => $removed, 'items_retained' => $remaining, 'messages' => array(), 'done' => count( $profiles ) < 20 && ! $remaining );
	}

	public function erase_phone( $phone ) {
		global $wpdb;
		$repo = new Repository();
		$profile = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phone_hash = %s', $repo->table( 'profiles' ), CommerceRepository::phone_hash( $phone ) ) );
		if ( $profile ) {
			$this->erase_profile( $profile, true ); }
	}
}
