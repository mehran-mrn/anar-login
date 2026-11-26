<?php
/**
 * One reminder per administrator, backed by a cached one-time source check.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Support\UserMigration;

defined( 'ABSPATH' ) || exit;

final class DigitsNotice {

	const DETECTED = 'anar_digits_migration_detected';
	const SEEN = '_anar_digits_migration_notice_seen';
	const CLAIM = 'anar_digits_notice_seen_';

	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'activated_plugin', array( $this, 'activated' ) );
	}

	public function activated( $plugin ) {
		if ( 'digit.php' === basename( $plugin ) ) {
			// A newly activated Digits installation may have source data to check.
			delete_option( self::DETECTED );
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'list_users' ) || ! current_user_can( 'edit_users' ) || get_option( UserMigration::COMPLETE, false ) || get_user_meta( get_current_user_id(), self::SEEN, true ) ) {
			return;
		}
		$detected = get_option( self::DETECTED, null );
		if ( null === $detected ) {
			$detected = UserMigration::available() ? 'yes' : 'no';
			update_option( self::DETECTED, $detected, true );
		}
		if ( 'yes' !== $detected ) {
			return;
		}
		// Option names are unique: simultaneous admin tabs must not show two reminders.
		if ( ! add_option( self::CLAIM . get_current_user_id(), time(), '', false ) ) {
			update_user_meta( get_current_user_id(), self::SEEN, 1 );
			return;
		}
		update_user_meta( get_current_user_id(), self::SEEN, 1 );
		?>
		<div class="notice notice-info is-dismissible" data-anar-digits-reminder>
			<p><strong><?php esc_html_e( 'Digits mobile numbers were found.', 'anar-toolkit' ); ?></strong>
			<?php esc_html_e( 'You can move existing users to Anar. Check the preview before transferring; accounts, passwords and orders are preserved. This reminder appears only once.', 'anar-toolkit' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-user-tools' ) ); ?>"><?php esc_html_e( 'Check and migrate users', 'anar-toolkit' ); ?></a></p>
		</div>
		<?php
	}
}
