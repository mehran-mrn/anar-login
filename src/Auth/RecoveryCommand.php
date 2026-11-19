<?php
/**
 * Hosting-only recovery for a single account; never a public login bypass.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

defined( 'ABSPATH' ) || exit;

final class RecoveryCommand {
	/**
	 * Issue replacement recovery codes for an existing user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : Existing user ID, login or email.
	 *
	 * [--yes]
	 * : Confirm replacement without prompting.
	 */
	public static function run( $args, $assoc_args ) {
		$user = get_user_by( 'id', $args[0] ) ?: get_user_by( 'login', $args[0] ) ?: get_user_by( 'email', $args[0] );
		if ( ! $user || is_multisite() ) {
			\WP_CLI::error( 'Choose an existing user on a single-site installation.' );
		}
		\WP_CLI::confirm( 'Replace this account\'s recovery codes and revoke its sessions?', $assoc_args );
		$codes = ( new RecoveryCodes() )->issue( $user->ID, true );
		if ( ! $codes ) {
			\WP_CLI::error( 'Recovery codes could not be saved.' );
		}
		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		update_user_meta( $user->ID, '_anar_recovery_reset', array( 'time' => time(), 'source' => 'cli' ) );
		do_action( 'anar_login_recovery_reset', $user->ID, 'cli' );
		wp_mail( $user->user_email, __( 'Account recovery codes replaced', 'anar-toolkit' ), __( 'Your recovery codes were replaced through hosting access. Contact your site administrator if you did not request this.', 'anar-toolkit' ) );
		\WP_CLI::line( implode( "\n", $codes ) );
		\WP_CLI::success( 'Store these codes offline. Sign in with your password, then choose Use recovery code. No SMS is required.' );
	}
}
