<?php
/**
 * Administrator-only recovery analytics, with bounded queries and real data.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Dashboard {
	private $repository;

	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	public static function labels() {
		$labels = array(
			'login_otp' => __( 'Login code', 'anar-toolkit' ),
			'password_reset' => __( 'Password recovery', 'anar-toolkit' ),
			'two_factor' => __( 'Two-step verification', 'anar-toolkit' ),
			'game_phone' => __( 'Game phone verification', 'anar-toolkit' ),
			'stock_verification' => __( 'Stock request verification', 'anar-toolkit' ),
			'paid_customer' => __( 'Payment confirmation', 'anar-toolkit' ),
			'paid_admin' => __( 'Paid order to administrators', 'anar-toolkit' ),
			'new_admin' => __( 'New order to administrators', 'anar-toolkit' ),
			'failed_admin' => __( 'Payment failure alert', 'anar-toolkit' ),
			'shipment_customer' => __( 'Shipment tracking', 'anar-toolkit' ),
			'status_processing' => __( 'Order processing', 'anar-toolkit' ),
			'status_completed' => __( 'Order completed', 'anar-toolkit' ),
			'status_on-hold' => __( 'Order on hold', 'anar-toolkit' ),
			'status_cancelled' => __( 'Order cancelled', 'anar-toolkit' ),
			'status_refunded' => __( 'Order refunded', 'anar-toolkit' ),
			'recovery_1' => __( 'Recovery · first reminder', 'anar-toolkit' ),
			'recovery_2' => __( 'Recovery · second reminder', 'anar-toolkit' ),
			'recovery_3' => __( 'Recovery · third reminder', 'anar-toolkit' ),
			'recovery_request' => __( 'Requested recovery code', 'anar-toolkit' ),
		);
		foreach ( array_keys( ( new \Anar\Login\Marketing\Settings() )->defaults()['rules'] ) as $event ) {
			$labels[ 'mkt_' . $event ] = \Anar\Login\Marketing\Engine::label( $event ); }
		return $labels;
	}

	public static function states() {
		return array( 'waiting' => __( 'Waiting for stock', 'anar-toolkit' ), 'queued' => __( 'Queued', 'anar-toolkit' ), 'notified' => __( 'Notified', 'anar-toolkit' ), 'rewarded' => __( 'Reward issued', 'anar-toolkit' ), 'finished' => __( 'Completed', 'anar-toolkit' ), 'started' => __( 'Started', 'anar-toolkit' ), 'paused' => __( 'Paused', 'anar-toolkit' ), 'draft' => __( 'Draft', 'anar-toolkit' ), 'active' => __( 'Open', 'anar-toolkit' ), 'converted' => __( 'Purchased', 'anar-toolkit' ), 'suppressed' => __( 'Stopped', 'anar-toolkit' ), 'expired' => __( 'Expired', 'anar-toolkit' ), 'pending' => __( 'Queued', 'anar-toolkit' ), 'running' => __( 'Sending', 'anar-toolkit' ), 'accepted' => __( 'Accepted by provider', 'anar-toolkit' ), 'failed' => __( 'Failed', 'anar-toolkit' ), 'uncertain' => __( 'Needs investigation', 'anar-toolkit' ), 'cancelled' => __( 'Cancelled', 'anar-toolkit' ) );
	}

	public static function badge( $state ) {
		$states = self::states();
		return '<span class="anar-badge anar-badge--' . esc_attr( sanitize_key( $state ) ) . '"><i aria-hidden="true"></i>' . esc_html( $states[ $state ] ?? $state ) . '</span>';
	}

	public function data( $days = 30, $currency = '' ) {
		global $wpdb;
		$days = in_array( (int) $days, array( 7, 30, 90 ), true ) ? (int) $days : 30;
		$since = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . ( $days - 1 ) . ' days' )->getTimestamp();
		$carts = $this->repository->table( 'carts' );
		$messages = $this->repository->table( 'messages' );
		$currencies = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT currency FROM %i ORDER BY currency LIMIT 30', $carts ) );
		$default = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRR';
		$currency = in_array( $currency, $currencies, true ) || $currency === $default ? $currency : $default;
		$counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) saved, SUM(CASE WHEN status = 'active' AND updated_at < %d THEN 1 ELSE 0 END) abandoned, SUM(CASE WHEN clicked_at >= %d THEN 1 ELSE 0 END) visits FROM %i WHERE created_at >= %d AND currency = %s", time() - 30 * MINUTE_IN_SECONDS, $since, $carts, $since, $currency ) );
		$revenue = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) recovered, COALESCE(SUM(amount),0) revenue FROM (SELECT recovered_order, MAX(recovered_total) amount FROM %i WHERE status = 'converted' AND recovered_order > 0 AND converted_at >= %d AND currency = %s GROUP BY recovered_order) recovered_orders", $carts, $since, $currency ) );
		$queue = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) count FROM %i WHERE created_at >= %d GROUP BY status', $messages, $since ), OBJECT_K );
		$chart = array();
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		for ( $index = min( 14, $days ) - 1; $index >= 0; --$index ) {
			$start = $today->modify( '-' . $index . ' days' );
			$end = $start->modify( '+1 day' );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) saved, SUM(CASE WHEN status = 'converted' AND recovered_order > 0 THEN 1 ELSE 0 END) recovered FROM %i WHERE created_at >= %d AND created_at < %d AND currency = %s", $carts, $start->getTimestamp(), $end->getTimestamp(), $currency ) );
			$chart[] = array( 'date' => wp_date( 'm/d', $start->getTimestamp() ), 'saved' => (int) $row->saved, 'recovered' => (int) $row->recovered );
		}
		return compact( 'days', 'since', 'currency', 'currencies', 'counts', 'revenue', 'queue', 'chart' );
	}

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, administrator-only report filters.
		$days = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30;
		$currency = isset( $_GET['currency'] ) && is_string( $_GET['currency'] ) ? sanitize_text_field( wp_unslash( $_GET['currency'] ) ) : '';
		$page = max( 1, min( 10000, isset( $_GET['report_page'] ) ? absint( $_GET['report_page'] ) : 1 ) );
		$filter = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$tab = \Anar\Login\Infrastructure\Features::current_tab();
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$data = 'overview' === $tab ? $this->data( $days, $currency ) : array( 'days' => $days, 'since' => time() - $days * DAY_IN_SECONDS, 'currency' => $currency ?: ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRR' ) );
		$labels = self::labels();
		$states = self::states();
		$accepted = (int) ( $data['queue']['accepted']->count ?? 0 );
		$pending = (int) ( $data['queue']['pending']->count ?? 0 );
		$failed = (int) ( $data['queue']['failed']->count ?? 0 ) + (int) ( $data['queue']['uncertain']->count ?? 0 );
		?>
		<header class="anar-suite-header"><div class="anar-suite-brand"><img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt=""><div><p>ANAR TOOLKIT <span>v<?php echo esc_html( ANAR_LOGIN_VERSION ); ?></span></p><h1><?php esc_html_e( 'Store communication', 'anar-toolkit' ); ?></h1><p class="anar-suite-subtitle"><?php esc_html_e( 'Manage messages, carts and enabled customer campaigns.', 'anar-toolkit' ); ?></p></div></div><a class="anar-suite-link" href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-settings' ) ); ?>"><span class="dashicons dashicons-admin-settings"></span><?php esc_html_e( 'Settings', 'anar-toolkit' ); ?></a></header>
		<hr class="wp-header-end">
		<?php if ( in_array( $tab, array( 'campaigns', 'messages' ), true ) ) : ?>
			<?php \Anar\Login\Admin\StatusPanel::credit(); ?>
		<?php endif; ?>
		<a class="anar-other-link" href="<?php echo esc_url( admin_url( 'admin.php?page=anar-features-other' ) ); ?>"><?php esc_html_e( 'Other features', 'anar-toolkit' ); ?></a>
		<nav class="anar-suite-tabs" aria-label="<?php esc_attr_e( 'Marketing workspace', 'anar-toolkit' ); ?>">
			<?php foreach ( \Anar\Login\Infrastructure\Features::workspace_tabs() as $key => $label ) : ?>
				<a class="<?php echo $key === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'anar-toolkit-commerce', 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php if ( 'overview' === $tab ) : ?>
		<section class="anar-suite-panel" id="overview" data-suite-panel="overview">
			<div class="anar-suite-toolbar"><div><span class="anar-eyebrow"><?php esc_html_e( 'Overview', 'anar-toolkit' ); ?></span><h2><?php esc_html_e( 'Store overview', 'anar-toolkit' ); ?></h2></div>
			<form method="get"><input type="hidden" name="page" value="anar-toolkit-commerce"><label class="screen-reader-text" for="anar-period"><?php esc_html_e( 'Reporting period', 'anar-toolkit' ); ?></label><select name="period" id="anar-period">
			<?php
			foreach ( array( 7, 30, 90 ) as $period ) :
				?>
				<option value="<?php echo esc_attr( $period ); ?>" <?php selected( $data['days'], $period ); ?>><?php echo esc_html( sprintf( /* translators: %d: days. */ __( 'Last %d days', 'anar-toolkit' ), $period ) ); ?></option><?php endforeach; ?></select><label class="screen-reader-text" for="anar-currency"><?php esc_html_e( 'Currency', 'anar-toolkit' ); ?></label><select name="currency" id="anar-currency">
				<?php
				foreach ( array_unique( array_merge( $data['currencies'], array( $data['currency'] ) ) ) as $unit ) :
					?>
				<option <?php selected( $data['currency'], $unit ); ?>><?php echo esc_html( $unit ); ?></option><?php endforeach; ?></select><button class="button"><?php esc_html_e( 'Apply', 'anar-toolkit' ); ?></button></form></div>
			<div class="anar-kpis">
				<?php
				$cards = array(
					array( __( 'Recovered revenue', 'anar-toolkit' ), number_format_i18n( (float) $data['revenue']->revenue, 0 ), $data['currency'], 'money', 'hero' ),
					array( __( 'Recovered purchases', 'anar-toolkit' ), number_format_i18n( (int) $data['revenue']->recovered ), __( 'Paid after verified recovery', 'anar-toolkit' ), 'cart', '' ),
					array( __( 'Open abandoned carts', 'anar-toolkit' ), number_format_i18n( (int) $data['counts']->abandoned ), __( 'Inactive for at least 30 minutes', 'anar-toolkit' ), 'clock', '' ),
					array( __( 'SMS requests accepted', 'anar-toolkit' ), number_format_i18n( $accepted ), __( 'Provider acceptance, not delivery', 'anar-toolkit' ), 'email-alt', '' ),
				);
				foreach ( $cards as $card ) :
					?>
					<article class="anar-kpi <?php echo esc_attr( $card[4] ); ?>"><span class="anar-kpi-icon dashicons dashicons-<?php echo esc_attr( $card[3] ); ?>" aria-hidden="true"></span><p><?php echo esc_html( $card[0] ); ?></p><strong><?php echo esc_html( $card[1] ); ?></strong><small><?php echo esc_html( $card[2] ); ?></small></article>
				<?php endforeach; ?>
			</div>
			<div class="anar-analytics-grid"><article class="anar-commerce-card anar-trend"><div class="anar-card-heading"><div><span class="anar-eyebrow"><?php esc_html_e( 'Cart recovery', 'anar-toolkit' ); ?></span><h2><?php esc_html_e( 'Saved carts and returning purchases', 'anar-toolkit' ); ?></h2></div><span class="anar-chart-legend"><i></i><?php esc_html_e( 'Saved', 'anar-toolkit' ); ?><i class="second"></i><?php esc_html_e( 'Purchased', 'anar-toolkit' ); ?></span></div>
				<?php $this->chart( $data['chart'] ); ?>
				<p class="anar-footnote"><?php esc_html_e( 'Purchases in this chart are grouped by the original cart date. Revenue cards use the payment date. Results describe attribution, not guaranteed extra sales.', 'anar-toolkit' ); ?></p>
			</article><article class="anar-commerce-card anar-message-summary"><span class="anar-eyebrow"><?php esc_html_e( 'Message status', 'anar-toolkit' ); ?></span><h2><?php esc_html_e( 'Your outgoing messages', 'anar-toolkit' ); ?></h2>
				<?php
				$total = max( 1, $accepted + $pending + $failed );
				$degree = round( $accepted * 360 / $total );
				?>
				<div class="anar-donut" style="--anar-sweep:<?php echo esc_attr( $degree ); ?>deg"><div><strong><?php echo esc_html( number_format_i18n( $accepted + $pending + $failed ) ); ?></strong><span><?php esc_html_e( 'requests', 'anar-toolkit' ); ?></span></div></div>
				<dl class="anar-health-list"><div><dt><?php esc_html_e( 'Accepted by provider', 'anar-toolkit' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $accepted ) ); ?></dd></div><div><dt><?php esc_html_e( 'Queued', 'anar-toolkit' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $pending ) ); ?></dd></div><div><dt><?php esc_html_e( 'Failed / uncertain', 'anar-toolkit' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $failed ) ); ?></dd></div></dl>
			</article></div>
			<div class="anar-suite-note"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><p><?php esc_html_e( 'Your data stays in this WordPress installation. Reports cover the configured retention period; currencies are never added together. No sample revenue is displayed.', 'anar-toolkit' ); ?></p></div>
		</section>
		<?php endif; if ( 'carts' === $tab ) : ?>
		<section class="anar-suite-panel" id="carts" data-suite-panel="carts"><div class="anar-suite-toolbar"><div><span class="anar-eyebrow"><?php esc_html_e( 'Cart records', 'anar-toolkit' ); ?></span><h2><?php esc_html_e( 'Saved carts', 'anar-toolkit' ); ?></h2></div></div><?php $this->table( 'carts', $page, $filter, $data ); ?></section>
		<?php endif; if ( 'messages' === $tab ) : ?>
		<section class="anar-suite-panel" id="messages" data-suite-panel="messages"><div class="anar-suite-toolbar"><div><span class="anar-eyebrow"><?php esc_html_e( 'Message log', 'anar-toolkit' ); ?></span><h2><?php esc_html_e( 'Message log', 'anar-toolkit' ); ?></h2></div></div><p class="anar-footnote"><?php esc_html_e( 'Accepted means the gateway accepted the request. Uncertain requests are never automatically resent. Login codes, credentials and full message contents are not stored in this report.', 'anar-toolkit' ); ?></p><?php $this->table( 'messages', $page, $filter, $data ); ?></section><?php endif; ?>
		<?php
	}

	private function chart( $rows ) {
		$max = max( 1, ...array_column( $rows, 'saved' ) );
		?>
		<div class="anar-chart" role="img" aria-label="<?php esc_attr_e( 'Daily saved carts and purchases. Exact counts are available in the table below.', 'anar-toolkit' ); ?>">
			<?php foreach ( $rows as $row ) : ?>
				<div class="anar-chart-column"><div class="anar-chart-bars"><span class="anar-chart-bar" style="height:<?php echo esc_attr( round( $row['saved'] * 100 / $max ) ); ?>%" title="<?php echo esc_attr( $row['saved'] ); ?>"></span><span class="anar-chart-bar second" style="height:<?php echo esc_attr( round( $row['recovered'] * 100 / $max ) ); ?>%" title="<?php echo esc_attr( $row['recovered'] ); ?>"></span></div><small><?php echo esc_html( $row['date'] ); ?></small></div>
			<?php endforeach; ?>
		</div><details class="anar-chart-details"><summary><?php esc_html_e( 'View exact daily counts', 'anar-toolkit' ); ?></summary><table class="widefat"><thead><tr><th><?php esc_html_e( 'Date', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Saved carts', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Purchased', 'anar-toolkit' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr><td><?php echo esc_html( $row['date'] ); ?></td><td><?php echo esc_html( $row['saved'] ); ?></td><td><?php echo esc_html( $row['recovered'] ); ?></td></tr><?php endforeach; ?></tbody></table></details>
		<?php
	}

	private function table( $kind, $page, $state, $data ) {
		global $wpdb;
		$table = $this->repository->table( $kind );
		$states = self::states();
		$state = isset( $states[ $state ] ) ? $state : '';
		$where = $wpdb->prepare( 'created_at >= %d', $data['since'] );
		if ( $state ) {
			$where .= $wpdb->prepare( ' AND status = %s', $state ); }
		if ( 'carts' === $kind ) {
			$where .= $wpdb->prepare( ' AND currency = %s', $data['currency'] ); }
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is assembled exclusively from prepared clauses above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $table ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE $where ORDER BY id DESC LIMIT 20 OFFSET %d", $table, ( $page - 1 ) * 20 ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$labels = self::labels();
		?>
		<form class="anar-table-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>"><input type="hidden" name="page" value="anar-toolkit-commerce"><input type="hidden" name="tab" value="<?php echo esc_attr( $kind ); ?>"><input type="hidden" name="period" value="<?php echo esc_attr( $data['days'] ); ?>"><input type="hidden" name="currency" value="<?php echo esc_attr( $data['currency'] ); ?>"><label><?php esc_html_e( 'Status', 'anar-toolkit' ); ?> <select name="state"><option value=""><?php esc_html_e( 'All statuses', 'anar-toolkit' ); ?></option>
		<?php
		foreach ( $states as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $state, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><button class="button"><?php esc_html_e( 'Filter', 'anar-toolkit' ); ?></button><span><?php echo esc_html( number_format_i18n( $total ) ); ?> <?php esc_html_e( 'records', 'anar-toolkit' ); ?></span></form>
		<div class="anar-commerce-card anar-table-card"><div class="anar-commerce-table"><table><thead><tr><th><?php esc_html_e( 'Reference', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Customer', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Status', 'anar-toolkit' ); ?></th><th><?php echo 'carts' === $kind ? esc_html__( 'Value / order', 'anar-toolkit' ) : esc_html__( 'Provider / result', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Last activity', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Action', 'anar-toolkit' ); ?></th></tr></thead><tbody>
			<?php
			foreach ( $rows as $row ) :
				$contact = 'carts' === $kind ? $this->repository->contact( $row->contact_id ) : null;
				?>
				<tr><td><strong><?php echo esc_html( 'carts' === $kind ? '#' . $row->id : ( $labels[ $row->event ] ?? $row->event ) ); ?></strong>
				<?php
				if ( 'carts' === $kind && $row->coupon_id && class_exists( 'WC_Coupon' ) ) :
					?>
					<small><?php echo esc_html( ( new \WC_Coupon( $row->coupon_id ) )->get_code() ); ?></small><?php endif; ?></td><td><span dir="ltr"><?php echo esc_html( Identity::mask( 'carts' === $kind ? ( $contact->phone ?? '' ) : $row->recipient ) ); ?></span></td><td><?php echo self::badge( $row->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes all values. ?></td><td>
					<?php
					if ( 'carts' === $kind ) :
						?>
										<?php echo esc_html( number_format_i18n( (float) $row->total, 0 ) . ' ' . $row->currency ); ?>
						<?php
						if ( $row->order_id && function_exists( 'wc_get_order' ) && wc_get_order( $row->order_id ) ) :
							?>
	<small><a href="<?php echo esc_url( wc_get_order( $row->order_id )->get_edit_order_url() ); ?>">#<?php echo esc_html( $row->order_id ); ?></a></small><?php endif; ?>
						<?php
else :
	?>
	<?php echo esc_html( $row->provider ?: '—' ); ?><small><code><?php echo esc_html( $row->error_code ); ?></code></small><?php endif; ?></td><td><?php echo esc_html( wp_date( 'Y/m/d H:i', $row->updated_at ) ); ?></td><td>
				<?php
				if ( 'messages' === $kind && $row->object_id > 0 && 'failed' === $row->status && $row->attempts < 3 && 'login_otp' !== $row->event && 'password_reset' !== $row->event ) :
					?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_toolkit_retry' ); ?><input type="hidden" name="action" value="anar_toolkit_retry"><input type="hidden" name="message_id" value="<?php echo esc_attr( $row->id ); ?>"><button class="button"><?php esc_html_e( 'Retry', 'anar-toolkit' ); ?></button></form>
					<?php
else :
	?>
	—<?php endif; ?></td></tr>
			<?php endforeach; ?>
			<?php
			if ( ! $rows ) :
				?>
				<tr><td colspan="6"><div class="anar-empty-state"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span><h3><?php esc_html_e( 'No records', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'No records match this period and filter. Real activity will appear here as your automations run.', 'anar-toolkit' ); ?></p></div></td></tr><?php endif; ?>
		</tbody></table></div></div>
		<div class="anar-pagination">
		<?php
		foreach ( array( $page - 1 => __( 'Previous', 'anar-toolkit' ), $page + 1 => __( 'Next', 'anar-toolkit' ) ) as $target => $label ) :
			if ( $target < 1 || ( $target - 1 ) * 20 >= $total ) {
				continue; }
			?>
			<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'anar-toolkit-commerce', 'period' => $data['days'], 'currency' => $data['currency'], 'state' => $state, 'report_page' => $target, 'tab' => $kind ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></div>
		<?php
	}
}
