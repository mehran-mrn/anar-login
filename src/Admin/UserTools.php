<?php
/**
 * A manual preview, then one explicit account-phone import action.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Support\Identity;
use Anar\Login\Support\UserMigration;

defined( 'ABSPATH' ) || exit;

final class UserTools {

	public function register() {
		( new DigitsNotice() )->register();
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_anar_user_tools', array( $this, 'request' ) );
	}

	public function menu() {
		add_submenu_page( 'anar-login', __( 'User tools', 'anar-toolkit' ), __( 'User tools', 'anar-toolkit' ), 'manage_options', 'anar-login-user-tools', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, '_page_anar-login-user-tools' ) ) {
			return;
		}
		wp_enqueue_style( 'anar-user-tools', ANAR_LOGIN_URL . 'assets/css/user-tools.css', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-user-tools', ANAR_LOGIN_URL . 'assets/js/user-tools.js', array(), ANAR_LOGIN_VERSION, true );
		wp_localize_script(
			'anar-user-tools',
			'AnarUserTools',
			array(
				'url' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'anar-user-tools' ),
				'strings' => array(
					'working' => __( 'Checking accounts…', 'anar-toolkit' ),
					'scanning' => __( 'Accounts checked:', 'anar-toolkit' ),
					'importing' => __( 'Transferring eligible mobile numbers…', 'anar-toolkit' ),
					'finished' => __( 'Check complete. Review the result below.', 'anar-toolkit' ),
					'imported' => __( 'Transfer finished. Existing accounts and Digits data were preserved.', 'anar-toolkit' ),
					'confirm' => __( 'Transfer only the eligible mobile numbers shown in this preview? Accounts, passwords, roles and orders will stay unchanged.', 'anar-toolkit' ),
					'failed' => __( 'The request did not finish. Check the accounts again before retrying a transfer.', 'anar-toolkit' ),
					'empty' => __( 'No account phone records were found.', 'anar-toolkit' ),
					'edit' => __( 'Review user', 'anar-toolkit' ),
					'progress' => __( 'Page', 'anar-toolkit' ),
				),
			)
		);
	}

	private static function allowed() {
		return current_user_can( 'manage_options' ) && current_user_can( 'list_users' );
	}

	public function render() {
		if ( ! self::allowed() ) {
			return;
		}
		$migrate = current_user_can( 'edit_users' ) && UserMigration::available();
		if ( $migrate ) {
			update_option( DigitsNotice::DETECTED, 'yes', true );
		}
		$summary = array( 'review' => __( 'Need review', 'anar-toolkit' ), 'linked' => __( 'Already linked to Anar', 'anar-toolkit' ) );
		if ( $migrate ) {
			$summary = array( 'review' => __( 'Need review', 'anar-toolkit' ), 'ready' => __( 'Ready to transfer', 'anar-toolkit' ), 'linked' => __( 'Already linked to Anar', 'anar-toolkit' ), 'imported' => __( 'Transferred', 'anar-toolkit' ) );
		}
		Workspace::start( __( 'User tools', 'anar-toolkit' ) );
		?>
		<div class="anar-user-tools" data-user-tools>
			<section class="anar-card">
				<h2><?php esc_html_e( 'Check account mobile numbers', 'anar-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Find shared mobile numbers, inconsistent account records and numbers that need attention. Checking does not change any user data.', 'anar-toolkit' ); ?></p>
				<p class="description"><?php esc_html_e( 'Anar and Digits phone records are checked automatically. Billing numbers are not used to identify an account.', 'anar-toolkit' ); ?></p>
				<button type="button" class="button button-primary" data-user-scan><?php esc_html_e( 'Check mobile numbers', 'anar-toolkit' ); ?></button>
			</section>
			<?php if ( $migrate ) : ?>
			<section class="anar-card" data-digits-migration>
				<h2><?php esc_html_e( 'Move from Digits to Anar', 'anar-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'Digits mobile numbers were found. Check the accounts first, then transfer only the numbers with no conflicts.', 'anar-toolkit' ); ?></p>
				<p class="description"><?php esc_html_e( 'Users keep the same accounts, passwords and orders. Digits data is retained. Keep a database backup before transferring.', 'anar-toolkit' ); ?></p>
				<button type="button" class="button" data-user-migrate disabled><?php esc_html_e( 'Transfer eligible numbers', 'anar-toolkit' ); ?></button>
			</section>
			<?php endif; ?>
			<p class="anar-user-tools__status" role="status" aria-live="polite" data-user-status></p>
			<section class="anar-card" data-user-report hidden>
				<h2><?php esc_html_e( 'Check result', 'anar-toolkit' ); ?></h2>
				<dl class="anar-user-tools__summary">
					<?php foreach ( $summary as $key => $label ) : ?>
					<div><dt><?php echo esc_html( $label ); ?></dt><dd data-user-count="<?php echo esc_attr( $key ); ?>">0</dd></div>
					<?php endforeach; ?>
				</dl>
				<p><?php esc_html_e( 'Conflicts and changed records are held for review. Anar never merges accounts or chooses an owner for a shared number.', 'anar-toolkit' ); ?></p>
				<div class="anar-user-tools__table"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'User', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Result', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Review', 'anar-toolkit' ); ?></th></tr></thead><tbody data-user-rows></tbody></table></div>
				<div class="anar-user-tools__pages"><button type="button" class="button" data-user-prev><?php esc_html_e( 'Previous', 'anar-toolkit' ); ?></button><span data-user-page></span><button type="button" class="button" data-user-next><?php esc_html_e( 'Next', 'anar-toolkit' ); ?></button></div>
			</section>
		</div>
		<?php
		Workspace::end();
	}

	public function request() {
		if ( ! self::allowed() ) {
			wp_send_json_error( array( 'message' => __( 'You cannot manage account mobile numbers.', 'anar-toolkit' ) ), 403 );
		}
		check_ajax_referer( 'anar-user-tools', 'nonce' );
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( 'start' === $operation ) {
			$token = wp_generate_password( 24, false, false );
			$job = UserMigration::start();
			$job['migration'] = $job['migration'] && current_user_can( 'edit_users' );
		} else {
			if ( ! preg_match( '/^[a-zA-Z0-9]{24}$/D', $token ) ) {
				wp_send_json_error( array( 'message' => __( 'This preview expired. Check the accounts again.', 'anar-toolkit' ) ), 409 );
			}
			$job = false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'anar_login_limits';
		$lock = hash( 'sha256', self::key( $token ) );
		$owner = random_int( 1, 2147483647 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND expires_at <= %d', $table, $lock, time() ) );
		if ( 1 !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (limit_key, attempts, expires_at) VALUES (%s, %d, %d)', $table, $lock, $owner, time() + 5 * MINUTE_IN_SECONDS ) ) ) {
			wp_send_json_error( array( 'message' => __( 'A check is already running. Please wait.', 'anar-toolkit' ) ), 409 );
		}
		$error = '';
		try {
			if ( 'start' !== $operation ) {
				$job = get_transient( self::key( $token ) );
			}
			if ( ! is_array( $job ) ) {
				$error = __( 'This preview expired. Check the accounts again.', 'anar-toolkit' );
			} elseif ( in_array( $operation, array( 'start', 'scan' ), true ) && ! $job['applying'] ) {
				$job = UserMigration::scan( $job );
			} elseif ( 'apply' === $operation ) {
				$confirmed = isset( $_POST['confirmed'] ) && '1' === $_POST['confirmed'];
				$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
				if ( ! $confirmed || ! current_user_can( 'edit_users' ) || ! $job['migration'] || get_option( UserMigration::COMPLETE, false ) || ! $job['done'] || $job['applied'] || $offset !== $job['offset'] ) {
					$error = __( 'The accounts changed or this transfer is no longer available. Check again.', 'anar-toolkit' );
				} else {
					$job = UserMigration::apply( $job );
				}
			} elseif ( 'report' !== $operation ) {
				$error = __( 'This action is not available.', 'anar-toolkit' );
			}
			if ( is_array( $job ) ) {
				set_transient( self::key( $token ), $job, 20 * MINUTE_IN_SECONDS );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND attempts = %d', $table, $lock, $owner ) );
		}
		if ( $error ) {
			wp_send_json_error( array( 'message' => $error ), 409 );
		}
		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
		wp_send_json_success( self::report( $job, $token, $page ) );
	}

	private static function key( $token ) {
		return 'anar_user_tools_' . get_current_user_id() . '_' . hash( 'sha256', $token );
	}

	public static function report( $job, $token, $page = 1 ) {
		$labels = array(
			'conflict' => __( 'Shared by multiple accounts — review required', 'anar-toolkit' ),
			'different' => __( 'Anar and Digits numbers differ — kept unchanged', 'anar-toolkit' ),
			'invalid' => __( 'Invalid, unsupported or inconsistent mobile number', 'anar-toolkit' ),
			'unverified' => __( 'Digits verification was skipped — not transferred', 'anar-toolkit' ),
			'changed' => __( 'Account changed after preview — check again', 'anar-toolkit' ),
			'ready' => __( 'Ready to transfer', 'anar-toolkit' ),
			'imported' => __( 'Transferred', 'anar-toolkit' ),
			'linked' => __( 'Already linked to Anar', 'anar-toolkit' ),
		);
		if ( ! $job['migration'] ) {
			$labels['ready'] = __( 'Digits record — kept unchanged', 'anar-toolkit' );
		}
		$order = array_flip( array_keys( $labels ) );
		$rows = $job['done'] ? $job['rows'] : array();
		uasort(
			$rows,
			static function ( $left, $right ) use ( $order ) {
				return $order[ $left['status'] ] <=> $order[ $right['status'] ];
			}
		);
		$pages = max( 1, (int) ceil( count( $rows ) / 25 ) );
		$page = min( $pages, max( 1, $page ) );
		$data = array();
		foreach ( array_slice( $rows, ( $page - 1 ) * 25, 25, true ) as $id => $row ) {
			$user = get_userdata( $id );
			$data[] = array(
				'id' => (int) $id,
				'name' => $user ? $user->display_name : __( 'Deleted account', 'anar-toolkit' ),
				'phone' => implode( ', ', array_map( array( Identity::class, 'local_phone' ), $row['phones'] ) ),
				'status' => $labels[ $row['status'] ],
				'url' => $user && current_user_can( 'edit_user', $id ) ? get_edit_user_link( $id ) : '',
			);
		}
		return array(
			'token' => $token,
			'done' => $job['done'],
			'scanned' => $job['scanned'],
			'counts' => UserMigration::counts( $job ),
			'offset' => $job['offset'],
			'applied' => $job['applied'],
			'migration' => $job['migration'] && ! get_option( UserMigration::COMPLETE, false ),
			'page' => $page,
			'pages' => $pages,
			'rows' => $data,
		);
	}
}
