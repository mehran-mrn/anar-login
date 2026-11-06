<?php
/**
 * WordPress personal-data export, erasure and retention.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	private $repository;
	private $settings;

	public function __construct( Repository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'anar_login_daily_cleanup', array( $this, 'cleanup' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	public function policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Anar Toolkit', '<p>' . esc_html__( 'If you opt into purchase reminders, we store your mobile number, optional email, consent text and timestamp, cart products, amount, currency and related order ID locally. We send your number and the configured reminder variables (which may include purchase links and order details) to our selected SMS provider. Each reminder includes a link to stop future cart and unpaid-order reminders. Payment notifications are separate service messages. Phone-bound, single-use recovery codes restore products or open WooCommerce payment; they do not log you in. Recovery code hashes, attempts, expiry and consumption timestamps are stored separately from login challenges. Unsubscribe links only stop purchase reminders. The store configures a 7–90 day retention period and SMS provider. Add the selected provider, its terms and privacy links, your actual retention period and the purposes of your messages to this policy before enabling the modules.', 'anar-toolkit' ) . '</p>' );
		}
	}

	public function exporters( $exporters ) {
		$exporters['anar-toolkit'] = array( 'exporter_friendly_name' => __( 'Anar purchase reminders', 'anar-toolkit' ), 'callback' => array( $this, 'export' ) );
		return $exporters;
	}

	public function erasers( $erasers ) {
		$erasers['anar-toolkit'] = array( 'eraser_friendly_name' => __( 'Anar purchase reminders', 'anar-toolkit' ), 'callback' => array( $this, 'erase' ) );
		return $erasers;
	}

	public function export( $email, $page = 1 ) {
		global $wpdb;
		$contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 50 OFFSET %d', $this->repository->table( 'contacts' ), sanitize_email( $email ), ( max( 1, (int) $page ) - 1 ) * 50 ) );
		$data = array();
		foreach ( $contacts as $contact ) {
			$fields = array();
			foreach ( array( 'phone' => __( 'Mobile number', 'anar-toolkit' ), 'email' => __( 'Email', 'anar-toolkit' ), 'consent_text' => __( 'Consent text', 'anar-toolkit' ), 'consented_at' => __( 'Consent timestamp (UTC Unix time)', 'anar-toolkit' ), 'suppressed' => __( 'Reminders stopped', 'anar-toolkit' ) ) as $key => $label ) {
				$fields[] = array( 'name' => $label, 'value' => (string) $contact->$key );
			}
			$data[] = array( 'group_id' => 'anar-toolkit', 'group_label' => __( 'Anar purchase reminders', 'anar-toolkit' ), 'item_id' => 'anar-contact-' . $contact->id, 'data' => $fields );
			$carts = $wpdb->get_results( $wpdb->prepare( 'SELECT id, items, total, currency, status, order_id, recovered_order, clicked_at, created_at, updated_at FROM %i WHERE contact_id = %d ORDER BY id', $this->repository->table( 'carts' ), $contact->id ) );
			foreach ( $carts as $cart ) {
				$fields = array();
				foreach ( get_object_vars( $cart ) as $key => $value ) {
					$fields[] = array( 'name' => $key, 'value' => (string) $value );
				}
				$data[] = array( 'group_id' => 'anar-toolkit', 'group_label' => __( 'Anar purchase reminders', 'anar-toolkit' ), 'item_id' => 'anar-cart-' . $cart->id, 'data' => $fields );
			}
			$messages = $wpdb->get_results( $wpdb->prepare( 'SELECT id, event, object_id, status, error_code, attempts, created_at, updated_at FROM %i WHERE recipient = %s ORDER BY id', $this->repository->table( 'messages' ), $contact->phone ) );
			foreach ( $messages as $message ) {
				$fields = array();
				foreach ( get_object_vars( $message ) as $key => $value ) {
					$fields[] = array( 'name' => $key, 'value' => (string) $value );
				}
				$data[] = array( 'group_id' => 'anar-toolkit', 'group_label' => __( 'Anar purchase reminders', 'anar-toolkit' ), 'item_id' => 'anar-message-' . $message->id, 'data' => $fields );
			}
		}
		$payments = $this->payment_records( $email, $page );
		foreach ( $payments['messages'] as $message ) {
			$fields = array();
			foreach ( get_object_vars( $message ) as $key => $value ) {
				$fields[] = array( 'name' => $key, 'value' => (string) $value );
			}
			$data[] = array( 'group_id' => 'anar-toolkit-payments', 'group_label' => __( 'Anar payment notifications', 'anar-toolkit' ), 'item_id' => 'anar-payment-' . $message->id, 'data' => $fields );
		}
		return array( 'data' => $data, 'done' => count( $contacts ) < 50 && $payments['done'] );
	}

	public function erase( $email, $page = 1 ) {
		global $wpdb;
		$contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id LIMIT 50', $this->repository->table( 'contacts' ), sanitize_email( $email ) ) );
		foreach ( $contacts as $contact ) {
			$this->erase_contact( $contact );
		}
		$payments = $this->payment_records( $email, $page );
		foreach ( $payments['messages'] as $message ) {
			$wpdb->delete( $this->repository->table( 'messages' ), array( 'id' => $message->id ) );
		}
		return array( 'items_removed' => ! empty( $contacts ) || ! empty( $payments['messages'] ), 'items_retained' => false, 'messages' => array(), 'done' => count( $contacts ) < 50 && $payments['done'] );
	}

	/** Match transaction-only recipients via WooCommerce CRUD, including HPOS orders. */
	private function payment_records( $email, $page ) {
		global $wpdb;
		$result = array( 'messages' => array(), 'done' => true );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $result;
		}
		$orders = wc_get_orders( array( 'billing_email' => sanitize_email( $email ), 'limit' => 50, 'page' => max( 1, (int) $page ), 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $orders as $order_id ) {
			$messages = $wpdb->get_results( $wpdb->prepare( 'SELECT id, event, object_id, status, error_code, attempts, created_at, updated_at FROM %i WHERE object_id = %d AND event = %s', $this->repository->table( 'messages' ), $order_id, 'paid_customer' ) );
			$result['messages'] = array_merge( $result['messages'], $messages );
		}
		$result['done'] = count( $orders ) < 50;
		return $result;
	}

	public function erase_phone( $phone ) {
		global $wpdb;
		$contact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE phone_hash = %s', $this->repository->table( 'contacts' ), Repository::phone_hash( $phone ) ) );
		if ( $contact ) {
			$this->erase_contact( $contact );
		}
		$wpdb->delete( $this->repository->table( 'messages' ), array( 'recipient' => $phone ) );
	}

	private function erase_contact( $contact ) {
		global $wpdb;
		$this->repository->suppress( $contact->id );
		$wpdb->delete( $this->repository->table( 'messages' ), array( 'recipient' => $contact->phone ) );
		$wpdb->delete( $this->repository->table( 'carts' ), array( 'contact_id' => $contact->id ) );
		$wpdb->delete( $this->repository->table( 'contacts' ), array( 'id' => $contact->id ) );
	}

	public function cleanup() {
		$this->repository->cleanup( (int) $this->settings->get( 'retention' ) );
	}
}
