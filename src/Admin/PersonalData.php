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
				return array( 'type' => 'info', 'message' => __( 'No local Anar records were found for this number. Nothing was deleted.', 'anar-toolkit' ) );
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
		}
		$url = admin_url( 'admin.php?page=anar-toolkit-commerce&tab=privacy' );
		?>
		<div class="anar-personal-data">
			<p><?php esc_html_e( 'Search a mobile number to see which local Anar records exist. Searching does not delete or change those records.', 'anar-toolkit' ); ?></p>
			<p class="description"><?php esc_html_e( 'This tool covers local reminder and marketing records. It does not delete WordPress accounts, login mobile numbers, WooCommerce orders or records held by the SMS provider.', 'anar-toolkit' ); ?></p>
			<?php if ( $result ) : ?>
				<div id="anar-privacy-result" class="notice notice-<?php echo esc_attr( $result['type'] ); ?> inline" role="<?php echo 'error' === $result['type'] ? 'alert' : 'status'; ?>"><p><?php echo esc_html( $result['message'] ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $url . '#anar-privacy-result' ); ?>" class="anar-privacy-search">
				<?php wp_nonce_field( 'anar_personal_data' ); ?>
				<input type="hidden" name="anar_privacy_action" value="search">
				<label for="anar-privacy-phone"><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?></label>
				<input id="anar-privacy-phone" name="phone" type="tel" dir="ltr" autocomplete="off" required>
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
				<form method="post" action="<?php echo esc_url( $url . '#anar-privacy-result' ); ?>">
					<?php wp_nonce_field( 'anar_personal_data' ); ?>
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
