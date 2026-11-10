<?php
/**
 * Administrator overview and cached SMS credit summary.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Balance\Accounts;
use Anar\Login\Balance\Monitor;
use Anar\Login\Commerce\Repository;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class StatusPanel {

	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( $hook ) {
		if ( current_user_can( 'manage_options' ) && ( 'index.php' === $hook || false !== strpos( $hook, '_page_anar-' ) ) ) {
			wp_enqueue_style( 'anar-status', ANAR_LOGIN_URL . 'assets/css/status.css', array( 'dashicons' ), ANAR_LOGIN_VERSION );
		}
	}

	public function dashboard() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'anar_toolkit_status', __( 'Anar Toolkit overview', 'anar-toolkit' ), array( $this, 'widget' ) );
		}
	}

	public function widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$flags = Features::all();
		$woo   = WooCommerce::active();
		$areas = array(
			array( __( 'Customer sign-in', 'anar-toolkit' ), $flags['login'] ),
			array( __( 'Order notifications', 'anar-toolkit' ), $woo && $flags['orders'] ),
			array( __( 'Abandoned carts', 'anar-toolkit' ), $woo && $flags['recovery'] ),
			array( __( 'Campaigns & CRM', 'anar-toolkit' ), $woo && Features::marketing() ),
		);
		?>
		<div class="anar-status-widget">
			<div class="anar-status-widget__brand">
				<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt="" width="32" height="32">
				<strong><?php esc_html_e( 'Anar Toolkit', 'anar-toolkit' ); ?></strong>
				<bdi class="anar-status-widget__version">v<?php echo esc_html( ANAR_LOGIN_VERSION ); ?></bdi>
			</div>
			<ul class="anar-status-widget__areas">
				<?php foreach ( $areas as list( $label, $enabled ) ) : ?>
					<li>
						<span><?php echo esc_html( $label ); ?></span>
						<span class="anar-status-widget__badge <?php echo $enabled ? 'is-enabled' : ''; ?>"><?php echo esc_html( $enabled ? __( 'Enabled', 'anar-toolkit' ) : __( 'Disabled', 'anar-toolkit' ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php self::credit(); ?>
			<?php self::sending_totals( $flags ); ?>
			<div class="anar-status-widget__links">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login' ) ); ?>"><?php esc_html_e( 'Features', 'anar-toolkit' ); ?></a>
				<a href="<?php echo esc_url( FeaturesPage::url( 'login' ) ); ?>"><?php esc_html_e( 'Settings', 'anar-toolkit' ); ?></a>
				<?php if ( isset( Features::workspace_tabs()['messages'] ) ) : ?>
					<a href="<?php echo esc_url( FeaturesPage::url( 'login_log' ) ); ?>"><?php esc_html_e( 'Sending report', 'anar-toolkit' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function sending_totals( $flags ) {
		$totals  = ( new Repository() )->recent_sms_totals();
		$periods = array( 'today' => __( 'Today', 'anar-toolkit' ), 'week' => __( 'Last 7 days', 'anar-toolkit' ) );
		$columns = array( 'accepted' => __( 'Accepted by provider', 'anar-toolkit' ), 'failed' => __( 'Failed', 'anar-toolkit' ), 'uncertain' => __( 'Needs investigation', 'anar-toolkit' ) );
		?>
		<section class="anar-status-widget__activity" aria-labelledby="anar-status-activity-title">
			<h3 id="anar-status-activity-title"><?php esc_html_e( 'Recorded SMS outcomes', 'anar-toolkit' ); ?></h3>
			<?php if ( null === $totals ) : ?>
				<p><?php esc_html_e( 'Sending totals are unavailable.', 'anar-toolkit' ); ?></p>
			<?php else : ?>
				<table class="anar-status-widget__totals">
					<thead>
						<tr>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Reporting period', 'anar-toolkit' ); ?></span></th>
							<?php foreach ( $columns as $label ) : ?>
								<th scope="col"><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $periods as $period => $label ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<?php foreach ( array_keys( $columns ) as $status ) : ?>
									<td><?php echo esc_html( number_format_i18n( $totals[ $period ][ $status ] ?? 0 ) ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( ! $flags['login_log'] && ( $flags['login'] || $flags['reset'] || $flags['two_factor'] ) ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=anar-features-other#feature-login_log' ) ); ?>"><?php esc_html_e( 'Enable the login SMS report to count future login and verification messages.', 'anar-toolkit' ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function credit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled  = Features::enabled( 'balance' );
		$accounts = $enabled ? array_filter(
			Accounts::all(),
			static function ( $account ) {
				return $account['ready'];
			}
		) : array();
		?>
		<div class="anar-credit-summary" role="region" aria-label="<?php esc_attr_e( 'SMS credit', 'anar-toolkit' ); ?>">
			<div class="anar-credit-summary__header">
				<strong><span class="dashicons dashicons-money-alt" aria-hidden="true"></span><?php esc_html_e( 'SMS credit', 'anar-toolkit' ); ?></strong>
				<a href="<?php echo esc_url( BalancePage::url() ); ?>"><?php esc_html_e( 'View balance and alert settings', 'anar-toolkit' ); ?></a>
			</div>
			<?php if ( $accounts ) : ?>
				<ul class="anar-credit-summary__accounts">
					<?php foreach ( $accounts as $key => $account ) : ?>
						<?php self::credit_account( $key, $account['title'] ); ?>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php echo esc_html( $enabled ? __( 'Complete the SMS account connection settings first.', 'anar-toolkit' ) : __( 'SMS credit monitoring is off.', 'anar-toolkit' ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function credit_account( $key, $title ) {
		$state      = Monitor::state( $key );
		$checked_at = (int) ( $state['success_at'] ?? 0 );
		$known      = isset( $state['value'], $state['unit'] ) && $checked_at > 0;
		$now        = time();
		$note       = '';
		$low        = false;
		if ( ! empty( $state['error'] ) ) {
			$note = __( 'The last balance check failed.', 'anar-toolkit' );
		} elseif ( ! $known ) {
			$note = __( 'Not checked yet', 'anar-toolkit' );
		} elseif ( $now - $checked_at > Monitor::FRESH ) {
			$note = __( 'An updated balance is needed.', 'anar-toolkit' );
		} elseif ( Monitor::low( $key, $now ) ) {
			$note = __( 'Low credit', 'anar-toolkit' );
			$low  = true;
		}
		?>
		<li class="anar-credit-summary__account">
			<div class="anar-credit-summary__identity">
				<strong class="anar-credit-summary__provider"><?php echo esc_html( $title ); ?></strong>
				<?php if ( $known ) : ?>
					<span class="anar-credit-summary__amount"><bdi><?php echo esc_html( Monitor::amount( $state['value'] ) ); ?></bdi> <span><?php echo esc_html( Monitor::unit_label( $state['unit'] ) ); ?></span></span>
				<?php endif; ?>
			</div>
			<div class="anar-credit-summary__meta">
				<?php if ( $known ) : ?>
					<time datetime="<?php echo esc_attr( wp_date( DATE_W3C, $checked_at ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' H:i', $checked_at ) ); ?>">
						<?php
						/* translators: %s: elapsed time since the last successful provider balance check. */
						echo esc_html( sprintf( __( 'Last successful check: %s ago', 'anar-toolkit' ), human_time_diff( $checked_at, $now ) ) );
						?>
					</time>
				<?php endif; ?>
				<?php if ( '' !== $note ) : ?>
					<span class="anar-credit-summary__note<?php echo $low ? ' is-low' : ''; ?>"><?php echo esc_html( $note ); ?></span>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}
}
