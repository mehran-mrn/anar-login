<?php
/**
 * Optional first-party traffic counts and local WooCommerce sales facts.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class Insights {
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_anar_visit', array( $this, 'visit' ) );
		add_action( 'wp_ajax_nopriv_anar_visit', array( $this, 'visit' ) );
	}

	public function assets() {
		if ( ! ( new Settings() )->enabled( 'traffic' ) || current_user_can( 'manage_woocommerce' ) || is_cart() || is_checkout() || is_account_page() || is_404() ) {
			return; }
		wp_enqueue_script( 'anar-insights', ANAR_LOGIN_URL . 'assets/js/insights.js', array(), ANAR_LOGIN_VERSION, true );
		wp_localize_script( 'anar-insights', 'AnarInsights', array( 'endpoint' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'anar_visit' ) ) );
	}

	public function visit() {
		global $wpdb;
		check_ajax_referer( 'anar_visit', 'nonce' );
		$token = isset( $_POST['visitor'] ) && is_string( $_POST['visitor'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor'] ) ) : '';
		if ( ! ( new Settings() )->enabled( 'traffic' ) || current_user_can( 'manage_woocommerce' ) || ! preg_match( '/^[a-f0-9-]{32,36}$/D', $token ) ) {
			wp_send_json_error( array(), 400 ); }
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limit = 'anar_visit_' . hash_hmac( 'sha256', $ip . '|' . floor( time() / 60 ), wp_salt( 'nonce' ) );
		$attempts = (int) get_transient( $limit );
		if ( $attempts > 120 ) {
			wp_send_json_error( array(), 429 ); }
		set_transient( $limit, $attempts + 1, 60 );
		$source = isset( $_POST['source'] ) && is_string( $_POST['source'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['source'] ) ) ) : '';
		$source = preg_match( '/^[a-z0-9.-]{0,190}$/D', $source ) ? $source : '';
		$repo = new Repository();
		$day = wp_date( 'Y-m-d' );
		$key = hash_hmac( 'sha256', $day . '|' . $token, wp_salt( 'auth' ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (visit_key, day, source, views, updated_at) VALUES (%s,%s,%s,1,%d) ON DUPLICATE KEY UPDATE views = LEAST(views + 1, 200), updated_at = VALUES(updated_at)', $repo->table( 'visits' ), $key, $day, $source, time() ) );
		wp_send_json_success();
	}

	public static function report( $days = 30 ) {
		global $wpdb;
		$days = max( 1, min( 90, (int) $days ) );
		$since = time() - $days * DAY_IN_SECONDS;
		$repo = new Repository();
		$sales = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) orders, COALESCE(SUM(total),0) revenue FROM %i WHERE paid = 1 AND paid_at >= %d AND currency = %s', $repo->table( 'orders' ), $since, get_woocommerce_currency() ) );
		$traffic = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) visitor_days, COALESCE(SUM(views),0) views FROM %i WHERE updated_at >= %d', $repo->table( 'visits' ), $since ) );
		$products = $wpdb->get_results( $wpdb->prepare( 'SELECT p.product_id, COUNT(DISTINCT p.order_id) orders FROM %i p INNER JOIN %i o ON o.order_id = p.order_id WHERE o.paid = 1 AND p.paid_at >= %d GROUP BY p.product_id ORDER BY orders DESC LIMIT 10', $repo->table( 'purchases' ), $repo->table( 'orders' ), $since ) );
		$sources = $wpdb->get_results( $wpdb->prepare( 'SELECT source, COUNT(*) visitors FROM %i WHERE updated_at >= %d GROUP BY source ORDER BY visitors DESC LIMIT 10', $repo->table( 'visits' ), $since ) );
		return compact( 'sales', 'traffic', 'products', 'sources' );
	}
}
