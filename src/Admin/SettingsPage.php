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
	public function menu() {
		add_menu_page(
			__( 'Anar Toolkit', 'anar-toolkit' ),
			__( 'Anar Toolkit', 'anar-toolkit' ),
			'manage_options',
			'anar-login',
			array( FeaturesPage::class, 'render' ),
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
		return $this->settings->sanitize( $input, $this->providers->all() );
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Current page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'anar-login-settings' ) ) {
			return;
		}

		if ( \Anar\Login\Infrastructure\Features::enabled( 'login' ) ) {
			wp_enqueue_media(); }
		wp_enqueue_style( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/css/admin.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_style( 'anar-meli-signup', ANAR_LOGIN_URL . 'assets/css/meli-signup.css', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/js/admin.js', array(), ANAR_LOGIN_VERSION, true );
		wp_enqueue_script( 'anar-meli-signup', ANAR_LOGIN_URL . 'assets/js/meli-signup.js', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION, true );
		wp_localize_script(
			'anar-login-admin',
			'AnarLoginAdmin',
			array(
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
			<header class="anar-admin__header">
				<div class="anar-admin__title">
					<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png?ver=' . ANAR_LOGIN_VERSION ); ?>" alt="">
					<div>
						<div><h1>Anar Toolkit</h1><span>v<?php echo esc_html( ANAR_LOGIN_VERSION ); ?></span></div>
						<p><?php esc_html_e( 'SMS sign-in and customer communication tools', 'anar-toolkit' ); ?></p>
						<p class="anar-admin__free"><?php esc_html_e( '100% free, forever — all features included.', 'anar-toolkit' ); ?></p>
						<span class="anar-admin__credits"><a class="anar-admin__author" href="https://mehranmarandi.ir" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Designed by Mehran Marandi', 'anar-toolkit' ); ?></a><span aria-hidden="true"> · </span><a class="anar-admin__author" href="https://mrnwp.ir/anar/" target="_blank" rel="noopener noreferrer">MRNWP</a></span>
					</div>
				</div>
				<div class="anar-admin__health"><span></span><?php esc_html_e( 'Core is active', 'anar-toolkit' ); ?></div>
			</header>
			<?php // WordPress moves admin notices after this marker; without it they land inside the flex header next to the h1. ?>
			<hr class="wp-header-end">

			<?php settings_errors(); ?>
			<?php MessageCenter::notice(); ?>
			<?php StatusPanel::credit(); ?>
			<?php if ( \Anar\Login\Infrastructure\Features::enabled( 'login' ) ) : ?>
				<div class="anar-setup-actions"><button type="button" class="button" data-login-preview><?php esc_html_e( 'Preview saved form', 'anar-toolkit' ); ?></button></div>
			<?php endif; ?>

			<div class="anar-admin__layout">
				<aside class="anar-admin__nav"><?php $this->navigation( $value ); ?></aside>
<div class="anar-admin__content">
<form class="anar-connection-form" method="post" action="options.php">
					<?php settings_fields( 'anar_login' ); ?>
					<?php if ( \Anar\Login\Infrastructure\Features::enabled( 'login' ) ) : ?>
						<section class="anar-tab" data-panel="setup" hidden><?php LoginSetup::render( $value ); ?></section>
					<?php endif; ?>

					<section class="anar-tab is-active" data-panel="general">
						<?php $this->section_header( __( 'General settings', 'anar-toolkit' ), __( 'Configure sign-in, registration, and the appearance of the form.', 'anar-toolkit' ), '⚙' ); ?>
						<div class="anar-card">
							<div class="anar-field-row">
								<label for="anar-identity-mode"><strong><?php esc_html_e( 'Sign-in methods', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Phone-only mode shows a numeric keypad on mobile for the fastest sign-in.', 'anar-toolkit' ); ?></small></label>
								<select id="anar-identity-mode" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[identity_mode]">
									<option value="both" <?php selected( $value['identity_mode'], 'both' ); ?>><?php esc_html_e( 'Mobile number or email', 'anar-toolkit' ); ?></option>
									<option value="phone" <?php selected( $value['identity_mode'], 'phone' ); ?>><?php esc_html_e( 'Mobile number only', 'anar-toolkit' ); ?></option>
									<option value="email" <?php selected( $value['identity_mode'], 'email' ); ?>><?php esc_html_e( 'Email only', 'anar-toolkit' ); ?></option>
								</select>
							</div>
							<?php $this->toggle( 'allow_registration', __( 'Allow new account registration', 'anar-toolkit' ), __( 'Let new users create an account after verification.', 'anar-toolkit' ), $value ); ?>
							<?php $this->mrn_forms( $value ); ?>
							<div class="anar-field-row">
								<label for="anar-profile-step"><strong><?php esc_html_e( 'Name after sign-up', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Ask new customers for their first and last name right after the code is verified, never before. Existing customers are not asked.', 'anar-toolkit' ); ?></small></label>
								<select id="anar-profile-step" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[profile_step]">
									<option value="off" <?php selected( $value['profile_step'], 'off' ); ?>><?php esc_html_e( 'Do not ask', 'anar-toolkit' ); ?></option>
									<option value="optional" <?php selected( $value['profile_step'], 'optional' ); ?>><?php esc_html_e( 'Ask, with a skip option', 'anar-toolkit' ); ?></option>
									<option value="required" <?php selected( $value['profile_step'], 'required' ); ?>><?php esc_html_e( 'Ask, without skipping', 'anar-toolkit' ); ?></option>
								</select>
							</div>
							<div class="anar-field-row">
								<label for="anar-role"><strong><?php esc_html_e( 'New user role', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Only Subscriber and Customer roles with basic read access are allowed. Elevated or custom roles cannot be used for public registration.', 'anar-toolkit' ); ?></small></label>
								<select id="anar-role" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[default_role]">
									<?php foreach ( get_editable_roles() as $role => $details ) : ?>
										<?php if ( Settings::is_assignable_role( $role ) ) : ?>
											<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $value['default_role'], $role ); ?>><?php echo esc_html( translate_user_role( $details['name'] ) ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="anar-field-row">
								<label for="anar-panel-page"><strong><?php esc_html_e( 'Account page', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'The page that contains the [anar_account] shortcode.', 'anar-toolkit' ); ?></small></label>
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
								<label for="anar-redirect"><strong><?php esc_html_e( 'Redirect URL after sign-in', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Leave empty to use the account page or home page.', 'anar-toolkit' ); ?></small></label>
								<input id="anar-redirect" type="url" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[login_redirect]" value="<?php echo esc_attr( $value['login_redirect'] ); ?>" dir="ltr" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
							</div>
							<div class="anar-field-row">
								<?php
								$anar_role_lines = array();
								foreach ( (array) $value['role_redirects'] as $anar_role => $anar_url ) {
									$anar_role_lines[] = $anar_role . ' = ' . $anar_url;
								}
								?>
								<label for="anar-role-redirects"><strong><?php esc_html_e( 'Redirect by role', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Optional. One per line as role = URL, for example shop_manager = /wp-admin/. A requested return page, such as checkout, still comes first.', 'anar-toolkit' ); ?></small></label>
								<textarea id="anar-role-redirects" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[role_redirects]" rows="3" dir="ltr" placeholder="shop_manager = <?php echo esc_attr( admin_url() ); ?>"><?php echo esc_textarea( implode( "\n", $anar_role_lines ) ); ?></textarea>
							</div>
							<div class="anar-field-row">
								<label for="anar-color"><strong><?php esc_html_e( 'Brand color', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'The primary color of the sign-in form and account dashboard.', 'anar-toolkit' ); ?></small></label>
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
								<label for="anar-form-logo"><strong><?php esc_html_e( 'Form logo', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Shown at the top of the sign-in form. The site logo falls back to the site icon, then to a lock icon.', 'anar-toolkit' ); ?></small></label>
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
						<?php $this->section_header( __( 'SMS provider', 'anar-toolkit' ), __( 'Connect your SMS service here. Message content and approved templates are available in Messages and templates.', 'anar-toolkit' ), '◈' ); ?>
						<p class="description"><?php esc_html_e( 'Choose and save the default provider for verification messages. Then edit the messages below to set their delivery method and content.', 'anar-toolkit' ); ?></p>
						<div class="anar-provider-grid">
							<?php foreach ( $providers as $key => $provider ) : ?>
								<label class="anar-provider <?php echo $key === $value['sms_provider'] ? 'is-selected' : ''; ?>">
									<input type="radio" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[sms_provider]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $value['sms_provider'], $key ); ?>>
									<span class="anar-provider__mark" aria-hidden="true">✦</span>
									<span><strong><?php echo esc_html( $provider->title() ); ?></strong>
									<?php
									if ( 'melipayamak' === $key ) :
										?>
										<span class="anar-meli-badge"><?php esc_html_e( 'Anar user discount', 'anar-toolkit' ); ?></span><?php endif; ?><small><?php echo esc_html( method_exists( $provider, 'description' ) ? $provider->description() : __( 'Service template delivery', 'anar-toolkit' ) ); ?></small></span>
									<span class="anar-provider__check" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 8 3 3 7-7"/></svg></span>
								</label>
							<?php endforeach; ?>
						</div>

						<?php foreach ( $providers as $key => $provider ) : ?>
							<div class="anar-card anar-provider-settings" data-provider="<?php echo esc_attr( $key ); ?>" <?php echo $key === $value['sms_provider'] ? '' : 'hidden'; ?>>
								<?php
								if ( 'melipayamak' === $key ) {
									MeliOffer::card(); }
								?>
								<div class="anar-card__head"><div><strong>
									<?php
									/* translators: %s: SMS provider title. */
									echo esc_html( sprintf( __( 'Connect to %s', 'anar-toolkit' ), $provider->title() ) );
									?>
								</strong><small><?php esc_html_e( 'Get these credentials from your SMS provider dashboard.', 'anar-toolkit' ); ?></small></div><span><?php esc_html_e( 'Secure HTTPS', 'anar-toolkit' ); ?></span></div>
								<?php if ( 'ippanel' === $key ) : ?>
									<p><a class="button" href="https://ippanel.co/?ref=75" target="_blank" rel="sponsored noopener noreferrer"><?php esc_html_e( 'Register with IPPanel (referral link)', 'anar-toolkit' ); ?></a></p>
								<?php elseif ( 'smsir' === $key ) : ?>
									<p><a class="button" href="https://app.sms.ir/auth/sign-up?ref=3NQTF" target="_blank" rel="sponsored noopener noreferrer"><?php esc_html_e( 'Register with SMS.ir (referral link)', 'anar-toolkit' ); ?></a></p>
								<?php endif; ?>
								<div class="anar-fields-grid">
									<?php foreach ( $provider->fields() as $field => $definition ) : ?>
										<?php
										if ( in_array( $field, array( 'template_id', 'code_parameter' ), true ) ) {
											continue;
										}

										$config     = isset( $value[ 'provider_' . $key ] ) ? $value[ 'provider_' . $key ] : array();
										$definition = Settings::field_definition( $definition );
										$is_secret  = in_array( $field, $this->settings->secret_fields(), true );
										$name       = Settings::OPTION_KEY . '[provider_' . $key . '][' . $field . ']';
										$current    = (string) ( $config[ $field ] ?? '' );
										?>
										<label class="<?php echo 'textarea' === $definition['type'] || 'url' === $definition['type'] ? 'anar-fields-grid__wide' : ''; ?>">
											<span><?php echo esc_html( $definition['label'] ); ?></span>
											<?php if ( 'select' === $definition['type'] ) : ?>
												<select name="<?php echo esc_attr( $name ); ?>">
													<?php foreach ( $definition['options'] as $option => $option_label ) : ?>
														<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $current, (string) $option ); ?>><?php echo esc_html( $option_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( 'textarea' === $definition['type'] ) : ?>
												<textarea name="<?php echo esc_attr( $name ); ?>" rows="4" dir="ltr" placeholder="<?php echo esc_attr( $definition['placeholder'] ); ?>"><?php echo esc_textarea( $current ); ?></textarea>
											<?php else : ?>
												<input type="<?php echo $is_secret ? 'password' : 'text'; ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $is_secret ? '' : esc_attr( $current ); ?>" dir="ltr" placeholder="<?php echo esc_attr( $is_secret && $current ? __( 'Saved — enter a new value to change it', 'anar-toolkit' ) : $definition['placeholder'] ); ?>">
											<?php endif; ?>
											<?php if ( $definition['help'] ) : ?>
												<small><?php echo esc_html( $definition['help'] ); ?></small>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>

						<div class="anar-card" data-verification-messages data-saved-provider="<?php echo esc_attr( $value['sms_provider'] ); ?>">
						<p class="anar-message-warning" role="status" data-save-provider-first hidden><?php esc_html_e( 'Your new SMS provider selection is unsaved.', 'anar-toolkit' ); ?></p>
						<?php
						foreach ( array( 'login_otp', 'two_factor', 'password_reset', 'game_phone', 'stock_verification' ) as $message_id ) {
							MessageCenter::summary( $message_id, 'sms' ); }
						?>
						</div>
<div class="anar-card anar-tip">
							<span class="dashicons dashicons-smartphone" aria-hidden="true"></span>
							<div>
								<strong><?php esc_html_e( 'Automatic code filling on Android', 'anar-toolkit' ); ?></strong>
								<small><?php esc_html_e( 'Chrome on Android can fill the code with one tap when the last line of your provider template is exactly:', 'anar-toolkit' ); ?></small>
								<code dir="ltr">@<?php echo esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); ?> #<var>code</var></code>
								<small><?php esc_html_e( 'Put your template’s code variable after the # sign. iPhones suggest the code above the keyboard without any change.', 'anar-toolkit' ); ?></small>
							</div>
						</div>

					</section>

					<section class="anar-tab" data-panel="google" hidden>
						<?php $this->section_header( __( 'Google sign-in', 'anar-toolkit' ), __( 'Enable standard Google OAuth without a heavy client library.', 'anar-toolkit' ), 'G' ); ?>
						<div class="anar-card">
							<?php $this->toggle( 'google_enabled', __( 'Enable Google sign-in', 'anar-toolkit' ), __( 'Show the Google sign-in button on the login form.', 'anar-toolkit' ), $value ); ?>
							<div class="anar-field-row">
								<label for="anar-google-id"><strong>Client ID</strong><small><?php esc_html_e( 'The OAuth 2.0 client ID from Google Cloud Console.', 'anar-toolkit' ); ?></small></label>
								<input id="anar-google-id" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[google_client_id]" value="<?php echo esc_attr( $value['google_client_id'] ); ?>" dir="ltr">
							</div>
							<div class="anar-field-row">
								<label for="anar-google-secret"><strong>Client Secret</strong><small><?php esc_html_e( 'Leave this field empty to keep the saved value.', 'anar-toolkit' ); ?></small></label>
								<input id="anar-google-secret" type="password" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[google_secret]" value="" dir="ltr" placeholder="<?php echo ! empty( $value['google_secret'] ) ? esc_attr__( 'Saved', 'anar-toolkit' ) : ''; ?>">
							</div>
							<div class="anar-callback">
								<div><strong>Authorized redirect URI</strong><small><?php esc_html_e( 'Add this exact URL to your Google OAuth settings.', 'anar-toolkit' ); ?></small></div>
								<code dir="ltr"><?php echo esc_html( rest_url( 'anar-login/v1/google/callback' ) ); ?></code>
								<button type="button" class="button" data-copy="<?php echo esc_attr( rest_url( 'anar-login/v1/google/callback' ) ); ?>"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="email" hidden>
						<?php $this->section_header( __( 'Email sign-in', 'anar-toolkit' ), __( 'Customize the one-time password email.', 'anar-toolkit' ), '@' ); ?>
						<div class="anar-card">
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-subject"><strong><?php esc_html_e( 'Email subject', 'anar-toolkit' ); ?></strong></label>
								<input id="anar-email-subject" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_subject]" value="<?php echo esc_attr( $value['email_subject'] ); ?>">
							</div>
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-message"><strong><?php esc_html_e( 'Email message', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Email is sent with the standard wp_mail function and is compatible with SMTP plugins.', 'anar-toolkit' ); ?></small></label>
								<textarea id="anar-email-message" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_message]" rows="6"><?php echo esc_textarea( $value['email_message'] ); ?></textarea>
								<div class="anar-placeholders"><code>{code}</code><code>{site}</code><code>{minutes}</code></div>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="security" hidden>
						<?php $this->section_header( __( 'Security and rate limits', 'anar-toolkit' ), __( 'Configure code lifetime and request limits for your site.', 'anar-toolkit' ), '⌾' ); ?>
						<div class="anar-card">
							<?php if ( ! is_multisite() ) : ?>
								<?php $this->toggle( 'passkeys_enabled', __( 'Passkey sign-in', 'anar-toolkit' ), __( 'Let customers register a passkey from account security. Passkeys require HTTPS and PHP 8.0 or later; the current sign-in methods remain available.', 'anar-toolkit' ), $value ); ?>
							<?php endif; ?>
							<?php $this->toggle( 'password_reset_enabled', __( 'SMS password recovery', 'anar-toolkit' ), __( 'Allow linked mobile numbers to reset an existing account password using a separate single-use SMS code.', 'anar-toolkit' ), $value ); ?>
							<div class="anar-field-row">
								<label for="anar-two-factor"><strong><?php esc_html_e( 'Two-step verification', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'After a correct password, a code is sent to the account’s mobile, or its email when no mobile is linked. Code and Google sign-ins skip this step, as they already prove ownership.', 'anar-toolkit' ); ?></small></label>
								<select id="anar-two-factor" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[two_factor]">
									<option value="off" <?php selected( $value['two_factor'], 'off' ); ?>><?php esc_html_e( 'Off', 'anar-toolkit' ); ?></option>
									<option value="privileged" <?php selected( $value['two_factor'], 'privileged' ); ?>><?php esc_html_e( 'Administrators, editors and shop managers', 'anar-toolkit' ); ?></option>
									<option value="all" <?php selected( $value['two_factor'], 'all' ); ?>><?php esc_html_e( 'All users', 'anar-toolkit' ); ?></option>
								</select>
							</div>
						</div>
						<div class="anar-card">
							<div class="anar-fields-grid anar-fields-grid--security">
								<?php $this->number_field( 'otp_length', __( 'Code length', 'anar-toolkit' ), $value, 4, 8, __( 'digits', 'anar-toolkit' ) ); ?>
								<?php $this->number_field( 'otp_ttl', __( 'Code lifetime', 'anar-toolkit' ), $value, 60, 600, __( 'seconds', 'anar-toolkit' ) ); ?>
								<?php $this->number_field( 'resend_delay', __( 'Resend interval', 'anar-toolkit' ), $value, 30, 300, __( 'seconds', 'anar-toolkit' ) ); ?>
								<?php $this->number_field( 'max_requests_hour', __( 'Hourly send limit', 'anar-toolkit' ), $value, 2, 30, __( 'requests', 'anar-toolkit' ) ); ?>
								<?php $this->number_field( 'max_verify_attempts', __( 'Attempts per code', 'anar-toolkit' ), $value, 3, 10, __( 'attempts', 'anar-toolkit' ) ); ?>
							</div>
							<div class="anar-field-row">
								<label for="anar-bot-challenge"><strong><?php esc_html_e( 'Invisible bot check', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Before a code is sent, the browser solves a short puzzle in the background, with no image or click. Adaptive mode asks only when one address requests repeatedly or the whole site sees an unusual volume, so SMS floods stop costing you money.', 'anar-toolkit' ); ?></small></label>
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
							<article><span>✓</span><div><strong><?php esc_html_e( 'Hashed code storage', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Raw OTPs are never stored in the database.', 'anar-toolkit' ); ?></small></div></article>
							<article><span>✓</span><div><strong><?php esc_html_e( 'Single use', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'A code is invalidated atomically after its first successful verification.', 'anar-toolkit' ); ?></small></div></article>
							<article><span>✓</span><div><strong><?php esc_html_e( 'Enumeration protection', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Request responses do not reveal whether an account exists.', 'anar-toolkit' ); ?></small></div></article>
						</div>
					</section>

					<?php if ( WooCommerce::active() ) : ?>
						<section class="anar-tab" data-panel="woocommerce" hidden>
							<?php $this->section_header( __( 'WooCommerce', 'anar-toolkit' ), __( 'Passwordless sign-in on My Account and checkout.', 'anar-toolkit' ), '🛒' ); ?>
							<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[wc_present]" value="1">
							<div class="anar-card">
								<div class="anar-field-row">
									<label for="anar-wc-account"><strong><?php esc_html_e( 'My Account sign-in', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'One form signs customers in and registers new ones. A “sign in with a password” link stays available.', 'anar-toolkit' ); ?></small></label>
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
								<div><strong><?php esc_html_e( 'Login form', 'anar-toolkit' ); ?><small><?php esc_html_e( 'For a login page or theme modal.', 'anar-toolkit' ); ?></small></strong><code dir="ltr">[anar_login]</code></div>
								<button type="button" class="button" data-copy="[anar_login]"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</article>
							<article>
								<span class="dashicons dashicons-dashboard"></span>
								<div><strong><?php esc_html_e( 'Account dashboard', 'anar-toolkit' ); ?><small><?php esc_html_e( 'Shows the login form to signed-out visitors.', 'anar-toolkit' ); ?></small></strong><code dir="ltr">[anar_account]</code></div>
								<button type="button" class="button" data-copy="[anar_account]"><?php esc_html_e( 'Copy', 'anar-toolkit' ); ?></button>
							</article>
							<article>
								<span class="dashicons dashicons-external"></span>
								<div><strong><?php esc_html_e( 'Popup button', 'anar-toolkit' ); ?><small><?php esc_html_e( 'Opens the login popup; signed-in users go to their account. For menus, add a custom link to #anar-login.', 'anar-toolkit' ); ?></small></strong><code dir="ltr">[anar_login_button]</code></div>
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
						<div><span class="dashicons dashicons-saved"></span><p><strong><?php esc_html_e( 'Your settings are stored securely', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Confidential values are not displayed again in the admin interface.', 'anar-toolkit' ); ?></small></p></div>
						<?php submit_button( __( 'Save settings', 'anar-toolkit' ), 'primary', 'submit', false ); ?>
					</footer>
				</form>
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
		</div>
		<?php
	}

	/**
	 * Order connection tabs by the enabled capabilities.
	 *
	 * @param array $value Saved connection settings.
	 * @return void
	 */
	private function navigation( $value ) {
		$flags = \Anar\Login\Infrastructure\Features::all();
		$labels = array( 'general' => __( 'General', 'anar-toolkit' ), 'setup' => __( 'Sign-in setup guide', 'anar-toolkit' ), 'sms' => __( 'SMS', 'anar-toolkit' ), 'email' => __( 'Email', 'anar-toolkit' ), 'google' => __( 'Google sign-in', 'anar-toolkit' ), 'security' => __( 'Security', 'anar-toolkit' ), 'woocommerce' => __( 'WooCommerce', 'anar-toolkit' ), 'install' => __( 'Add to pages', 'anar-toolkit' ) );
		$primary = array( 'general' => $flags['login'], 'setup' => $flags['login'], 'sms' => ( $flags['login'] && 'email' !== $value['identity_mode'] ) || $flags['two_factor'] || $flags['reset'] || \Anar\Login\Infrastructure\Features::queue_needed(), 'email' => $flags['login'] && 'phone' !== $value['identity_mode'], 'google' => $flags['login'] && ! empty( $value['google_enabled'] ), 'security' => $flags['login'] || $flags['reset'] || $flags['two_factor'], 'woocommerce' => $flags['login'] && WooCommerce::active(), 'install' => $flags['login'] );
		$first = true;
		?>
		<div class="anar-admin__nav-label"><?php esc_html_e( 'Shared settings', 'anar-toolkit' ); ?></div>
		<button type="button" data-tab="messages"><?php esc_html_e( 'Messages and templates', 'anar-toolkit' ); ?></button>
		<button type="button" data-tab="sending"><?php esc_html_e( 'Campaign sending rules', 'anar-toolkit' ); ?></button>
		<?php
		foreach ( array( true => __( 'Connection settings', 'anar-toolkit' ), false => __( 'Other features', 'anar-toolkit' ) ) as $enabled => $heading ) {
			?>
			<div class="anar-admin__nav-label"><?php echo esc_html( $heading ); ?></div>
			<?php
			foreach ( $labels as $key => $label ) {
				if ( (bool) $primary[ $key ] !== (bool) $enabled || ( 'woocommerce' === $key && ! WooCommerce::active() ) || ( 'setup' === $key && ! $flags['login'] ) ) {
					continue; }
				?>
				<button type="button" <?php echo $first ? 'class="is-active"' : ''; ?> data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php
				$first = false;
			}
		}
	}

	private function mrn_forms( array $value ) {
		$connected = class_exists( \MRN\Form\Anar_Integration::class );
		$forms     = \Anar\Login\Integrations\MrnForm::registration_forms();
		$selected  = absint( $value['mrn_registration_form'] );
		$available = in_array( $selected, array_column( $forms, 'id' ), true );
		?>
		<div id="anar-mrn-forms" class="anar-mrn-card">
			<div class="anar-mrn-card__heading">
				<div><small><?php esc_html_e( 'Plugin integration', 'anar-toolkit' ); ?></small><h3><?php esc_html_e( 'Anar and MRN Forms', 'anar-toolkit' ); ?></h3></div>
				<strong><?php echo esc_html( $connected ? __( 'MRN Forms available', 'anar-toolkit' ) : __( 'Compatible MRN Forms required', 'anar-toolkit' ) ); ?></strong>
			</div>
			<p><?php esc_html_e( 'Build a registration form with your own fields in MRN Forms. Anar verifies the mobile number and creates the WordPress account. Other forms can use the same SMS service to verify a number before submission.', 'anar-toolkit' ); ?></p>
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
		</div>
		<?php
	}

	private function section_header( $title, $description, $icon ) {
		?>
		<header class="anar-section-head"><span><?php echo esc_html( $icon ); ?></span><div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div><?php Help::button( $description ); ?></header>
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
			<label for="anar-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $description ); ?></small></label>
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
	private function number_field( $key, $label, $value, $min, $max, $suffix ) {
		?>
		<label><span><?php echo esc_html( $label ); ?></span><div class="anar-number"><input type="number" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value[ $key ] ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>"><i><?php echo esc_html( $suffix ); ?></i></div></label>
		<?php
	}
}
