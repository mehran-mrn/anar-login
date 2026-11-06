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
		add_action( 'admin_menu', array( $this, 'menu' ) );
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
		if ( false !== strpos( $hook, 'anar-toolkit-commerce' ) ) {
			wp_enqueue_style( 'anar-toolkit-commerce', ANAR_LOGIN_URL . 'assets/css/commerce.css', array(), ANAR_LOGIN_VERSION );
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
		wp_safe_redirect( admin_url( 'admin.php?page=anar-toolkit-commerce#queue' ) );
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
		wp_safe_redirect( admin_url( 'admin.php?page=anar-toolkit-commerce#privacy' ) );
		exit;
	}

	private function input( $key, $label, $value, $type = 'text', $help = '' ) {
		$id = 'anar-commerce-' . sanitize_key( str_replace( array( '[', ']' ), '-', $key ) );
		$direction = 'admin_phones' === $key || false !== strpos( $key, '][params' ) || false !== strpos( $key, '][template' ) ? 'ltr' : 'auto';
		?>
		<p class="anar-commerce-field"><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><br>
		<?php if ( 'textarea' === $type ) : ?>
			<textarea dir="<?php echo esc_attr( $direction ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
		<?php else : ?>
			<input dir="<?php echo esc_attr( $direction ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" <?php echo 'number' === $type ? 'min="0" step="1"' : ''; ?>>
		<?php endif; ?>
		<?php
		if ( $help ) :
			?>
			<br><span class="description"><?php echo esc_html( $help ); ?></span><?php endif; ?></p>
		<?php
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$value  = $this->settings->all();
		$report = $this->repository->report();
		$labels = array(
			'recovery_request' => __( 'Requested recovery code', 'anar-toolkit' ),
			'paid_customer' => __( 'Payment confirmation to customer', 'anar-toolkit' ),
			'paid_admin' => __( 'Payment confirmation to administrators', 'anar-toolkit' ),
			'recovery_1' => __( 'Purchase reminder — stage 1', 'anar-toolkit' ),
			'recovery_2' => __( 'Purchase reminder — stage 2', 'anar-toolkit' ),
			'recovery_3' => __( 'Purchase reminder — stage 3', 'anar-toolkit' ),
		);
		?>
		<div class="wrap anar-commerce">
			<h1><?php esc_html_e( 'Anar Toolkit — WooCommerce SMS', 'anar-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Recover unfinished purchases with consent, and notify customers and administrators after confirmed payment.', 'anar-toolkit' ); ?></p>
			<?php settings_errors(); ?>
			<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Install and activate WooCommerce to run these modules. Login features work independently.', 'anar-toolkit' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'anar_toolkit_commerce' ); ?>
				<div class="anar-commerce-card">
					<h2><?php esc_html_e( 'Modules', 'anar-toolkit' ); ?></h2>
					<?php foreach ( array( 'paid_customer' => $labels['paid_customer'], 'paid_admin' => $labels['paid_admin'], 'recovery' => __( 'Cart and unpaid-order reminders', 'anar-toolkit' ) ) as $key => $label ) : ?>
						<p><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $value[ $key ] ); ?>> <?php echo esc_html( $label ); ?></label></p>
					<?php endforeach; ?>
					<p><?php esc_html_e( 'Payment messages are triggered by WooCommerce payment completion, not by viewing the thank-you page. Merely creating an order or choosing cash on delivery does not trigger them.', 'anar-toolkit' ); ?></p>
					<p><label for="anar-commerce-provider"><strong><?php esc_html_e( 'Commerce SMS provider', 'anar-toolkit' ); ?></strong></label><br><select id="anar-commerce-provider" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
					<?php foreach ( $this->providers->all() as $key => $provider ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value['provider'], $key ); ?>><?php echo esc_html( $provider->title() ); ?></option>
					<?php endforeach; ?></select></p>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login' ) ); ?>"><?php esc_html_e( 'Configure provider credentials in SMS settings', 'anar-toolkit' ); ?></a></p>
					<?php $this->input( 'admin_phones', __( 'Administrator mobile numbers (maximum 5, one per line)', 'anar-toolkit' ), $value['admin_phones'], 'textarea' ); ?>
				</div>
				<div class="anar-commerce-card">
					<h2><?php esc_html_e( 'Reminder timing and privacy', 'anar-toolkit' ); ?></h2>
					<p><label for="anar-recovery-page"><strong><?php esc_html_e( 'Page for entering recovery codes', 'anar-toolkit' ); ?></strong></label><br>
					<?php
					$dropdown = wp_dropdown_pages( array( 'name' => esc_attr( Settings::OPTION . '[recovery_page_id]' ), 'id' => 'anar-recovery-page', 'selected' => (int) $value['recovery_page_id'], 'show_option_none' => esc_html__( 'Default WooCommerce cart page', 'anar-toolkit' ), 'option_none_value' => 0, 'echo' => 0 ) );
					echo wp_kses( $dropdown, array( 'select' => array( 'name' => true, 'id' => true ), 'option' => array( 'value' => true, 'selected' => true, 'class' => true ) ) );
					?>
					<br><span class="description"><?php esc_html_e( 'Add [anar_recover_cart] to a custom page. SMS includes its URL and a separate recovery code. The URL itself contains no code or account-access token.', 'anar-toolkit' ); ?></span></p>
					<p><?php esc_html_e( 'Only shoppers who save an explicit reminder preference are tracked. Add [anar_cart_reminders] and [anar_recover_cart] beside Cart or Checkout blocks. Classic pages show the forms automatically. Recovery uses a phone-bound six-digit code; OTP login never subscribes a shopper.', 'anar-toolkit' ); ?></p>
					<p><?php esc_html_e( 'Stage times are minutes after the last saved cart change. A value of 0 disables that stage. Payment, cancellation, expiry or opting out stops future reminders. Guest carts without a saved number cannot receive SMS.', 'anar-toolkit' ); ?></p>
					<?php
					$this->input( 'start_hour', __( 'Recovery sending starts at hour (0–23)', 'anar-toolkit' ), $value['start_hour'], 'number' );
					$this->input( 'end_hour', __( 'Recovery sending stops at hour (1–24)', 'anar-toolkit' ), $value['end_hour'], 'number' );
					$this->input( 'daily_limit', __( 'Maximum reminders per phone in 24 hours (1–10)', 'anar-toolkit' ), $value['daily_limit'], 'number' );
					$this->input( 'link_days', __( 'Recovery eligibility after cart activity, days (1–30)', 'anar-toolkit' ), $value['link_days'], 'number' );
					$this->input( 'code_minutes', __( 'Recovery code validity, minutes (5–1440)', 'anar-toolkit' ), $value['code_minutes'], 'number' );
					$this->input( 'retention', __( 'Local data retention, days (7–90)', 'anar-toolkit' ), $value['retention'], 'number' );
					?>
					<p><?php esc_html_e( 'Hours use the WordPress site timezone. Action Scheduler is used when available; WP-Cron is the fallback. Execution depends on the site scheduler and may be delayed. Retention cleanup runs daily.', 'anar-toolkit' ); ?> <code><?php echo esc_html( wp_timezone_string() ); ?></code></p>
				</div>
				<?php foreach ( $value['events'] as $key => $event ) : ?>
					<div class="anar-commerce-card">
						<h2><?php echo esc_html( $labels[ $key ] ); ?></h2>
						<?php
						if ( isset( $event['minutes'] ) ) {
							$this->input( 'events][' . $key . '][minutes', __( 'Minutes after the last cart change (0 = disabled)', 'anar-toolkit' ), $event['minutes'], 'number' );
						}
						$this->input( 'events][' . $key . '][message', __( 'Message text for a custom gateway / template reference', 'anar-toolkit' ), $event['message'], 'textarea', __( 'Built-in providers send the approved provider template, not this text. Keep its wording in sync with the approved template.', 'anar-toolkit' ) );
						$this->input( 'events][' . $key . '][template', __( 'Approved provider template ID or name', 'anar-toolkit' ), $event['template'] );
						$this->input( 'events][' . $key . '][params', __( 'Provider variables — one name={placeholder} per line', 'anar-toolkit' ), $event['params'], 'textarea', __( 'Kavenegar: token, token2, token3, token10, token20. IPPanel/Panelchi/Faraz/SMS.ir: exact approved variable names. Meli Payamak: variables in template order. Custom gateway: uses the message text and its {message} transport placeholder.', 'anar-toolkit' ) );
						?>
						<p><code>{site} {name} {order_id} {total} {currency} <?php echo isset( $event['minutes'] ) ? '{code} {minutes} {recovery_page} {unsubscribe_url}' : ''; ?></code></p>
						<?php if ( isset( $event['minutes'] ) ) : ?>
							<p><?php esc_html_e( 'The code, recovery page and unsubscribe link must be included in the recovery parameter mapping (or custom message). Use templates approved for purchase reminders.', 'anar-toolkit' ); ?></p>
						<?php endif; ?>
						<p><?php esc_html_e( 'Conservative Unicode SMS segment estimate before replacing placeholders; actual billing depends on the provider:', 'anar-toolkit' ); ?> <strong><?php echo esc_html( Templates::segments( $event['message'] ) ); ?></strong></p>
					</div>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
			<div class="anar-commerce-card" id="queue">
				<h2><?php esc_html_e( 'Results and outgoing queue', 'anar-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Accepted means the provider accepted the request; it is not a handset delivery receipt. A recovered purchase means a paid order linked to a verified recovery code. These counts describe attribution, not proven extra revenue. Reports cover locally retained data.', 'anar-toolkit' ); ?></p>
				<p><?php esc_html_e( 'Confirmed recovery code verifications:', 'anar-toolkit' ); ?> <strong><?php echo esc_html( $report['clicks'] ); ?></strong> · <?php esc_html_e( 'Attributed paid purchases:', 'anar-toolkit' ); ?> <strong><?php echo esc_html( $report['recovered'] ); ?></strong></p>
				<p>
				<?php
				foreach ( $report['queue'] as $state => $row ) :
					?>
					<span class="anar-commerce-count"><?php echo esc_html( $state . ': ' . $row->count ); ?></span> <?php endforeach; ?></p>
				<p><?php esc_html_e( 'Only confirmed failures can be retried here, up to three attempts. Uncertain results may already have been accepted; investigate them in the provider panel. Failed messages are not retried automatically.', 'anar-toolkit' ); ?></p>
				<div class="anar-commerce-table"><table class="widefat striped"><thead><tr>
					<?php
					foreach ( array( __( 'Event', 'anar-toolkit' ), __( 'Recipient', 'anar-toolkit' ), __( 'Status', 'anar-toolkit' ), __( 'Error code', 'anar-toolkit' ), __( 'Scheduled', 'anar-toolkit' ), __( 'Action', 'anar-toolkit' ) ) as $label ) :
						?>
						<th><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
				</tr></thead><tbody>
				<?php foreach ( $report['messages'] as $job ) : ?>
					<tr><td><?php echo esc_html( $labels[ $job->event ] ?? $job->event ); ?></td><td dir="ltr"><?php echo esc_html( Identity::mask( $job->recipient ) ); ?></td><td><?php echo esc_html( $job->status ); ?></td><td><code><?php echo esc_html( $job->error_code ); ?></code></td><td><?php echo esc_html( wp_date( 'Y-m-d H:i', $job->scheduled_at ) ); ?></td><td>
					<?php if ( 'failed' === $job->status && $job->attempts < 3 ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_toolkit_retry' ); ?><input type="hidden" name="action" value="anar_toolkit_retry"><input type="hidden" name="message_id" value="<?php echo esc_attr( $job->id ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Retry', 'anar-toolkit' ); ?></button></form>
					<?php endif; ?></td></tr>
				<?php endforeach; ?>
				<?php
				if ( empty( $report['messages'] ) ) :
					?>
					<tr><td colspan="6"><?php esc_html_e( 'No messages yet.', 'anar-toolkit' ); ?></td></tr><?php endif; ?>
				</tbody></table></div>
			</div>
			<div class="anar-commerce-card" id="privacy">
				<h2><?php esc_html_e( 'Personal data', 'anar-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'WordPress export and erasure tools can locate reminders by the optional saved email. For a phone-only request, verify the requester first and remove that number here. This removes local reminder data and queue entries, not WooCommerce orders or records already held by the SMS provider.', 'anar-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'anar_toolkit_forget' ); ?><input type="hidden" name="action" value="anar_toolkit_forget">
					<label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?> <input name="phone" type="tel" dir="ltr" required></label>
					<button type="submit" class="button"><?php esc_html_e( 'Erase local reminder data for this number', 'anar-toolkit' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}
}
