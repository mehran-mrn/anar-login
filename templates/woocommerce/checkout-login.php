<?php
/**
 * Checkout sign-in, replacing WooCommerce's checkout/form-login.php.
 *
 * A native details element toggles the form, so no extra script is needed and
 * the customer returns to checkout once signed in.
 *
 * @package AnarLogin
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() || 'yes' !== get_option( 'woocommerce_enable_checkout_login_reminder' ) ) {
	return;
}
?>
<details class="woocommerce-form-login-toggle anar-wc-checkout">
	<summary class="woocommerce-info">
		<?php esc_html_e( 'Returning customer?', 'anar-toolkit' ); ?>
		<span class="showlogin"><?php esc_html_e( 'Sign in with a one-time code', 'anar-toolkit' ); ?></span>
	</summary>
	<?php echo do_shortcode( '[anar_login redirect="' . esc_url( wc_get_checkout_url() ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped where it is built. ?>
</details>
