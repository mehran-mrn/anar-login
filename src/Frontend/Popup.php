<?php
/**
 * Login popup that loads on demand.
 *
 * Every page only carries a ~1 KB inline loader. The form markup, stylesheet and
 * script are fetched on the first click of a trigger (and preloaded on hover),
 * so the popup adds no requests and no render-blocking assets to page views.
 *
 * Triggers: any link to #anar-login (e.g. a custom menu item), elements with the
 * anar-login-trigger class, or the [anar_login_button] shortcode.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Api\AuthController;
use Anar\Login\Infrastructure\Settings;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Popup {
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
		add_shortcode( 'anar_login_button', array( $this, 'button' ) );

		if ( ! $this->settings->get( 'popup_enabled', 0 ) && ! $this->settings->get( 'replace_theme_login', 1 ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'loader' ), 99 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'exclude_from_prefetch' ) );
	}

	/**
	 * Keep browsers from prefetching login targets for visitors.
	 *
	 * Core speculative loading prefetches a link on pointer-down; for a link the
	 * popup intercepts, that stores the signed-out page and the browser would
	 * reuse it right after sign-in.
	 *
	 * @param string[] $paths Excluded path patterns, relative to the home URL.
	 * @return string[]
	 */
	public function exclude_from_prefetch( $paths ) {
		if ( is_user_logged_in() ) {
			return $paths;
		}

		$home = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		$urls = array( $this->account_url() );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = (string) wc_get_page_permalink( 'myaccount' );
		}

		foreach ( array_unique( array_filter( $urls ) ) as $url ) {
			$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$path = '' !== $home && 0 === strpos( $path, $home ) ? substr( $path, strlen( $home ) ) : $path;
			if ( $path && '/' !== $path ) {
				$paths[] = $path;
				$paths[] = $path . '/*';
			}
		}

		return $paths;
	}

	/**
	 * Register the form endpoint.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			AuthController::NAMESPACE,
			'/form',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'form' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'redirect' => array( 'required' => false, 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	/**
	 * Form markup and assets for the popup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function form( WP_REST_Request $request ) {
		$redirect = wp_validate_redirect( (string) $request->get_param( 'redirect' ), '' );

		return array(
			'html'   => $this->shortcodes->login(
				array(
					'redirect'      => $redirect,
					'password_link' => '1',
				)
			),
			'config' => $this->shortcodes->script_config(),
			'css'    => add_query_arg( 'ver', ANAR_LOGIN_VERSION, ANAR_LOGIN_URL . 'assets/css/frontend.css' ),
			'js'     => add_query_arg( 'ver', ANAR_LOGIN_VERSION, ANAR_LOGIN_URL . 'assets/js/frontend.js' ),
		);
	}

	/**
	 * Print the inline loader.
	 *
	 * @return void
	 */
	public function loader() {
		$file      = ANAR_LOGIN_PATH . 'assets/js/popup.js';
		$logged_in = is_user_logged_in();
		$popup     = (bool) $this->settings->get( 'popup_enabled', 0 );

		// Signed-in visitors only need the loader for explicit popup triggers.
		if ( ! is_readable( $file ) || ( $logged_in && ! $popup ) ) {
			return;
		}

		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		$config  = array(
			'formUrl'     => esc_url_raw( rest_url( AuthController::NAMESPACE . '/form' ) ),
			'loggedIn'    => $logged_in,
			'accountUrl'  => esc_url_raw( $this->account_url() ),
			'replace'     => (bool) $this->settings->get( 'replace_theme_login', 1 ),
			// wp-login.php links are left alone unless the admin form is replaced too.
			'loginPath'   => $this->settings->get( 'replace_wp_login', 0 ) ? untrailingslashit( (string) wp_parse_url( wp_login_url(), PHP_URL_PATH ) ) : '',
			'accountPath' => $account ? untrailingslashit( (string) wp_parse_url( $account, PHP_URL_PATH ) ) : '',
			'close'       => __( 'Close', 'anar-toolkit' ),
			'title'       => __( 'Sign in', 'anar-toolkit' ),
		);

		$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		// The loader ships on every page, so drop whole-line comments and indentation.
		$source = (string) preg_replace( array( '#^\s*//.*$#m', '#^\s+#m', '#\n+#' ), array( '', '', "\n" ), $source );

		wp_print_inline_script_tag(
			'window.AnarLoginPopup=' . wp_json_encode( $config ) . ';' . $source,
			array( 'id' => 'anar-login-popup' )
		);
	}

	/**
	 * [anar_login_button label="..."] — a link that opens the popup, or the account page when signed in.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 * @return string
	 */
	public function button( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'label'         => __( 'Sign in / Register', 'anar-toolkit' ),
				'account_label' => __( 'My account', 'anar-toolkit' ),
				'class'         => '',
			),
			is_array( $atts ) ? $atts : array(),
			'anar_login_button'
		);

		$logged_in = is_user_logged_in();
		// Without the popup the link falls back to the account page, which shows the form to visitors.
		$href = $logged_in || ! $this->settings->get( 'popup_enabled', 0 ) ? $this->account_url() : '#anar-login';

		return sprintf(
			'<a href="%1$s" class="anar-login-button %2$s">%3$s</a>',
			esc_url( $href ),
			esc_attr( $atts['class'] ),
			esc_html( $logged_in ? $atts['account_label'] : $atts['label'] )
		);
	}

	/**
	 * Account page, else WooCommerce My Account, else the WordPress login.
	 *
	 * @return string
	 */
	private function account_url() {
		$panel_id = absint( $this->settings->get( 'panel_page_id', 0 ) );
		if ( $panel_id && get_permalink( $panel_id ) ) {
			return (string) get_permalink( $panel_id );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			return (string) wc_get_page_permalink( 'myaccount' );
		}

		return is_user_logged_in() ? admin_url( 'profile.php' ) : wp_login_url();
	}
}
