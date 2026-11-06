<?php
/**
 * Print-ready invoices with order ownership and expiring scoped links.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class Invoices {
	public function register() {
		add_action( 'template_redirect', array( $this, 'render' ), 0 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'actions' ), 20, 2 );
	}

	public static function url( $order, $expires = 0 ) {
		$expires = $expires ?: time() + 7 * DAY_IN_SECONDS;
		return add_query_arg( 'ai', Frontend::token( 'invoice', $order->get_id(), $expires, hash_hmac( 'sha256', 'invoice|' . $order->get_id() . '|' . $order->get_order_key() . '|' . $expires, wp_salt( 'auth' ) ) ), home_url( '/' ) );
	}

	public static function authorized( $order, $expires, $signature ) {
		if ( ! $order ) {
			return false; }
		if ( current_user_can( 'manage_woocommerce' ) || ( is_user_logged_in() && $order->get_customer_id() > 0 && $order->get_customer_id() === get_current_user_id() ) ) {
			return true; }
		return $expires >= time() && $expires <= time() + 8 * DAY_IN_SECONDS && hash_equals( substr( hash_hmac( 'sha256', 'invoice|' . $order->get_id() . '|' . $order->get_order_key() . '|' . $expires, wp_salt( 'auth' ) ), 0, 40 === strlen( $signature ) ? 40 : 64 ), $signature );
	}

	public function actions( $actions, $order ) {
		if ( ( new Settings() )->enabled( 'invoice' ) ) {
			$actions['anar-invoice'] = array( 'url' => self::url( $order ), 'name' => __( 'Print invoice', 'anar-toolkit' ) ); }
		return $actions;
	}

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only invoice, protected by order ownership or a purpose-scoped HMAC.
		if ( empty( $_GET['anar_invoice'] ) && empty( $_GET['ai'] ) ) {
			return; }
		$order = wc_get_order( isset( $_GET['anar_invoice'] ) ? absint( $_GET['anar_invoice'] ) : 0 );
		$expires = isset( $_GET['expires'] ) ? absint( $_GET['expires'] ) : 0;
		$signature = isset( $_GET['signature'] ) && is_string( $_GET['signature'] ) ? sanitize_text_field( wp_unslash( $_GET['signature'] ) ) : '';
		if ( isset( $_GET['ai'] ) && is_string( $_GET['ai'] ) ) {
			$link = Frontend::decode( sanitize_text_field( wp_unslash( $_GET['ai'] ) ) );
			$order = $link && 'invoice' === $link['scope'] ? wc_get_order( $link['id'] ) : null;
			$expires = $link['expires'] ?? 0;
			$signature = $link['signature'] ?? '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! ( new Settings() )->enabled( 'invoice' ) || ! self::authorized( $order, $expires, $signature ) ) {
			wp_die( esc_html__( 'This invoice link is invalid, expired or does not belong to you.', 'anar-toolkit' ), '', array( 'response' => 403 ) ); }
		$config = ( new Settings() )->all();
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
		<!doctype html><html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( $config['invoice_prefix'] . $order->get_order_number() ); ?></title><style>body{margin:0;background:#f4f1f5;color:#29232f;font:14px/1.9 Tahoma,Arial,sans-serif}main{max-width:850px;margin:40px auto;background:#fff;border:1px solid #e9e0ea;padding:46px;border-radius:18px}header{display:flex;justify-content:space-between;gap:24px;border-bottom:3px solid #a72c52;padding-bottom:24px}h1{font-size:24px;margin:0;color:#9b294e}h2{font-size:16px;margin-top:0}.addresses{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin:32px 0;color:#786d80}table{width:100%;border-collapse:collapse;margin:26px 0}th,td{padding:13px;text-align:start;border-bottom:1px solid #eee8f0}th{font-size:12px;color:#95849f;background:#faf7fb}.totals{max-width:360px;margin-inline-start:auto}.total{display:flex;justify-content:space-between;padding:8px 0}.grand{font-weight:700;color:#9b294e;border-top:2px solid #e8d9e3;padding-top:16px}footer{font-size:11px;color:#9c8da6;border-top:1px solid #e9e0ed;padding-top:18px;margin-top:30px}button{display:block;margin:20px auto;background:#a72c52;color:#fff;border:0;border-radius:10px;padding:12px 26px;cursor:pointer}small{display:block;color:#96879d}address{font-style:normal}@media(max-width:600px){main{margin:12px;padding:20px}.addresses{grid-template-columns:1fr}table{font-size:11px}th,td{padding:8px}header{flex-direction:column}}@media print{body{background:#fff}main{margin:0;padding:0;border:0;max-width:none}button{display:none}@page{size:A4;margin:18mm}}</style></head><body><main>
		<header><div><h1><?php echo esc_html( $config['store_name'] ?: get_bloginfo( 'name' ) ); ?></h1><small><?php echo esc_html( $config['store_address'] ); ?></small>
		<?php
		if ( $config['store_tax_id'] ) :
			?>
			<small><?php esc_html_e( 'Store tax / registration ID:', 'anar-toolkit' ); ?> <?php echo esc_html( $config['store_tax_id'] ); ?></small><?php endif; ?></div><div><h2><?php esc_html_e( 'Order invoice', 'anar-toolkit' ); ?> <span dir="ltr"><?php echo esc_html( $config['invoice_prefix'] . $order->get_order_number() ); ?></span></h2><small><?php echo esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ); ?></small><small><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></small></div></header>
		<div class="addresses"><div><h2><?php esc_html_e( 'Bill to', 'anar-toolkit' ); ?></h2><address><?php echo wp_kses_post( $order->get_formatted_billing_address() ); ?></address><small dir="ltr"><?php echo esc_html( $order->get_billing_phone() ); ?></small></div><div><h2><?php esc_html_e( 'Ship to', 'anar-toolkit' ); ?></h2><address><?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?></address><small><?php echo esc_html( $order->get_payment_method_title() ); ?></small></div></div>
		<table><thead><tr><th><?php esc_html_e( 'Item', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Quantity', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Amount', 'anar-toolkit' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $order->get_items() as $item ) :
			?>
			<tr><td><?php echo esc_html( $item->get_name() ); ?>
			<?php
			if ( $item->get_product() ) :
				?>
			<small><?php echo esc_html( $item->get_product()->get_sku() ); ?></small><?php endif; ?></td><td><?php echo esc_html( $item->get_quantity() ); ?></td><td><?php echo wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td></tr><?php endforeach; ?></tbody></table><div class="totals">
			<?php
			foreach ( $order->get_order_item_totals() as $key => $total ) :
				?>
	<div class="total <?php echo 'order_total' === $key ? 'grand' : ''; ?>"><span><?php echo esc_html( $total['label'] ); ?></span><span><?php echo wp_kses_post( $total['value'] ); ?></span></div><?php endforeach; ?></div><footer><?php esc_html_e( 'This document reflects the current order and payment status. Use your browser print dialog to print it or save it as PDF.', 'anar-toolkit' ); ?></footer></main><button type="button" onclick="window.print()"><?php esc_html_e( 'Print / save as PDF', 'anar-toolkit' ); ?></button></body></html>
		<?php
		exit;
	}
}
