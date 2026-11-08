<?php
/**
 * An optional guide to the existing controls, with an inert shared-form preview.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Frontend\Shortcodes;
use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class LoginSetup {
	public static function render( array $value ) {
		$steps = array( __( 'Sign-in method', 'anar-toolkit' ), __( 'Connection and message', 'anar-toolkit' ), __( 'Test sign-in', 'anar-toolkit' ), __( 'Display on the site', 'anar-toolkit' ) );
		?>
		<div data-login-setup>
			<header class="anar-section-head"><div><h2><?php esc_html_e( 'Sign-in setup guide', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Use the existing settings in four steps. This guide does not change your live sign-in form.', 'anar-toolkit' ); ?></p></div><?php Help::button( __( 'Your saved values remain in their usual sections. Return to this guide at any time. Test the new form before replacing your current sign-in page.', 'anar-toolkit' ) ); ?></header>
			<ol class="anar-setup-steps" aria-label="<?php esc_attr_e( 'Setup steps', 'anar-toolkit' ); ?>">
			<?php foreach ( $steps as $index => $label ) : ?>
				<li><button type="button" class="button" data-setup-go="<?php echo (int) $index; ?>" <?php echo 0 === $index ? 'aria-current="step"' : ''; ?>><?php echo esc_html( ( $index + 1 ) . '. ' . $label ); ?></button></li>
			<?php endforeach; ?>
			</ol>
			<div class="anar-card" data-setup-step="0">
				<h3 tabindex="-1"><?php esc_html_e( 'Choose how customers sign in', 'anar-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'Choose mobile number, email or both. Existing customers keep their accounts.', 'anar-toolkit' ); ?></p>
				<button type="button" class="button" data-setup-target="general"><?php esc_html_e( 'Open sign-in settings', 'anar-toolkit' ); ?></button>
			</div>
			<div class="anar-card" data-setup-step="1" hidden>
				<h3 tabindex="-1"><?php esc_html_e( 'Connect the selected service', 'anar-toolkit' ); ?></h3>
				<?php if ( 'email' !== $value['identity_mode'] ) : ?>
					<button type="button" class="button" data-setup-target="sms"><?php esc_html_e( 'Open SMS connection', 'anar-toolkit' ); ?></button>
					<?php MessageCenter::summary( 'login_otp', 'login' ); ?>
				<?php endif; ?>
				<?php if ( 'phone' !== $value['identity_mode'] ) : ?>
					<button type="button" class="button" data-setup-target="email"><?php esc_html_e( 'Open email settings', 'anar-toolkit' ); ?></button>
				<?php endif; ?>
				<?php Help::button( __( 'Configure the service and save it. SMS templates open in the same message editor used throughout Anar; there is no separate template in this guide.', 'anar-toolkit' ) ); ?>
			</div>
			<div class="anar-card" data-setup-step="2" hidden>
				<h3 tabindex="-1"><?php esc_html_e( 'Check delivery and complete a test sign-in', 'anar-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'Send a test from the message editor, then sign in from a private browser window using your own contact. Test sending uses your provider credit.', 'anar-toolkit' ); ?></p>
				<?php if ( 'email' !== $value['identity_mode'] ) : ?>
					<?php MessageCenter::summary( 'login_otp', 'login', true ); ?>
				<?php endif; ?>
				<p><?php esc_html_e( 'A provider acceptance response does not confirm receipt on the phone. Check the received code before enabling the form for customers.', 'anar-toolkit' ); ?></p>
			</div>
			<div class="anar-card" data-setup-step="3" hidden>
				<h3 tabindex="-1"><?php esc_html_e( 'Preview the form and choose where it appears', 'anar-toolkit' ); ?></h3>
				<p><?php esc_html_e( 'The preview shows the saved form without sending codes or signing anyone in.', 'anar-toolkit' ); ?></p>
				<button type="button" class="button" data-login-preview><?php esc_html_e( 'Preview saved form', 'anar-toolkit' ); ?></button>
				<button type="button" class="button" data-setup-target="install"><?php esc_html_e( 'Open display options', 'anar-toolkit' ); ?></button>
				<?php Help::button( __( 'Use the existing shortcode, popup or WooCommerce integration. Changing where the form appears does not change its verification rules. Save appearance changes before previewing.', 'anar-toolkit' ) ); ?>
			</div>
			<div class="anar-setup-actions"><button type="button" class="button" data-setup-prev disabled><?php esc_html_e( 'Previous step', 'anar-toolkit' ); ?></button><button type="button" class="button button-primary" data-setup-next><?php esc_html_e( 'Next step', 'anar-toolkit' ); ?></button></div>
		</div>
		<?php
	}

	public static function dialog() {
		if ( ! \Anar\Login\Infrastructure\Features::enabled( 'login' ) ) {
			return;
		}
		?>
		<dialog class="anar-login-preview" data-login-preview-dialog aria-labelledby="anar-login-preview-title">
			<header><h2 id="anar-login-preview-title"><?php esc_html_e( 'Preview saved form', 'anar-toolkit' ); ?></h2><button type="button" class="button" data-login-preview-close><?php esc_html_e( 'Close', 'anar-toolkit' ); ?></button></header>
			<p><?php esc_html_e( 'The preview shows the saved form without sending codes or signing anyone in.', 'anar-toolkit' ); ?></p>
			<iframe title="<?php esc_attr_e( 'Sign-in form preview', 'anar-toolkit' ); ?>" sandbox data-src="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=anar_login_preview' ), 'anar_login_preview' ) ); ?>"></iframe>
		</dialog>
		<?php
	}

	/** Authenticated, read-only preview; no frontend scripts or working form actions. */
	public static function preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'anar_login_preview' );
		nocache_headers();
		header( "Content-Security-Policy: script-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'" );
		header( 'Referrer-Policy: no-referrer' );
		$html = ( new Shortcodes( new Settings() ) )->render_login( array(), true );
		wp_enqueue_style( 'anar-login-preview', ANAR_LOGIN_URL . 'assets/css/frontend.css', array(), ANAR_LOGIN_VERSION );
		wp_add_inline_style( 'anar-login-preview', 'body{margin:0;padding:12px;background:#f8f6f7}main{pointer-events:none}' );
		?>
		<!doctype html><html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e( 'Sign-in form preview', 'anar-toolkit' ); ?></title><?php wp_print_styles( array( 'anar-login-preview', 'anar-password-reset' ) ); ?></head><body><main inert><?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes every value. ?></main></body></html>
		<?php
		exit;
	}
}
