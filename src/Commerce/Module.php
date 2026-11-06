<?php
/**
 * Consent-driven cart recovery and payment events for WooCommerce.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Support\Identity;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Module {

	private $settings;
	private $repository;
	private $restoring = false;

	public function __construct( Settings $settings, Repository $repository ) {
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	public function register() {
		add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'order_created' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'order_created' ) );
		add_action( 'woocommerce_after_cart_table', array( $this, 'display_form' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'display_form' ), 15 );
		add_shortcode( 'anar_cart_reminders', array( $this, 'form' ) );
		add_shortcode( 'anar_recover_cart', array( $this, 'recovery_form' ) );
		add_action( 'woocommerce_before_cart', array( $this, 'display_recovery_form' ) );
		add_action( 'woocommerce_cart_is_empty', array( $this, 'display_recovery_form' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'display_recovery_form' ), 5 );
		add_action( 'wc_ajax_anar_toolkit_consent', array( $this, 'capture' ) );
		add_action( 'wc_ajax_anar_toolkit_recover', array( $this, 'recover' ) );
		add_action( 'wc_ajax_anar_toolkit_recovery_code', array( $this, 'request_recovery_code' ) );
		add_action( 'woocommerce_cart_updated', array( $this, 'cart_updated' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'cart_emptied' ) );
		add_action( 'template_redirect', array( $this, 'prepare_recovery_page' ), 0 );
		add_action( 'template_redirect', array( $this, 'link_page' ), 1 );
	}

	public function prepare_recovery_page() {
		if ( ! $this->settings->get( 'recovery' ) || ! WC()->session ) {
			return;
		}
		$page = get_queried_object();
		$shortcode = $page instanceof \WP_Post && ( has_shortcode( $page->post_content, 'anar_recover_cart' ) || has_shortcode( $page->post_content, 'anar_cart_reminders' ) );
		if ( $shortcode || is_cart() || is_checkout() || ( $this->settings->get( 'recovery_page_id' ) && is_page( (int) $this->settings->get( 'recovery_page_id' ) ) ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard WordPress/page-cache integration flag.
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			WC()->session->set_customer_session_cookie( true );
		}
	}

	public static function consent_text() {
		return __( 'Send me SMS reminders about this cart and its unpaid order. I can stop reminders using the link in each message. My phone number and reminder details will be sent to the store\'s SMS provider.', 'anar-toolkit' );
	}

	private function session_key() {
		return WC()->session ? hash_hmac( 'sha256', (string) WC()->session->get_customer_id(), wp_salt( 'nonce' ) ) : '';
	}

	private function items() {
		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$items[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) $item['variation_id'],
				'variation'    => (array) $item['variation'],
				'quantity'     => wc_stock_amount( $item['quantity'] ),
			);
		}
		return $items;
	}

	public function display_form() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- form() escapes each dynamic value.
		echo $this->form();
	}

	public function display_recovery_form() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- recovery_form() escapes each dynamic value.
		echo $this->recovery_form();
	}

	public function recovery_form() {
		if ( ! $this->settings->get( 'recovery' ) || ! WC()->session ) {
			return '';
		}
		WC()->session->set_customer_session_cookie( true );
		wp_enqueue_script( 'anar-toolkit-recovery', ANAR_LOGIN_URL . 'assets/js/recovery.js', array(), ANAR_LOGIN_VERSION, true );
		ob_start();
		?>
		<div class="anar-recovery" style="border:1px solid #ddd;padding:16px;margin:16px 0;border-radius:8px" data-anar-recover data-endpoint="<?php echo esc_url( \WC_AJAX::get_endpoint( 'anar_toolkit_recover' ) ); ?>" data-request="<?php echo esc_url( \WC_AJAX::get_endpoint( 'anar_toolkit_recovery_code' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'anar_cart_' . $this->session_key() ) ); ?>">
			<h3><?php esc_html_e( 'Continue a purchase with your recovery code', 'anar-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Enter the mobile number that received the SMS and your latest six-digit recovery code. This replaces your current cart with saved products or opens payment for the unpaid order. It does not sign you in.', 'anar-toolkit' ); ?></p>
			<p><label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><br><input data-phone type="tel" autocomplete="tel" dir="ltr"></label></p>
			<p><label><?php esc_html_e( 'Recovery code', 'anar-toolkit' ); ?><br><input data-code type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr"></label></p>
			<p><button type="button" class="button" data-restore><?php esc_html_e( 'Verify code and continue purchase', 'anar-toolkit' ); ?></button> <button type="button" class="button" data-request-code><?php esc_html_e( 'Send a new recovery code', 'anar-toolkit' ); ?></button></p>
			<p role="status" aria-live="polite" data-status data-error="<?php esc_attr_e( 'The code is invalid, expired or unavailable. Check the number and latest code, or request a new code.', 'anar-toolkit' ); ?>" data-requested="<?php esc_attr_e( 'If a consented unfinished purchase exists, a new code will be sent shortly. Please wait before trying again.', 'anar-toolkit' ); ?>"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Generate a recovery-only code; no login session or plaintext code is stored. */
	public function issue_code( $cart ) {
		$contact = $this->repository->contact( $cart->contact_id );
		if ( ! $contact || $contact->suppressed || ! $this->valid_cart( $cart ) ) {
			return new WP_Error( 'anar_recovery_unavailable' );
		}
		$codes = new OtpRepository();
		$code  = sprintf( '%06d', random_int( 0, 999999 ) );
		if ( ! $codes->create( $contact->phone, 'recovery', $code, (int) $this->settings->get( 'code_minutes' ) * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'anar_recovery_storage' );
		}
		$challenge = $codes->latest( $contact->phone, 'recovery' );
		if ( ! $challenge || ! $this->repository->bind_challenge( $contact->id, $cart->id, $challenge->id ) ) {
			return new WP_Error( 'anar_recovery_storage' );
		}
		return $code;
	}

	public function recovery_page() {
		$id = (int) $this->settings->get( 'recovery_page_id' );
		return $id && 'publish' === get_post_status( $id ) ? get_permalink( $id ) : wc_get_cart_url();
	}

	/** Atomically consume a phone-bound recovery challenge with a five-guess budget. */
	public function verify_recovery( $phone, $code ) {
		$error = new WP_Error( 'anar_recovery_invalid', __( 'The code is invalid, expired or unavailable. Check the number and latest code, or request a new code.', 'anar-toolkit' ) );
		$phone = Identity::normalize( $phone );
		$code  = trim( Identity::latin_digits( $code ) );
		if ( ! $this->settings->get( 'recovery' ) || is_wp_error( $phone ) || 'sms' !== $phone['channel'] ) {
			return $error;
		}
		$limit = ( new RateLimiter( new LoginSettings() ) )->guard_recovery( $phone['identity'] );
		if ( is_wp_error( $limit ) ) {
			return $error;
		}
		$contact = $this->repository->contact_by_phone( $phone['identity'] );
		$codes   = new OtpRepository();
		$challenge = $codes->latest( $phone['identity'], 'recovery' );
		if ( ! $contact || $contact->suppressed || ! $challenge || (int) $contact->challenge_id !== (int) $challenge->id || strtotime( $challenge->expires_at . ' UTC' ) <= time() || ! $codes->claim_attempt( $challenge->id, 5 ) ) {
			return $error;
		}
		$cart = $this->repository->cart( $contact->recovery_cart_id );
		if ( ! preg_match( '/^\d{6}$/D', $code ) || ! $codes->matches( $challenge, $phone['identity'], $code ) || ! $this->valid_cart( $cart ) || ! $codes->consume( $challenge->id ) ) {
			return $error;
		}
		return $cart;
	}

	public function request_recovery_code() {
		if ( ! WC()->session ) {
			wp_send_json_error( array(), 400 );
		}
		check_ajax_referer( 'anar_cart_' . $this->session_key(), 'nonce' );
		$raw = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone = Identity::normalize( $raw );
		if ( $this->settings->get( 'recovery' ) && ! is_wp_error( $phone ) && 'sms' === $phone['channel'] ) {
			$allowed = ( new RateLimiter( new LoginSettings() ) )->guard_request( 'purchase-recovery:' . $phone['identity'] );
			$contact = $this->repository->contact_by_phone( $phone['identity'] );
			$cart    = $contact ? $this->repository->latest_cart_for_contact( $contact->id ) : null;
			if ( ! is_wp_error( $allowed ) && $contact && ! $contact->suppressed && $this->valid_cart( $cart ) ) {
				$this->repository->enqueue( 'recovery_request', $cart->id, $phone['identity'], time(), (string) floor( time() / 300 ) );
			}
		}
		// Identical response for unknown, opted-out, exhausted and eligible numbers.
		wp_send_json_success();
	}

	public function recover() {
		if ( ! WC()->session || ! WC()->cart ) {
			wp_send_json_error( array(), 400 );
		}
		check_ajax_referer( 'anar_cart_' . $this->session_key(), 'nonce' );
		$phone = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$code  = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$cart  = $this->verify_recovery( $phone, $code );
		if ( is_wp_error( $cart ) ) {
			wp_send_json_error( array(), 400 );
		}
		global $wpdb;
		$visit = array( 'clicked_at' => time() );
		if ( $cart->order_id ) {
			$visit['recovered_order'] = $cart->order_id;
		}
		$wpdb->update( $this->repository->table( 'carts' ), $visit, array( 'id' => $cart->id ) );
		$url = $cart->order_id ? wc_get_order( $cart->order_id )->get_checkout_payment_url() : $this->restore( $cart );
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array(), 409 );
		}
		wp_send_json_success( array( 'redirect' => wp_validate_redirect( $url, wc_get_cart_url() ) ) );
	}

	/** Use the shortcode beside Cart/Checkout blocks; never inject into their React tree. */
	public function form() {
		if ( ! $this->settings->get( 'recovery' ) || ! WC()->cart || WC()->cart->is_empty() || ! WC()->session ) {
			return '';
		}
		WC()->session->set_customer_session_cookie( true );
		wp_enqueue_script( 'anar-toolkit-recovery', ANAR_LOGIN_URL . 'assets/js/recovery.js', array(), ANAR_LOGIN_VERSION, true );
		$phone   = WC()->customer ? WC()->customer->get_billing_phone() : '';
		$email   = is_user_logged_in() ? wp_get_current_user()->user_email : '';
		$cart    = $this->repository->current_cart( $this->session_key() );
		$contact = $cart ? $this->repository->contact( $cart->contact_id ) : null;
		ob_start();
		?>
		<div class="anar-recovery" style="border:1px solid #ddd;padding:16px;margin:16px 0;border-radius:8px" data-anar-recovery data-endpoint="<?php echo esc_url( \WC_AJAX::get_endpoint( 'anar_toolkit_consent' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'anar_cart_' . $this->session_key() ) ); ?>">
			<h3><?php esc_html_e( 'SMS purchase reminders', 'anar-toolkit' ); ?></h3>
			<p><label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><br><input data-phone type="tel" autocomplete="tel" dir="ltr" value="<?php echo esc_attr( $contact ? $contact->phone : $phone ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Email (optional, for privacy requests)', 'anar-toolkit' ); ?><br><input data-email type="email" autocomplete="email" value="<?php echo esc_attr( $contact ? $contact->email : $email ); ?>"></label></p>
			<p><label><input data-consent type="checkbox" <?php checked( $contact && ! $contact->suppressed ); ?>> <?php echo esc_html( self::consent_text() ); ?></label></p>
			<p><button type="button" class="button" data-save><?php esc_html_e( 'Save reminder preference', 'anar-toolkit' ); ?></button></p>
			<p role="status" aria-live="polite" data-status data-success="<?php esc_attr_e( 'Your reminder preference has been saved.', 'anar-toolkit' ); ?>" data-error="<?php esc_attr_e( 'Could not save. Check your number and reload the page before trying again.', 'anar-toolkit' ); ?>"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	public function capture() {
		if ( ! $this->settings->get( 'recovery' ) || ! WC()->session || ! WC()->cart ) {
			wp_send_json_error( array(), 400 );
		}
		check_ajax_referer( 'anar_cart_' . $this->session_key(), 'nonce' );
		$cart = $this->repository->current_cart( $this->session_key() );
		if ( empty( $_POST['consent'] ) ) {
			if ( $cart ) {
				$this->repository->suppress( $cart->contact_id );
			}
			wp_send_json_success();
		}
		$phone = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email = isset( $_POST['email'] ) && is_string( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone = Identity::normalize( $phone );
		if ( is_wp_error( $phone ) || 'sms' !== $phone['channel'] || WC()->cart->is_empty() ) {
			wp_send_json_error( array(), 400 );
		}
		// Bound consent changes per real WooCommerce session; no public arbitrary-number send API.
		$changes = (array) WC()->session->get( 'anar_consent_changes', array( 'time' => time(), 'count' => 0 ) );
		if ( $changes['time'] < time() - HOUR_IN_SECONDS ) {
			$changes = array( 'time' => time(), 'count' => 0 );
		}
		if ( $changes['count'] >= 5 ) {
			wp_send_json_error( array(), 429 );
		}
		++$changes['count'];
		WC()->session->set( 'anar_consent_changes', $changes );
		$contact_id = $this->repository->consent( $phone['identity'], $email, self::consent_text() );
		$id = $contact_id ? $this->repository->save_cart( $this->session_key(), $contact_id, $this->items(), WC()->cart->get_total( 'edit' ), get_woocommerce_currency() ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array(), 500 );
		}
		wp_send_json_success();
	}

	public function cart_updated() {
		if ( $this->restoring || ! $this->settings->get( 'recovery' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$cart = $this->repository->current_cart( $this->session_key() );
		if ( ! $cart || $cart->order_id ) {
			return;
		}
		$contact = $this->repository->contact( $cart->contact_id );
		if ( $contact && ! $contact->suppressed ) {
			$this->repository->save_cart( $this->session_key(), $contact->id, $this->items(), WC()->cart->get_total( 'edit' ), get_woocommerce_currency() );
		}
	}

	public function cart_emptied() {
		if ( $this->restoring || ! WC()->session ) {
			return;
		}
		$cart = $this->repository->current_cart( $this->session_key() );
		// WooCommerce empties the cart after creating an order: keep unpaid-order reminders.
		if ( $cart && ! $cart->order_id ) {
			$this->repository->set_cart_status( $cart->id, 'cancelled' );
		}
	}

	public function order_created( $order ) {
		if ( ! $this->settings->get( 'recovery' ) || ! WC()->session ) {
			return;
		}
		$cart = $this->repository->current_cart( $this->session_key() );
		if ( ! $cart ) {
			return;
		}
		$contact = $this->repository->contact( $cart->contact_id );
		$phone   = Identity::normalize( $order->get_billing_phone() );
		// Consent for one recipient cannot be transferred to a different billing number.
		if ( ! $contact || $contact->suppressed || is_wp_error( $phone ) || $phone['identity'] !== $contact->phone ) {
			$this->repository->set_cart_status( $cart->id, 'suppressed' );
			return;
		}
		$restored = (int) WC()->session->get( 'anar_recovered_cart' ) === (int) $cart->id;
		$this->repository->attach_order( $cart->id, $order->get_id(), $restored );
		$order->update_meta_data( '_anar_toolkit_cart_id', $cart->id );
		$order->save_meta_data();
		WC()->session->__unset( 'anar_recovered_cart' );
	}

	public function payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || ! $order->get_date_paid() || (float) $order->get_total() <= 0 ) {
			return;
		}
		$this->repository->paid( $order_id );
		if ( $order->get_meta( '_anar_toolkit_paid_queued' ) ) {
			return;
		}
		$queued = false;
		$ok     = true;
		if ( $this->settings->get( 'paid_customer' ) ) {
			$phone = Identity::normalize( $order->get_billing_phone() );
			if ( ! is_wp_error( $phone ) && 'sms' === $phone['channel'] ) {
				$ok = $this->repository->enqueue( 'paid_customer', $order_id, $phone['identity'], time() ) && $ok;
				$queued = true;
			}
		}
		if ( $this->settings->get( 'paid_admin' ) ) {
			foreach ( array_filter( explode( "\n", (string) $this->settings->get( 'admin_phones' ) ) ) as $phone ) {
				$ok     = $this->repository->enqueue( 'paid_admin', $order_id, $phone, time() ) && $ok;
				$queued = true;
			}
		}
		if ( $ok && $queued ) {
			$order->update_meta_data( '_anar_toolkit_paid_queued', 1 );
			$order->save_meta_data();
		}
	}

	public function status_changed( $order_id, $from, $to ) {
		$order = wc_get_order( $order_id );
		if ( $order && ( $order->is_paid() || in_array( $to, array( 'cancelled', 'refunded', 'trash' ), true ) ) ) {
			$this->repository->paid( $order_id, $order->is_paid() ? 'converted' : 'cancelled' );
		}
	}

	public static function signature( $cart, $action ) {
		// 160-bit MAC keeps provider tokens short without introducing an external URL shortener.
		return substr( hash_hmac( 'sha256', $action . '|' . $cart->id . '|' . $cart->seed, wp_salt( 'auth' ) ), 0, 40 );
	}

	public static function link( $cart, $action ) {
		if ( 'unsubscribe' !== $action ) {
			return '';
		}
		$code = base_convert( (string) $cart->id, 10, 36 ) . '.' . ( 'recover' === $action ? 'r' : 'u' ) . '.' . self::signature( $cart, $action );
		return add_query_arg( 'ar', $code, home_url( '/' ) );
	}

	public function valid_cart( $cart ) {
		if ( ! $cart || 'active' !== $cart->status || $cart->updated_at + $this->settings->get( 'link_days' ) * DAY_IN_SECONDS < time() ) {
			return false;
		}
		$contact = $this->repository->contact( $cart->contact_id );
		if ( ! $contact || $contact->suppressed ) {
			return false;
		}
		if ( $cart->order_id ) {
			$order = wc_get_order( $cart->order_id );
			return $order && $order->needs_payment() && in_array( $order->get_status(), array( 'pending', 'failed' ), true );
		}
		return true;
	}

	/** GET displays confirmation only: link previews must not mutate carts or opt shoppers out. */
	public function link_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed bearer links are validated below; mutations additionally require a POST nonce.
		$code   = isset( $_GET['ar'] ) && is_string( $_GET['ar'] ) ? sanitize_text_field( wp_unslash( $_GET['ar'] ) ) : '';
		$parts  = explode( '.', $code );
		$valid  = 3 === count( $parts ) && preg_match( '/^[0-9a-z]{1,13}$/', $parts[0] ) && 'u' === $parts[1] && preg_match( '/^[0-9a-f]{40}$/', $parts[2] );
		$id     = $valid ? (int) base_convert( $parts[0], 36, 10 ) : 0;
		$action = 'unsubscribe';
		$token  = $valid ? $parts[2] : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $code ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$cart = $this->repository->cart( $id );
		if ( ! $cart || ! in_array( $action, array( 'recover', 'unsubscribe' ), true ) || ! hash_equals( self::signature( $cart, $action ), $token ) ) {
			wp_die( esc_html__( 'This reminder link is invalid or has expired.', 'anar-toolkit' ), '', array( 'response' => 404 ) );
		}
		$nonce_action = 'anar_link_' . $id . '_' . $action . '_' . $token;
		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( $nonce_action );
			if ( 'unsubscribe' === $action ) {
				$this->repository->suppress( $cart->contact_id );
				wp_die( esc_html__( 'Purchase reminders have been stopped for this mobile number.', 'anar-toolkit' ), esc_html__( 'Reminders stopped', 'anar-toolkit' ), array( 'response' => 200 ) );
			}
		}
		$title = __( 'Stop purchase reminders', 'anar-toolkit' );
		$text  = __( 'Stop all cart and unpaid-order reminders for this mobile number?', 'anar-toolkit' );
		$html  = '<p>' . esc_html( $text ) . '</p><form method="post">' . wp_nonce_field( $nonce_action, '_wpnonce', false, false ) . '<button type="submit">' . esc_html( $title ) . '</button></form>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All text is escaped above; wp_nonce_field produces trusted markup.
		wp_die( $html, esc_html( $title ), array( 'response' => 200 ) );
	}

	private function restore( $cart ) {
		$items = json_decode( $cart->items, true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error( 'anar_cart_unavailable', __( 'The saved cart is empty.', 'anar-toolkit' ) );
		}
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $item['quantity'] ) ) {
				return new WP_Error( 'anar_cart_unavailable', __( 'A saved product is no longer available. Your current cart has been kept.', 'anar-toolkit' ) );
			}
		}
		$this->restoring = true;
		$previous = WC()->cart->get_cart();
		$previous_coupons = WC()->cart->get_applied_coupons();
		WC()->cart->empty_cart( false );
		$ok = true;
		foreach ( $items as $item ) {
			if ( ! WC()->cart->add_to_cart( $item['product_id'], $item['quantity'], $item['variation_id'], $item['variation'] ) ) {
				$ok = false;
				break;
			}
		}
		if ( ! $ok ) {
			WC()->cart->empty_cart( false );
			WC()->cart->set_cart_contents( $previous );
			WC()->cart->set_applied_coupons( $previous_coupons );
			WC()->cart->calculate_totals();
			WC()->cart->set_session();
			$this->restoring = false;
			return new WP_Error( 'anar_cart_unavailable', __( 'The saved cart could not be restored. Your previous cart has been kept.', 'anar-toolkit' ) );
		}
		WC()->cart->calculate_totals();
		WC()->session->set( 'anar_recovered_cart', (int) $cart->id );
		global $wpdb;
		$wpdb->update( $this->repository->table( 'carts' ), array( 'session_key' => $this->session_key(), 'updated_at' => time() ), array( 'id' => $cart->id ) );
		WC()->cart->set_session();
		$this->restoring = false;
		return wc_get_checkout_url();
	}

	public function variables( $order ) {
		return array(
			'site' => get_bloginfo( 'name' ),
			'name' => $order ? $order->get_billing_first_name() : '',
			'order_id' => $order ? $order->get_order_number() : '',
			'total' => $order ? wc_format_decimal( $order->get_total(), wc_get_price_decimals() ) : '',
			'currency' => $order ? $order->get_currency() : get_woocommerce_currency(),
		);
	}
}
