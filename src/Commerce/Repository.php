<?php
/**
 * Local consent, cart snapshots and an atomic outgoing queue.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

defined( 'ABSPATH' ) || exit;

final class Repository {

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$tables  = array(
			'contacts' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				phone varchar(32) NOT NULL,
				phone_hash char(64) NOT NULL,
				email varchar(191) NOT NULL DEFAULT '',
				consented_at bigint(20) unsigned NOT NULL,
				consent_text text NOT NULL,
				suppressed tinyint(1) NOT NULL DEFAULT 0,
				recovery_cart_id bigint(20) unsigned NOT NULL DEFAULT 0,
				challenge_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY phone_hash (phone_hash),
				KEY email (email)",
			'carts' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				contact_id bigint(20) unsigned NOT NULL,
				session_key char(64) NOT NULL,
				seed varchar(64) NOT NULL,
				items longtext NOT NULL,
				total decimal(26,8) NOT NULL DEFAULT 0,
				currency varchar(8) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				recovered_order bigint(20) unsigned NOT NULL DEFAULT 0,
				clicked_at bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at bigint(20) unsigned NOT NULL,
				updated_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				KEY session_key (session_key),
				KEY contact_id (contact_id),
				KEY status_updated (status, updated_at),
				KEY order_id (order_id)",
			'messages' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dedupe char(64) NOT NULL,
				event varchar(32) NOT NULL,
				object_id bigint(20) unsigned NOT NULL,
				recipient varchar(32) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				error_code varchar(80) NOT NULL DEFAULT '',
				attempts smallint unsigned NOT NULL DEFAULT 0,
				scheduled_at bigint(20) unsigned NOT NULL,
				created_at bigint(20) unsigned NOT NULL,
				updated_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe (dedupe),
				KEY status_schedule (status, scheduled_at),
				KEY recipient_updated (recipient, updated_at)",
		);
		foreach ( $tables as $name => $columns ) {
			$table = $wpdb->prefix . 'anar_toolkit_' . $name;
			dbDelta( "CREATE TABLE {$table} (\n{$columns}\n) {$charset};" );
		}
	}

	public function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'anar_toolkit_' . $name;
	}

	public static function phone_hash( $phone ) {
		return hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
	}

	public function contact( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'contacts' ), $id ) );
	}

	public function contact_by_phone( $phone ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phone_hash = %s', $this->table( 'contacts' ), self::phone_hash( $phone ) ) );
	}

	public function latest_cart_for_contact( $contact_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE contact_id = %d AND status = 'active' ORDER BY updated_at DESC, id DESC LIMIT 1", $this->table( 'carts' ), $contact_id ) );
	}

	public function bind_challenge( $contact_id, $cart_id, $challenge_id ) {
		global $wpdb;
		return false !== $wpdb->update( $this->table( 'contacts' ), array( 'recovery_cart_id' => $cart_id, 'challenge_id' => $challenge_id ), array( 'id' => $contact_id ) );
	}

	public function consent( $phone, $email, $text ) {
		global $wpdb;
		$hash = self::phone_hash( $phone );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (phone, phone_hash, email, consented_at, consent_text, suppressed) VALUES (%s, %s, %s, %d, %s, 0) ON DUPLICATE KEY UPDATE email = VALUES(email), consented_at = VALUES(consented_at), consent_text = VALUES(consent_text), suppressed = 0', $this->table( 'contacts' ), $phone, $hash, $email, time(), $text ) );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE phone_hash = %s', $this->table( 'contacts' ), $hash ) );
	}

	public function cart( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'carts' ), $id ) );
	}

	public function current_cart( $session_key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE session_key = %s AND status = 'active' ORDER BY id DESC LIMIT 1", $this->table( 'carts' ), $session_key ) );
	}

	public function save_cart( $session_key, $contact_id, $items, $total, $currency ) {
		global $wpdb;
		$cart = $this->current_cart( $session_key );
		$data = array( 'contact_id' => $contact_id, 'items' => wp_json_encode( $items ), 'total' => $total, 'currency' => $currency, 'updated_at' => time() );
		if ( $cart ) {
			// A different number is a new consent and cannot inherit another recipient's links.
			if ( (int) $cart->contact_id !== (int) $contact_id ) {
				$this->set_cart_status( $cart->id, 'suppressed' );
			} else {
				$wpdb->update( $this->table( 'carts' ), $data, array( 'id' => $cart->id ) );
				return (int) $cart->id;
			}
		}
		$data += array( 'session_key' => $session_key, 'seed' => wp_generate_password( 48, false, false ), 'status' => 'active', 'created_at' => time() );
		$ok = $wpdb->insert( $this->table( 'carts' ), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function set_cart_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( $this->table( 'carts' ), array( 'status' => $status ), array( 'id' => $id ) );
		if ( 'active' !== $status ) {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', updated_at = %d WHERE object_id = %d AND event LIKE %s AND status = 'pending'", $this->table( 'messages' ), time(), $id, $wpdb->esc_like( 'recovery_' ) . '%' ) );
		}
	}

	public function suppress( $contact_id ) {
		global $wpdb;
		$wpdb->update( $this->table( 'contacts' ), array( 'suppressed' => 1 ), array( 'id' => $contact_id ) );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE contact_id = %d AND status = 'active'", $this->table( 'carts' ), $contact_id ) );
		foreach ( $ids as $id ) {
			$this->set_cart_status( $id, 'suppressed' );
		}
	}

	public function attach_order( $cart_id, $order_id, $recovered = false ) {
		global $wpdb;
		$data = array( 'order_id' => $order_id );
		if ( $recovered ) {
			$data['recovered_order'] = $order_id;
		}
		$wpdb->update( $this->table( 'carts' ), $data, array( 'id' => $cart_id ) );
	}

	public function paid( $order_id, $status = 'converted' ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE order_id = %d AND status IN ('active', 'converted')", $this->table( 'carts' ), $order_id ) );
		foreach ( $ids as $id ) {
			$this->set_cart_status( $id, $status );
		}
	}

	public function active_carts( $limit = 100 ) {
		global $wpdb;
		$cursor = (int) get_option( 'anar_toolkit_scan_cursor', 0 );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'active' AND id > %d ORDER BY id ASC LIMIT %d", $this->table( 'carts' ), $cursor, $limit ) );
		update_option( 'anar_toolkit_scan_cursor', count( $rows ) < $limit ? 0 : (int) end( $rows )->id, false );
		return $rows;
	}

	public function enqueue( $event, $object_id, $recipient, $scheduled_at, $bucket = '' ) {
		global $wpdb;
		$key = hash( 'sha256', $event . '|' . $object_id . '|' . $recipient . '|' . $bucket );
		return false !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (dedupe, event, object_id, recipient, scheduled_at, created_at, updated_at) VALUES (%s, %s, %d, %s, %d, %d, %d)', $this->table( 'messages' ), $key, $event, $object_id, $recipient, $scheduled_at, time(), time() ) );
	}

	public function due() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' AND scheduled_at <= %d ORDER BY scheduled_at, id LIMIT 10", $this->table( 'messages' ), time() ) );
	}

	public function claim( $id ) {
		global $wpdb;
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'running', attempts = attempts + 1, updated_at = %d WHERE id = %d AND status = 'pending'", $this->table( 'messages' ), time(), $id ) );
	}

	public function finish( $id, $status, $error = '' ) {
		global $wpdb;
		$wpdb->update( $this->table( 'messages' ), array( 'status' => $status, 'error_code' => substr( sanitize_key( $error ), 0, 80 ), 'updated_at' => time() ), array( 'id' => $id ) );
	}

	public function postpone( $id, $timestamp ) {
		global $wpdb;
		$wpdb->update( $this->table( 'messages' ), array( 'status' => 'pending', 'scheduled_at' => $timestamp, 'updated_at' => time() ), array( 'id' => $id ) );
	}

	public function recent_count( $phone ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE recipient = %s AND event IN ('recovery_1', 'recovery_2', 'recovery_3') AND status IN ('accepted', 'uncertain', 'running') AND updated_at > %d", $this->table( 'messages' ), $phone, time() - DAY_IN_SECONDS ) );
	}

	/** Expired worker leases are uncertain, never silently re-sent. */
	public function expire_running() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'uncertain', error_code = 'worker_interrupted', updated_at = %d WHERE status = 'running' AND updated_at < %d", $this->table( 'messages' ), time(), time() - 600 ) );
	}

	/** A compare-and-swap lease also works with persistent object caches. */
	public function lock() {
		global $wpdb;
		$token = ( time() + 300 ) . ':' . wp_generate_password( 24, false, false );
		if ( add_option( 'anar_toolkit_worker_lock', $token, '', false ) ) {
			return $token;
		}
		$old = (string) get_option( 'anar_toolkit_worker_lock' );
		if ( (int) $old >= time() ) {
			return false;
		}
		$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $token, 'anar_toolkit_worker_lock', $old ) );
		wp_cache_delete( 'anar_toolkit_worker_lock', 'options' );
		return 1 === $ok ? $token : false;
	}

	public function unlock( $token ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, 'anar_toolkit_worker_lock', $token ) );
		wp_cache_delete( 'anar_toolkit_worker_lock', 'options' );
	}

	public function retry( $id ) {
		global $wpdb;
		// Uncertain outcomes require investigation at the provider and are never retried here.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'pending', error_code = '', scheduled_at = %d, updated_at = %d WHERE id = %d AND status = 'failed' AND attempts < 3", $this->table( 'messages' ), time(), time(), $id ) );
	}

	public function report() {
		global $wpdb;
		return array(
			'queue' => $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS count FROM %i GROUP BY status', $this->table( 'messages' ) ), OBJECT_K ),
			'carts' => $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS count FROM %i GROUP BY status', $this->table( 'carts' ) ), OBJECT_K ),
			'clicks' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE clicked_at > 0', $this->table( 'carts' ) ) ),
			'recovered' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'converted' AND recovered_order > 0", $this->table( 'carts' ) ) ),
			'messages' => $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 50', $this->table( 'messages' ) ) ),
		);
	}

	public function cleanup( $days ) {
		global $wpdb;
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %d', $this->table( 'messages' ), $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %d', $this->table( 'carts' ), $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE consented_at < %d AND id NOT IN (SELECT contact_id FROM %i)', $this->table( 'contacts' ), $cutoff, $this->table( 'carts' ) ) );
	}
}
