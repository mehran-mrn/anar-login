<?php
/**
 * First-purchase referral gifts, settled after completion and a refund waiting window.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class Referrals {
	public function register() {
		add_shortcode( 'anar_referrals', array( $this, 'form' ) );
		add_action( 'template_redirect', array( $this, 'capture' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'attach' ), 10 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'attach' ), 10 );
		add_action( 'anar_marketing_order_recorded', array( $this, 'paid' ), 10, 2 );
		add_action( 'anar_marketing_discover', array( $this, 'settle' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'refunded' ) );
	}

	public function capture() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only public referral code sets only a scoped attribution cookie.
		$code = isset( $_GET['anar_ref'] ) && is_string( $_GET['anar_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['anar_ref'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! ( new Settings() )->enabled( 'referral' ) || ! preg_match( '/^[a-zA-Z0-9]{20}$/D', $code ) ) {
			return; }
		$users = get_users( array( 'meta_key' => '_anar_referral_code', 'meta_value' => $code, 'number' => 1, 'fields' => 'ID' ) );
		if ( $users && get_current_user_id() !== (int) $users[0] ) {
			wc_setcookie( 'anar_referral', $code, time() + 30 * DAY_IN_SECONDS, is_ssl(), true ); }
	}

	public function attach( $order ) {
		if ( ! ( new Settings() )->enabled( 'referral' ) || $order->get_meta( '_anar_referrer' ) ) {
			return; }
		$code = isset( $_COOKIE['anar_referral'] ) && is_string( $_COOKIE['anar_referral'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['anar_referral'] ) ) : '';
		if ( ! preg_match( '/^[a-zA-Z0-9]{20}$/D', $code ) ) {
			return; }
		$ids = get_users( array( 'meta_key' => '_anar_referral_code', 'meta_value' => $code, 'number' => 1, 'fields' => 'ID' ) );
		if ( $ids && (int) $ids[0] !== $order->get_customer_id() ) {
			$order->update_meta_data( '_anar_referrer', (int) $ids[0] ); }
	}

	public function paid( $order, $profile ) {
		global $wpdb;
		if ( ! ( new Settings() )->enabled( 'referral' ) || ! $order->get_meta( '_anar_referrer' ) || (float) $order->get_total() < (float) ( new Settings() )->all()['referral_minimum'] ) {
			return; }
		$repo = new Repository();
		$facts = $repo->facts( $profile->id );
		if ( 1 !== (int) $facts->orders ) {
			return; }
		// Check real WooCommerce history even before the store imports old orders.
		$history = array( 'status' => wc_get_is_paid_statuses(), 'limit' => 2, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' );
		if ( $order->get_customer_id() ) {
			$history['customer_id'] = $order->get_customer_id(); } elseif ( $order->get_billing_email() ) {
			$history['billing_email'] = $order->get_billing_email(); } else {
				return; }
			foreach ( wc_get_orders( $history ) as $previous ) {
				if ( (int) $previous !== $order->get_id() ) {
						return; }
			}
			$user = get_userdata( (int) $order->get_meta( '_anar_referrer' ) );
			if ( ! $user || strtolower( $user->user_email ) === strtolower( $order->get_billing_email() ) ) {
				return; }
			$referrer = $repo->remember( get_user_meta( $user->ID, '_anar_phone', true ), $user->user_email, $user->first_name, $user->ID );
			if ( ! $referrer || $referrer === (int) $profile->id ) {
				return; }
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (referrer, referred, order_id, created_at) VALUES (%d,%d,%d,%d)', $repo->table( 'referrals' ), $referrer, $profile->id, $order->get_id(), time() ) );
	}

	public function settle() {
		global $wpdb;
		$config = ( new Settings() )->all();
		if ( empty( $config['modules']['referral'] ) ) {
			return; }
		$repo = new Repository();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'pending' AND created_at <= %d ORDER BY id LIMIT 10", $repo->table( 'referrals' ), time() - $config['referral_wait_days'] * DAY_IN_SECONDS ) );
		foreach ( $rows as $row ) {
			$order = wc_get_order( $row->order_id );
			if ( ! $order || ! $order->is_paid() || $order->get_total_refunded() > 0 ) {
				$wpdb->update( $repo->table( 'referrals' ), array( 'status' => 'cancelled' ), array( 'id' => $row->id ) );
				continue; }
			if ( ! $order->has_status( 'completed' ) ) {
				continue; }
			if ( ! $order->get_date_completed() || $order->get_date_completed()->getTimestamp() + $config['referral_wait_days'] * DAY_IN_SECONDS > time() ) {
				continue; }
			$rule = array( 'discount_type' => 'percent', 'discount_amount' => $config['referral_amount'], 'discount_hours' => 168 );
			$code = ( new Rewards() )->coupon( $repo->profile( $row->referrer ), $rule, 'referral:' . $row->id );
			$wpdb->update( $repo->table( 'referrals' ), array( 'status' => $code ? 'rewarded' : 'failed', 'coupon_id' => $code ? wc_get_coupon_id_by_code( $code ) : 0 ), array( 'id' => $row->id, 'status' => 'pending' ) );
		}
	}

	public function refunded( $id ) {
		global $wpdb;
		$repo = new Repository();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d', $repo->table( 'referrals' ), $id ) );
		if ( $row ) {
			if ( $row->coupon_id ) {
				$coupon = new \WC_Coupon( $row->coupon_id );
				$coupon->set_date_expires( time() - 1 );
				$coupon->save(); }
			$wpdb->update( $repo->table( 'referrals' ), array( 'status' => 'cancelled' ), array( 'id' => $row->id ) );
		}
	}

	public function form() {
		global $wpdb;
		if ( ! ( new Settings() )->enabled( 'referral' ) || ! is_user_logged_in() ) {
			return ''; }
		$user = wp_get_current_user();
		wp_enqueue_style( 'anar-marketing', ANAR_LOGIN_URL . 'assets/css/marketing.css', array(), ANAR_LOGIN_VERSION );
		$repo = new Repository();
		$id = $repo->remember( get_user_meta( $user->ID, '_anar_phone', true ), $user->user_email, $user->first_name, $user->ID );
		if ( ! $id ) {
			return '<p>' . esc_html__( 'Sign in with a verified mobile number to use customer referrals.', 'anar-toolkit' ) . '</p>'; }
		$code = get_user_meta( $user->ID, '_anar_referral_code', true );
		if ( ! $code ) {
			$code = wp_generate_password( 20, false, false );
			update_user_meta( $user->ID, '_anar_referral_code', $code ); }
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE referrer = %d ORDER BY id DESC LIMIT 30', $repo->table( 'referrals' ), $id ) );
		ob_start();
		?>
		<section class="anar-customer-preferences"><h3><?php esc_html_e( 'Customer referrals', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Share your link. An eligible first purchase can earn a personal coupon after completion and the store refund waiting period. Self-referrals and refunded orders do not qualify.', 'anar-toolkit' ); ?></p><label><?php esc_html_e( 'Your invitation link', 'anar-toolkit' ); ?><input readonly type="url" dir="ltr" value="<?php echo esc_url( add_query_arg( 'anar_ref', $code, home_url( '/' ) ) ); ?>"></label><ul>
		<?php
		foreach ( $rows as $row ) :
			?>
			<li><?php echo esc_html( '#' . $row->order_id . ' · ' . $row->status ); ?>
			<?php
			if ( $row->coupon_id ) :
				?>
			· <strong dir="ltr"><?php echo esc_html( ( new \WC_Coupon( $row->coupon_id ) )->get_code() ); ?></strong><?php endif; ?></li><?php endforeach; ?></ul></section>
		<?php
		return ob_get_clean();
	}
}
