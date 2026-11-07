<?php
/**
 * Cached SMS credit and administrator-controlled low-credit warnings.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Balance\Accounts;
use Anar\Login\Balance\Gateways;
use Anar\Login\Balance\Monitor;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Partners\MeliSignup;

defined( 'ABSPATH' ) || exit;

final class BalancePage {
	public function register() {
		add_action( 'admin_post_anar_balance_save', array( $this, 'save' ) );
		add_action( 'admin_post_anar_balance_refresh', array( $this, 'refresh' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	public static function url() {
		return admin_url( 'admin.php?page=anar-toolkit-balance' );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'anar-toolkit-balance' ) ) {
			return;
		}
		wp_enqueue_style( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/css/admin.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_style( 'anar-balance', ANAR_LOGIN_URL . 'assets/css/balance.css', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-balance', ANAR_LOGIN_URL . 'assets/js/balance.js', array(), ANAR_LOGIN_VERSION, true );
		wp_localize_script( 'anar-balance', 'AnarBalance', array( 'checking' => __( 'Checking balance…', 'anar-toolkit' ), 'copied' => __( 'Copied ✓', 'anar-toolkit' ), 'failed' => __( 'Copy failed', 'anar-toolkit' ) ) );
	}

	private function allowed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ), '', array( 'response' => 403 ) );
		}
	}

	public function save() {
		$this->allowed();
		check_admin_referer( 'anar_balance_save' );
		$input = isset( $_POST['balance'] ) && is_array( $_POST['balance'] ) ? wp_unslash( $_POST['balance'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Fixed allowlist, booleans, unit and numeric validation below.
		$settings = Monitor::sanitize( $input );
		if ( is_wp_error( $settings ) ) {
			wp_safe_redirect( add_query_arg( 'balance_result', 'threshold', self::url() ) );
			exit;
		}
		update_option( Monitor::OPTION, $settings, false );
		Monitor::sync();
		wp_safe_redirect( add_query_arg( 'balance_result', 'saved', self::url() ) );
		exit;
	}

	public function refresh() {
		$this->allowed();
		$provider = isset( $_POST['provider'] ) && is_string( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		check_admin_referer( 'anar_balance_refresh_' . $provider );
		$result = ( new Monitor() )->refresh( $provider );
		$code = is_wp_error( $result ) ? $result->get_error_code() : 'checked';
		wp_safe_redirect( add_query_arg( 'balance_result', $code, self::url() ) . '#balance-' . rawurlencode( $provider ) );
		exit;
	}

	public function notices() {
		if ( ! current_user_can( 'manage_options' ) || ! Features::enabled( 'balance' ) ) {
			return;
		}
		$low = array();
		foreach ( Accounts::all() as $provider => $account ) {
			if ( Monitor::low( $provider ) ) {
				$low[] = $account['title'] . ': ' . Monitor::formatted( Monitor::state( $provider ) );
			}
		}
		if ( ! $low ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible"><p><strong><?php esc_html_e( 'Anar: SMS credit is running low.', 'anar-toolkit' ); ?></strong> <?php echo esc_html( implode( ' / ', $low ) ); ?> — <a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'View balance and alert settings', 'anar-toolkit' ); ?></a></p></div>
		<?php
	}

	private static function result_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display code; account data always comes from the validated stored record.
		$code = isset( $_GET['balance_result'] ) && is_string( $_GET['balance_result'] ) ? sanitize_key( wp_unslash( $_GET['balance_result'] ) ) : '';
		$messages = array( 'saved' => __( 'Balance settings saved.', 'anar-toolkit' ), 'checked' => __( 'The account balance was updated.', 'anar-toolkit' ), 'threshold' => __( 'Retrieve the account balance first, then enter a valid alert threshold in the displayed unit.', 'anar-toolkit' ), 'anar_balance_wait' => __( 'Please wait one minute before checking this account again.', 'anar-toolkit' ), 'anar_balance_off' => __( 'Enable SMS credit monitoring before checking an account.', 'anar-toolkit' ), 'anar_balance_busy' => __( 'A balance check is already in progress.', 'anar-toolkit' ) );
		if ( ! $code ) {
			return;
		}
		$message = $messages[ $code ] ?? __( 'Could not retrieve the balance. Check the account connection or try again later.', 'anar-toolkit' );
		?>
		<div class="notice <?php echo in_array( $code, array( 'saved', 'checked' ), true ) ? 'notice-success' : 'notice-warning'; ?>"><p><?php echo esc_html( $message ); ?></p></div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$accounts = Accounts::all();
		$enabled = Features::enabled( 'balance' );
		$active = array();
		$other = array();
		foreach ( $accounts as $key => $account ) {
			if ( ! empty( $account['uses'] ) || ! empty( Monitor::account( $key )['monitor'] ) ) {
				$active[ $key ] = $account;
			} else {
				$other[ $key ] = $account;
			}
		}
		if ( ! $active ) {
			foreach ( $other as $key => $account ) {
				if ( $account['ready'] ) {
					$active[ $key ] = $account;
					unset( $other[ $key ] );
				}
			}
		}
		?>
		<div class="wrap anar-admin anar-balance" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<header class="anar-admin__header"><div class="anar-admin__title"><img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt=""><div><div><h1><?php esc_html_e( 'SMS credit', 'anar-toolkit' ); ?></h1><span>v<?php echo esc_html( ANAR_LOGIN_VERSION ); ?></span></div><p><?php esc_html_e( 'Keep track of your messaging credit before it runs out.', 'anar-toolkit' ); ?></p></div></div></header><hr class="wp-header-end">
			<?php self::result_notice(); ?>
			<form id="anar-balance-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="anar_balance_save"><?php wp_nonce_field( 'anar_balance_save' ); ?>
				<section class="anar-balance-intro"><div><h2><?php esc_html_e( 'Balance monitoring', 'anar-toolkit' ); ?> <?php Help::button( Help::text( 'balance' ) ); ?></h2><p><?php esc_html_e( 'Enable this feature, check an account, then set its alert threshold. Periodic checks contact only the accounts you select, about every 30 minutes.', 'anar-toolkit' ); ?></p></div><label class="anar-balance-switch"><input type="checkbox" name="balance[enabled]" value="1" <?php checked( $enabled ); ?>><?php esc_html_e( 'Enable SMS credit monitoring', 'anar-toolkit' ); ?></label></section>
				<?php
				if ( $active ) :
					?>
					<div class="anar-balance-grid">
					<?php
					foreach ( $active as $key => $account ) {
										self::card( $key, $account, $enabled ); }
					?>
</div>
					<?php
else :
	?>
					<div class="anar-balance-empty"><p><?php esc_html_e( 'Configure an SMS account in connection settings, then check its balance here.', 'anar-toolkit' ); ?></p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-settings&section=sms' ) ); ?>"><?php esc_html_e( 'Connection settings', 'anar-toolkit' ); ?></a></div><?php endif; ?>
				<?php
				if ( $other ) :
					?>
					<details class="anar-balance-other"><summary><?php esc_html_e( 'Other SMS accounts', 'anar-toolkit' ); ?> <span><?php echo esc_html( count( $other ) ); ?></span></summary><div class="anar-balance-grid">
					<?php
					foreach ( $other as $key => $account ) {
										self::card( $key, $account, $enabled ); }
					?>
</div></details><?php endif; ?>
				<footer class="anar-balance-footer"><?php submit_button( __( 'Save balance settings', 'anar-toolkit' ), 'primary', 'submit', false ); ?><p><?php esc_html_e( 'Turning monitoring off stops scheduled checks and warnings. Sending SMS and saved connection settings are preserved.', 'anar-toolkit' ); ?></p></footer>
			</form>
			<?php foreach ( $accounts as $key => $account ) : ?>
			<form id="anar-balance-refresh-<?php echo esc_attr( $key ); ?>" class="anar-balance-refresh" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="anar_balance_refresh"><input type="hidden" name="provider" value="<?php echo esc_attr( $key ); ?>"><?php wp_nonce_field( 'anar_balance_refresh_' . $key ); ?></form>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function card( $key, $account, $enabled ) {
		$config = Monitor::account( $key );
		$state = Monitor::state( $key );
		$known = isset( $state['value'], $state['unit'], $state['success_at'] );
		$low = Monitor::low( $key );
		$prefix = 'balance[accounts][' . $key . ']';
		$status = __( 'Not checked yet', 'anar-toolkit' );
		if ( ! $account['supported'] ) {
			$status = __( 'This gateway has no standard balance endpoint.', 'anar-toolkit' );
		} elseif ( ! $account['ready'] ) {
			$status = __( 'Connection settings are incomplete.', 'anar-toolkit' );
		} elseif ( ! empty( $state['error'] ) ) {
			$status = __( 'The last balance check failed.', 'anar-toolkit' );
		} elseif ( $known ) {
			$status = time() - $state['success_at'] > Monitor::FRESH ? __( 'An updated balance is needed.', 'anar-toolkit' ) : ( $low ? __( 'Low credit', 'anar-toolkit' ) : __( 'Balance received', 'anar-toolkit' ) );
		}
		?>
		<article id="balance-<?php echo esc_attr( $key ); ?>" class="anar-balance-card <?php echo $low ? 'is-low' : ''; ?>">
			<div class="anar-balance-card__head"><h2><?php echo esc_html( $account['title'] ); ?></h2><span class="anar-balance-status"><?php echo esc_html( $status ); ?></span></div>
			<p class="anar-balance-uses"><?php echo esc_html( $account['uses'] ? implode( ' / ', $account['uses'] ) : __( 'No active sending module uses this account.', 'anar-toolkit' ) ); ?></p>
			<div class="anar-balance-value"><small><?php esc_html_e( 'Last available credit', 'anar-toolkit' ); ?></small><strong><?php echo esc_html( $known ? Monitor::amount( $state['value'] ) : '—' ); ?></strong><em><?php echo esc_html( $known ? Monitor::unit_label( $state['unit'] ) : '' ); ?></em><span>
			<?php
			if ( $known ) {
				/* translators: %s: date and time of the last successful provider balance check. */
				echo esc_html( sprintf( __( 'Last successful check: %s', 'anar-toolkit' ), wp_date( get_option( 'date_format' ) . ' H:i', $state['success_at'] ) ) );
			} else {
				esc_html_e( 'Check this account to see its balance and unit.', 'anar-toolkit' ); }
			?>
			</span></div>
			<div class="anar-balance-actions"><button type="submit" class="button" form="anar-balance-refresh-<?php echo esc_attr( $key ); ?>" <?php disabled( ! $enabled || ! $account['ready'] ); ?>><?php esc_html_e( 'Check balance', 'anar-toolkit' ); ?></button><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-settings&section=sms' ) ); ?>"><?php esc_html_e( 'Connection settings', 'anar-toolkit' ); ?></a>
			<?php
			if ( Accounts::portal( $key ) ) :
				?>
				<a href="<?php echo esc_url( Accounts::portal( $key ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open SMS panel', 'anar-toolkit' ); ?></a><?php endif; ?></div>
			<?php
			if ( 'farazsms' === $key ) :
				?>
				<p class="anar-balance-note"><?php esc_html_e( 'Faraz balance checks use the new API key for this same account. Enter it in connection settings; the sending password is kept separately.', 'anar-toolkit' ); ?></p><?php endif; ?>
			<?php
			if ( 'melipayamak' === $key ) :
				?>
				<div class="anar-balance-coupon"><span><?php esc_html_e( 'Account top-up discount code', 'anar-toolkit' ); ?></span><code><?php echo esc_html( MeliSignup::COUPON ); ?></code><button type="button" class="button" data-balance-copy="<?php echo esc_attr( MeliSignup::COUPON ); ?>"><?php esc_html_e( 'Copy discount code', 'anar-toolkit' ); ?></button></div><?php endif; ?>
			<?php if ( $account['supported'] ) : ?>
			<div class="anar-balance-controls"><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[monitor]" value="1" <?php checked( $config['monitor'] ); ?> <?php disabled( ! $account['ready'] ); ?>><?php esc_html_e( 'Check this account periodically', 'anar-toolkit' ); ?></label><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[warn]" value="1" <?php checked( $config['warn'] ); ?> <?php disabled( ! $known || ! $account['ready'] ); ?>><?php esc_html_e( 'Show low-credit warnings to site administrators', 'anar-toolkit' ); ?></label>
			<label for="anar-balance-threshold-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Alert threshold', 'anar-toolkit' ); ?></label><div class="anar-balance-threshold"><input id="anar-balance-threshold-<?php echo esc_attr( $key ); ?>" type="text" inputmode="decimal" dir="ltr" maxlength="24" name="<?php echo esc_attr( $prefix ); ?>[threshold]" value="<?php echo esc_attr( $config['threshold'] ); ?>" <?php disabled( ! $known || ! $account['ready'] ); ?>><span><?php echo esc_html( Monitor::unit_label( $state['unit'] ?? 'CREDIT' ) ); ?></span></div><input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[unit]" value="<?php echo esc_attr( $state['unit'] ?? '' ); ?>"><p><?php esc_html_e( 'A warning appears at this balance or below. Use the displayed account unit; the value is not converted between currencies or SMS units.', 'anar-toolkit' ); ?></p>
				<?php
				if ( $known && $config['warn'] && $config['unit'] !== $state['unit'] ) :
					?>
					<p class="anar-balance-note"><?php esc_html_e( 'The provider balance unit changed. Save the alert threshold again to confirm the new unit.', 'anar-toolkit' ); ?></p><?php endif; ?></div>
			<?php endif; ?>
		</article>
		<?php
	}
}
