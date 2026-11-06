<?php
/**
 * Frontend login and account shortcodes.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {
	/** @var Settings */
	private $settings;

	/** @var bool */
	private $localized = false;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register shortcodes and assets.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'anar_login', array( $this, 'login' ) );
		add_shortcode( 'anar_account', array( $this, 'account' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register assets everywhere but enqueue them only where a shortcode is used.
	 *
	 * Pages without the form load no CSS, no JavaScript and no inline data at all.
	 * When the shortcode sits in the post content, the stylesheet goes in the head
	 * to avoid a flash of unstyled content; shortcodes rendered elsewhere (widgets,
	 * page builders) still enqueue on demand from the render callback.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'anar-login', ANAR_LOGIN_URL . 'assets/css/frontend.css', array(), ANAR_LOGIN_VERSION );
		wp_register_script(
			'anar-login',
			ANAR_LOGIN_URL . 'assets/js/frontend.js',
			array(),
			ANAR_LOGIN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$post   = get_queried_object();
		$in_use = is_singular() && $post instanceof \WP_Post && ( has_shortcode( $post->post_content, 'anar_login' ) || has_shortcode( $post->post_content, 'anar_account' ) );

		/**
		 * Filter whether this page needs the login assets in the head.
		 *
		 * Integrations that render the form from templates (e.g. WooCommerce) return true here.
		 *
		 * @param bool $in_use Whether the queried post contains a shortcode.
		 */
		if ( apply_filters( 'anar_login_enqueue_assets', $in_use ) ) {
			$this->assets();
		}
	}

	/**
	 * Login card.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes; redirect sets the post-login destination.
	 * @return string
	 */
	public function login( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'redirect'      => '',
				'password_link' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'anar_login'
		);
		$this->assets();
		$direction = is_rtl() ? 'rtl' : 'ltr';

		if ( is_user_logged_in() ) {
			$panel_id  = absint( $this->settings->get( 'panel_page_id', 0 ) );
			$panel_url = $panel_id ? get_permalink( $panel_id ) : home_url( '/' );

			return sprintf(
				'<div class="anar-auth anar-auth--compact" dir="%1$s">%2$s<div><strong>%3$s</strong><p>%4$s</p></div><a class="anar-btn anar-btn--soft" href="%5$s">%6$s</a></div>',
				esc_attr( $direction ),
				$this->logo(), // Escaped inside logo().
				esc_html__( 'You are signed in', 'anar-toolkit' ),
				esc_html( wp_get_current_user()->display_name ),
				esc_url( $panel_url ),
				esc_html__( 'Go to my account', 'anar-toolkit' )
			);
		}

		$identity_id = wp_unique_id( 'anar-identity-' );
		$code_id     = wp_unique_id( 'anar-code-' );
		$field       = $this->identity_field();
		$error       = $this->query_error();

		ob_start();
		?>
		<section class="anar-auth anar-login-card" dir="<?php echo esc_attr( $direction ); ?>" data-redirect="<?php echo esc_url( $atts['redirect'] ? wp_validate_redirect( $atts['redirect'], '' ) : '' ); ?>" style="--anar-accent:<?php echo esc_attr( $this->settings->get( 'brand_color', '#d81b3f' ) ); ?>">
			<div class="anar-auth__glow" aria-hidden="true"></div>
			<header class="anar-auth__header">
				<?php $logo = $this->logo(); ?>
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
					<h2><?php esc_html_e( 'Welcome', 'anar-toolkit' ); ?></h2>
					<p><?php echo esc_html( $field['intro'] ); ?></p>
				</div>

				<form class="anar-form anar-form--identity" novalidate>
					<label for="<?php echo esc_attr( $identity_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
					<div class="anar-input-wrap">
						<?php if ( 'email' === $field['mode'] ) : ?>
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm8 7.2L4 6.3V18h16V6.3l-8 4.9Zm0-2.3L19.2 6H4.8L12 8.9Z"/></svg>
						<?php else : ?>
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v13h10V5H7Zm4 14.5h2V21h-2v-1.5Z"/></svg>
						<?php endif; ?>
						<input id="<?php echo esc_attr( $identity_id ); ?>" name="identity" type="<?php echo esc_attr( $field['type'] ); ?>" inputmode="<?php echo esc_attr( $field['inputmode'] ); ?>" autocomplete="<?php echo esc_attr( $field['autocomplete'] ); ?>" dir="ltr" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" autocapitalize="off" spellcheck="false" required>
					</div>
					<input class="anar-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button class="anar-btn anar-btn--primary" type="submit">
						<span><?php esc_html_e( 'Send login code', 'anar-toolkit' ); ?></span>
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.3 5.3 1.4 1.4L6.4 11H20v2H6.4l4.3 4.3-1.4 1.4L2.6 12l6.7-6.7Z"/></svg>
					</button>
				</form>

				<form class="anar-form anar-form--code" hidden novalidate>
					<button class="anar-auth__back" type="button">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.7 5.3-1.4 1.4 4.3 4.3H4v2h13.6l-4.3 4.3 1.4 1.4 6.7-6.7-6.7-6.7Z"/></svg>
						<?php esc_html_e( 'Change details', 'anar-toolkit' ); ?>
					</button>
					<div class="anar-code-copy">
						<strong><?php esc_html_e( 'Enter the verification code', 'anar-toolkit' ); ?></strong>
						<span class="anar-code-copy__identity" dir="ltr"></span>
					</div>
					<label for="<?php echo esc_attr( $code_id ); ?>"><?php esc_html_e( 'One-time password', 'anar-toolkit' ); ?></label>
					<?php /* No maxlength: pasted text such as "Code: 12345" is reduced to its digits by the script. */ ?>
					<input id="<?php echo esc_attr( $code_id ); ?>" class="anar-code-input" name="code" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" dir="ltr" placeholder="<?php echo esc_attr( str_repeat( '•', absint( $this->settings->get( 'otp_length', 5 ) ) ) ); ?>" required>
					<label class="anar-check">
						<input type="checkbox" name="remember" checked>
						<span><?php esc_html_e( 'Keep me signed in on this device', 'anar-toolkit' ); ?></span>
					</label>
					<button class="anar-btn anar-btn--primary" type="submit">
						<span><?php esc_html_e( 'Verify and sign in', 'anar-toolkit' ); ?></span>
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.3 5.3 1.4 1.4L6.4 11H20v2H6.4l4.3 4.3-1.4 1.4L2.6 12l6.7-6.7Z"/></svg>
					</button>
					<button class="anar-resend" type="button" disabled><span><?php esc_html_e( 'Resend code', 'anar-toolkit' ); ?></span> <time class="anar-resend__timer" aria-hidden="true"></time></button>
				</form>

				<?php if ( $this->settings->get( 'google_enabled', 0 ) ) : ?>
					<div class="anar-divider"><span><?php esc_html_e( 'or', 'anar-toolkit' ); ?></span></div>
					<a class="anar-btn anar-btn--google" href="<?php echo esc_url( rest_url( 'anar-login/v1/google/start' ) ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.5-.2-2.2H12v4.3h5.4a4.6 4.6 0 0 1-2 3v2.8h3.3c1.9-1.8 2.9-4.4 2.9-7.9Z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.7-2.4l-3.3-2.8c-.9.6-2.1 1-3.4 1-2.6 0-4.9-1.8-5.7-4.2H2.9v2.8A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.3 13.6A6 6 0 0 1 6 12c0-.6.1-1.1.3-1.6V7.6H2.9A10 10 0 0 0 2 12c0 1.6.4 3 1 4.4l3.3-2.8Z"/><path fill="#EA4335" d="M12 6.2c1.5 0 2.8.5 3.9 1.5l2.9-2.9A9.8 9.8 0 0 0 12 2a10 10 0 0 0-9.1 5.6l3.4 2.8C7.1 8 9.4 6.2 12 6.2Z"/></svg>
						<span><?php esc_html_e( 'Continue with Google', 'anar-toolkit' ); ?></span>
					</a>
				<?php endif; ?>

				<?php echo \Anar\Login\Auth\PasswordRecovery::form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form escapes all dynamic values. ?>
				<div class="anar-notice<?php echo $error ? ' is-error' : ''; ?>" role="status" aria-live="polite"<?php echo $error ? '' : ' hidden'; ?>><?php echo esc_html( $error ); ?></div>

				<?php if ( $atts['password_link'] ) : ?>
					<p class="anar-password-link"><a href="<?php echo esc_url( add_query_arg( array( 'anar_password' => 1, 'redirect_to' => rawurlencode( $atts['redirect'] ) ), wp_login_url() ) ); ?>"><?php esc_html_e( 'Sign in with a password instead', 'anar-toolkit' ); ?></a></p>
				<?php endif; ?>
			</div>
			<footer class="anar-auth__footer">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2Zm-7-2a2 2 0 0 1 4 0v2h-4V6Zm7 12H7v-8h10v8Z"/></svg>
				<?php esc_html_e( 'Your one-time code expires and can only be used once.', 'anar-toolkit' ); ?>
			</footer>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Account dashboard.
	 *
	 * @return string
	 */
	public function account() {
		$this->assets();
		$direction = is_rtl() ? 'rtl' : 'ltr';

		if ( ! is_user_logged_in() ) {
			return $this->login();
		}

		$user   = wp_get_current_user();
		$phone  = get_user_meta( $user->ID, '_anar_phone', true );
		$google = get_user_meta( $user->ID, '_anar_google_sub', true );
		$avatar = UserService::avatar( $user, 192 );

		ob_start();
		?>
		<section class="anar-panel" dir="<?php echo esc_attr( $direction ); ?>" style="--anar-accent:<?php echo esc_attr( $this->settings->get( 'brand_color', '#d81b3f' ) ); ?>">
			<aside class="anar-panel__sidebar">
				<a class="anar-panel__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php echo $this->logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside logo(). ?>
					<span><strong><?php esc_html_e( 'My account', 'anar-toolkit' ); ?></strong><small><?php echo esc_html( get_bloginfo( 'name' ) ); ?></small></span>
				</a>
				<div class="anar-panel__user">
					<img src="<?php echo esc_url( $avatar ); ?>" alt="">
					<strong data-anar-display-name><?php echo esc_html( $user->display_name ); ?></strong>
					<span><?php echo esc_html( $phone ? Identity::local_phone( $phone ) : $user->user_email ); ?></span>
				</div>
				<nav class="anar-panel__nav" aria-label="<?php esc_attr_e( 'Account sections', 'anar-toolkit' ); ?>">
					<button type="button" class="is-active" aria-current="page" data-anar-tab="overview"><span aria-hidden="true">⌂</span><?php esc_html_e( 'Overview', 'anar-toolkit' ); ?></button>
					<button type="button" data-anar-tab="profile"><span aria-hidden="true">♙</span><?php esc_html_e( 'Account details', 'anar-toolkit' ); ?></button>
					<button type="button" data-anar-tab="security"><span aria-hidden="true">♢</span><?php esc_html_e( 'Sign-in & security', 'anar-toolkit' ); ?></button>
				</nav>
				<a class="anar-panel__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'anar-toolkit' ); ?> <span aria-hidden="true">←</span></a>
			</aside>

			<main class="anar-panel__main">
				<header class="anar-panel__top">
					<div>
						<span><?php esc_html_e( 'Account dashboard', 'anar-toolkit' ); ?></span>
						<h2>
							<?php
							/* translators: %s: current user's display name. */
							printf( esc_html__( 'Hello %s 👋', 'anar-toolkit' ), '<span data-anar-display-name>' . esc_html( $user->display_name ) . '</span>' );
							?>
						</h2>
					</div>
					<span class="anar-status-dot"><?php esc_html_e( 'Active account', 'anar-toolkit' ); ?></span>
				</header>

				<div class="anar-panel__view is-active" data-anar-view="overview">
					<div class="anar-welcome">
						<div>
							<span><?php esc_html_e( 'Your account is ready', 'anar-toolkit' ); ?></span>
							<h3><?php esc_html_e( 'Everything at a glance', 'anar-toolkit' ); ?></h3>
							<p><?php esc_html_e( 'Manage your profile and secure sign-in methods from this dashboard.', 'anar-toolkit' ); ?></p>
							<button class="anar-btn anar-btn--light" type="button" data-anar-goto="profile"><?php esc_html_e( 'Complete account details', 'anar-toolkit' ); ?></button>
						</div>
					</div>
					<div class="anar-stats">
						<article><span class="anar-stat-icon">✓</span><div><small><?php esc_html_e( 'Account status', 'anar-toolkit' ); ?></small><strong><?php esc_html_e( 'Verified', 'anar-toolkit' ); ?></strong></div></article>
						<article><span class="anar-stat-icon">⌁</span><div><small><?php esc_html_e( 'Sign-in methods', 'anar-toolkit' ); ?></small><strong><?php echo esc_html( ( $phone ? 1 : 0 ) + ( $user->user_email ? 1 : 0 ) + ( $google ? 1 : 0 ) ); ?></strong></div></article>
						<article><span class="anar-stat-icon">◷</span><div><small><?php esc_html_e( 'Member since', 'anar-toolkit' ); ?></small><strong><?php echo esc_html( wp_date( 'Y/m/d', strtotime( $user->user_registered ) ) ); ?></strong></div></article>
					</div>
				</div>

				<div class="anar-panel__view" data-anar-view="profile" hidden>
					<div class="anar-section-title"><div><span><?php esc_html_e( 'Profile', 'anar-toolkit' ); ?></span><h3><?php esc_html_e( 'Account information', 'anar-toolkit' ); ?></h3></div></div>
					<form class="anar-profile-form">
						<div class="anar-profile-head">
							<img src="<?php echo esc_url( $avatar ); ?>" alt="">
							<div><strong data-anar-display-name><?php echo esc_html( $user->display_name ); ?></strong><span><?php esc_html_e( 'Your profile picture', 'anar-toolkit' ); ?></span></div>
						</div>
						<div class="anar-fields">
							<label><?php esc_html_e( 'First name', 'anar-toolkit' ); ?><input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>"></label>
							<label><?php esc_html_e( 'Last name', 'anar-toolkit' ); ?><input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>"></label>
							<label class="anar-fields__wide"><?php esc_html_e( 'Display name', 'anar-toolkit' ); ?><input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
							<label><?php esc_html_e( 'Email', 'anar-toolkit' ); ?><input type="text" value="<?php echo esc_attr( $user->user_email ? $user->user_email : '—' ); ?>" readonly></label>
							<label><?php esc_html_e( 'Mobile', 'anar-toolkit' ); ?><input type="text" value="<?php echo esc_attr( $phone ? Identity::local_phone( $phone ) : '—' ); ?>" readonly dir="ltr"></label>
						</div>
						<div class="anar-profile-actions"><button class="anar-btn anar-btn--primary" type="submit"><span><?php esc_html_e( 'Save changes', 'anar-toolkit' ); ?></span></button><span class="anar-form-status" role="status" aria-live="polite"></span></div>
					</form>
				</div>

				<div class="anar-panel__view" data-anar-view="security" hidden>
					<div class="anar-section-title"><div><span><?php esc_html_e( 'Security', 'anar-toolkit' ); ?></span><h3><?php esc_html_e( 'Account sign-in methods', 'anar-toolkit' ); ?></h3></div></div>
					<div class="anar-security-list">
						<?php $this->security_method( __( 'SMS sign-in', 'anar-toolkit' ), $phone ? Identity::local_phone( $phone ) : __( 'Not connected to this account', 'anar-toolkit' ), (bool) $phone, 'SMS' ); ?>
						<?php $this->security_method( __( 'Email sign-in', 'anar-toolkit' ), $user->user_email ? $user->user_email : __( 'Not connected to this account', 'anar-toolkit' ), (bool) $user->user_email, '@' ); ?>
						<?php $this->security_method( __( 'Google account', 'anar-toolkit' ), $google ? __( 'Connected and verified', 'anar-toolkit' ) : __( 'Not connected to this account', 'anar-toolkit' ), (bool) $google, 'G' ); ?>
					</div>
					<div class="anar-security-note"><span>i</span><p><?php esc_html_e( 'One-time passwords are never stored in a recoverable form and are invalidated immediately after their first use.', 'anar-toolkit' ); ?></p></div>
				</div>
			</main>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render one security method.
	 *
	 * @param string $title   Title.
	 * @param string $detail  Detail.
	 * @param bool   $enabled Enabled.
	 * @param string $icon    Icon.
	 * @return void
	 */
	private function security_method( $title, $detail, $enabled, $icon ) {
		?>
		<article>
			<span class="anar-method-icon"><?php echo esc_html( $icon ); ?></span>
			<div><strong><?php echo esc_html( $title ); ?></strong><small dir="auto"><?php echo esc_html( $detail ); ?></small></div>
			<span class="anar-method-state <?php echo $enabled ? 'is-enabled' : ''; ?>"><?php echo esc_html( $enabled ? __( 'Active', 'anar-toolkit' ) : __( 'Inactive', 'anar-toolkit' ) ); ?></span>
		</article>
		<?php
	}

	/**
	 * Brand mark for the form: the site's logo by default, never the plugin's.
	 *
	 * Site logo falls back to the site icon, then to a neutral lock icon.
	 *
	 * @return string Escaped HTML.
	 */
	private function logo() {
		$mode = (string) $this->settings->get( 'form_logo', 'site' );
		$url  = '';

		if ( 'none' === $mode ) {
			return '';
		}
		if ( 'custom' === $mode ) {
			$url = (string) $this->settings->get( 'form_logo_url', '' );
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

	/**
	 * Label, copy and keyboard hints for the identity field in the active sign-in mode.
	 *
	 * @return array<string,string>
	 */
	private function identity_field() {
		$mode = (string) $this->settings->get( 'identity_mode', 'both' );

		if ( 'phone' === $mode ) {
			return array(
				'mode'         => 'phone',
				'label'        => __( 'Mobile number', 'anar-toolkit' ),
				'intro'        => __( 'Enter your mobile number and we will text you a one-time password.', 'anar-toolkit' ),
				'type'         => 'tel',
				'inputmode'    => 'tel',
				'autocomplete' => 'tel',
				'placeholder'  => '09121234567',
			);
		}

		if ( 'email' === $mode ) {
			return array(
				'mode'         => 'email',
				'label'        => __( 'Email', 'anar-toolkit' ),
				'intro'        => __( 'Enter your email address and we will send you a one-time password.', 'anar-toolkit' ),
				'type'         => 'email',
				'inputmode'    => 'email',
				'autocomplete' => 'email',
				'placeholder'  => 'name@example.com',
			);
		}

		return array(
			'mode'         => 'both',
			'label'        => __( 'Mobile or email', 'anar-toolkit' ),
			'intro'        => __( 'Enter your mobile number or email address and we will send you a one-time password.', 'anar-toolkit' ),
			'type'         => 'text',
			'inputmode'    => 'text',
			'autocomplete' => 'username',
			'placeholder'  => __( '09121234567 or name@example.com', 'anar-toolkit' ),
		);
	}

	/**
	 * Validation message for an empty identity field.
	 *
	 * @return string
	 */
	private function identity_required_message() {
		$mode = (string) $this->settings->get( 'identity_mode', 'both' );

		if ( 'phone' === $mode ) {
			return __( 'Enter your mobile number.', 'anar-toolkit' );
		}

		return 'email' === $mode
			? __( 'Enter your email address.', 'anar-toolkit' )
			: __( 'Enter your mobile number or email address.', 'anar-toolkit' );
	}

	/**
	 * Message for an error code sent back from the Google callback.
	 *
	 * Only known codes are mapped, so the query string cannot inject text.
	 *
	 * @return string
	 */
	private function query_error() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a whitelisted message.
		$code = isset( $_GET[ GoogleAuth::ERROR_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ GoogleAuth::ERROR_ARG ] ) ) : '';
		if ( ! $code ) {
			return '';
		}

		$messages = GoogleAuth::error_messages();

		return $messages[ $code ] ?? $messages['google_failed'];
	}

	/**
	 * Enqueue front assets once.
	 *
	 * @return void
	 */
	private function assets() {
		if ( ! wp_script_is( 'anar-login', 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_style( 'anar-login' );
		wp_enqueue_script( 'anar-login' );

		if ( ! $this->localized ) {
			wp_localize_script( 'anar-login', 'AnarLogin', $this->script_config() );
			$this->localized = true;
		}
	}

	/**
	 * Configuration for the frontend script, shared with the lazily loaded popup.
	 *
	 * @return array<string,mixed>
	 */
	public function script_config() {
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'anar-login/v1/' ) ),
			// Only signed-in requests need a nonce; a cached nonce would otherwise break logins.
			'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'otpLength' => absint( $this->settings->get( 'otp_length', 5 ) ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'errorArg'  => GoogleAuth::ERROR_ARG,
			'strings'   => array(
				'network'          => __( 'Could not connect. Please try again.', 'anar-toolkit' ),
				'processing'       => __( 'Please wait…', 'anar-toolkit' ),
				'redirecting'      => __( 'Signing you in…', 'anar-toolkit' ),
				'resend'           => __( 'Resend code', 'anar-toolkit' ),
				'identityRequired' => $this->identity_required_message(),
				'codeRequired'     => __( 'Enter the complete verification code.', 'anar-toolkit' ),
			),
		);
	}
}
