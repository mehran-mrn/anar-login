<?php
/**
 * My Account sign-in, replacing WooCommerce's myaccount/form-login.php.
 *
 * The verified code signs existing customers in and registers new ones, so one
 * form replaces both WooCommerce columns. The password form stays available for
 * staff and customers who prefer it.
 *
 * @package AnarLogin
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_customer_login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
?>
<div class="anar-wc-account">
	<?php echo do_shortcode( '[anar_login redirect="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped where it is built. ?>

	<details class="anar-wc-password">
		<summary><?php esc_html_e( 'Sign in with a password instead', 'anar-toolkit' ); ?></summary>
		<?php
		\Anar\Login\Integrations\WooCommerce::password_form(
			array(
				'redirect' => wc_get_page_permalink( 'myaccount' ),
			)
		);
		?>
	</details>
</div>
<?php
do_action( 'woocommerce_after_customer_login_form' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
