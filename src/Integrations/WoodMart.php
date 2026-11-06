<?php
/**
 * WoodMart theme integration.
 *
 * WoodMart draws its own password form in the header account dropdown and in a
 * side panel, outside WooCommerce templates. With theme login replacement on,
 * both are removed for signed-out visitors and their links open the Anar popup.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Integrations;

defined( 'ABSPATH' ) || exit;

final class WoodMart {
	/**
	 * Whether the active theme (or parent theme) is WoodMart.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'woodmart_login_form' );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woodmart_account_element_dropdown', array( $this, 'dropdown' ) );
		add_action( 'wp', array( $this, 'remove_side_panel' ) );
		add_filter( 'anar_login_logo_url', array( $this, 'logo' ), 10, 2 );
		add_action( 'wp_footer', array( $this, 'remember_logo' ) );
	}

	/**
	 * Store the header logo while the header is loaded, for the popup's REST request.
	 *
	 * Autoloaded and written only when it changes, so page views cost no query.
	 *
	 * @return void
	 */
	public function remember_logo() {
		$image = $this->header_logo();
		if ( $image && get_option( 'anar_login_theme_logo' ) !== $image ) {
			update_option( 'anar_login_theme_logo', $image, true );
		}
	}

	/**
	 * Drop the header password dropdown for visitors; members keep their account menu.
	 *
	 * @param string $html Dropdown HTML.
	 * @return string
	 */
	public function dropdown( $html ) {
		return is_user_logged_in() ? $html : '';
	}

	/**
	 * Remove the side-panel password form; its opener now launches the popup.
	 *
	 * @return void
	 */
	public function remove_side_panel() {
		remove_action( 'woodmart_before_wp_footer', 'woodmart_sidebar_login_form', 160 );
	}

	/**
	 * Use the logo from WoodMart's header builder when the site logo is requested.
	 *
	 * @param string $url  Logo URL found so far.
	 * @param string $mode Logo setting.
	 * @return string
	 */
	public function logo( $url, $mode ) {
		if ( 'site' !== $mode ) {
			return $url;
		}

		// The header is not built during REST requests (the popup form), so fall back to the stored copy.
		$image = $this->header_logo();
		$image = $image ? $image : (string) get_option( 'anar_login_theme_logo', '' );

		return $image ? $image : $url;
	}

	/**
	 * Logo image chosen in WoodMart's header builder, if the header is loaded.
	 *
	 * Header options are keyed by element ID, so the logo element is recognised
	 * by its image and sticky image parameters.
	 *
	 * @return string
	 */
	private function header_logo() {
		if ( ! function_exists( 'whb_get_header' ) || ! function_exists( 'whb_get_settings' ) ) {
			return '';
		}

		try {
			$settings = is_object( whb_get_header() ) ? (array) whb_get_settings() : array();
		} catch ( \Throwable $error ) {
			return '';
		}

		foreach ( $settings as $params ) {
			if ( is_array( $params ) && array_key_exists( 'sticky_image', $params ) && ! empty( $params['image']['url'] ) ) {
				return (string) $params['image']['url'];
			}
		}

		return '';
	}
}
