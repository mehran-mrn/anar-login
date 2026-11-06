<?php
/**
 * Order lifecycle and shipment notifications using WooCommerce CRUD (including HPOS).
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class OrderEvents {
	private $settings;
	private $repository;

	public function __construct( Settings $settings, Repository $repository ) {
		$this->settings = $settings;
		$this->repository = $repository;
	}

	public function register() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'status' ), 20, 3 );
		add_action( 'woocommerce_new_order', array( $this, 'created' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'created' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'created' ), 20, 1 );
		add_action( 'woocommerce_order_object_updated_props', array( $this, 'updated' ), 30, 2 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'shipment' ), 30, 1 );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 20, 1 );
	}

	public function queue( $event, $order, $bucket = '' ) {
		if ( ! $order || ! $this->settings->get( $event ) || in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'trash' ), true ) ) {
			return;
		}
		$phones = 'new_admin' === $event || 'failed_admin' === $event ? preg_split( '/\s+/', trim( $this->settings->get( 'admin_phones' ) ) ) : array( $order->get_billing_phone() );
		foreach ( $phones as $raw ) {
			$phone = Identity::normalize( $raw );
			if ( ! is_wp_error( $phone ) && 'sms' === $phone['channel'] ) {
				$this->repository->enqueue( $event, $order->get_id(), $phone['identity'], time(), $bucket );
			}
		}
	}

	public function created( $order ) {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		$this->queue( 'new_admin', $order );
	}

	public function status( $id, $from, $to ) {
		$order = wc_get_order( $id );
		if ( $from !== $to ) {
			$this->created( $order );
			$this->queue( 'status_' . $to, $order );
			if ( 'failed' === $to ) {
				$this->queue( 'failed_admin', $order );
			}
		}
		$this->shipment( $order );
	}

	public function updated( $order, $props ) {
		if ( array_intersect( array( 'billing_phone', 'status' ), $props ) ) {
			$this->created( $order );
		}
	}

	public static function tracking( $order ) {
		$number = trim( (string) $order->get_meta( '_anar_tracking_number' ) );
		$carrier = (string) $order->get_meta( '_anar_tracking_carrier' );
		$url = (string) $order->get_meta( '_anar_tracking_url' );
		if ( ! $number ) {
			$items = $order->get_meta( '_wc_shipment_tracking_items' );
			if ( is_array( $items ) && $items ) {
				$item = end( $items );
				$number = (string) ( $item['tracking_number'] ?? '' );
				$carrier = (string) ( $item['custom_tracking_provider'] ?? $item['tracking_provider'] ?? '' );
				$url = (string) ( $item['custom_tracking_link'] ?? '' );
			}
		}
		$key = ( new Settings() )->get( 'tracking_meta_key' );
		if ( ! $number && $key ) {
			$value = $order->get_meta( $key );
			$number = is_scalar( $value ) ? (string) $value : '';
		}
		$data = apply_filters( 'anar_toolkit_order_tracking', array( 'tracking_number' => $number, 'carrier' => $carrier, 'tracking_url' => $url ), $order );
		return array( 'tracking_number' => sanitize_text_field( $data['tracking_number'] ?? '' ), 'carrier' => sanitize_text_field( $data['carrier'] ?? '' ), 'tracking_url' => esc_url_raw( $data['tracking_url'] ?? '', array( 'https' ) ) );
	}

	public function shipment( $order ) {
		if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			return;
		}
		$data = self::tracking( $order );
		if ( $data['tracking_number'] ) {
			$this->queue( 'shipment_customer', $order, hash( 'sha256', $data['tracking_number'] . '|' . $data['carrier'] ) );
		}
	}

	public function meta_boxes() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'anar-shipment', __( 'Anar shipment tracking', 'anar-toolkit' ), array( $this, 'box' ), $screen, 'side', 'default' );
	}

	public function box( $object ) {
		$order = $object instanceof \WC_Order ? $object : wc_get_order( $object->ID );
		if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}
		wp_nonce_field( 'anar_shipment_' . $order->get_id(), 'anar_shipment_nonce' );
		foreach ( array( 'number' => __( 'Tracking number', 'anar-toolkit' ), 'carrier' => __( 'Carrier', 'anar-toolkit' ), 'url' => __( 'HTTPS tracking URL (optional)', 'anar-toolkit' ) ) as $key => $label ) {
			?>
			<p><label><?php echo esc_html( $label ); ?><input style="width:100%" name="anar_tracking_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $order->get_meta( '_anar_tracking_' . $key ) ); ?>" type="<?php echo 'url' === $key ? 'url' : 'text'; ?>"></label></p>
			<?php
		}
		echo '<p>' . esc_html__( 'Save the order to queue a shipment SMS when this event is enabled and its template is configured.', 'anar-toolkit' ) . '</p>';
	}

	public function save( $id ) {
		if ( ! current_user_can( 'edit_shop_order', $id ) || empty( $_POST['anar_shipment_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anar_shipment_nonce'] ) ), 'anar_shipment_' . $id ) ) {
			return;
		}
		$order = wc_get_order( $id );
		if ( ! $order ) {
			return;
		}
		foreach ( array( 'number', 'carrier', 'url' ) as $key ) {
			$raw = isset( $_POST[ 'anar_tracking_' . $key ] ) && is_string( $_POST[ 'anar_tracking_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'anar_tracking_' . $key ] ) ) : '';
			$order->update_meta_data( '_anar_tracking_' . $key, 'url' === $key ? esc_url_raw( $raw, array( 'https' ) ) : sanitize_text_field( $raw ) );
		}
		$order->save();
		$this->shipment( $order );
	}
}
