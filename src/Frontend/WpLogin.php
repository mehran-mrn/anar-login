<?php
/**
 * Optional replacement of wp-login.php.
 *
 * Only active when the administrator opts in. The password form stays one click
 * away (?anar_password=1), so staff are never locked out by an SMS outage.
 * Core password, recovery and special-mode handlers retain their behavior while
 * the optional Anar theme follows the whole login page.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Integrations\MrnForm;

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
		if ( $this->settings->get( 'mrn_registration_form', 0 ) ) {
			add_action( 'login_form_anar_register', array( MrnForm::class, 'page' ) );
			add_action( 'login_form_anar_signin', array( $this, 'render' ) );
		}
		if ( ! $this->settings->get( 'replace_wp_login', 0 ) ) {
			return;
		}

		add_action( 'login_form_login', array( $this, 'render' ) );
		add_action( 'login_form_register', array( $this, 'register_page' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'theme' ) );
		add_filter( 'login_body_class', array( $this, 'body_classes' ) );
		add_filter( 'login_message', array( $this, 'native_header' ) );
	}

	/** Skin the core forms without replacing their fields, validation or actions. */
	public function theme() {
		FormView::prepare_wordpress( false );
		wp_enqueue_style( 'anar-wp-login', ANAR_LOGIN_URL . 'assets/css/wp-login.css', array( 'anar-login' ), ANAR_LOGIN_VERSION );
		$color = sanitize_hex_color( $this->settings->get( 'brand_color', '#d81b3f' ) ) ?: '#d81b3f';
		wp_add_inline_style( 'anar-wp-login', 'body.anar-wp-login-themed{--anar-accent:' . $color . ';}' );
	}

	public function body_classes( $classes ) {
		$classes[] = 'anar-wp-login-themed';
		$preset = $this->settings->get( 'form_preset', 'card' );
		$classes[] = 'anar-wp-login--' . ( in_array( $preset, array( 'card', 'minimal', 'bold' ), true ) ? $preset : 'card' );
		if ( ! FormView::wordpress_custom() ) {
			$classes[] = 'anar-wp-login-native';
		}
		return $classes;
	}

	public function native_header( $message ) {
		if ( FormView::wordpress_custom() ) {
			return $message;
		}
		$logo = FormView::logo( $this->settings );
		$action = isset( $GLOBALS['action'] ) && is_string( $GLOBALS['action'] ) ? $GLOBALS['action'] : 'login';
		$titles = array( 'login' => __( 'Sign in with a password', 'anar-toolkit' ), 'lostpassword' => __( 'Password recovery', 'anar-toolkit' ), 'retrievepassword' => __( 'Password recovery', 'anar-toolkit' ), 'rp' => __( 'Set a new password', 'anar-toolkit' ), 'resetpass' => __( 'Set a new password', 'anar-toolkit' ), 'register' => __( 'Create your account', 'anar-toolkit' ) );
		ob_start();
		?>
		<header class="anar-auth__header anar-wp-login-brand">
			<a class="anar-auth__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared logo renderer escapes all dynamic values. ?>
				<?php if ( false === strpos( $logo, '<img' ) ) : ?>
					<div><span class="anar-auth__eyebrow"><?php esc_html_e( 'Fast, secure sign-in', 'anar-toolkit' ); ?></span><strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong></div>
				<?php endif; ?>
			</a>
			<span class="anar-auth__secure"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4.5 5v6.2c0 4.9 3.2 9.4 7.5 10.8 4.3-1.4 7.5-5.9 7.5-10.8V5L12 2Zm0 2.2 5.5 2.2v4.8c0 3.8-2.3 7.3-5.5 8.6-3.2-1.3-5.5-4.8-5.5-8.6V6.4L12 4.2Z"/></svg><?php echo esc_html( is_ssl() ? __( 'Secure connection', 'anar-toolkit' ) : __( 'Sign in', 'anar-toolkit' ) ); ?></span>
		</header>
		<?php if ( isset( $titles[ $action ] ) ) : ?>
			<div class="anar-wp-login-intro"><h2><?php echo esc_html( $titles[ $action ] ); ?></h2></div>
		<?php endif; ?>
		<?php
		return ob_get_clean() . $message;
	}

	public function register_page() {
		if ( $this->settings->get( 'mrn_registration_form', 0 ) && MrnForm::registration_url( $this->settings ) ) {
			MrnForm::page();
		}
		$this->render();
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
