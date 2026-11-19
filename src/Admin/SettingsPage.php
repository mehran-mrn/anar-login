<?php
/**
 * Modern RTL settings page.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Integrations\WooCommerce;
use Anar\Login\Messaging\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {
	/** @var Settings */
	private $settings;

	/** @var ProviderRegistry */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Settings.
	 * @param ProviderRegistry $providers Providers.
	 */
	public function __construct( Settings $settings, ProviderRegistry $providers ) {
		$this->settings  = $settings;
		$this->providers = $providers;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		ScopedSettings::register();
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_anar_login_preview', array( LoginSetup::class, 'preview' ) );
	}

	/**
	 * Add top-level menu.
	 *
	 * @return void
	 */
	public static function landing() {
		( new self( new Settings(), ProviderRegistry::shared() ) )->render();
	}

	public function menu() {
		add_menu_page(
			__( 'Anar Toolkit', 'anar-toolkit' ),
			__( 'Anar Toolkit', 'anar-toolkit' ),
			'manage_options',
			'anar-login',
			array( self::class, 'landing' ),
			ANAR_LOGIN_URL . 'assets/images/anar-menu-icon.png?ver=' . ANAR_LOGIN_VERSION,
			58
		);
	}

	/**
	 * Register one sanitized option.
	 *
	 * @return void
	 */
	public function setting() {
		register_setting(
			'anar_login',
			Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->settings->defaults(),
			)
		);
	}

	/**
	 * Sanitize with the registered providers, so custom provider fields are stored too.
	 *
	 * @param mixed $input Raw settings.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$current = $this->settings->all();
		$input = is_array( $input ) ? $input : array();
		foreach ( array_keys( $current ) as $key ) {
			if ( ( isset( ScopedSettings::otp_fields()[ $key ] ) || 0 === strpos( $key, 'provider_' ) || 'form_logo_url' === $key ) && ! array_key_exists( $key, $input ) ) {
				$input[ $key ] = $current[ $key ];
			}
		}
		$clean = $this->settings->sanitize( $input, $this->providers->all() );
		$previous = $current['two_factor'];
		if ( 'off' !== $clean['two_factor'] && $previous !== $clean['two_factor'] && ! \Anar\Login\Auth\RecoveryCodes::prepared( get_current_user_id() ) ) {
			$clean['two_factor'] = $previous;
			add_settings_error( Settings::OPTION_KEY, 'anar_recovery_required', __( 'Before enabling two-step verification, open account security and confirm your saved recovery codes.', 'anar-toolkit' ) );
		}
		return $clean;
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Current page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'anar-login' ) ) {
			return;
		}

		if ( \Anar\Login\Infrastructure\Features::enabled( 'login' ) ) {
			wp_enqueue_media(); }
		wp_enqueue_style( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/css/admin.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_style( 'anar-meli-signup', ANAR_LOGIN_URL . 'assets/css/meli-signup.css', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/js/admin.js', array(), ANAR_LOGIN_VERSION, true );
		wp_enqueue_script( 'anar-settings-save', ANAR_LOGIN_URL . 'assets/js/settings-save.js', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION, true );
		wp_enqueue_script( 'anar-meli-signup', ANAR_LOGIN_URL . 'assets/js/meli-signup.js', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION, true );
		wp_localize_script(
			'anar-login-admin',
			'AnarLoginAdmin',
			array(
				'connectionCompleteDraft' => __( 'Connection complete — save to apply', 'anar-toolkit' ),
				'connectionIncomplete' => __( 'Connection incomplete', 'anar-toolkit' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'saving' => __( 'Saving…', 'anar-toolkit' ),
				'checking' => __( 'Checking connection…', 'anar-toolkit' ),
				'invalid' => __( 'Check the highlighted field before saving.', 'anar-toolkit' ),
				'copied'  => __( 'Copied ✓', 'anar-toolkit' ),
				'failed'  => __( 'Copy failed', 'anar-toolkit' ),
				'restUrl' => esc_url_raw( rest_url( 'anar-login/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'sending' => __( 'Sending…', 'anar-toolkit' ),
				'network' => __( 'Could not connect. Please try again.', 'anar-toolkit' ),
				'meli'    => array(
					'loading' => __( 'Checking available registration plans…', 'anar-toolkit' ),
					'posting' => __( 'Creating your invoice…', 'anar-toolkit' ),
					'failed' => __( 'Registration could not be confirmed. Check with Meli Payamak before submitting again.', 'anar-toolkit' ),
					'irr' => __( 'rial', 'anar-toolkit' ),
					'irt' => __( 'toman', 'anar-toolkit' ),
					/* translators: %s: Formatted rate with currency. */
					'smsRate' => __( '%s per SMS', 'anar-toolkit' ),
				),
			)
		);
	}

	/**
	 * Render settings application.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$value     = $this->settings->all();
		$providers = $this->providers->all();
		if ( isset( $providers['melipayamak'] ) ) {
			$providers = array( 'melipayamak' => $providers['melipayamak'] ) + $providers;
		}
		$direction = is_rtl() ? 'rtl' : 'ltr';
		?>
		<div class="wrap anar-admin" dir="<?php echo esc_attr( $direction ); ?>">
			<?php Workspace::header( __( 'Settings', 'anar-toolkit' ), true ); ?>
			<?php // WordPress moves admin notices after this marker; without it they land inside the flex header next to the h1. ?>
			<hr class="wp-header-end">

			<?php settings_errors(); ?>
			<?php MessageCenter::notice(); ?>
			<?php StatusPanel::credit(); ?>

			<div class="anar-admin__layout">
				<aside class="anar-admin__nav"><?php $this->navigation(); ?></aside>
<div class="anar-admin__content">
<form class="anar-connection-form" method="post" action="options.php">
					<?php settings_fields( 'anar_login' ); ?>
					<?php if ( \Anar\Login\Infrastructure\Features::enabled( 'login' ) ) : ?>
						<section class="anar-tab" data-panel="setup" hidden><?php LoginSetup::render( $value ); ?></section>
					<?php endif; ?>

					<section class="anar-tab is-active" data-panel="general">
						<?php $this->section_header( __( 'Sign-in settings', 'anar-toolkit' ), __( 'Configure sign-in, registration, and the appearance of the form.', 'anar-toolkit' ), '⚙' ); ?>
						<?php MobileDelivery::render( $value ); ?>
<section class="anar-card anar-otp-settings" aria-labelledby="anar-otp-title">
	<h3 id="anar-otp-title"><?php esc_html_e( 'One-time code generation', 'anar-toolkit' ); ?></h3>
	<div class="anar-otp-grid"><?php $this->number_field( 'otp_length', __( 'Code length', 'anar-toolkit' ), $value, 4, 8, __( 'digits', 'anar-toolkit' ), 'anar-otp-form' ); ?><?php $this->number_field( 'otp_ttl', __( 'Code lifetime', 'anar-toolkit' ), $value, 60, 600, __( 'seconds', 'anar-toolkit' ), 'anar-otp-form' ); ?><?php $this->number_field( 'resend_delay', __( 'Resend interval', 'anar-toolkit' ), $value, 30, 300, __( 'seconds', 'anar-toolkit' ), 'anar-otp-form' ); ?><?php $this->number_field( 'max_requests_hour', __( 'Hourly send limit', 'anar-toolkit' ), $value, 2, 30, __( 'requests', 'anar-toolkit' ), 'anar-otp-form' ); ?><?php $this->number_field( 'max_verify_attempts', __( 'Attempts per code', 'anar-toolkit' ), $value, 3, 10, __( 'attempts', 'anar-toolkit' ), 'anar-otp-form' ); ?></div>
	<div class="anar-scoped-actions"><button type="submit" form="anar-otp-form" class="button button-primary"><?php esc_html_e( 'Save code settings', 'anar-toolkit' ); ?></button><span role="status" aria-live="polite" data-scoped-feedback="otp" hidden></span></div>
</section>
<section class="anar-card" data-verification-messages data-saved-provider="<?php echo esc_attr( $value['sms_provider'] ); ?>" aria-labelledby="anar-login-messages-title">
	<h3 id="anar-login-messages-title"><?php esc_html_e( 'Sign-in and recovery messages', 'anar-toolkit' ); ?></h3>
		<?php
		foreach ( array( 'login_otp', 'two_factor', 'password_reset', 'game_phone', 'stock_verification' ) as $message_id ) {
			MessageCenter::summary( $message_id, 'login' ); }
		?>
	<div class="anar-card anar-tip">
							<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
							<div>
								<span class="anar-field-title"><strong><?php esc_html_e( 'Automatic code filling on Android', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Chrome on Android can fill the code with one tap when the last line of your provider template is exactly:', 'anar-toolkit' ) ); ?></span>
								<code dir="ltr">@<?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?> #<var>code</var></code>
								<?php Help::button( __( 'Put your template’s code variable after the # sign. iPhones suggest the code above the keyboard without any change.', 'anar-toolkit' ) ); ?>
							</div>
						</div>

		<?php ProviderConnections::email_message( $value ); ?>
</section><div class="anar-card">
							<?php $this->toggle( 'allow_registration', __( 'Allow new account registration', 'anar-toolkit' ), __( 'Let new users create an account after verification.', 'anar-toolkit' ), $value ); ?>
							<?php $this->mrn_forms( $value ); ?>
							<div class="anar-field-row">
								<label for="anar-profile-step"><span class="anar-field-title"><strong><?php esc_html_e( 'Name after sign-up', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Ask new customers for their first and last name right after the code is verified, never before. Existing customers are not asked.', 'anar-toolkit' ) ); ?></span></label>
								<select id="anar-profile-step" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[profile_step]">
									<option value="off" <?php selected( $value['profile_step'], 'off' ); ?>><?php esc_html_e( 'Do not ask', 'anar-toolkit' ); ?></option>
									<option value="optional" <?php selected( $value['profile_step'], 'optional' ); ?>><?php esc_html_e( 'Ask, with a skip option', 'anar-toolkit' ); ?></option>
									<option value="required" <?php selected( $value['profile_step'], 'required' ); ?>><?php esc_html_e( 'Ask, without skipping', 'anar-toolkit' ); ?></option>
								</select>
							</div>
							<div class="anar-field-row">
								<label for="anar-role"><span class="anar-field-title"><strong><?php esc_html_e( 'New user role', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Only Subscriber and Customer roles with basic read access are allowed. Elevated or custom roles cannot be used for public registration.', 'anar-toolkit' ) ); ?></span></label>
								<select id="anar-role" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[default_role]">
									<?php foreach ( get_editable_roles() as $role => $details ) : ?>
										<?php if ( Settings::is_assignable_role( $role ) ) : ?>
											<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $value['default_role'], $role ); ?>><?php echo esc_html( translate_user_role( $details['name'] ) ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="anar-field-row">
								<label for="anar-panel-page"><span class="anar-field-title"><strong><?php esc_html_e( 'Account page', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'The page that contains the [anar_account] shortcode.', 'anar-toolkit' ) ); ?></span></label>
								<?php
								$pages_dropdown = wp_dropdown_pages(
									array(
										'name'              => 'anar_login_settings[panel_page_id]',
										'id'                => 'anar-panel-page',
										'selected'          => absint( $value['panel_page_id'] ),
										'show_option_none'  => esc_html__( 'Not selected', 'anar-toolkit' ),
										'option_none_value' => 0,
										'echo'              => 0,
									)
								);
								echo wp_kses(
									$pages_dropdown,
									array(
										'select' => array(
											'name' => true,
											'id'   => true,
										),
										'option' => array(
											'value'    => true,
											'selected' => true,
											'class'    => true,
										),
									)
								);
								?>
							</div>
							<div class="anar-field-row">
								<label for="anar-redirect"><span class="anar-field-title"><strong><?php esc_html_e( 'Redirect URL after sign-in', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Leave empty to use the account page or home page.', 'anar-toolkit' ) ); ?></span></label>
								<input id="anar-redirect" type="url" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[login_redirect]" value="<?php echo esc_attr( $value['login_redirect'] ); ?>" dir="ltr" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
							</div>
							<div class="anar-field-row">
								<?php
								$anar_role_lines = array();
								foreach ( (array) $value['role_redirects'] as $anar_role => $anar_url ) {
									$anar_role_lines[] = $anar_role . ' = ' . $anar_url;
								}
								?>
								<label for="anar-role-redirects"><span class="anar-field-title"><strong><?php esc_html_e( 'Redirect by role', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Optional. One per line as role = URL, for example shop_manager = /wp-admin/. A requested return page, such as checkout, still comes first.', 'anar-toolkit' ) ); ?></span></label>
								<textarea id="anar-role-redirects" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[role_redirects]" rows="3" dir="ltr" placeholder="shop_manager = <?php echo esc_attr( admin_url() ); ?>"><?php echo esc_textarea( implode( "\n", $anar_role_lines ) ); ?></textarea>
							</div>
							<div class="anar-field-row">
								<label for="anar-color"><span class="anar-field-title"><strong><?php esc_html_e( 'Brand color', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'The primary color of the sign-in form and account dashboard.', 'anar-toolkit' ) ); ?></span></label>
								<div class="anar-color"><input id="anar-color" type="color" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[brand_color]" value="<?php echo esc_attr( $value['brand_color'] ); ?>"><code><?php echo esc_html( $value['brand_color'] ); ?></code></div>
							</div>
							<div class="anar-field-row">
								<label for="anar-form-preset"><strong><?php esc_html_e( 'Sign-in form style', 'anar-toolkit' ); ?></strong></label>
								<select id="anar-form-preset" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[form_preset]">
									<?php foreach ( array( 'card' => __( 'Card', 'anar-toolkit' ), 'minimal' => __( 'Simple', 'anar-toolkit' ), 'bold' => __( 'Accent', 'anar-toolkit' ) ) as $preset => $label ) : ?>
									<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( $value['form_preset'], $preset ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="anar-field-row">
								<label for="anar-form-logo"><span class="anar-field-title"><strong><?php esc_html_e( 'Form logo', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Shown at the top of the sign-in form. The site logo falls back to the site icon, then to a lock icon.', 'anar-toolkit' ) ); ?></span></label>
								<div class="anar-logo-picker">
									<select id="anar-form-logo" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[form_logo]">
										<option value="site" <?php selected( $value['form_logo'], 'site' ); ?>><?php esc_html_e( 'Site logo', 'anar-toolkit' ); ?></option>
										<option value="custom" <?php selected( $value['form_logo'], 'custom' ); ?>><?php esc_html_e( 'Custom image', 'anar-toolkit' ); ?></option>
										<option value="icon" <?php selected( $value['form_logo'], 'icon' ); ?>><?php esc_html_e( 'Sign-in icon only', 'anar-toolkit' ); ?></option>
										<option value="none" <?php selected( $value['form_logo'], 'none' ); ?>><?php esc_html_e( 'No logo', 'anar-toolkit' ); ?></option>
									</select>
									<span class="anar-logo-picker__custom" <?php echo 'custom' === $value['form_logo'] ? '' : 'hidden'; ?>>
										<input type="url" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[form_logo_url]" value="<?php echo esc_attr( $value['form_logo_url'] ); ?>" dir="ltr" placeholder="https://">
										<button type="button" class="button" data-anar-media><?php esc_html_e( 'Choose image', 'anar-toolkit' ); ?></button>
									</span>
								</div>
							</div>
							<?php $this->toggle( 'replace_theme_login', __( 'Replace the theme’s login', 'anar-toolkit' ), __( 'For signed-out visitors, every WooCommerce login form and the theme’s login links (header account, side panels, WoodMart dropdowns) use the passwordless form.', 'anar-toolkit' ), $value ); ?>
							<?php $this->toggle( 'replace_wp_login', __( 'Also replace wp-login.php', 'anar-toolkit' ), __( 'Off by default. When on, the WordPress login page shows the passwordless form with a “Sign in with a password” link, so staff can always use their password.', 'anar-toolkit' ), $value ); ?>
							<?php $this->toggle( 'popup_enabled', __( 'Login popup', 'anar-toolkit' ), __( 'Open the sign-in form in a popup from any link to #anar-login, such as a menu item. The form loads only when it is first opened, so page speed is unaffected.', 'anar-toolkit' ), $value ); ?>
							<?php $this->toggle( 'popup_password_link', __( 'Password sign-in in the popup', 'anar-toolkit' ), __( 'Show a link to sign in with a password. Password recovery also appears here when SMS password recovery is enabled.', 'anar-toolkit' ), $value ); ?>
						</div>
					</section>

					<section class="anar-tab" data-panel="sms" hidden>
						<?php ProviderConnections::render( $value, $providers ); ?>
					</section>

					<section class="anar-tab" data-panel="google" hidden>
						<?php $this->section_header( __( 'Google sign-in', 'anar-toolkit' ), __( 'Enable standard Google OAuth without a heavy client library.', 'anar-toolkit' ), 'G' ); ?>
						<div class="anar-card">
							<?php $this->toggle( 'google_enabled', __( 'Enable Google sign-in', 'anar-toolkit' ), __( 'Show the Google sign-in button on the login form.', 'anar-toolkit' ), $value ); ?>
							<div class="anar-field-row">
								<label for="anar-google-id"><span class="anar-field-title"><strong>Client ID</strong><?php Help::button( __( 'The OAuth 2.0 client ID from Google Cloud Console.', 'anar-toolkit' ) ); ?></span></label>
								<input id="anar-google-id" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[google_client_id]" value="<?php echo esc_attr( $value['google_client_id'] ); ?>" dir="ltr">
							</div>
							<div class="anar-field-row">
								<label for="anar-google-secret"><span class="anar-field-title"><strong>Client Secret</strong><?php Help::button( __( 'Leave this field empty to keep the saved value.', 'anar-toolkit' ) ); ?></span></label>
								<input id="anar-google-secret" type="password" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[google_secret]" value="" dir="ltr" placeholder="<?php echo ! empty( $value['google_secret'] ) ? esc_attr__( 'Saved', 'anar-toolkit' ) : ''; ?>">
							</div>
							<div class="anar-callback">
								<div><span class="anar-field-title"><strong>Authorized redirect URI</strong><?php Help::button( __( 'Add this exact URL to your Google OAuth settings.', 'anar-toolkit' ) ); ?></span></div>
								<code dir="ltr"><?php echo esc_html( rest_url( 'anar-login/v1/google/callback' ) ); ?></code>
								<button type="button" class="button" data-copy="<?php echo esc_attr( rest_url( 'anar-login/v1/google/callback' ) ); ?>"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="security" hidden>
						<div class="anar-recovery-callout"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><div><h2><?php esc_html_e( 'Keep access when SMS is unavailable', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Set up an offline authenticator and save recovery codes before requiring two-step verification. Recovery codes also work with SMS verification.', 'anar-toolkit' ); ?></p><a class="button" href="<?php echo esc_url( add_query_arg( 'action', 'anar_security', wp_login_url() ) ); ?>"><?php esc_html_e( 'Set up my recovery access', 'anar-toolkit' ); ?></a></div></div>
						<?php $this->section_header( __( 'Security and rate limits', 'anar-toolkit' ), __( 'Configure code lifetime and request limits for your site.', 'anar-toolkit' ), '⌾' ); ?>
						<div class="anar-card">
							<?php if ( ! is_multisite() ) : ?>
								<?php $this->toggle( 'passkeys_enabled', __( 'Passkey sign-in', 'anar-toolkit' ), __( 'Let customers register a passkey from account security. Passkeys require HTTPS and PHP 8.0 or later; the current sign-in methods remain available.', 'anar-toolkit' ), $value ); ?>
							<?php endif; ?>
							<?php $this->toggle( 'password_reset_enabled', __( 'SMS password recovery', 'anar-toolkit' ), __( 'Allow linked mobile numbers to reset an existing account password using a separate single-use SMS code.', 'anar-toolkit' ), $value ); ?>
							<div class="anar-field-row">
								<label for="anar-two-factor"><span class="anar-field-title"><strong><?php esc_html_e( 'Two-step verification', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'After a correct password, a code is sent to the account’s mobile, or its email when no mobile is linked. Code and Google sign-ins skip this step, as they already prove ownership.', 'anar-toolkit' ) ); ?></span></label>
								<select id="anar-two-factor" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[two_factor]">
									<option value="off" <?php selected( $value['two_factor'], 'off' ); ?>><?php esc_html_e( 'Off', 'anar-toolkit' ); ?></option>
									<option value="privileged" <?php selected( $value['two_factor'], 'privileged' ); ?>><?php esc_html_e( 'Administrators, editors and shop managers', 'anar-toolkit' ); ?></option>
									<option value="all" <?php selected( $value['two_factor'], 'all' ); ?>><?php esc_html_e( 'All users', 'anar-toolkit' ); ?></option>
								</select>
							</div>
						</div>
						<div class="anar-card">
							<div class="anar-fields-grid anar-fields-grid--security">
							</div>
							<div class="anar-field-row">
								<label for="anar-bot-challenge"><span class="anar-field-title"><strong><?php esc_html_e( 'Invisible bot check', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Before a code is sent, the browser solves a short puzzle in the background, with no image or click. Adaptive mode asks only when one address requests repeatedly or the whole site sees an unusual volume, so SMS floods stop costing you money.', 'anar-toolkit' ) ); ?></span></label>
								<select id="anar-bot-challenge" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[bot_challenge]">
									<option value="adaptive" <?php selected( $value['bot_challenge'], 'adaptive' ); ?>><?php esc_html_e( 'Adaptive (recommended)', 'anar-toolkit' ); ?></option>
									<option value="always" <?php selected( $value['bot_challenge'], 'always' ); ?>><?php esc_html_e( 'Every code request', 'anar-toolkit' ); ?></option>
									<option value="off" <?php selected( $value['bot_challenge'], 'off' ); ?>><?php esc_html_e( 'Off', 'anar-toolkit' ); ?></option>
								</select>
							</div>
							<div class="anar-fields-grid anar-fields-grid--security">
								<?php $this->number_field( 'challenge_threshold', __( 'Site-wide alert level', 'anar-toolkit' ), $value, 20, 100000, __( 'SMS codes per hour', 'anar-toolkit' ) ); ?>
							</div>
							<div class="anar-field-row">
								<?php $detected = RateLimiter::detected_proxy_headers(); ?>
								<label for="anar-client-ip"><strong><?php esc_html_e( 'Visitor address behind a CDN', 'anar-toolkit' ); ?></strong><small>
									<?php esc_html_e( 'With ArvanCloud, Cloudflare or another proxy, every visitor arrives from the proxy address and would share one limit. Choose the header your CDN fills with the real visitor address. Only choose a header when all traffic passes through that proxy.', 'anar-toolkit' ); ?>
									<?php if ( $detected && ! $value['client_ip_header'] ) : ?>
										<br><b><?php /* translators: %s: HTTP header names. */ echo esc_html( sprintf( __( 'Detected on this request: %s', 'anar-toolkit' ), implode( ', ', $detected ) ) ); ?></b>
									<?php endif; ?>
								</small></label>
								<select id="anar-client-ip" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[client_ip_header]">
									<?php foreach ( RateLimiter::proxy_headers() as $header => $label ) : ?>
										<option value="<?php echo esc_attr( $header ); ?>" <?php selected( $value['client_ip_header'], $header ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<?php $this->toggle( 'debug_log', __( 'Technical error log', 'anar-toolkit' ), __( 'Log only error codes and channel names; OTPs and confidential data are never logged.', 'anar-toolkit' ), $value ); ?>
							<?php $this->toggle( 'delete_data', __( 'Clean up when uninstalling', 'anar-toolkit' ), __( 'A full uninstall removes the code table and plugin metadata. Deactivation keeps the data.', 'anar-toolkit' ), $value ); ?>
						</div>
						<div class="anar-security-summary">
							<article><span>✓</span><div><span class="anar-field-title"><strong><?php esc_html_e( 'Hashed code storage', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Raw OTPs are never stored in the database.', 'anar-toolkit' ) ); ?></span></div></article>
							<article><span>✓</span><div><span class="anar-field-title"><strong><?php esc_html_e( 'Single use', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'A code is invalidated atomically after its first successful verification.', 'anar-toolkit' ) ); ?></span></div></article>
							<article><span>✓</span><div><span class="anar-field-title"><strong><?php esc_html_e( 'Enumeration protection', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Request responses do not reveal whether an account exists.', 'anar-toolkit' ) ); ?></span></div></article>
						</div>
					</section>

					<?php if ( WooCommerce::active() ) : ?>
						<section class="anar-tab" data-panel="woocommerce" hidden>
							<?php $this->section_header( __( 'WooCommerce', 'anar-toolkit' ), __( 'Passwordless sign-in on My Account and checkout.', 'anar-toolkit' ), '🛒' ); ?>
							<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[wc_present]" value="1">
							<div class="anar-card">
								<div class="anar-field-row">
									<label for="anar-wc-account"><span class="anar-field-title"><strong><?php esc_html_e( 'My Account sign-in', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'One form signs customers in and registers new ones. A “sign in with a password” link stays available.', 'anar-toolkit' ) ); ?></span></label>
									<select id="anar-wc-account" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[wc_account_mode]">
										<option value="replace" <?php selected( $value['wc_account_mode'], 'replace' ); ?>><?php esc_html_e( 'Replace the WooCommerce forms', 'anar-toolkit' ); ?></option>
										<option value="above" <?php selected( $value['wc_account_mode'], 'above' ); ?>><?php esc_html_e( 'Show above the WooCommerce forms', 'anar-toolkit' ); ?></option>
										<option value="off" <?php selected( $value['wc_account_mode'], 'off' ); ?>><?php esc_html_e( 'Off', 'anar-toolkit' ); ?></option>
									</select>
								</div>
								<?php $this->toggle( 'wc_checkout_login', __( 'Sign-in at checkout', 'anar-toolkit' ), __( 'Replace “Returning customer? Click here to login” with the code form; customers return straight to checkout. Follows the WooCommerce login reminder setting.', 'anar-toolkit' ), $value ); ?>
								<?php $this->toggle( 'wc_sync_phone', __( 'Fill the billing phone', 'anar-toolkit' ), __( 'Copy the verified mobile number into an empty billing phone, so checkout is pre-filled.', 'anar-toolkit' ), $value ); ?>
								<?php $this->toggle( 'wc_customer_role', __( 'Register as customers', 'anar-toolkit' ), __( 'Give new users the WooCommerce customer role instead of the general new-user role.', 'anar-toolkit' ), $value ); ?>
							</div>
						</section>
					<?php endif; ?>

					<section class="anar-tab" data-panel="install" hidden>
						<?php $this->section_header( __( 'Use on your site', 'anar-toolkit' ), __( 'Lightweight shortcodes compatible with the Classic and Block editors.', 'anar-toolkit' ), '‹/›' ); ?>
						<div class="anar-shortcodes">
							<article>
								<span class="dashicons dashicons-unlock"></span>
								<div><strong><?php esc_html_e( 'Login form', 'anar-toolkit' ); ?><?php Help::button( __( 'For a login page or theme modal.', 'anar-toolkit' ) ); ?></strong><code dir="ltr">[anar_login]</code></div>
								<button type="button" class="button" data-copy="[anar_login]"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</article>
							<article>
								<span class="dashicons dashicons-dashboard"></span>
								<div><strong><?php esc_html_e( 'Account dashboard', 'anar-toolkit' ); ?><?php Help::button( __( 'Shows the login form to signed-out visitors.', 'anar-toolkit' ) ); ?></strong><code dir="ltr">[anar_account]</code></div>
								<button type="button" class="button" data-copy="[anar_account]"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</article>
							<article>
								<span class="dashicons dashicons-external"></span>
								<div><strong><?php esc_html_e( 'Popup button', 'anar-toolkit' ); ?><?php Help::button( __( 'Opens the login popup; signed-in users go to their account. For menus, add a custom link to #anar-login.', 'anar-toolkit' ) ); ?></strong><code dir="ltr">[anar_login_button]</code></div>
								<button type="button" class="button" data-copy="[anar_login_button]"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</article>
						</div>
						<div class="anar-hooks">
							<h3><?php esc_html_e( 'Key extension points', 'anar-toolkit' ); ?></h3>
							<code>anar_login_sms_providers</code>
							<code>anar_login_allow_otp_request</code>
							<code>anar_login_otp_message</code>
							<code>anar_login_redirect_url</code>
							<code>anar_login_user_registered</code>
							<code>anar_login_authenticated</code>
						</div>
					</section>

					<footer class="anar-admin__save">
						<div><span class="dashicons dashicons-saved"></span><p><span class="anar-field-title"><strong><?php esc_html_e( 'Your settings are stored securely', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Confidential values are not displayed again in the admin interface.', 'anar-toolkit' ) ); ?></span></p></div>
						<?php submit_button( __( 'Save settings', 'anar-toolkit' ), 'primary', 'submit', false ); ?><span role="status" data-settings-validation hidden></span>
					</footer>
				</form><?php ScopedSettings::forms(); ?>
				<section class="anar-tab" data-panel="features" hidden><?php FeaturesPage::render( true ); ?></section>
				<section class="anar-tab" data-panel="messages" hidden>
					<?php $this->section_header( __( 'Messages and templates', 'anar-toolkit' ), __( 'Find a message and edit it here. The same editor is available in each feature.', 'anar-toolkit' ), '✉' ); ?>
					<?php ( new MessageCenter() )->catalog(); ?>
				</section>
				<section class="anar-tab" data-panel="sending" hidden>
					<?php $this->section_header( __( 'Campaign sending rules', 'anar-toolkit' ), __( 'Set shared limits and customer filters for marketing campaigns. Verification codes and order notifications keep their own rules.', 'anar-toolkit' ), '⚙' ); ?>
					<?php ( new MessageCenter() )->shared(); ?>
				</section>
				</div>
			<?php LoginSetup::dialog(); ?>
				<?php
				if ( isset( $providers['melipayamak'] ) ) {
					MeliOffer::dialog(); }
				?>
			</div>
			<footer class="anar-admin__credits"><a class="anar-admin__author" href="https://mehranmarandi.ir/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Designed by Mehran Marandi', 'anar-toolkit' ); ?></a><span aria-hidden="true"> · </span><a class="anar-admin__author" href="https://mrnwp.ir/anar/" target="_blank" rel="noopener noreferrer">MRNWP</a></footer>
		</div>
		<?php
	}

	/**
	 * Order connection tabs by the enabled capabilities.
	 *
	 * @return void
	 */
	private function navigation() {
		Workspace::navigation( true ); }

	private function mrn_forms( array $value ) {
		$connected = class_exists( \MRN\Form\Anar_Integration::class );
		$forms     = \Anar\Login\Integrations\MrnForm::registration_forms();
		$selected  = absint( $value['mrn_registration_form'] );
		$available = in_array( $selected, array_column( $forms, 'id' ), true );
		?>
		<details id="anar-mrn-forms" class="anar-mrn-card"><summary><?php esc_html_e( 'Want a custom registration form?', 'anar-toolkit' ); ?></summary>
			<div class="anar-mrn-card__heading">
				<div><h3><?php esc_html_e( 'Add your own registration fields', 'anar-toolkit' ); ?></h3></div>
				<strong><?php echo esc_html( $connected ? __( 'MRN Forms available', 'anar-toolkit' ) : __( 'Compatible MRN Forms required', 'anar-toolkit' ) ); ?></strong>
			</div>
			<p><?php esc_html_e( 'For extra registration fields, build and publish a form in MRN Forms and enable Anar registration. Select it below; Anar verifies the mobile number and creates the account. Keep the default form if you do not need extra fields.', 'anar-toolkit' ); ?></p>
			<label for="anar-mrn-registration"><strong><?php esc_html_e( 'Registration form', 'anar-toolkit' ); ?></strong></label>
			<select id="anar-mrn-registration" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[mrn_registration_form]" aria-describedby="anar-mrn-help">
				<option value="0"><?php esc_html_e( 'Default Anar registration', 'anar-toolkit' ); ?></option>
				<?php if ( $selected && ! $available ) : ?>
					<option value="<?php echo esc_attr( (string) $selected ); ?>" selected><?php esc_html_e( 'Selected form is unavailable', 'anar-toolkit' ); ?></option>
				<?php endif; ?>
				<?php foreach ( $forms as $form ) : ?>
					<option value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php selected( $selected, $form['id'] ); ?>><?php echo esc_html( $form['title'] . ' (#' . $form['id'] . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<p id="anar-mrn-help"><?php esc_html_e( 'Publish a form in MRN Forms with “Anar registration” enabled. Allow registration and mobile sign-in here, then configure the SMS verification service.', 'anar-toolkit' ); ?></p>
			<?php if ( $connected ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mrn-form-builder' ) ); ?>"><?php esc_html_e( 'Open the MRN Forms builder', 'anar-toolkit' ); ?> <span aria-hidden="true">↗</span></a>
			<?php endif; ?>
		</details>
		<?php
	}

	private function section_header( $title, $description, $icon ) {
		?>
		<header class="anar-section-head"><span><?php echo esc_html( $icon ); ?></span><div><h2><?php echo esc_html( $title ); ?></h2></div><?php Help::button( $description ); ?></header>
		<?php
	}

	/**
	 * Toggle row.
	 *
	 * @param string              $key         Key.
	 * @param string              $title       Title.
	 * @param string              $description Description.
	 * @param array<string,mixed> $value       Values.
	 * @return void
	 */
	private function toggle( $key, $title, $description, $value ) {
		?>
		<div class="anar-field-row">
			<label for="anar-<?php echo esc_attr( $key ); ?>"><span class="anar-field-title"><strong><?php echo esc_html( $title ); ?></strong><?php Help::button( $description ); ?></span></label>
			<label class="anar-switch"><input id="anar-<?php echo esc_attr( $key ); ?>" type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $value[ $key ] ) ); ?>><span></span></label>
		</div>
		<?php
	}

	/**
	 * Number field.
	 *
	 * @param string              $key    Key.
	 * @param string              $label  Label.
	 * @param array<string,mixed> $value  Values.
	 * @param int                 $min    Min.
	 * @param int                 $max    Max.
	 * @param string              $suffix Suffix.
	 * @return void
	 */
	private function number_field( $key, $label, $value, $min, $max, $suffix, $form = '' ) {
		?>
		<label><span><?php echo esc_html( $label ); ?></span><div class="anar-number"><input
		<?php
		if ( $form ) {
			?>
			form="<?php echo esc_attr( $form ); ?>"<?php } ?> type="number" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value[ $key ] ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>"><i><?php echo esc_html( $suffix ); ?></i></div></label>
		<?php
	}
}
