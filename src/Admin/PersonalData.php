<?php
/**
 * Search local phone records before an explicit, preview-bound erasure.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Commerce\Privacy;
use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class PersonalData {
	private static function key( $token ) {
		return 'anar_privacy_' . get_current_user_id() . '_' . hash( 'sha256', $token );
	}

	private static function result_key( $token ) {
		return 'anar_privacy_result_' . get_current_user_id() . '_' . hash( 'sha256', $token );
	}

	/** Redirect to a private result so normal admin reloads do not discard form feedback. */
	public static function submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- process() validates the nonce, action, token and phone before any operation.
		$result = self::process( wp_unslash( $_POST ), new Privacy( new \Anar\Login\Commerce\Repository(), new \Anar\Login\Commerce\Settings() ) );
		$token = wp_generate_password( 24, false, false );
		if ( ! set_transient( self::result_key( $token ), $result, 10 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html__( 'The search result could not be saved. Please search again.', 'anar-toolkit' ) );
		}
		wp_safe_redirect( add_query_arg( 'privacy_result', $token, admin_url( 'admin.php?page=anar-toolkit-commerce&tab=privacy' ) ) . '#anar-privacy-result', 303 );
		exit;
	}

	/** Accept only authenticated form posts; deletion uses the server-stored phone. */
	public static function process( $input, Privacy $privacy ) {
		$error = static function ( $text ) {
			return array( 'type' => 'error', 'message' => $text );
		};
		if ( ! current_user_can( 'manage_options' ) || ! is_string( $input['_wpnonce'] ?? null ) || ! wp_verify_nonce( $input['_wpnonce'], 'anar_personal_data' ) ) {
			return $error( __( 'Your request could not be verified. Reload this page and search again.', 'anar-toolkit' ) );
		}
		$operation = $input['anar_privacy_action'] ?? '';
		if ( 'search' === $operation ) {
			$phone = Identity::normalize( is_string( $input['phone'] ?? null ) ? $input['phone'] : '' );
			if ( is_wp_error( $phone ) || 'sms' !== $phone['channel'] ) {
				return $error( __( 'Enter a valid mobile number to search.', 'anar-toolkit' ) );
			}
			$summary = $privacy->phone_summary( $phone['identity'] );
			if ( is_wp_error( $summary ) ) {
				return $error( __( 'Local data could not be checked. Please try again.', 'anar-toolkit' ) );
			}
			if ( ! $summary['found'] ) {
				return array( 'type' => 'info', 'state' => 'empty', 'phone' => $phone['identity'], 'message' => __( 'No local Anar records were found for this number. Nothing was deleted.', 'anar-toolkit' ) );
			}
			$token = wp_generate_password( 24, false, false );
			$preview = array( 'phone' => $phone['identity'], 'summary' => $summary );
			if ( ! set_transient( self::key( $token ), $preview, 10 * MINUTE_IN_SECONDS ) ) {
				return $error( __( 'The search result could not be saved. Please search again.', 'anar-toolkit' ) );
			}
			$preview['token'] = $token;
			return array( 'type' => 'info', 'message' => __( 'Local Anar records were found. Review the summary before confirming erasure.', 'anar-toolkit' ), 'preview' => $preview );
		}
		$token = is_string( $input['token'] ?? null ) ? $input['token'] : '';
		if ( 'erase' !== $operation || '1' !== ( $input['confirmed'] ?? '' ) || ! preg_match( '/^[a-zA-Z0-9]{24}$/D', $token ) ) {
			return $error( __( 'Search for a number first, then confirm the displayed erasure.', 'anar-toolkit' ) );
		}
		$key = self::key( $token );
		$preview = get_transient( $key );
		if ( ! is_array( $preview ) || ! add_option( $key, time(), '', false ) ) {
			return $error( __( 'This search result expired or was already used. Search again before erasing.', 'anar-toolkit' ) );
		}
		try {
			delete_transient( $key );
			$current = $privacy->phone_summary( $preview['phone'] );
			if ( is_wp_error( $current ) ) {
				return $error( __( 'Local data could not be checked. Please try again.', 'anar-toolkit' ) );
			}
			if ( ! hash_equals( $preview['summary']['fingerprint'], $current['fingerprint'] ) ) {
				return $error( __( 'These records changed after your search. Search again and review the updated summary.', 'anar-toolkit' ) );
			}
			$privacy->erase_phone( $preview['phone'] );
			$remaining = $privacy->phone_summary( $preview['phone'] );
			if ( is_wp_error( $remaining ) || $remaining['found'] ) {
				return $error( __( 'Erasure could not be completed. Some local records may remain; search again to review them.', 'anar-toolkit' ) );
			}
			return array( 'type' => 'success', 'message' => __( 'Local Anar records for this number were erased. The WordPress account and WooCommerce orders were kept.', 'anar-toolkit' ) );
		} catch ( \Throwable $exception ) {
			return $error( __( 'Erasure could not be completed. Some local records may remain; search again to review them.', 'anar-toolkit' ) );
		} finally {
			delete_option( $key );
		}
	}

	public static function render( Privacy $privacy ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() validates the nonce and capability before reading or erasing records.
		if ( isset( $_POST['anar_privacy_action'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict action, token and normalized phone validation is centralized in process().
			$result = self::process( wp_unslash( $_POST ), $privacy );
		} elseif ( isset( $_GET['privacy_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, opaque result key is scoped to the current administrator.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- No operation is performed by opening a result.
			$token = is_string( $_GET['privacy_result'] ) ? sanitize_text_field( wp_unslash( $_GET['privacy_result'] ) ) : '';
			$result = preg_match( '/^[a-zA-Z0-9]{24}$/D', $token ) ? get_transient( self::result_key( $token ) ) : false;
			if ( ! is_array( $result ) ) {
				$result = array( 'type' => 'error', 'message' => __( 'This search result expired or was already used. Search again before erasing.', 'anar-toolkit' ) );
			}
		}
		$url = admin_url( 'admin.php?page=anar-toolkit-commerce&tab=privacy' );
		$action_url = admin_url( 'admin-post.php' );
		$phone_value = isset( $result['phone'] ) ? Identity::local_phone( $result['phone'] ) : ( isset( $result['preview']['phone'] ) ? Identity::local_phone( $result['preview']['phone'] ) : '' );
		?>
		<div class="anar-personal-data">
			<p><?php esc_html_e( 'Search a mobile number to see which local Anar records exist. Searching does not delete or change those records.', 'anar-toolkit' ); ?></p>
			<p class="description"><?php esc_html_e( 'This tool covers local reminder and marketing records. It does not delete WordPress accounts, login mobile numbers, WooCommerce orders or records held by the SMS provider.', 'anar-toolkit' ); ?></p>
			<?php if ( $result ) : ?>
				<section id="anar-privacy-result" class="anar-privacy-result anar-privacy-result--<?php echo esc_attr( $result['type'] ); ?>" data-state="<?php echo esc_attr( $result['state'] ?? $result['type'] ); ?>" role="<?php echo 'error' === $result['type'] ? 'alert' : 'status'; ?>" tabindex="-1">
					<span class="dashicons dashicons-<?php echo 'error' === $result['type'] ? 'warning' : ( 'success' === $result['type'] ? 'yes-alt' : 'search' ); ?>" aria-hidden="true"></span>
					<div><h3><?php echo esc_html( 'empty' === ( $result['state'] ?? '' ) ? __( 'No records found for this mobile number.', 'anar-toolkit' ) : __( 'Check result', 'anar-toolkit' ) ); ?></h3>
					<?php if ( $phone_value ) : ?>
						<p><bdi dir="ltr"><?php echo esc_html( $phone_value ); ?></bdi></p>
					<?php endif; ?>
					<p><?php echo esc_html( $result['message'] ); ?></p></div>
				</section>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="anar-privacy-search">
				<?php wp_nonce_field( 'anar_personal_data' ); ?>
				<input type="hidden" name="action" value="anar_personal_data">
				<input type="hidden" name="anar_privacy_action" value="search">
				<label for="anar-privacy-phone"><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?></label>
				<input id="anar-privacy-phone" name="phone" type="tel" dir="ltr" autocomplete="off" value="<?php echo esc_attr( $phone_value ); ?>" required>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Search local data', 'anar-toolkit' ); ?></button>
			</form>
			<?php
			if ( isset( $result['preview'] ) ) :
				$preview = $result['preview'];
				?>
			<section class="anar-privacy-preview">
				<h3><?php esc_html_e( 'Records found for this number', 'anar-toolkit' ); ?> <bdi dir="ltr"><?php echo esc_html( Identity::local_phone( $preview['phone'] ) ); ?></bdi></h3>
				<dl><?php foreach ( array( 'contact' => __( 'Reminder contact details', 'anar-toolkit' ), 'carts' => __( 'Saved carts', 'anar-toolkit' ), 'messages' => __( 'Messages and queue entries', 'anar-toolkit' ), 'profile' => __( 'Marketing profile', 'anar-toolkit' ), 'campaigns' => __( 'Campaign, referral and game records', 'anar-toolkit' ) ) as $key => $label ) : ?>
					<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( (string) $preview['summary']['counts'][ $key ] ); ?></dd></div>
				<?php endforeach; ?></dl>
				<p><?php esc_html_e( 'This removes local Anar reminder and marketing records and revokes personal gift coupons. The WordPress account, login mobile number, WooCommerce orders and SMS provider records remain. Minimal suppression identifiers and anonymous campaign budget totals are retained.', 'anar-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<?php wp_nonce_field( 'anar_personal_data' ); ?>
					<input type="hidden" name="action" value="anar_personal_data">
					<input type="hidden" name="anar_privacy_action" value="erase"><input type="hidden" name="token" value="<?php echo esc_attr( $preview['token'] ); ?>">
					<label class="anar-privacy-confirm"><input type="checkbox" name="confirmed" value="1" required> <?php esc_html_e( 'I verified the requester and confirm erasure of the displayed records.', 'anar-toolkit' ); ?></label>
					<div class="anar-privacy-actions"><button type="submit" class="button"><?php esc_html_e( 'Confirm erasure', 'anar-toolkit' ); ?></button> <a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Cancel', 'anar-toolkit' ); ?></a></div>
				</form>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
