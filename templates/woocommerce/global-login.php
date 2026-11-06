<?php
/**
 * Replaces WooCommerce's global/form-login.php, the form behind
 * woocommerce_login_form() that themes and plugins print in many places.
 *
 * @package AnarLogin
 *
 * @var string $redirect Destination passed by the caller, if any.
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
	return;
}

$anar_redirect = ! empty( $redirect ) ? (string) $redirect : '';

echo do_shortcode( '[anar_login redirect="' . esc_url( $anar_redirect ) . '" password_link="1"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped where it is built.
