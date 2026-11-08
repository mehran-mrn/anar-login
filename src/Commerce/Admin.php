<?php
/**
 * Commerce module settings and a bounded, masked queue report.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private $settings;
	private $repository;
	private $providers;

	public function __construct( Settings $settings, Repository $repository, ProviderRegistry $providers ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->providers  = $providers;
	}

	public function register() {
		add_action( 'admin_init', array( $this, 'setting' ) );
		add_action( 'admin_post_anar_toolkit_retry', array( $this, 'retry' ) );
		add_action( 'admin_post_anar_toolkit_forget', array( $this, 'forget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_submenu_page( 'anar-login', __( 'Commerce toolkit', 'anar-toolkit' ), __( 'Commerce toolkit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-commerce', array( $this, 'render' ) );
	}

	public function setting() {
		register_setting( 'anar_toolkit_commerce', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( $this->settings, 'sanitize' ), 'default' => $this->settings->defaults() ) );
	}

	public function assets( $hook ) {
		if ( false !== strpos( $hook, 'anar-toolkit-' ) ) {
			wp_enqueue_style( 'anar-toolkit-commerce', ANAR_LOGIN_URL . 'assets/css/commerce-suite.css', array(), ANAR_LOGIN_VERSION );
		}
	}

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ), '', array( 'response' => 403 ) );
		}
	}

	public function retry() {
		$this->guard();
		check_admin_referer( 'anar_toolkit_retry' );
		$this->repository->retry( isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0 );
		wp_safe_redirect( admin_url( 'admin.php?page=anar-toolkit-commerce&tab=messages' ) );
		exit;
	}

	public function forget() {
		$this->guard();
		check_admin_referer( 'anar_toolkit_forget' );
		$raw   = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$phone = Identity::normalize( $raw );
		if ( ! is_wp_error( $phone ) && 'sms' === $phone['channel'] ) {
			( new Privacy( $this->repository, $this->settings ) )->erase_phone( $phone['identity'] );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=anar-toolkit-commerce&tab=privacy' ) );
		exit;
	}

	private function input( $key, $label, $value, $type = 'text', $help = '' ) {
		$id = 'anar-commerce-' . sanitize_key( str_replace( array( '[', ']' ), '-', $key ) );
		$direction = 'admin_phones' === $key || false !== strpos( $key, '][params' ) || false !== strpos( $key, '][template' ) ? 'ltr' : 'auto';
		?>
		<div class="anar-commerce-field"><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><?php \Anar\Login\Admin\Help::button( $help ); ?><br>
		<?php if ( 'textarea' === $type ) : ?>
			<textarea dir="<?php echo esc_attr( $direction ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		<?php else : ?>
			<input dir="<?php echo esc_attr( $direction ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" <?php echo 'number' === $type ? 'min="0" step="1"' : ''; ?>>
		<?php endif; ?>
		<?php
		if ( $help ) :
			?>
			<br><span class="description"><?php echo esc_html( $help ); ?></span><?php endif; ?></div>
		<?php
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$value = $this->settings->all();
		$flags = \Anar\Login\Infrastructure\Features::all();
		$tab = \Anar\Login\Infrastructure\Features::current_tab();
		$labels = Dashboard::labels();
		?>
		<div class="wrap anar-commerce">
			<?php ( new Dashboard( $this->repository ) )->render(); ?>
			<?php
			if ( 'campaigns' === $tab ) {
				do_action( 'anar_toolkit_campaign_workspace' ); }
			?>
			<?php settings_errors(); ?>
			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Install and activate WooCommerce to run these modules. Login features work independently.', 'anar-toolkit' ); ?></p></div>
			<?php endif; ?>
			<?php
			if ( 'settings' === $tab ) :
				\Anar\Login\Admin\Help::section( $flags['recovery'] ? 'recovery' : 'orders' );
				?>
				<form method="post" action="options.php" class="anar-settings-form"><input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_partial]" value="1"><section class="anar-suite-panel" id="settings" data-suite-panel="settings" <?php echo 'settings' === $tab ? '' : 'hidden'; ?>><div class="anar-suite-toolbar"><h2><?php esc_html_e( 'Automation settings', 'anar-toolkit' ); ?></h2></div><div class="anar-settings-grid">
				<?php settings_fields( 'anar_toolkit_commerce' ); ?>
				<div class="anar-commerce-card">
					<h2><?php esc_html_e( 'Modules', 'anar-toolkit' ); ?></h2>
					<?php
					foreach ( array( 'paid_customer' => $labels['paid_customer'], 'paid_admin' => $labels['paid_admin'], 'recovery' => __( 'Cart and unpaid-order reminders', 'anar-toolkit' ), 'new_admin' => $labels['new_admin'], 'failed_admin' => $labels['failed_admin'], 'shipment_customer' => $labels['shipment_customer'], 'status_processing' => $labels['status_processing'], 'status_completed' => $labels['status_completed'], 'status_on-hold' => $labels['status_on-hold'], 'status_cancelled' => $labels['status_cancelled'], 'status_refunded' => $labels['status_refunded'] ) as $key => $label ) :
						if ( 'recovery' === $key ? ! $flags['recovery'] : ! $flags['orders'] ) {
							continue; }
						?>
						<div class="anar-order-event" data-order-event="<?php echo esc_attr( $key ); ?>"><label><input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="0"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $value[ $key ] ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php
							if ( 'recovery' !== $key ) {
								\Anar\Login\Admin\MessageCenter::summary( $key, 'orders', true ); }
							?>
						</div>
					<?php endforeach; ?>
					<p><?php esc_html_e( 'Payment messages are triggered by WooCommerce payment completion, not by viewing the thank-you page. Merely creating an order or choosing cash on delivery does not trigger them.', 'anar-toolkit' ); ?></p>
					<p><label for="anar-commerce-provider"><strong><?php esc_html_e( 'Commerce SMS provider', 'anar-toolkit' ); ?></strong></label><br><select id="anar-commerce-provider" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
					<?php foreach ( $this->providers->all() as $key => $provider ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value['provider'], $key ); ?>><?php echo esc_html( $provider->title() ); ?></option>
					<?php endforeach; ?></select></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-settings' ) ); ?>"><?php esc_html_e( 'Configure provider credentials in SMS settings', 'anar-toolkit' ); ?></a></p>
					<?php $this->input( 'admin_phones', __( 'Administrator mobile numbers (maximum 5, one per line)', 'anar-toolkit' ), $value['admin_phones'], 'textarea' ); ?>
				</div>
				<?php
				if ( $flags['recovery'] ) :
					?>
					<div class="anar-commerce-card">
					<h2><?php esc_html_e( 'Reminder timing and privacy', 'anar-toolkit' ); ?></h2>
					<p><label for="anar-recovery-page"><strong><?php esc_html_e( 'Page for entering recovery codes', 'anar-toolkit' ); ?></strong></label><br>
					<?php
					$dropdown = wp_dropdown_pages( array( 'name' => esc_attr( Settings::OPTION . '[recovery_page_id]' ), 'id' => 'anar-recovery-page', 'selected' => (int) $value['recovery_page_id'], 'show_option_none' => esc_html__( 'Default WooCommerce cart page', 'anar-toolkit' ), 'option_none_value' => 0, 'echo' => 0 ) );
					echo wp_kses( $dropdown, array( 'select' => array( 'name' => true, 'id' => true ), 'option' => array( 'value' => true, 'selected' => true, 'class' => true ) ) );
					?>
					<br><span class="description"><?php esc_html_e( 'Add [anar_recover_cart] to a custom page. SMS includes its URL and a separate recovery code. The URL itself contains no code or account-access token.', 'anar-toolkit' ); ?></span></p>
					<p><label for="anar-capture-mode"><strong><?php esc_html_e( 'How to capture carts', 'anar-toolkit' ); ?></strong></label><br><select id="anar-capture-mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[capture_mode]"><option value="automatic" <?php selected( $value['capture_mode'], 'automatic' ); ?>><?php esc_html_e( 'Automatically from account and checkout', 'anar-toolkit' ); ?></option><option value="explicit" <?php selected( $value['capture_mode'], 'explicit' ); ?>><?php esc_html_e( 'With a separate reminder preference form', 'anar-toolkit' ); ?></option></select></p><p class="description"><?php esc_html_e( 'Automatic mode does not require a separate checkbox. A short notice explains cart storage. Previously stopped numbers remain stopped; every reminder includes an unsubscribe link.', 'anar-toolkit' ); ?></p>
					<p><?php esc_html_e( 'Stage times are minutes after the last saved cart change. A value of 0 disables that stage. Payment, cancellation, expiry or opting out stops future reminders. Guest carts without a saved number cannot receive SMS.', 'anar-toolkit' ); ?></p>
					<?php
					$this->input( 'start_hour', __( 'Recovery sending starts at hour (0–23)', 'anar-toolkit' ), $value['start_hour'], 'number' );
					$this->input( 'end_hour', __( 'Recovery sending stops at hour (1–24)', 'anar-toolkit' ), $value['end_hour'], 'number' );
					$this->input( 'daily_limit', __( 'Maximum reminders per phone in 24 hours (1–10)', 'anar-toolkit' ), $value['daily_limit'], 'number' );
					$this->input( 'link_days', __( 'Recovery eligibility after cart activity, days (1–30)', 'anar-toolkit' ), $value['link_days'], 'number' );
					$this->input( 'code_minutes', __( 'Recovery code validity, minutes (5–1440)', 'anar-toolkit' ), $value['code_minutes'], 'number' );
					$this->input( 'retention', __( 'Local data retention, days (7–90)', 'anar-toolkit' ), $value['retention'], 'number' );
					foreach ( $value['events'] as $key => $event ) {
						if ( ! isset( $event['minutes'] ) ) {
							continue; }
						?>
							<div class="anar-recovery-message" data-recovery-stage="<?php echo esc_attr( $key ); ?>">
							<?php \Anar\Login\Admin\MessageCenter::summary( $key, 'recovery' ); ?>
							<?php $this->input( 'events][' . $key . '][minutes', __( 'Minutes after the last cart change (0 = disabled)', 'anar-toolkit' ), $event['minutes'], 'number' ); ?>
							</div>
							<?php
					}
						\Anar\Login\Admin\MessageCenter::summary( 'recovery_request', 'recovery' );
					?>
					<p><?php esc_html_e( 'Hours use the WordPress site timezone. Action Scheduler is used when available; WP-Cron is the fallback. Execution depends on the site scheduler and may be delayed. Retention cleanup runs daily.', 'anar-toolkit' ); ?> <code><?php echo esc_html( wp_timezone_string() ); ?></code></p>
				</div>

				<div class="anar-commerce-card"><h2><?php esc_html_e( 'Recovery discounts', 'anar-toolkit' ); ?></h2>
					<?php
					foreach ( array( 'coupon_enabled' => __( 'Generate recovery discounts', 'anar-toolkit' ), 'coupon_individual' => __( 'Do not combine with other coupons', 'anar-toolkit' ), 'coupon_exclude_sale' => __( 'Exclude sale products', 'anar-toolkit' ) ) as $key => $label ) :
						?>
					<p><label><input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( $value[ $key ] ); ?>> <?php echo esc_html( $label ); ?></label></p><?php endforeach; ?>
				<p><label><?php esc_html_e( 'Discount type', 'anar-toolkit' ); ?> <select name="<?php echo esc_attr( Settings::OPTION ); ?>[coupon_type]"><option value="percent" <?php selected( $value['coupon_type'], 'percent' ); ?>><?php esc_html_e( 'Percentage', 'anar-toolkit' ); ?></option><option value="fixed_cart" <?php selected( $value['coupon_type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart amount', 'anar-toolkit' ); ?></option></select></label></p>
					<?php
					$this->input( 'coupon_amount', __( 'Discount amount', 'anar-toolkit' ), $value['coupon_amount'], 'number' );
					$this->input( 'coupon_minimum', __( 'Minimum cart amount', 'anar-toolkit' ), $value['coupon_minimum'], 'number' );
					$this->input( 'coupon_stage', __( 'Offer from reminder stage (1–3)', 'anar-toolkit' ), $value['coupon_stage'], 'number' );
					$this->input( 'coupon_hours', __( 'Discount validity in hours', 'anar-toolkit' ), $value['coupon_hours'], 'number' );
					?>
				<p class="description"><?php esc_html_e( 'One coupon per cart, one use, restricted to the recipient billing phone. Include {coupon_code} in the relevant reminder template. A valid coupon is applied after the customer verifies their recovery code.', 'anar-toolkit' ); ?></p></div>
					<?php
				endif; if ( $flags['orders'] ) :
					?>
					<div class="anar-commerce-card"><h2><?php esc_html_e( 'Order notifications', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Enter shipment details in the Anar box on the WooCommerce order. Standard WooCommerce Shipment Tracking metadata is also supported. For another shipping plugin, enter its tracking-number meta key.', 'anar-toolkit' ); ?></p><?php $this->input( 'tracking_meta_key', __( 'Additional tracking-number meta key', 'anar-toolkit' ), $value['tracking_meta_key'] ); ?><p class="description"><?php esc_html_e( 'Each status is sent once per order. Changed tracking numbers can create a new notification; superseded pending messages are cancelled before sending.', 'anar-toolkit' ); ?></p></div>
				<?php endif; ?></div></section><div class="anar-suite-savebar"><?php submit_button(); ?></div>
			</form><?php endif; ?>
			<?php
			if ( 'privacy' === $tab ) :
				\Anar\Login\Admin\Help::section( 'privacy' );
				?>
				<section class="anar-suite-panel" data-suite-panel="privacy" id="privacy"><div class="anar-commerce-card">
				<h2><?php esc_html_e( 'Personal data', 'anar-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'WordPress export and erasure tools can locate reminders by the optional saved email. For a phone-only request, verify the requester first and remove that number here. This removes local reminder data and queue entries, not WooCommerce orders or records already held by the SMS provider.', 'anar-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'anar_toolkit_forget' ); ?><input type="hidden" name="action" value="anar_toolkit_forget">
					<label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?> <input name="phone" type="tel" dir="ltr" required></label>
					<button type="submit" class="button"><?php esc_html_e( 'Erase local reminder data for this number', 'anar-toolkit' ); ?></button>
				</form>
			</div>
			</section><?php endif; ?>
		</div>
		<?php
	}
}
