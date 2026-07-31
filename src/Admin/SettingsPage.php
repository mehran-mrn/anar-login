<?php
/**
 * Modern RTL settings page.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Infrastructure\Settings;
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
	}

	/**
	 * Add top-level menu.
	 *
	 * @return void
	 */
	public function menu() {
		add_menu_page(
			__( 'Anar Login', 'anar-login' ),
			__( 'Anar Login', 'anar-login' ),
			'manage_options',
			'anar-login',
			array( $this, 'render' ),
			'dashicons-shield-alt',
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
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => $this->settings->defaults(),
			)
		);
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Current page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_anar-login' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/css/admin.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/js/admin.js', array(), ANAR_LOGIN_VERSION, true );
		wp_localize_script(
			'anar-login-admin',
			'AnarLoginAdmin',
			array(
				'copied' => __( 'Copied ✓', 'anar-login' ),
				'failed' => __( 'Copy failed', 'anar-login' ),
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
		$direction = is_rtl() ? 'rtl' : 'ltr';
		?>
		<div class="wrap anar-admin" dir="<?php echo esc_attr( $direction ); ?>">
			<header class="anar-admin__header">
				<div class="anar-admin__title">
					<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt="">
					<div>
						<div><h1>Anar Login</h1><span>v<?php echo esc_html( ANAR_LOGIN_VERSION ); ?></span></div>
						<p><?php esc_html_e( 'Passwordless sign-in that is simple for users and secure for you', 'anar-login' ); ?></p>
						<span class="anar-admin__credits"><a class="anar-admin__author" href="https://mehranmarandi.ir" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Designed by Mehran Marandi', 'anar-login' ); ?></a><span aria-hidden="true"> · </span><a class="anar-admin__author" href="https://github.com/mehran-mrn/" target="_blank" rel="noopener noreferrer">@mehran_mrn</a></span>
					</div>
				</div>
				<div class="anar-admin__health"><span></span><?php esc_html_e( 'Core is active', 'anar-login' ); ?></div>
			</header>

			<?php settings_errors(); ?>

			<div class="anar-admin__layout">
				<aside class="anar-admin__nav">
					<div class="anar-admin__nav-label"><?php esc_html_e( 'Settings', 'anar-login' ); ?></div>
					<button type="button" class="is-active" data-tab="general"><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'General', 'anar-login' ); ?></button>
					<button type="button" data-tab="sms"><span class="dashicons dashicons-smartphone"></span><?php esc_html_e( 'SMS', 'anar-login' ); ?></button>
					<button type="button" data-tab="google"><span class="dashicons dashicons-google"></span><?php esc_html_e( 'Google sign-in', 'anar-login' ); ?></button>
					<button type="button" data-tab="email"><span class="dashicons dashicons-email-alt"></span><?php esc_html_e( 'Email', 'anar-login' ); ?></button>
					<button type="button" data-tab="security"><span class="dashicons dashicons-lock"></span><?php esc_html_e( 'Security', 'anar-login' ); ?></button>
					<div class="anar-admin__nav-label"><?php esc_html_e( 'Help', 'anar-login' ); ?></div>
					<button type="button" data-tab="install"><span class="dashicons dashicons-editor-code"></span><?php esc_html_e( 'Add to pages', 'anar-login' ); ?></button>
				</aside>

				<form class="anar-admin__content" method="post" action="options.php">
					<?php settings_fields( 'anar_login' ); ?>

					<section class="anar-tab is-active" data-panel="general">
						<?php $this->section_header( __( 'General settings', 'anar-login' ), __( 'Configure sign-in, registration, and the appearance of the form.', 'anar-login' ), '⚙' ); ?>
						<div class="anar-card">
							<?php $this->toggle( 'allow_registration', __( 'Create accounts automatically', 'anar-login' ), __( 'Create a new account when a verified user does not already have one.', 'anar-login' ), $value ); ?>
							<div class="anar-field-row">
								<label for="anar-role"><strong><?php esc_html_e( 'New user role', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Choose the least-privileged suitable role.', 'anar-login' ); ?></small></label>
								<select id="anar-role" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[default_role]">
									<?php wp_dropdown_roles( $value['default_role'] ); ?>
								</select>
							</div>
							<div class="anar-field-row">
								<label for="anar-panel-page"><strong><?php esc_html_e( 'Account page', 'anar-login' ); ?></strong><small><?php esc_html_e( 'The page that contains the [anar_account] shortcode.', 'anar-login' ); ?></small></label>
								<?php
								$pages_dropdown = wp_dropdown_pages(
									array(
										'name'              => 'anar_login_settings[panel_page_id]',
										'id'                => 'anar-panel-page',
										'selected'          => absint( $value['panel_page_id'] ),
										'show_option_none'  => esc_html__( 'Not selected', 'anar-login' ),
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
								<label for="anar-redirect"><strong><?php esc_html_e( 'Redirect URL after sign-in', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Leave empty to use the account page or home page.', 'anar-login' ); ?></small></label>
								<input id="anar-redirect" type="url" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[login_redirect]" value="<?php echo esc_attr( $value['login_redirect'] ); ?>" dir="ltr" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>">
							</div>
							<div class="anar-field-row">
								<label for="anar-color"><strong><?php esc_html_e( 'Brand color', 'anar-login' ); ?></strong><small><?php esc_html_e( 'The primary color of the sign-in form and account dashboard.', 'anar-login' ); ?></small></label>
								<div class="anar-color"><input id="anar-color" type="color" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[brand_color]" value="<?php echo esc_attr( $value['brand_color'] ); ?>"><code><?php echo esc_html( $value['brand_color'] ); ?></code></div>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="sms" hidden>
						<?php $this->section_header( __( 'SMS provider', 'anar-login' ), __( 'Configure the delivery service and OTP template details.', 'anar-login' ), '◈' ); ?>
						<div class="anar-provider-grid">
							<?php foreach ( $providers as $key => $provider ) : ?>
								<label class="anar-provider <?php echo $key === $value['sms_provider'] ? 'is-selected' : ''; ?>">
									<input type="radio" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[sms_provider]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $value['sms_provider'], $key ); ?>>
									<span class="anar-provider__mark">✦</span>
									<span><strong><?php echo esc_html( $provider->title() ); ?></strong><small><?php esc_html_e( 'Service template delivery', 'anar-login' ); ?></small></span>
									<i>✓</i>
								</label>
							<?php endforeach; ?>
						</div>

						<?php foreach ( $providers as $key => $provider ) : ?>
							<div class="anar-card anar-provider-settings" data-provider="<?php echo esc_attr( $key ); ?>" <?php echo $key === $value['sms_provider'] ? '' : 'hidden'; ?>>
								<div class="anar-card__head"><div><strong>
									<?php
									/* translators: %s: SMS provider title. */
									echo esc_html( sprintf( __( 'Connect to %s', 'anar-login' ), $provider->title() ) );
									?>
								</strong><small><?php esc_html_e( 'Get these credentials from your SMS provider dashboard.', 'anar-login' ); ?></small></div><span><?php esc_html_e( 'Secure HTTPS', 'anar-login' ); ?></span></div>
								<div class="anar-fields-grid">
									<?php foreach ( $provider->fields() as $field => $label ) : ?>
										<?php
										$config    = isset( $value[ 'provider_' . $key ] ) ? $value[ 'provider_' . $key ] : array();
										$is_secret = in_array( $field, array( 'api_key', 'password' ), true );
										?>
										<label>
											<span><?php echo esc_html( $label ); ?></span>
											<input type="<?php echo $is_secret ? 'password' : 'text'; ?>" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[provider_<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $field ); ?>]" value="<?php echo $is_secret ? '' : esc_attr( $config[ $field ] ?? '' ); ?>" dir="ltr" placeholder="<?php echo $is_secret && ! empty( $config[ $field ] ) ? esc_attr__( 'Saved — enter a new value to change it', 'anar-login' ) : ''; ?>">
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>

						<div class="anar-card">
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-sms-message"><strong><?php esc_html_e( 'SMS fallback message', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Template providers only use {code}; this message is available to developers and text-message providers.', 'anar-login' ); ?></small></label>
								<textarea id="anar-sms-message" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[sms_message]" rows="3"><?php echo esc_textarea( $value['sms_message'] ); ?></textarea>
								<div class="anar-placeholders"><code>{code}</code><code>{site}</code><code>{minutes}</code></div>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="google" hidden>
						<?php $this->section_header( __( 'Google sign-in', 'anar-login' ), __( 'Enable standard Google OAuth without a heavy client library.', 'anar-login' ), 'G' ); ?>
						<div class="anar-card">
							<?php $this->toggle( 'google_enabled', __( 'Enable Google sign-in', 'anar-login' ), __( 'Show the Google sign-in button on the login form.', 'anar-login' ), $value ); ?>
							<div class="anar-field-row">
								<label for="anar-google-id"><strong>Client ID</strong><small><?php esc_html_e( 'The OAuth 2.0 client ID from Google Cloud Console.', 'anar-login' ); ?></small></label>
								<input id="anar-google-id" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[google_client_id]" value="<?php echo esc_attr( $value['google_client_id'] ); ?>" dir="ltr">
							</div>
							<div class="anar-field-row">
								<label for="anar-google-secret"><strong>Client Secret</strong><small><?php esc_html_e( 'Leave this field empty to keep the saved value.', 'anar-login' ); ?></small></label>
								<input id="anar-google-secret" type="password" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[google_secret]" value="" dir="ltr" placeholder="<?php echo ! empty( $value['google_secret'] ) ? esc_attr__( 'Saved', 'anar-login' ) : ''; ?>">
							</div>
							<div class="anar-callback">
								<div><strong>Authorized redirect URI</strong><small><?php esc_html_e( 'Add this exact URL to your Google OAuth settings.', 'anar-login' ); ?></small></div>
								<code dir="ltr"><?php echo esc_html( rest_url( 'anar-login/v1/google/callback' ) ); ?></code>
								<button type="button" class="button" data-copy="<?php echo esc_attr( rest_url( 'anar-login/v1/google/callback' ) ); ?>"><?php esc_html_e( 'Copy', 'anar-login' ); ?></button>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="email" hidden>
						<?php $this->section_header( __( 'Email sign-in', 'anar-login' ), __( 'Customize the one-time password email.', 'anar-login' ), '@' ); ?>
						<div class="anar-card">
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-subject"><strong><?php esc_html_e( 'Email subject', 'anar-login' ); ?></strong></label>
								<input id="anar-email-subject" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_subject]" value="<?php echo esc_attr( $value['email_subject'] ); ?>">
							</div>
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-message"><strong><?php esc_html_e( 'Email message', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Email is sent with the standard wp_mail function and is compatible with SMTP plugins.', 'anar-login' ); ?></small></label>
								<textarea id="anar-email-message" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_message]" rows="6"><?php echo esc_textarea( $value['email_message'] ); ?></textarea>
								<div class="anar-placeholders"><code>{code}</code><code>{site}</code><code>{minutes}</code></div>
							</div>
						</div>
					</section>

					<section class="anar-tab" data-panel="security" hidden>
						<?php $this->section_header( __( 'Security and rate limits', 'anar-login' ), __( 'Configure code lifetime and request limits for your site.', 'anar-login' ), '⌾' ); ?>
						<div class="anar-card">
							<div class="anar-fields-grid anar-fields-grid--security">
								<?php $this->number_field( 'otp_length', __( 'Code length', 'anar-login' ), $value, 4, 8, __( 'digits', 'anar-login' ) ); ?>
								<?php $this->number_field( 'otp_ttl', __( 'Code lifetime', 'anar-login' ), $value, 60, 600, __( 'seconds', 'anar-login' ) ); ?>
								<?php $this->number_field( 'resend_delay', __( 'Resend interval', 'anar-login' ), $value, 30, 300, __( 'seconds', 'anar-login' ) ); ?>
								<?php $this->number_field( 'max_requests_hour', __( 'Hourly send limit', 'anar-login' ), $value, 2, 30, __( 'requests', 'anar-login' ) ); ?>
								<?php $this->number_field( 'max_verify_attempts', __( 'Attempts per code', 'anar-login' ), $value, 3, 10, __( 'attempts', 'anar-login' ) ); ?>
							</div>
							<?php $this->toggle( 'debug_log', __( 'Technical error log', 'anar-login' ), __( 'Log only error codes and channel names; OTPs and confidential data are never logged.', 'anar-login' ), $value ); ?>
							<?php $this->toggle( 'delete_data', __( 'Clean up when uninstalling', 'anar-login' ), __( 'A full uninstall removes the code table and plugin metadata. Deactivation keeps the data.', 'anar-login' ), $value ); ?>
						</div>
						<div class="anar-security-summary">
							<article><span>✓</span><div><strong><?php esc_html_e( 'Hashed code storage', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Raw OTPs are never stored in the database.', 'anar-login' ); ?></small></div></article>
							<article><span>✓</span><div><strong><?php esc_html_e( 'Single use', 'anar-login' ); ?></strong><small><?php esc_html_e( 'A code is invalidated atomically after its first successful verification.', 'anar-login' ); ?></small></div></article>
							<article><span>✓</span><div><strong><?php esc_html_e( 'Enumeration protection', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Request responses do not reveal whether an account exists.', 'anar-login' ); ?></small></div></article>
						</div>
					</section>

					<section class="anar-tab" data-panel="install" hidden>
						<?php $this->section_header( __( 'Use on your site', 'anar-login' ), __( 'Two lightweight shortcodes compatible with the Classic and Block editors.', 'anar-login' ), '‹/›' ); ?>
						<div class="anar-shortcodes">
							<article>
								<span class="dashicons dashicons-unlock"></span>
								<div><strong><?php esc_html_e( 'Login form', 'anar-login' ); ?><small><?php esc_html_e( 'For a login page or theme modal.', 'anar-login' ); ?></small></strong><code dir="ltr">[anar_login]</code></div>
								<button type="button" class="button" data-copy="[anar_login]"><?php esc_html_e( 'Copy', 'anar-login' ); ?></button>
							</article>
							<article>
								<span class="dashicons dashicons-dashboard"></span>
								<div><strong><?php esc_html_e( 'Account dashboard', 'anar-login' ); ?><small><?php esc_html_e( 'Shows the login form to signed-out visitors.', 'anar-login' ); ?></small></strong><code dir="ltr">[anar_account]</code></div>
								<button type="button" class="button" data-copy="[anar_account]"><?php esc_html_e( 'Copy', 'anar-login' ); ?></button>
							</article>
						</div>
						<div class="anar-hooks">
							<h3><?php esc_html_e( 'Key extension points', 'anar-login' ); ?></h3>
							<code>anar_login_sms_providers</code>
							<code>anar_login_allow_otp_request</code>
							<code>anar_login_otp_message</code>
							<code>anar_login_redirect_url</code>
							<code>anar_login_user_registered</code>
							<code>anar_login_authenticated</code>
						</div>
					</section>

					<footer class="anar-admin__save">
						<div><span class="dashicons dashicons-saved"></span><p><strong><?php esc_html_e( 'Your settings are stored securely', 'anar-login' ); ?></strong><small><?php esc_html_e( 'Confidential values are not displayed again in the admin interface.', 'anar-login' ); ?></small></p></div>
						<?php submit_button( __( 'Save settings', 'anar-login' ), 'primary', 'submit', false ); ?>
					</footer>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Section heading.
	 *
	 * @param string $title       Title.
	 * @param string $description Description.
	 * @param string $icon        Icon.
	 * @return void
	 */
	private function section_header( $title, $description, $icon ) {
		?>
		<header class="anar-section-head"><span><?php echo esc_html( $icon ); ?></span><div><h2><?php echo esc_html( $title ); ?></h2><p><?php echo esc_html( $description ); ?></p></div></header>
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
