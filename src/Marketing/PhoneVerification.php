<?php
/**
 * Link a verified phone to an existing signed-in account without changing sessions.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Auth\CodeChallenge;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Support\Identity;
use Anar\Login\Support\PhoneDirectory;

defined( 'ABSPATH' ) || exit;

final class PhoneVerification {

	public static function phone( $user_id ) {
		$phone = Identity::normalize( (string) get_user_meta( $user_id, '_anar_phone', true ) );
		return ! is_wp_error( $phone ) && 'sms' === $phone['channel'] ? $phone['identity'] : '';
	}

	public static function form() {
		wp_enqueue_style( 'anar-marketing', ANAR_LOGIN_URL . 'assets/css/marketing.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-games', ANAR_LOGIN_URL . 'assets/js/games.js', array(), ANAR_LOGIN_VERSION, true );
		ob_start();
		?>
		<form class="anar-game-signin anar-game-phone" data-game-phone data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-network="<?php esc_attr_e( 'Could not connect. Please try again.', 'anar-toolkit' ); ?>">
			<h3><?php esc_html_e( 'Verify your mobile number', 'anar-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'You are signed in. To play and receive a personal coupon, verify a mobile number for this account. You only need to do this once.', 'anar-toolkit' ); ?></p>
			<input type="hidden" name="action" value="anar_game_phone">
			<?php wp_nonce_field( 'anar_game_phone', 'nonce', false ); ?>
			<label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><input name="phone" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" required></label>
			<button type="button" data-phone-request><?php esc_html_e( 'Send verification code', 'anar-toolkit' ); ?></button>
			<div data-phone-code hidden>
				<label><?php esc_html_e( 'Verification code', 'anar-toolkit' ); ?><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr"></label>
				<button type="submit"><?php esc_html_e( 'Verify number and continue', 'anar-toolkit' ); ?></button>
			</div>
			<p role="status" aria-live="polite" data-status></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public function process( $raw_phone, $raw_code = '', $request_code = false ) {
		if ( ! is_user_logged_in() || ! ( new Settings() )->enabled( 'games' ) ) {
			return new \WP_Error( 'anar_phone_forbidden', __( 'Sign in before verifying your mobile number.', 'anar-toolkit' ) );
		}
		$user_id = get_current_user_id();
		if ( self::phone( $user_id ) ) {
			return new \WP_Error( 'anar_phone_linked', __( 'This account already has a verified mobile number. Reload the page to play.', 'anar-toolkit' ) );
		}
		$phone = Identity::normalize( $raw_phone );
		if ( is_wp_error( $phone ) || 'sms' !== $phone['channel'] ) {
			return new \WP_Error( 'anar_phone_invalid', __( 'Please enter a valid Iranian mobile number.', 'anar-toolkit' ) );
		}
		$settings = new LoginSettings();
		$codes = CodeChallenge::create( $settings );
		$rate_key = 'game-phone|' . $phone['identity'];
		// A login, reset or another account/session's code cannot authorize linking.
		$identity = $rate_key . '|' . $user_id . '|' . hash( 'sha256', wp_get_session_token() );
		if ( $request_code ) {
			$sent = $codes->issue(
				$phone,
				$identity,
				static function ( $code ) {
					/* translators: %s: verification code. */
					return sprintf( __( 'Your account mobile verification code is %s. It expires in 5 minutes.', 'anar-toolkit' ), $code );
				},
				array(
					'channel' => 'game_phone',
					'context' => 'game_phone',
					'scope'   => 'game_phone',
				)
			);
			if ( is_wp_error( $sent ) ) {
				return in_array( $sent->get_error_code(), array( 'anar_too_soon', 'anar_rate_limited' ), true ) ? $sent : new \WP_Error( 'anar_phone_delivery', __( 'The verification code could not be sent. Please try again later.', 'anar-toolkit' ) );
			}
			return array( 'message' => __( 'The verification code was sent. Enter it below.', 'anar-toolkit' ), 'resend_after' => absint( $settings->get( 'resend_delay', 60 ) ) );
		}
		$error = new \WP_Error( 'anar_phone_code', __( 'The verification code is invalid or expired.', 'anar-toolkit' ) );
		$guard = $codes->limiter()->guard_recovery( $rate_key );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( is_wp_error( $codes->verify( $identity, 'game_phone', $raw_code, array( 'length' => 6 ) ) ) ) {
			return $error;
		}
		clean_user_cache( $user_id );
		if ( self::phone( $user_id ) ) {
			return new \WP_Error( 'anar_phone_unavailable', __( 'This number cannot be linked to this account. Contact the store for assistance.', 'anar-toolkit' ) );
		}
		$linked = PhoneDirectory::claim( $user_id, $phone['identity'], 'game_verification' );
		if ( is_wp_error( $linked ) ) {
			return $linked;
		}
		return array( 'verified' => true, 'message' => __( 'Your mobile number is verified. Loading the game…', 'anar-toolkit' ) );
	}

	public function action() {
		check_ajax_referer( 'anar_game_phone', 'nonce' );
		$phone = isset( $_POST['phone'] ) && is_string( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$code = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$result = $this->process( $phone, $code, ! empty( $_POST['request_code'] ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}
}
