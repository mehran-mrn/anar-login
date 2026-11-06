<?php
/**
 * Automatic cart capture from the current shopper's checkout or linked account.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

defined( 'ABSPATH' ) || exit;

final class AutomaticCapture {
	private $settings;
	private $module;

	public function __construct( Settings $settings, Module $module ) {
		$this->settings = $settings;
		$this->module = $module;
	}

	public function register() {
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'checkout' ) );
		add_action( 'woocommerce_cart_updated', array( $this, 'known_customer' ), 20 );
		add_action( 'template_redirect', array( $this, 'page' ), 15 );
		add_action( 'wc_ajax_anar_toolkit_capture', array( $this, 'capture' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'order' ), 5, 1 );
	}

	private function enabled() {
		return $this->settings->get( 'recovery' ) && 'automatic' === $this->settings->get( 'capture_mode' ) && WC()->session && WC()->cart;
	}

	public function page() {
		if ( $this->enabled() && ( is_cart() || is_checkout() ) ) {
			$this->known_customer();
			wp_enqueue_script( 'anar-capture', ANAR_LOGIN_URL . 'assets/js/capture.js', array(), ANAR_LOGIN_VERSION, true );
			wp_enqueue_style( 'anar-password-reset', ANAR_LOGIN_URL . 'assets/css/password-reset.css', array(), ANAR_LOGIN_VERSION );
			add_action( 'wp_footer', array( $this, 'notice' ) );
		}
	}

	public function known_customer() {
		if ( ! $this->enabled() || ! is_user_logged_in() ) {
			return;
		}
		$phone = get_user_meta( get_current_user_id(), '_anar_phone', true );
		if ( ! $phone && WC()->customer ) {
			$phone = WC()->customer->get_billing_phone();
		}
		$this->module->remember( $phone, wp_get_current_user()->user_email, 'account' );
	}

	public function checkout( $posted ) {
		if ( ! $this->enabled() ) {
			return;
		}
		parse_str( $posted, $data );
		$this->module->remember( $data['billing_phone'] ?? '', $data['billing_email'] ?? '', 'checkout' );
	}

	public function order( $order ) {
		if ( $this->enabled() ) {
			$this->module->remember( $order->get_billing_phone(), $order->get_billing_email(), 'checkout' );
		}
	}

	public function capture() {
		if ( ! $this->enabled() ) {
			wp_send_json_error( array(), 400 );
		}
		$key = hash_hmac( 'sha256', (string) WC()->session->get_customer_id(), wp_salt( 'nonce' ) );
		check_ajax_referer( 'anar_cart_' . $key, 'nonce' );
		$phone = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$this->module->remember( $phone, $email, 'checkout' );
		// Do not disclose whether a number is known or has stopped reminders.
		wp_send_json_success();
	}

	public function notice() {
		if ( ! $this->enabled() || WC()->cart->is_empty() ) {
			return;
		}
		wp_enqueue_script( 'anar-capture', ANAR_LOGIN_URL . 'assets/js/capture.js', array(), ANAR_LOGIN_VERSION, true );
		wp_enqueue_style( 'anar-password-reset', ANAR_LOGIN_URL . 'assets/css/password-reset.css', array(), ANAR_LOGIN_VERSION );
		$key = hash_hmac( 'sha256', (string) WC()->session->get_customer_id(), wp_salt( 'nonce' ) );
		?>
		<aside class="anar-capture-notice" data-anar-auto data-endpoint="<?php echo esc_url( \WC_AJAX::get_endpoint( 'anar_toolkit_capture' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'anar_cart_' . $key ) ); ?>">
			<?php esc_html_e( 'Your account or checkout mobile number and cart may be saved for purchase reminders. Each reminder includes a link to stop future messages.', 'anar-toolkit' ); ?>
		</aside>
		<?php
	}
}
