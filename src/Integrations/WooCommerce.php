<?php
/**
 * WooCommerce integration.
 *
 * Loaded only when WooCommerce is active. Puts passwordless sign-in on My Account
 * and checkout, keeps the billing phone in sync, and registers new users as
 * customers.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Integrations;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {
	/** @var Settings */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wc_get_template', array( $this, 'template' ), 10, 2 );
		add_action( 'woocommerce_before_customer_login_form', array( $this, 'above_account_forms' ), 5 );
		add_filter( 'anar_login_enqueue_assets', array( $this, 'needs_assets' ) );
		add_filter( 'anar_login_default_redirect', array( $this, 'default_redirect' ) );
		add_filter( 'anar_login_redirect_url', array( $this, 'login_redirect' ), 10, 2 );
		add_filter( 'anar_login_new_user_role', array( $this, 'customer_role' ) );
		add_action( 'anar_login_user_registered', array( $this, 'sync_phone' ) );
		add_action( 'anar_login_authenticated', array( $this, 'sync_phone' ) );
	}

	/**
	 * Swap WooCommerce login templates for the passwordless versions.
	 *
	 * @param string $template      Located template path.
	 * @param string $template_name Template name.
	 * @return string
	 */
	public function template( $template, $template_name ) {
		if ( 'myaccount/form-login.php' === $template_name && 'replace' === $this->settings->get( 'wc_account_mode', 'replace' ) ) {
			return ANAR_LOGIN_PATH . 'templates/woocommerce/myaccount-login.php';
		}

		if ( 'checkout/form-login.php' === $template_name && $this->settings->get( 'wc_checkout_login', 1 ) ) {
			return ANAR_LOGIN_PATH . 'templates/woocommerce/checkout-login.php';
		}

		// The shared form behind woocommerce_login_form(), including theme overrides of it.
		if ( 'global/form-login.php' === $template_name && ! self::$password_form && $this->settings->get( 'replace_theme_login', 1 ) ) {
			return ANAR_LOGIN_PATH . 'templates/woocommerce/global-login.php';
		}

		return $template;
	}

	/**
	 * Set while the explicit password fallback renders, so it is not replaced too.
	 *
	 * @var bool
	 */
	private static $password_form = false;

	/**
	 * Render WooCommerce's original password form.
	 *
	 * @param array<string,mixed> $args woocommerce_login_form() arguments.
	 * @return void
	 */
	public static function password_form( $args = array() ) {
		self::$password_form = true;
		woocommerce_login_form( $args );
		self::$password_form = false;
	}

	/**
	 * In "above" mode, show the form above WooCommerce's own login and register forms.
	 *
	 * @return void
	 */
	public function above_account_forms() {
		if ( 'above' === $this->settings->get( 'wc_account_mode', 'replace' ) ) {
			echo '<div class="anar-wc-account">' . do_shortcode( '[anar_login redirect="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" password_form="1"]' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is escaped where it is built.
		}
	}

	/**
	 * Load the login assets in the head on WooCommerce pages that show the form.
	 *
	 * @param bool $needed Whether a shortcode was found in the content.
	 * @return bool
	 */
	public function needs_assets( $needed ) {
		if ( $needed || is_user_logged_in() ) {
			return $needed;
		}

		$account  = 'off' !== $this->settings->get( 'wc_account_mode', 'replace' ) && is_account_page();
		$checkout = $this->settings->get( 'wc_checkout_login', 1 ) && is_checkout() && 'yes' === get_option( 'woocommerce_enable_checkout_login_reminder' );

		return $account || $checkout;
	}

	/**
	 * Use My Account as the fallback destination instead of the home page.
	 *
	 * @param string $url Fallback URL.
	 * @return string
	 */
	public function default_redirect( $url ) {
		$panel = absint( $this->settings->get( 'panel_page_id', 0 ) );

		return $panel ? $url : wc_get_page_permalink( 'myaccount' );
	}

	/**
	 * Apply WooCommerce's own login redirect filter for compatibility.
	 *
	 * @param string $url     Destination.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function login_redirect( $url, $user_id ) {
		$user = get_userdata( $user_id );

		return $user ? (string) apply_filters( 'woocommerce_login_redirect', $url, $user ) : $url; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
	}

	/**
	 * Register new users as WooCommerce customers.
	 *
	 * @param string $role Role from settings.
	 * @return string
	 */
	public function customer_role( $role ) {
		return $this->settings->get( 'wc_customer_role', 1 ) && get_role( 'customer' ) ? 'customer' : $role;
	}

	/**
	 * Fill an empty billing phone with the verified mobile number.
	 *
	 * @param int|WP_User $user User ID or object.
	 * @return void
	 */
	public function sync_phone( $user ) {
		$user_id = $user instanceof WP_User ? $user->ID : absint( $user );
		if ( ! $user_id || ! $this->settings->get( 'wc_sync_phone', 1 ) ) {
			return;
		}

		$phone = (string) get_user_meta( $user_id, '_anar_phone', true );
		if ( $phone && ! get_user_meta( $user_id, 'billing_phone', true ) ) {
			update_user_meta( $user_id, 'billing_phone', Identity::local_phone( $phone ) );
		}
	}
}
