<?php
/**
 * Customer preferences, verified stock subscriptions and purpose-scoped survey links.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Commerce\Repository as CommerceRepository;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Frontend {
	private $settings;
	private $repository;

	public function __construct( Settings $settings, Repository $repository ) {
		$this->settings = $settings;
		$this->repository = $repository; }

	public function register() {
		if ( $this->settings->enabled( 'birthday' ) ) {
			add_shortcode(
				'anar_customer_preferences',
				function () {
					return $this->preferences( true );
				}
			);
			add_action( 'woocommerce_edit_account_form', array( $this, 'account_form' ) );
			add_action( 'admin_post_anar_preferences', array( $this, 'save_preferences' ) );
			add_action( 'woocommerce_save_account_details', array( $this, 'save_account' ) );
		}
		add_action( 'template_redirect', array( $this, 'route' ), 0 );
		if ( $this->settings->enabled( 'stock' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'stock_form' ), 35 );
			add_action( 'wp_ajax_anar_stock', array( $this, 'stock' ) );
			add_action( 'wp_ajax_nopriv_anar_stock', array( $this, 'stock' ) );
		}
	}

	public static function link( $scope, $id, $expiry = 0 ) {
		$expiry = $expiry ?: time() + 30 * DAY_IN_SECONDS;
		$signature = hash_hmac( 'sha256', $scope . '|' . (int) $id . '|' . $expiry, wp_salt( 'auth' ) );
		return add_query_arg( 'am', self::token( $scope, $id, $expiry, $signature ), home_url( '/' ) );
	}

	public static function token( $scope, $id, $expires, $signature ) {
		$prefix = array( 'stop' => 's', 'survey' => 'v', 'invoice' => 'i' );
		return $prefix[ $scope ] . '.' . base_convert( (string) $id, 10, 36 ) . '.' . base_convert( (string) $expires, 10, 36 ) . '.' . substr( $signature, 0, 40 );
	}

	public static function decode( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^([svi])\.([a-z0-9]{1,13})\.([a-z0-9]{1,8})\.([a-f0-9]{40})$/D', $token, $parts ) ) {
			return null; }
		$scopes = array( 's' => 'stop', 'v' => 'survey', 'i' => 'invoice' );
		return array( 'scope' => $scopes[ $parts[1] ], 'id' => (int) base_convert( $parts[2], 36, 10 ), 'expires' => (int) base_convert( $parts[3], 36, 10 ), 'signature' => $parts[4] );
	}

	public static function authorized( $scope, $id, $expires, $signature ) {
		return $id > 0 && $expires >= time() && $expires <= time() + 31 * DAY_IN_SECONDS && hash_equals( substr( hash_hmac( 'sha256', $scope . '|' . (int) $id . '|' . $expires, wp_salt( 'auth' ) ), 0, 40 === strlen( $signature ) ? 40 : 64 ), $signature );
	}

	public function route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Purpose-scoped HMAC validates GET access; mutations additionally verify a POST nonce.
		$scope = isset( $_GET['anar_action'] ) && is_string( $_GET['anar_action'] ) ? sanitize_key( wp_unslash( $_GET['anar_action'] ) ) : '';
		if ( ! isset( $_GET['am'] ) && ! in_array( $scope, array( 'stop', 'survey' ), true ) ) {
			return; }
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$expires = isset( $_GET['expires'] ) ? absint( $_GET['expires'] ) : 0;
		$sig = isset( $_GET['signature'] ) && is_string( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : '';
		if ( isset( $_GET['am'] ) && is_string( $_GET['am'] ) ) {
			$link = self::decode( sanitize_text_field( wp_unslash( $_GET['am'] ) ) );
			if ( ! $link || ! in_array( $link['scope'], array( 'stop', 'survey' ), true ) ) {
				wp_die( esc_html__( 'This link is invalid or expired.', 'anar-toolkit' ), '', array( 'response' => 404 ) ); }
			$scope = $link['scope'];
			$id = $link['id'];
			$expires = $link['expires'];
			$sig = $link['signature'];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! self::authorized( $scope, $id, $expires, $sig ) ) {
			wp_die( esc_html__( 'This link is invalid or expired.', 'anar-toolkit' ), '', array( 'response' => 404 ) ); }
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( 'stop' === $scope ) {
			$this->stop_page( $id ); } else {
			$this->survey_page( $id ); }
			exit;
	}

	private function stop_page( $id ) {
		$profile = $this->repository->profile( $id );
		if ( ! $profile ) {
			wp_die( esc_html__( 'This link is invalid or expired.', 'anar-toolkit' ) ); }
		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( 'anar_stop_' . $id );
			$repo = new CommerceRepository();
			$contact = $repo->contact_by_phone( $profile->phone );
			$contact_id = $contact ? $contact->id : $repo->capture_contact( $profile->phone, '', 'checkout' );
			$repo->suppress( $contact_id );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled' WHERE recipient = %s AND event LIKE %s AND status = 'pending'", $repo->table( 'messages' ), $profile->phone, $wpdb->esc_like( 'mkt_' ) . '%' ) );
			wp_die( esc_html__( 'Marketing and purchase reminders have been stopped. Order service messages are separate.', 'anar-toolkit' ) );
		}
		$html = '<h2>' . esc_html__( 'Stop marketing messages?', 'anar-toolkit' ) . '</h2><p>' . esc_html__( 'This stops future campaign and abandoned-cart reminders for your number. Payment and shipment service notices are separate.', 'anar-toolkit' ) . '</p><form method="post">' . wp_nonce_field( 'anar_stop_' . $id, '_wpnonce', false, false ) . '<button type="submit">' . esc_html__( 'Stop future reminders', 'anar-toolkit' ) . '</button></form>';
		wp_die( $html, esc_html__( 'Message preferences', 'anar-toolkit' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML and escaped strings/WordPress nonce markup.
	}

	private function survey_page( $id ) {
		global $wpdb;
		if ( ! function_exists( 'wc_get_order' ) ) {
			wp_die( esc_html__( 'This survey is no longer available.', 'anar-toolkit' ) ); }
		$order = wc_get_order( $id );
		if ( ! $this->settings->enabled( 'survey' ) || ! $order || ! $order->is_paid() || $order->get_total_refunded() > 0 ) {
			wp_die( esc_html__( 'This survey is no longer available.', 'anar-toolkit' ) ); }
		$profile_id = $this->repository->remember( $order->get_billing_phone(), $order->get_billing_email(), $order->get_billing_first_name(), $order->get_customer_id() );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $this->repository->table( 'feedback' ), $id ) );
		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && ! $existing ) {
			check_admin_referer( 'anar_survey_' . $id );
			$rating = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
			$text = isset( $_POST['comment'] ) && is_string( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';
			if ( $rating < 1 || $rating > 5 || strlen( $text ) > 6000 ) {
				wp_die( esc_html__( 'Enter a rating from 1 to 5 and a shorter comment.', 'anar-toolkit' ) ); }
			$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (order_id, profile_id, rating, comment, created_at) VALUES (%d,%d,%d,%s,%d)', $this->repository->table( 'feedback' ), $id, $profile_id, $rating, $text, time() ) );
			if ( 1 === $inserted && $this->settings->all()['survey_reviews'] && $text ) {
				$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
				$products = array();
				foreach ( $order->get_items() as $item ) {
					$products[] = $item->get_product_id(); }
				if ( in_array( $product_id, $products, true ) ) {
					$comment_id = wp_insert_comment( array( 'comment_post_ID' => $product_id, 'comment_author' => $order->get_billing_first_name(), 'comment_author_email' => $order->get_billing_email(), 'comment_content' => $text, 'comment_type' => 'review', 'comment_approved' => 0 ) );
					if ( $comment_id ) {
						update_comment_meta( $comment_id, 'rating', $rating );
						update_comment_meta( $comment_id, 'verified', 1 );
						$wpdb->update( $this->repository->table( 'feedback' ), array( 'comment_id' => $comment_id ), array( 'order_id' => $id ) ); }
				}
			}
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $this->repository->table( 'feedback' ), $id ) );
		}
		if ( $existing ) {
			$code = ( new Rewards() )->coupon( $this->repository->profile( $profile_id ), $this->settings->all()['rules']['survey'], 'survey:' . $id );
			wp_die( '<h2>' . esc_html__( 'Thank you for your honest feedback.', 'anar-toolkit' ) . '</h2>' . ( $code ? '<p>' . esc_html__( 'Your thank-you coupon:', 'anar-toolkit' ) . ' <strong dir="ltr">' . esc_html( $code ) . '</strong></p>' : '' ), esc_html__( 'Feedback received', 'anar-toolkit' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML and escaped text/coupon code.
		}
		ob_start();
		?>
		<h2><?php esc_html_e( 'Purchase feedback', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Your feedback can be positive or negative. Any configured thank-you gift is independent of your rating. Product reviews require store moderation.', 'anar-toolkit' ); ?></p><form method="post"><?php wp_nonce_field( 'anar_survey_' . $id ); ?><p><label><?php esc_html_e( 'Your rating', 'anar-toolkit' ); ?><select name="rating" required>
		<?php
		foreach ( array( 5, 4, 3, 2, 1 ) as $rating ) :
			?>
			<option><?php echo esc_html( $rating ); ?></option><?php endforeach; ?></select></label></p><p><label><?php esc_html_e( 'Your review', 'anar-toolkit' ); ?><textarea name="comment" maxlength="3000" style="width:100%;min-height:100px"></textarea></label></p><p><label><?php esc_html_e( 'Purchased product', 'anar-toolkit' ); ?><select name="product_id"><option value="0"><?php esc_html_e( 'Overall order feedback', 'anar-toolkit' ); ?></option>
			<?php
			foreach ( $order->get_items() as $item ) :
				?>
			<option value="<?php echo esc_attr( $item->get_product_id() ); ?>"><?php echo esc_html( $item->get_name() ); ?></option><?php endforeach; ?></select></label></p><button type="submit"><?php esc_html_e( 'Submit honest feedback', 'anar-toolkit' ); ?></button></form>
		<?php
		wp_die( ob_get_clean(), esc_html__( 'Purchase feedback', 'anar-toolkit' ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered template escapes its dynamic values.
	}

	public function account_form() {
		echo $this->preferences( false ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- preferences() escapes all values.

	public function preferences( $standalone = true ) {
		if ( ! is_user_logged_in() || ! $this->settings->enabled( 'birthday' ) ) {
			return ''; }
		$phone = get_user_meta( get_current_user_id(), '_anar_phone', true );
		wp_enqueue_style( 'anar-marketing', ANAR_LOGIN_URL . 'assets/css/marketing.css', array(), ANAR_LOGIN_VERSION );
		$id = $this->repository->remember( $phone, wp_get_current_user()->user_email, wp_get_current_user()->first_name, get_current_user_id() );
		$profile = $this->repository->profile( $id );
		$parts = explode( '-', $profile->birthday ?? '' );
		ob_start();
		?>
		<div class="anar-customer-preferences"><h3><?php esc_html_e( 'Birthday messages', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Save only your birthday month and day to receive a birthday greeting when enabled by the store. Your birth year is not requested.', 'anar-toolkit' ); ?></p>
		<?php
		if ( $standalone ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="anar_preferences"><?php endif; ?>
		<?php wp_nonce_field( 'anar_preferences', 'anar_preferences_nonce' ); ?><label><?php esc_html_e( 'Calendar', 'anar-toolkit' ); ?><select name="anar_calendar"><option value="gregorian" <?php selected( $profile->calendar ?? '', 'gregorian' ); ?>><?php esc_html_e( 'Gregorian', 'anar-toolkit' ); ?></option>
		<?php
		if ( class_exists( 'IntlDateFormatter' ) ) :
			?>
			<option value="jalali" <?php selected( $profile->calendar ?? '', 'jalali' ); ?>><?php esc_html_e( 'Jalali (Persian)', 'anar-toolkit' ); ?></option><?php endif; ?></select></label><label><?php esc_html_e( 'Month', 'anar-toolkit' ); ?><input name="anar_birth_month" type="number" min="1" max="12" value="<?php echo esc_attr( $parts[0] ?? '' ); ?>"></label><label><?php esc_html_e( 'Day', 'anar-toolkit' ); ?><input name="anar_birth_day" type="number" min="1" max="31" value="<?php echo esc_attr( $parts[1] ?? '' ); ?>"></label>
		<?php
		if ( $standalone ) :
			?>
			<button class="button"><?php esc_html_e( 'Save birthday', 'anar-toolkit' ); ?></button></form><?php endif; ?></div>
		<?php
		return ob_get_clean();
	}

	public function save_account( $user_id ) {
		global $wpdb;
		if ( ! $this->settings->enabled( 'birthday' ) || get_current_user_id() !== (int) $user_id || empty( $_POST['anar_preferences_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anar_preferences_nonce'] ) ), 'anar_preferences' ) ) {
			return; }
		$calendar = isset( $_POST['anar_calendar'] ) && 'jalali' === $_POST['anar_calendar'] ? 'jalali' : 'gregorian';
		$month = isset( $_POST['anar_birth_month'] ) ? absint( $_POST['anar_birth_month'] ) : 0;
		$day = isset( $_POST['anar_birth_day'] ) ? absint( $_POST['anar_birth_day'] ) : 0;
		if ( ( $month || $day ) && ! Calendar::valid( $month, $day, $calendar ) ) {
			return; }
		$id = $this->repository->remember( get_user_meta( $user_id, '_anar_phone', true ), wp_get_current_user()->user_email, wp_get_current_user()->first_name, $user_id );
		if ( $id ) {
			$wpdb->update( $this->repository->table( 'profiles' ), array( 'birthday' => $month && $day ? sprintf( '%02d-%02d', $month, $day ) : '', 'calendar' => $calendar ), array( 'id' => $id ) ); }
	}

	public function save_preferences() {
		if ( ! is_user_logged_in() ) {
			auth_redirect(); }
		$this->save_account( get_current_user_id() );
		wp_safe_redirect( wc_get_account_endpoint_url( 'edit-account' ) );
		exit;
	}

	public function stock_form() {
		global $product;
		if ( ! $this->settings->enabled( 'stock' ) || ! $product ) {
			return; }
		$products = $product->is_type( 'variable' ) ? array_filter(
			array_map( 'wc_get_product', array_slice( $product->get_children(), 0, 100 ) ),
			static function ( $p ) {
				return $p && ! $p->is_in_stock();
			}
		) : ( $product->is_in_stock() ? array() : array( $product ) );
		if ( ! $products ) {
			return; }
		wp_enqueue_script( 'anar-marketing', ANAR_LOGIN_URL . 'assets/js/marketing.js', array(), ANAR_LOGIN_VERSION, true );
		wp_enqueue_style( 'anar-marketing', ANAR_LOGIN_URL . 'assets/css/marketing.css', array(), ANAR_LOGIN_VERSION );
		?>
		<form class="anar-stock-form" data-anar-stock data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"><h3><?php esc_html_e( 'Stock notification request', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Verify your mobile number to receive one notification when your selected product is restocked.', 'anar-toolkit' ); ?></p><input type="hidden" name="action" value="anar_stock"><?php wp_nonce_field( 'anar_stock', 'nonce' ); ?><label><?php esc_html_e( 'Product / variation', 'anar-toolkit' ); ?><select name="product_id">
		<?php
		foreach ( $products as $p ) :
			?>
			<option value="<?php echo esc_attr( $p->get_id() ); ?>"><?php echo esc_html( $p->get_name() ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><input name="phone" type="tel" dir="ltr" required></label><button type="button" data-stock-request><?php esc_html_e( 'Send verification code', 'anar-toolkit' ); ?></button><label><?php esc_html_e( 'Verification code', 'anar-toolkit' ); ?><input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label><button type="submit"><?php esc_html_e( 'Notify me when available', 'anar-toolkit' ); ?></button><p role="status" data-status></p></form>
		<?php
	}

	public function stock() {
		global $wpdb;
		check_ajax_referer( 'anar_stock', 'nonce' );
		$phone = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? Identity::normalize( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : null;
		$product = isset( $_POST['product_id'] ) ? wc_get_product( absint( $_POST['product_id'] ) ) : null;
		if ( ! $this->settings->enabled( 'stock' ) || ! $phone || is_wp_error( $phone ) || 'sms' !== $phone['channel'] || ! $product || 'publish' !== $product->get_status() || $product->is_in_stock() ) {
			wp_send_json_error( array( 'message' => __( 'This subscription is not available.', 'anar-toolkit' ) ), 400 ); }
		$codes = new OtpRepository();
		$limits = new RateLimiter( new LoginSettings() );
		$identity = 'stock|' . $phone['identity'];
		if ( ! empty( $_POST['request_code'] ) ) {
			if ( is_wp_error( $limits->guard_request( $identity ) ) ) {
				wp_send_json_error( array( 'message' => __( 'Please wait before requesting another code.', 'anar-toolkit' ) ), 429 ); }
			$code = (string) random_int( 100000, 999999 );
			if ( $codes->create( $identity, 'stock', $code, 300 ) ) {
				$result = ( new ProviderRegistry( new LoginSettings() ) )->send( $phone['identity'], $code, sprintf( /* translators: %s: verification code. */ __( 'Your stock-notification verification code is %s.', 'anar-toolkit' ), $code ), 'stock_verification' );
				if ( is_wp_error( $result ) ) {
					$challenge = $codes->latest( $identity, 'stock' );
					if ( $challenge ) {
						$codes->consume( $challenge->id ); }
				}
			}
			wp_send_json_success( array( 'message' => __( 'If delivery is available, a verification code will arrive shortly.', 'anar-toolkit' ) ) );
		}
		$code = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? Identity::latin_digits( sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
		$challenge = $codes->latest( $identity, 'stock' );
		if ( is_wp_error( $limits->guard_recovery( $identity ) ) || ! $challenge || ! $codes->claim_attempt( $challenge->id, 5 ) || ! $codes->matches( $challenge, $identity, $code ) || ! $codes->consume( $challenge->id ) ) {
			wp_send_json_error( array( 'message' => __( 'The verification code is invalid or expired.', 'anar-toolkit' ) ), 400 ); }
		$id = $this->repository->remember( $phone['identity'] );
		if ( ! $this->repository->allowed( $this->repository->profile( $id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This number has stopped messages. Contact the store to change that preference.', 'anar-toolkit' ) ), 400 ); }
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (profile_id, product_id, state, created_at, updated_at) VALUES (%d,%d,'waiting',%d,%d) ON DUPLICATE KEY UPDATE state = 'waiting', created_at = VALUES(created_at), updated_at = VALUES(updated_at)", $this->repository->table( 'stock' ), $id, $product->get_id(), time(), time() ) );
		wp_send_json_success( array( 'message' => __( 'Saved. We will notify you once when this product is back in stock.', 'anar-toolkit' ) ) );
	}
}
