<?php
/**
 * Link a verified phone to an existing signed-in account without changing sessions.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings as LoginSettings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Support\Identity;

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

	private function available( $phone, $user_id ) {
		$keys = (array) apply_filters( 'anar_login_phone_meta_keys', array( '_anar_phone', 'digits_phone' ) );
		foreach ( $keys as $key ) {
			foreach ( array_unique( array( $phone, ltrim( $phone, '+' ), Identity::local_phone( $phone ) ) ) as $value ) {
				if ( get_users( array( 'meta_key' => sanitize_key( $key ), 'meta_value' => $value, 'exclude' => array( $user_id ), 'number' => 1, 'fields' => 'ID', 'count_total' => false ) ) ) {
					return false;
				}
			}
		}
		return true;
	}

	public function process( $raw_phone, $raw_code = '', $request_code = false ) {
		global $wpdb;
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
		$codes = new OtpRepository();
		$limits = new RateLimiter( $settings );
		$rate_key = 'game-phone|' . $phone['identity'];
		// A login, reset or another account/session's code cannot authorize linking.
		$identity = $rate_key . '|' . $user_id . '|' . hash( 'sha256', wp_get_session_token() );
		if ( $request_code ) {
			$guard = $limits->guard_request( $rate_key );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$code = (string) random_int( 100000, 999999 );
			if ( ! $codes->create( $identity, 'game_phone', $code, 300 ) ) {
				return new \WP_Error( 'anar_phone_storage', __( 'The verification code could not be created. Please try again.', 'anar-toolkit' ) );
			}
			$sent = ( new ProviderRegistry( $settings ) )->send( $phone['identity'], $code, sprintf( /* translators: %s: verification code. */ __( 'Your account mobile verification code is %s. It expires in 5 minutes.', 'anar-toolkit' ), $code ), 'game_phone' );
			if ( is_wp_error( $sent ) ) {
				$challenge = $codes->latest( $identity, 'game_phone' );
				if ( $challenge ) {
					$codes->consume( $challenge->id );
				}
				$limits->release( $rate_key );
				return new \WP_Error( 'anar_phone_delivery', __( 'The verification code could not be sent. Please try again later.', 'anar-toolkit' ) );
			}
			return array( 'message' => __( 'The verification code was sent. Enter it below.', 'anar-toolkit' ), 'resend_after' => absint( $settings->get( 'resend_delay', 60 ) ) );
		}
		$error = new \WP_Error( 'anar_phone_code', __( 'The verification code is invalid or expired.', 'anar-toolkit' ) );
		$guard = $limits->guard_recovery( $rate_key );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$challenge = $codes->latest( $identity, 'game_phone' );
		$code = trim( Identity::latin_digits( $raw_code ) );
		if ( ! $challenge || ! $codes->claim_attempt( $challenge->id, 5 ) || ! preg_match( '/^\d{6}$/D', $code ) || ! $codes->matches( $challenge, $identity, $code ) || ! $codes->consume( $challenge->id ) ) {
			return $error;
		}
		// Serialize account links so simultaneous verifications cannot claim one number.
		$locks = $wpdb->prefix . 'anar_login_limits';
		$lock = hash( 'sha256', 'game-phone-links' );
		$owner = random_int( 1, 2147483647 );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND expires_at <= %d', $locks, $lock, time() ) );
		if ( 1 !== $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (limit_key, attempts, expires_at) VALUES (%s, %d, %d)', $locks, $lock, $owner, time() + MINUTE_IN_SECONDS ) ) ) {
			return new \WP_Error( 'anar_phone_busy', __( 'Mobile verification is busy. Please request a new code and try again.', 'anar-toolkit' ) );
		}
		try {
			clean_user_cache( $user_id );
			if ( self::phone( $user_id ) || ! $this->available( $phone['identity'], $user_id ) ) {
				return new \WP_Error( 'anar_phone_unavailable', __( 'This number cannot be linked to this account. Contact the store for assistance.', 'anar-toolkit' ) );
			}
			if ( ! update_user_meta( $user_id, '_anar_phone', $phone['identity'] ) ) {
				return new \WP_Error( 'anar_phone_save', __( 'Your mobile number could not be saved. Please try again.', 'anar-toolkit' ) );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE limit_key = %s AND attempts = %d', $locks, $lock, $owner ) );
		}
		do_action( 'anar_login_phone_identity_linked', $user_id, 'game_verification' );
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
