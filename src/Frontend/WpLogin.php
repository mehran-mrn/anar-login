<?php
/**
 * Optional replacement of wp-login.php.
 *
 * Only active when the administrator opts in. The password form stays one click
 * away (?anar_password=1), so staff are never locked out by an SMS outage, and
 * every other wp-login.php action (logout, lost password, two-step code) is untouched.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class WpLogin {
	/** @var Settings */
	private $settings;

	/** @var Shortcodes */
	private $shortcodes;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Shortcodes $shortcodes Shortcodes, which render the form.
	 */
	public function __construct( Settings $settings, Shortcodes $shortcodes ) {
		$this->settings   = $settings;
		$this->shortcodes = $shortcodes;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! $this->settings->get( 'replace_wp_login', 0 ) ) {
			return;
		}

		add_action( 'login_form_login', array( $this, 'render' ) );
		add_action( 'login_form_register', array( $this, 'render' ) );
	}

	/**
	 * Show the passwordless form instead of the password form.
	 *
	 * @return void
	 */
	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing flags.
		$is_post  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$password = ! empty( $_GET['anar_password'] );
		$special  = isset( $_REQUEST['interim-login'] ) || ! empty( $_REQUEST['reauth'] );
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Password submissions, the explicit password link and core's special modes keep the original form.
		if ( $is_post || $password || $special ) {
			return;
		}

		// Rendering first enqueues the form's assets before login_header() prints the head.
		$form = $this->shortcodes->login(
			array(
				'redirect'      => $redirect,
				'password_link' => '1',
			)
		);

		FormView::prepare_wordpress();

		login_header( __( 'Sign in', 'anar-toolkit' ) );
		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped where it is built.
		login_footer();
		exit;
	}
}
