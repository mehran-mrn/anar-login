<?php
/**
 * Frontend login and account shortcodes.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Infrastructure\Settings;

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
	}

	/**
	 * Login card.
	 *
	 * @return string
	 */
	public function login() {
		$this->assets();

		if ( is_user_logged_in() ) {
			$panel_id  = absint( $this->settings->get( 'panel_page_id', 0 ) );
			$panel_url = $panel_id ? get_permalink( $panel_id ) : home_url( '/' );

			return sprintf(
				'<div class="anar-auth anar-auth--compact" dir="rtl"><img src="%1$s" alt="" class="anar-auth__logo"><div><strong>%2$s</strong><p>%3$s</p></div><a class="anar-btn anar-btn--soft" href="%4$s">%5$s</a></div>',
				esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ),
				esc_html__( 'شما وارد شده‌اید', 'anar-login' ),
				esc_html( wp_get_current_user()->display_name ),
				esc_url( $panel_url ),
				esc_html__( 'رفتن به حساب کاربری', 'anar-login' )
			);
		}

		$identity_id = wp_unique_id( 'anar-identity-' );
		$code_id     = wp_unique_id( 'anar-code-' );

		ob_start();
		?>
		<section class="anar-auth anar-login-card" dir="rtl" style="--anar-accent:<?php echo esc_attr( $this->settings->get( 'brand_color', '#d81b3f' ) ); ?>">
			<div class="anar-auth__glow" aria-hidden="true"></div>
			<header class="anar-auth__header">
				<div class="anar-auth__brand">
					<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt="" class="anar-auth__logo">
					<div>
						<span class="anar-auth__eyebrow"><?php esc_html_e( 'ورود امن و سریع', 'anar-login' ); ?></span>
						<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
					</div>
				</div>
				<span class="anar-auth__secure">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4.5 5v6.2c0 4.9 3.2 9.4 7.5 10.8 4.3-1.4 7.5-5.9 7.5-10.8V5L12 2Zm0 2.2 5.5 2.2v4.8c0 3.8-2.3 7.3-5.5 8.6-3.2-1.3-5.5-4.8-5.5-8.6V6.4L12 4.2Zm-.9 4.3v2H9.8v5h4.4v-5h-1.3v-2h-1.8Z"/></svg>
					<?php esc_html_e( 'اتصال امن', 'anar-login' ); ?>
				</span>
			</header>

			<div class="anar-auth__body">
				<div class="anar-auth__intro">
					<h2><?php esc_html_e( 'خوش آمدید', 'anar-login' ); ?></h2>
					<p><?php esc_html_e( 'شماره موبایل یا ایمیل خود را وارد کنید؛ رمز یک‌بارمصرف برایتان ارسال می‌شود.', 'anar-login' ); ?></p>
				</div>

				<form class="anar-form anar-form--identity" novalidate>
					<label for="<?php echo esc_attr( $identity_id ); ?>"><?php esc_html_e( 'موبایل یا ایمیل', 'anar-login' ); ?></label>
					<div class="anar-input-wrap">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 3v13h10V5H7Zm4 14.5h2V21h-2v-1.5Z"/></svg>
						<input id="<?php echo esc_attr( $identity_id ); ?>" name="identity" type="text" inputmode="email" autocomplete="username" dir="ltr" placeholder="0912•••••••  یا  name@example.com" required>
					</div>
					<input class="anar-hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button class="anar-btn anar-btn--primary" type="submit">
						<span><?php esc_html_e( 'دریافت کد ورود', 'anar-login' ); ?></span>
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.3 5.3 1.4 1.4L6.4 11H20v2H6.4l4.3 4.3-1.4 1.4L2.6 12l6.7-6.7Z"/></svg>
					</button>
				</form>

				<form class="anar-form anar-form--code" hidden novalidate>
					<button class="anar-auth__back" type="button">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.7 5.3-1.4 1.4 4.3 4.3H4v2h13.6l-4.3 4.3 1.4 1.4 6.7-6.7-6.7-6.7Z"/></svg>
						<?php esc_html_e( 'ویرایش مشخصات', 'anar-login' ); ?>
					</button>
					<div class="anar-code-copy">
						<strong><?php esc_html_e( 'کد تأیید را وارد کنید', 'anar-login' ); ?></strong>
						<span class="anar-code-copy__identity" dir="ltr"></span>
					</div>
					<label for="<?php echo esc_attr( $code_id ); ?>"><?php esc_html_e( 'رمز یک‌بارمصرف', 'anar-login' ); ?></label>
					<input id="<?php echo esc_attr( $code_id ); ?>" class="anar-code-input" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="<?php echo esc_attr( $this->settings->get( 'otp_length', 5 ) ); ?>" autocomplete="one-time-code" dir="ltr" required>
					<label class="anar-check">
						<input type="checkbox" name="remember" checked>
						<span><?php esc_html_e( 'در این دستگاه وارد بمان', 'anar-login' ); ?></span>
					</label>
					<button class="anar-btn anar-btn--primary" type="submit">
						<span><?php esc_html_e( 'تأیید و ورود', 'anar-login' ); ?></span>
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.3 5.3 1.4 1.4L6.4 11H20v2H6.4l4.3 4.3-1.4 1.4L2.6 12l6.7-6.7Z"/></svg>
					</button>
					<button class="anar-resend" type="button" disabled><?php esc_html_e( 'ارسال دوباره', 'anar-login' ); ?> <span></span></button>
				</form>

				<?php if ( $this->settings->get( 'google_enabled', 0 ) ) : ?>
					<div class="anar-divider"><span><?php esc_html_e( 'یا', 'anar-login' ); ?></span></div>
					<a class="anar-btn anar-btn--google" href="<?php echo esc_url( rest_url( 'anar-login/v1/google/start' ) ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.5-.2-2.2H12v4.3h5.4a4.6 4.6 0 0 1-2 3v2.8h3.3c1.9-1.8 2.9-4.4 2.9-7.9Z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.7-2.4l-3.3-2.8c-.9.6-2.1 1-3.4 1-2.6 0-4.9-1.8-5.7-4.2H2.9v2.8A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.3 13.6A6 6 0 0 1 6 12c0-.6.1-1.1.3-1.6V7.6H2.9A10 10 0 0 0 2 12c0 1.6.4 3 1 4.4l3.3-2.8Z"/><path fill="#EA4335" d="M12 6.2c1.5 0 2.8.5 3.9 1.5l2.9-2.9A9.8 9.8 0 0 0 12 2a10 10 0 0 0-9.1 5.6l3.4 2.8C7.1 8 9.4 6.2 12 6.2Z"/></svg>
						<?php esc_html_e( 'ادامه با حساب گوگل', 'anar-login' ); ?>
					</a>
				<?php endif; ?>

				<div class="anar-notice" role="status" aria-live="polite" hidden></div>
			</div>
			<footer class="anar-auth__footer">
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10h14V10a2 2 0 0 0-2-2Zm-7-2a2 2 0 0 1 4 0v2h-4V6Zm7 12H7v-8h10v8Z"/></svg>
				<?php esc_html_e( 'اطلاعات ورود شما رمزنگاری و محافظت می‌شود.', 'anar-login' ); ?>
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

		if ( ! is_user_logged_in() ) {
			return $this->login();
		}

		$user   = wp_get_current_user();
		$phone  = get_user_meta( $user->ID, '_anar_phone', true );
		$google = get_user_meta( $user->ID, '_anar_google_sub', true );
		$avatar = get_user_meta( $user->ID, '_anar_google_avatar', true );
		$avatar = $avatar ? $avatar : get_avatar_url( $user->ID, array( 'size' => 192 ) );

		ob_start();
		?>
		<section class="anar-panel" dir="rtl" style="--anar-accent:<?php echo esc_attr( $this->settings->get( 'brand_color', '#d81b3f' ) ); ?>">
			<aside class="anar-panel__sidebar">
				<a class="anar-panel__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt="">
					<span><strong><?php esc_html_e( 'حساب من', 'anar-login' ); ?></strong><small><?php echo esc_html( get_bloginfo( 'name' ) ); ?></small></span>
				</a>
				<div class="anar-panel__user">
					<img src="<?php echo esc_url( $avatar ); ?>" alt="">
					<strong><?php echo esc_html( $user->display_name ); ?></strong>
					<span><?php echo esc_html( $phone ? $phone : $user->user_email ); ?></span>
				</div>
				<nav class="anar-panel__nav" aria-label="<?php esc_attr_e( 'بخش‌های حساب', 'anar-login' ); ?>">
					<button type="button" class="is-active" data-anar-tab="overview"><span>⌂</span><?php esc_html_e( 'نمای کلی', 'anar-login' ); ?></button>
					<button type="button" data-anar-tab="profile"><span>♙</span><?php esc_html_e( 'اطلاعات حساب', 'anar-login' ); ?></button>
					<button type="button" data-anar-tab="security"><span>♢</span><?php esc_html_e( 'ورود و امنیت', 'anar-login' ); ?></button>
				</nav>
				<a class="anar-panel__logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'خروج از حساب', 'anar-login' ); ?> <span>←</span></a>
			</aside>

			<main class="anar-panel__main">
				<header class="anar-panel__top">
					<div>
						<span><?php esc_html_e( 'پنل کاربری', 'anar-login' ); ?></span>
						<h2>
							<?php
							/* translators: %s: current user's display name. */
							echo esc_html( sprintf( __( 'سلام %s 👋', 'anar-login' ), $user->display_name ) );
							?>
						</h2>
					</div>
					<span class="anar-status-dot"><?php esc_html_e( 'حساب فعال', 'anar-login' ); ?></span>
				</header>

				<div class="anar-panel__view is-active" data-anar-view="overview">
					<div class="anar-welcome">
						<div>
							<span><?php esc_html_e( 'حساب شما آماده است', 'anar-login' ); ?></span>
							<h3><?php esc_html_e( 'همه‌چیز در یک نگاه', 'anar-login' ); ?></h3>
							<p><?php esc_html_e( 'مشخصات و روش‌های ورود امن خود را از این پنل مدیریت کنید.', 'anar-login' ); ?></p>
							<button class="anar-btn anar-btn--light" type="button" data-anar-goto="profile"><?php esc_html_e( 'تکمیل اطلاعات حساب', 'anar-login' ); ?></button>
						</div>
						<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt="">
					</div>
					<div class="anar-stats">
						<article><span class="anar-stat-icon">✓</span><div><small><?php esc_html_e( 'وضعیت حساب', 'anar-login' ); ?></small><strong><?php esc_html_e( 'تأییدشده', 'anar-login' ); ?></strong></div></article>
						<article><span class="anar-stat-icon">⌁</span><div><small><?php esc_html_e( 'روش‌های ورود', 'anar-login' ); ?></small><strong><?php echo esc_html( ( $phone ? 1 : 0 ) + ( $user->user_email ? 1 : 0 ) + ( $google ? 1 : 0 ) ); ?></strong></div></article>
						<article><span class="anar-stat-icon">◷</span><div><small><?php esc_html_e( 'عضویت از', 'anar-login' ); ?></small><strong><?php echo esc_html( wp_date( 'Y/m/d', strtotime( $user->user_registered ) ) ); ?></strong></div></article>
					</div>
				</div>

				<div class="anar-panel__view" data-anar-view="profile" hidden>
					<div class="anar-section-title"><div><span><?php esc_html_e( 'پروفایل', 'anar-login' ); ?></span><h3><?php esc_html_e( 'اطلاعات حساب کاربری', 'anar-login' ); ?></h3></div></div>
					<form class="anar-profile-form">
						<div class="anar-profile-head">
							<img src="<?php echo esc_url( $avatar ); ?>" alt="">
							<div><strong><?php echo esc_html( $user->display_name ); ?></strong><span><?php esc_html_e( 'تصویر حساب شما', 'anar-login' ); ?></span></div>
						</div>
						<div class="anar-fields">
							<label><?php esc_html_e( 'نام', 'anar-login' ); ?><input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>"></label>
							<label><?php esc_html_e( 'نام خانوادگی', 'anar-login' ); ?><input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>"></label>
							<label class="anar-fields__wide"><?php esc_html_e( 'نام نمایشی', 'anar-login' ); ?><input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" required></label>
							<label><?php esc_html_e( 'ایمیل', 'anar-login' ); ?><input type="text" value="<?php echo esc_attr( $user->user_email ? $user->user_email : '—' ); ?>" readonly></label>
							<label><?php esc_html_e( 'موبایل', 'anar-login' ); ?><input type="text" value="<?php echo esc_attr( $phone ? $phone : '—' ); ?>" readonly dir="ltr"></label>
						</div>
						<div class="anar-profile-actions"><button class="anar-btn anar-btn--primary" type="submit"><?php esc_html_e( 'ذخیره تغییرات', 'anar-login' ); ?></button><span class="anar-form-status" role="status"></span></div>
					</form>
				</div>

				<div class="anar-panel__view" data-anar-view="security" hidden>
					<div class="anar-section-title"><div><span><?php esc_html_e( 'امنیت', 'anar-login' ); ?></span><h3><?php esc_html_e( 'روش‌های ورود به حساب', 'anar-login' ); ?></h3></div></div>
					<div class="anar-security-list">
						<?php $this->security_method( __( 'ورود پیامکی', 'anar-login' ), $phone ? $phone : __( 'به این حساب متصل نیست', 'anar-login' ), (bool) $phone, 'SMS' ); ?>
						<?php $this->security_method( __( 'ورود با ایمیل', 'anar-login' ), $user->user_email ? $user->user_email : __( 'به این حساب متصل نیست', 'anar-login' ), (bool) $user->user_email, '@' ); ?>
						<?php $this->security_method( __( 'حساب گوگل', 'anar-login' ), $google ? __( 'متصل و تأییدشده', 'anar-login' ) : __( 'به این حساب متصل نیست', 'anar-login' ), (bool) $google, 'G' ); ?>
					</div>
					<div class="anar-security-note"><span>i</span><p><?php esc_html_e( 'رمزهای یک‌بارمصرف به‌صورت قابل‌بازیابی ذخیره نمی‌شوند و پس از اولین استفاده فوراً باطل خواهند شد.', 'anar-login' ); ?></p></div>
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
			<span class="anar-method-state <?php echo $enabled ? 'is-enabled' : ''; ?>"><?php echo esc_html( $enabled ? __( 'فعال', 'anar-login' ) : __( 'غیرفعال', 'anar-login' ) ); ?></span>
		</article>
		<?php
	}

	/**
	 * Enqueue front assets once.
	 *
	 * @return void
	 */
	private function assets() {
		wp_enqueue_style( 'anar-login', ANAR_LOGIN_URL . 'assets/css/frontend.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-login', ANAR_LOGIN_URL . 'assets/js/frontend.js', array(), ANAR_LOGIN_VERSION, true );

		if ( ! $this->localized ) {
			wp_localize_script(
				'anar-login',
				'AnarLogin',
				array(
					'restUrl' => esc_url_raw( rest_url( 'anar-login/v1/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'strings' => array(
						'network'    => __( 'ارتباط برقرار نشد؛ دوباره تلاش کنید.', 'anar-login' ),
						'processing' => __( 'چند لحظه…', 'anar-login' ),
						'resend'     => __( 'ارسال دوباره', 'anar-login' ),
					),
				)
			);
			$this->localized = true;
		}
	}
}
