<?php
/**
 * Shared branding and frame for every interactive authentication step.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class FormView {
	public static function open( Settings $settings, $class, $redirect, $title, $intro = '' ) {
		$direction = is_rtl() ? 'rtl' : 'ltr';
		ob_start();
		?>
		<section class="anar-auth <?php echo esc_attr( $class . ' anar-auth--' . $settings->get( 'form_preset', 'card' ) ); ?>" dir="<?php echo esc_attr( $direction ); ?>" data-redirect="<?php echo esc_url( $redirect ? wp_validate_redirect( $redirect, '' ) : '' ); ?>" style="--anar-accent:<?php echo esc_attr( $settings->get( 'brand_color', '#d81b3f' ) ); ?>">
			<div class="anar-auth__glow" aria-hidden="true"></div>
			<header class="anar-auth__header">
				<?php $logo = self::logo( $settings ); ?>
				<div class="anar-auth__brand">
					<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside logo(). ?>
					<?php if ( false === strpos( $logo, '<img' ) ) : // An image logo already names the site. ?>
						<div>
							<span class="anar-auth__eyebrow"><?php esc_html_e( 'Fast, secure sign-in', 'anar-toolkit' ); ?></span>
							<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
						</div>
					<?php endif; ?>
				</div>
				<span class="anar-auth__secure">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4.5 5v6.2c0 4.9 3.2 9.4 7.5 10.8 4.3-1.4 7.5-5.9 7.5-10.8V5L12 2Zm0 2.2 5.5 2.2v4.8c0 3.8-2.3 7.3-5.5 8.6-3.2-1.3-5.5-4.8-5.5-8.6V6.4L12 4.2Zm-.9 4.3v2H9.8v5h4.4v-5h-1.3v-2h-1.8Z"/></svg>
					<?php echo esc_html( is_ssl() ? __( 'Secure connection', 'anar-toolkit' ) : __( 'One-time code', 'anar-toolkit' ) ); ?>
				</span>
			</header>

			<div class="anar-auth__body">
				<div class="anar-auth__intro">
					<h2><?php echo esc_html( $title ); ?></h2>
						<?php if ( '' !== $intro ) : ?>
					<p><?php echo esc_html( $intro ); ?></p>
						<?php endif; ?>
				</div>

		<?php
		return ob_get_clean();
	}

	public static function close( $footer = '' ) {
		$footer = $footer ? $footer : __( 'Your one-time code expires and can only be used once.', 'anar-toolkit' );
		ob_start();
		?>
			</div>
			<footer class="anar-auth__footer">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2Zm-7-2a2 2 0 0 1 4 0v2h-4V6Zm7 12H7v-8h10v8Z"/></svg>
				<?php echo esc_html( $footer ); ?>
			</footer>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function prepare_wordpress() {
		static $prepared = false;
		if ( $prepared ) {
			return; }
		$prepared = true;
		wp_enqueue_style( 'anar-login', ANAR_LOGIN_URL . 'assets/css/frontend.css', array( 'login' ), ANAR_LOGIN_VERSION );
		$styles = wp_styles();
		if ( isset( $styles->registered['anar-login'] ) && ! in_array( 'login', $styles->registered['anar-login']->deps, true ) ) {
			$styles->registered['anar-login']->deps[] = 'login';
		}
		add_filter(
			'login_body_class',
			static function ( $classes ) {
				$classes[] = 'anar-wp-login';
				return $classes;
			}
		);
	}

	/**
	 * Brand mark for the form: the site's logo by default, never the plugin's.
	 *
	 * Site logo falls back to the site icon, then to a neutral lock icon.
	 *
	 * @return string Escaped HTML.
	 */
	public static function logo( Settings $settings ) {
		$mode = (string) $settings->get( 'form_logo', 'site' );
		$url  = '';

		if ( 'none' === $mode ) {
			return '';
		}
		if ( 'custom' === $mode ) {
			$url = (string) $settings->get( 'form_logo_url', '' );
		} elseif ( 'site' === $mode ) {
			$logo_id = (int) get_theme_mod( 'custom_logo' );
			$url     = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
			$url     = $url ? $url : (string) get_site_icon_url( 128 );
		}

		/**
		 * Filter the logo URL shown on the sign-in form; an empty string shows the lock icon.
		 *
		 * @param string $url  Image URL.
		 * @param string $mode Logo setting.
		 */
		$url = (string) apply_filters( 'anar_login_logo_url', $url, $mode );

		if ( $url ) {
			return sprintf( '<img src="%1$s" alt="%2$s" class="anar-auth__logo anar-auth__logo--image" loading="lazy" decoding="async">', esc_url( $url ), esc_attr( get_bloginfo( 'name' ) ) );
		}

		return '<span class="anar-auth__logo anar-auth__logo--icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2Zm-7-2a2 2 0 0 1 4 0v2h-4V6Zm7 12H7v-8h10v8Zm-5-2.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/></svg></span>';
	}
}
