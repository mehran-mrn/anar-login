<?php
/**
 * Local delivery checks and the administrator's saved-message test workspace.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class DeliveryCheck {

	/**
	 * Inspect saved configuration only. No network requests or option writes.
	 *
	 * @return array Report containing no credentials, recipients or template values.
	 */
	public static function report() {
		$settings  = new Settings();
		$message   = MessageSettings::get( 'login_otp' );
		$providers = ProviderRegistry::shared()->all();
		$provider  = $providers[ $message['provider'] ] ?? null;
		$errors    = MessageSettings::errors( 'login_otp', $message );
		$checks    = array(
			array(
				'label'  => __( 'Delivery service', 'anar-toolkit' ),
				'ok'     => null !== $provider,
				'detail' => $provider ? $provider->title() : __( 'Choose an SMS provider in connection settings.', 'anar-toolkit' ),
			),
			array(
				'label'  => __( 'Credentials and message', 'anar-toolkit' ),
				'ok'     => ! $errors,
				'detail' => $errors ? implode( ' ', array_unique( $errors ) ) : __( 'Saved configuration is complete. A real send is still needed to check the service.', 'anar-toolkit' ),
			),
		);

		return array(
			'ready'    => ! $errors && null !== $provider,
			'provider' => $provider ? $provider->title() : __( 'Not configured', 'anar-toolkit' ),
			'method'   => 'text' === $message['mode'] ? __( 'Plain text', 'anar-toolkit' ) : __( 'Approved template', 'anar-toolkit' ),
			'revision' => MessageSettings::fingerprint( $message ),
			'checks'   => $checks,
			'environment' => array(
				'Anar'      => ANAR_LOGIN_VERSION,
				'WordPress' => get_bloginfo( 'version' ),
				'PHP'       => PHP_VERSION,
				'HTTPS'     => is_ssl() ? __( 'Yes', 'anar-toolkit' ) : __( 'No', 'anar-toolkit' ),
				'OTP'       => (string) $settings->get( 'otp_length', 5 ),
			),
		);
	}

	/**
	 * Shortcuts reflect configuration, never an unverified connection claim.
	 *
	 * @param array $report Local report.
	 * @return void
	 */
	public static function overview( array $report ) {
		$cards = array(
			array( 'sms', 'email-alt', __( 'SMS connection', 'anar-toolkit' ), $report['provider'], $report['ready'] ? __( 'Ready to test', 'anar-toolkit' ) : __( 'Needs configuration', 'anar-toolkit' ), 'blue' ),
			array( 'security', 'shield', __( 'Sign-in protection', 'anar-toolkit' ), __( 'Code lifetime and request limits', 'anar-toolkit' ), __( 'Review security', 'anar-toolkit' ), 'teal' ),
			array( 'messages', 'admin-comments', __( 'Messages and templates', 'anar-toolkit' ), __( 'One editor for every message', 'anar-toolkit' ), __( 'Manage messages', 'anar-toolkit' ), 'violet' ),
			array( 'test', 'yes-alt', __( 'Test and troubleshoot', 'anar-toolkit' ), __( 'Check settings, then test delivery', 'anar-toolkit' ), __( 'Open test center', 'anar-toolkit' ), 'amber' ),
		);
		?>
		<div class="anar-overview" aria-label="<?php esc_attr_e( 'Workspace shortcuts', 'anar-toolkit' ); ?>">
			<?php foreach ( $cards as $card ) : ?>
				<button type="button" class="anar-overview__card is-<?php echo esc_attr( $card[5] ); ?>" data-setup-target="<?php echo esc_attr( $card[0] ); ?>">
					<span class="anar-overview__icon dashicons dashicons-<?php echo esc_attr( $card[1] ); ?>" aria-hidden="true"></span>
					<span><strong><?php echo esc_html( $card[2] ); ?></strong><small><?php echo esc_html( $card[3] ); ?></small><span class="anar-overview__action"><?php echo esc_html( $card[4] ); ?><span aria-hidden="true"> ↗</span></span></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render outside the connection form so testing cannot submit its settings.
	 *
	 * @param array $report Local report.
	 * @return void
	 */
	public static function render( array $report ) {
		?>
		<div class="anar-delivery" data-delivery-check data-revision="<?php echo esc_attr( $report['revision'] ); ?>" data-ready="<?php echo $report['ready'] ? '1' : '0'; ?>">
			<header class="anar-section-head"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><h2 tabindex="-1"><?php esc_html_e( 'Test and troubleshoot', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Two clear steps: check the saved settings, then send to your own phone.', 'anar-toolkit' ); ?></p></div></header>
			<p class="anar-delivery__unsaved" role="status" data-delivery-unsaved hidden><?php esc_html_e( 'You have unsaved settings. Save them before testing so the test matches your live configuration.', 'anar-toolkit' ); ?></p>
			<div class="anar-delivery__grid">
				<section class="anar-delivery__card">
					<div class="anar-delivery__heading"><span>01</span><div><h3><?php esc_html_e( 'Check configuration', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Free local check. No message is sent and no credit is used.', 'anar-toolkit' ); ?></p></div></div>
					<ul class="anar-delivery__checks" data-delivery-checks>
						<?php foreach ( $report['checks'] as $check ) : ?>
							<li class="<?php echo $check['ok'] ? 'is-ready' : 'needs-setup'; ?>"><span aria-hidden="true"><?php echo $check['ok'] ? '✓' : '!'; ?></span><div><strong><?php echo esc_html( $check['label'] ); ?></strong><p><?php echo esc_html( $check['detail'] ); ?></p></div></li>
						<?php endforeach; ?>
					</ul>
					<button type="button" class="button" data-delivery-refresh><?php esc_html_e( 'Check saved settings', 'anar-toolkit' ); ?></button>
					<p role="status" class="anar-delivery__feedback" data-delivery-feedback></p>
				</section>
				<section class="anar-delivery__card anar-sms-test" data-saved-config>
					<div class="anar-delivery__heading"><span>02</span><div><h3><?php esc_html_e( 'Send a real test', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Uses the saved sign-in message and provider credit. No account or usable sign-in code is created.', 'anar-toolkit' ); ?></p></div></div>
					<dl class="anar-delivery__binding"><div><dt><?php esc_html_e( 'Delivery service', 'anar-toolkit' ); ?></dt><dd data-delivery-provider><?php echo esc_html( $report['provider'] ); ?></dd></div><div><dt><?php esc_html_e( 'Delivery method', 'anar-toolkit' ); ?></dt><dd data-delivery-method><?php echo esc_html( $report['method'] ); ?></dd></div></dl>
					<label for="anar-delivery-phone"><?php esc_html_e( 'Your test mobile number', 'anar-toolkit' ); ?></label>
					<div class="anar-sms-test__row"><input id="anar-delivery-phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="09123456789" aria-describedby="anar-test-help"><button type="button" class="button button-primary" data-delivery-send <?php disabled( ! $report['ready'] ); ?>><?php esc_html_e( 'Send test code', 'anar-toolkit' ); ?></button></div>
					<p id="anar-test-help" class="description"><?php esc_html_e( 'Provider acceptance does not confirm handset delivery. Check your phone after sending.', 'anar-toolkit' ); ?></p>
					<p class="anar-sms-test__result" role="status" aria-live="polite" hidden></p>
					<a class="anar-delivery__edit" data-message-open href="<?php echo esc_url( MessageCenter::url( 'login_otp', 'sms' ) ); ?>"><?php esc_html_e( 'Edit the sign-in message', 'anar-toolkit' ); ?> ↗</a>
				</section>
			</div>
			<details class="anar-delivery__support"><summary><?php esc_html_e( 'Troubleshooting and support report', 'anar-toolkit' ); ?></summary><p><?php esc_html_e( 'If no code arrives, check your provider credit, approved template and its variables, then inspect the provider delivery log.', 'anar-toolkit' ); ?></p><p><?php esc_html_e( 'The report includes software versions and configuration status. It excludes credentials, phone numbers, template values and site addresses.', 'anar-toolkit' ); ?></p><textarea rows="9" readonly dir="ltr" data-delivery-report aria-label="<?php esc_attr_e( 'Support report', 'anar-toolkit' ); ?>"><?php echo esc_textarea( self::support_text( $report ) ); ?></textarea><button type="button" class="button" data-delivery-copy><?php esc_html_e( 'Copy support report', 'anar-toolkit' ); ?></button><span role="status" data-delivery-copy-status></span></details>
		</div>
		<?php
	}

	/**
	 * Convert only the fixed allowlist to shareable text.
	 *
	 * @param array $report Local report.
	 * @return string
	 */
	public static function support_text( array $report ) {
		$lines = array( 'Anar Toolkit — ' . __( 'Support report', 'anar-toolkit' ) );
		foreach ( $report['environment'] as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}
		$lines[] = __( 'Delivery service', 'anar-toolkit' ) . ': ' . $report['provider'];
		$lines[] = __( 'Delivery method', 'anar-toolkit' ) . ': ' . $report['method'];
		foreach ( $report['checks'] as $check ) {
			$lines[] = ( $check['ok'] ? '[OK] ' : '[!] ' ) . $check['label'];
		}
		return implode( "\n", $lines );
	}
}
