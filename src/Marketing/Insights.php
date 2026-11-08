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
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function assets() {
		if ( ! ( new Settings() )->enabled( 'traffic' ) || current_user_can( 'manage_woocommerce' ) || is_cart() || is_checkout() || is_account_page() || is_404() ) {
			return; }
		wp_enqueue_script(
			'anar-insights',
			ANAR_LOGIN_URL . 'assets/js/insights.js',
			array(),
			ANAR_LOGIN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		// No nonce: it would be shared by every visitor and expire inside cached pages.
		wp_add_inline_script( 'anar-insights', 'window.AnarInsights=' . wp_json_encode( array( 'endpoint' => esc_url_raw( rest_url( 'anar-login/v1/visit' ) ) ) ) . ';', 'before' );
	}

	/**
	 * Beacon endpoint. REST avoids admin-ajax, which boots the whole admin area
	 * (and every plugin's admin code) for each storefront page view.
	 */
	public function routes() {
		register_rest_route(
			'anar-login/v1',
			'/visit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function visit( $request ) {
		global $wpdb;
		$token  = (string) $request->get_param( 'visitor' );
		$source = strtolower( sanitize_text_field( (string) $request->get_param( 'source' ) ) );
		if ( ! ( new Settings() )->enabled( 'traffic' ) || ! preg_match( '/^[a-f0-9-]{32,36}$/D', $token ) ) {
			return new \WP_REST_Response( null, 400 ); }
		// Bounded in the plugin's own limits table; transients would grow wp_options on sites without an object cache.
		$limits = new \Anar\Login\Infrastructure\RateLimiter( new \Anar\Login\Infrastructure\Settings() );
		if ( ! $limits->allow( 'visit_ip', $limits->client_ip(), MINUTE_IN_SECONDS, 120 ) ) {
			return new \WP_REST_Response( null, 429 ); }
		$source = preg_match( '/^[a-z0-9.-]{0,190}$/D', $source ) ? $source : '';
		$repo = new Repository();
		$day = wp_date( 'Y-m-d' );
		$key = hash_hmac( 'sha256', $day . '|' . $token, wp_salt( 'auth' ) );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (visit_key, day, source, views, updated_at) VALUES (%s,%s,%s,1,%d) ON DUPLICATE KEY UPDATE views = LEAST(views + 1, 200), updated_at = VALUES(updated_at)', $repo->table( 'visits' ), $key, $day, $source, time() ) );
		return new \WP_REST_Response( null, 204 );
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
