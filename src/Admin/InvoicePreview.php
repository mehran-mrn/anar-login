<?php
/**
 * Anar toolkit update: configuration, delivery and presentation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

defined( 'ABSPATH' ) || exit;
final class InvoicePreview {
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WC_Order' ) ) {
			wp_die( esc_html__( 'You cannot preview invoices.', 'anar-toolkit' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'anar_invoice_preview' );
		$id = absint( $_GET['preview_order'] ?? 0 );
		$order = $id ? wc_get_order( $id ) : new \WC_Order();
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'anar-toolkit' ) ); }
		if ( ! $id ) {
			$order->set_billing_first_name( __( 'Sample customer', 'anar-toolkit' ) );
			$order->set_billing_address_1( __( 'Sample address', 'anar-toolkit' ) );
			$order->set_currency( get_woocommerce_currency() );
			$order->set_date_created( time() );
			$order->set_status( 'processing' );
			$item = new \WC_Order_Item_Product();
			$item->set_name( __( 'Sample product', 'anar-toolkit' ) );
			$item->set_quantity( 2 );
			$item->set_subtotal( 200000 );
			$item->set_total( 200000 );
			$order->add_item( $item );
			$order->set_total( 200000 );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Referrer-Policy: no-referrer' );
		\Anar\Login\Marketing\Invoices::document( $order, ( new \Anar\Login\Marketing\Settings() )->all() );
		exit;
	}
	public static function controls( $config ) {
		?>
<p><label><?php esc_html_e( 'Invoice template', 'anar-toolkit' ); ?> <select name="anar_toolkit_marketing[invoice_template]"><option value="branded" <?php selected( $config['invoice_template'], 'branded' ); ?>><?php esc_html_e( 'Branded', 'anar-toolkit' ); ?></option><option value="simple" <?php selected( $config['invoice_template'], 'simple' ); ?>><?php esc_html_e( 'Simple print', 'anar-toolkit' ); ?></option></select></label></p>
		<?php foreach ( array( 'invoice_color' => __( 'Accent color', 'anar-toolkit' ), 'invoice_logo' => __( 'Logo URL', 'anar-toolkit' ), 'invoice_footer' => __( 'Invoice footer', 'anar-toolkit' ) ) as $key => $label ) : ?>
<p><label><?php echo esc_html( $label ); ?> <input type="<?php echo 'invoice_color' === $key ? 'color' : ( 'invoice_logo' === $key ? 'url' : 'text' ); ?>" name="anar_toolkit_marketing[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $config[ $key ] ); ?>"></label></p>
<?php endforeach; foreach ( array( 'invoice_show_sku' => __( 'Show product SKU', 'anar-toolkit' ), 'invoice_show_shipping' => __( 'Show shipping address', 'anar-toolkit' ), 'invoice_show_note' => __( 'Show customer note', 'anar-toolkit' ) ) as $key => $label ) : ?>
<p><input type="hidden" name="anar_toolkit_marketing[<?php echo esc_attr( $key ); ?>]" value="0"><label><input type="checkbox" name="anar_toolkit_marketing[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $config[ $key ] ); ?>><?php echo esc_html( $label ); ?></label></p>
<?php endforeach; ?>
<p><label><?php esc_html_e( 'Preview order ID (empty = sample)', 'anar-toolkit' ); ?> <input type="number" min="1" data-invoice-order></label></p>
<p><a data-invoice-preview class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=anar_invoice_preview' ), 'anar_invoice_preview' ) ); ?>"><?php esc_html_e( 'Preview saved invoice settings', 'anar-toolkit' ); ?></a></p>
		<?php
	}
}
