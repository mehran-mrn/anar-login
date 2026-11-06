<?php
/**
 * Phone-bound password resets, isolated from login and cart recovery challenges.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PasswordRecovery {
	private $settings;
	private $codes;
	private $limits;
	private $providers;

	public function __construct( Settings $settings, OtpRepository $codes, RateLimiter $limits, ProviderRegistry $providers ) {
		$this->settings = $settings;
		$this->codes = $codes;
		$this->limits = $limits;
		$this->providers = $providers;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_shortcode( 'anar_reset_password', array( self::class, 'form' ) );
	}

	public function routes() {
		foreach ( array( 'request', 'reset' ) as $operation ) {
			register_rest_route(
				'anar-login/v1',
				'/password/' . $operation,
				array(
					'methods' => 'POST',
					'permission_callback' => '__return_true',
					'callback' => function ( $request ) use ( $operation ) {
						$response = $this->$operation( (string) $request->get_param( 'phone' ), (string) $request->get_param( 'code' ), (string) $request->get_param( 'password' ), (string) $request->get_param( 'website' ) );
						return is_wp_error( $response ) ? $response : new \WP_REST_Response( $response, 200, array( 'Cache-Control' => 'no-store' ) );
					},
					'args' => array( 'phone' => array( 'required' => true, 'type' => 'string' ) ),
				)
			);
		}
	}

	/** Resolve exactly one linked account; billing phone alone is never reset authority. */
	private function user( $phone ) {
		$ids = array();
		foreach ( array( '_anar_phone', 'digits_phone' ) as $key ) {
			foreach ( array( $phone, ltrim( $phone, '+' ), Identity::local_phone( $phone ) ) as $value ) {
				foreach ( get_users( array( 'meta_key' => $key, 'meta_value' => $value, 'number' => 2, 'fields' => 'ID', 'count_total' => false ) ) as $id ) {
					$ids[ (int) $id ] = true;
				}
			}
		}
		if ( 1 !== count( $ids ) ) {
			return false;
		}
		$user = get_userdata( (int) key( $ids ) );
		$allowed = $user ? apply_filters( 'allow_password_reset', true, $user->ID ) : false; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress account-policy hook.
		return true === $allowed && empty( $user->spam ) && empty( $user->deleted ) ? $user : false;
	}

	private function identity( $user, $phone ) {
		return 'password|' . $user->ID . '|' . $phone . '|' . hash( 'sha256', $user->user_pass );
	}

	public function request( $raw_phone, $unused_code = '', $unused_password = '', $website = '' ) {
		$generic = array( 'message' => __( 'If this number is linked to an eligible account, a password reset code will be sent. Please wait before requesting another.', 'anar-toolkit' ) );
		$phone = Identity::normalize( $raw_phone );
		if ( $website || is_wp_error( $phone ) || 'sms' !== $phone['channel'] || ! $this->settings->get( 'password_reset_enabled', 1 ) ) {
			return $generic;
		}
		$limit = $this->limits->guard_request( 'password|' . $phone['identity'] );
		$user = $this->user( $phone['identity'] );
		if ( is_wp_error( $limit ) || ! $user ) {
			return $generic;
		}
		$code = (string) random_int( 100000, 999999 );
		$identity = $this->identity( $user, $phone['identity'] );
		if ( ! $this->codes->create( $identity, 'reset', $code, 300 ) ) {
			return $generic;
		}
		$sent = $this->providers->send(
			$phone['identity'],
			$code,
			sprintf(
			/* translators: 1: site name, 2: password reset code. */
				__( '%1$s: Your password reset code is %2$s. It expires in 5 minutes. Do not share this code.', 'anar-toolkit' ),
				get_bloginfo( 'name' ),
				$code
			),
			'password_reset'
		);
		if ( is_wp_error( $sent ) ) {
			$challenge = $this->codes->latest( $identity, 'reset' );
			if ( $challenge ) {
				$this->codes->consume( $challenge->id );
			}
		}
		return $generic;
	}

	public function reset( $raw_phone, $raw_code, $password, $website = '' ) {
		$error = new WP_Error( 'anar_password_reset_invalid', __( 'The password reset code is invalid, expired or unavailable.', 'anar-toolkit' ), array( 'status' => 400 ) );
		$phone = Identity::normalize( $raw_phone );
		if ( $website || is_wp_error( $phone ) || 'sms' !== $phone['channel'] || ! $this->settings->get( 'password_reset_enabled', 1 ) ) {
			return $error;
		}
		if ( is_wp_error( $this->limits->guard_recovery( 'password|' . $phone['identity'] ) ) ) {
			return $error;
		}
		$user = $this->user( $phone['identity'] );
		if ( ! $user ) {
			return $error;
		}
		$identity = $this->identity( $user, $phone['identity'] );
		$challenge = $this->codes->latest( $identity, 'reset' );
		$code = trim( Identity::latin_digits( $raw_code ) );
		if ( ! $challenge || ! $this->codes->claim_attempt( $challenge->id, 5 ) || ! preg_match( '/^\d{6}$/D', $code ) || ! $this->codes->matches( $challenge, $identity, $code ) ) {
			return $error;
		}
		$errors = new WP_Error();
		if ( strlen( $password ) < 12 || strlen( $password ) > 4096 ) {
			$errors->add( 'anar_password_length', __( 'Use a password between 12 and 4096 bytes long.', 'anar-toolkit' ) );
		}
		do_action( 'validate_password_reset', $errors, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress password-validation hook.
		if ( $errors->has_errors() ) {
			return $errors;
		}
		if ( ! $this->codes->consume( $challenge->id ) ) {
			return $error;
		}
		reset_password( $user, $password );
		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		return array( 'message' => __( 'Your password has been changed. Sign in with your new password or a new login code.', 'anar-toolkit' ) );
	}

	public static function form() {
		if ( ! \Anar\Login\Infrastructure\Features::enabled( 'reset' ) || ! ( new Settings() )->get( 'password_reset_enabled', 1 ) ) {
			return '';
		}
		wp_enqueue_style( 'anar-password-reset', ANAR_LOGIN_URL . 'assets/css/password-reset.css', array(), ANAR_LOGIN_VERSION );
		wp_enqueue_script( 'anar-password-reset', ANAR_LOGIN_URL . 'assets/js/password-reset.js', array(), ANAR_LOGIN_VERSION, true );
		ob_start();
		?>
		<details class="anar-reset" data-anar-password data-endpoint="<?php echo esc_url( rest_url( 'anar-login/v1/password/' ) ); ?>">
			<summary><?php esc_html_e( 'Forgot your password? Reset it by SMS', 'anar-toolkit' ); ?></summary>
			<form class="anar-reset__form">
				<p><?php esc_html_e( 'Enter the mobile number already linked to your account. Login codes and purchase recovery codes cannot reset your password.', 'anar-toolkit' ); ?></p>
				<label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><input name="phone" type="tel" dir="ltr" autocomplete="tel" required></label>
				<div class="anar-reset__trap" aria-hidden="true"><input name="website" type="text" tabindex="-1" autocomplete="off"></div>
				<button type="button" class="anar-btn anar-btn--soft" data-request><?php esc_html_e( 'Send password reset code', 'anar-toolkit' ); ?></button>
				<div data-reset-fields hidden>
					<label><?php esc_html_e( 'Password reset code', 'anar-toolkit' ); ?><input name="code" type="text" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>
					<label><?php esc_html_e( 'New password (at least 12 characters)', 'anar-toolkit' ); ?><input name="password" type="password" autocomplete="new-password" minlength="12"></label>
					<label><?php esc_html_e( 'Confirm new password', 'anar-toolkit' ); ?><input name="confirm" type="password" autocomplete="new-password" minlength="12"></label>
					<button type="submit" class="anar-btn anar-btn--primary"><?php esc_html_e( 'Set new password', 'anar-toolkit' ); ?></button>
				</div>
				<p role="status" aria-live="polite" data-status data-network="<?php esc_attr_e( 'Could not connect. Please try again.', 'anar-toolkit' ); ?>" data-mismatch="<?php esc_attr_e( 'The passwords do not match.', 'anar-toolkit' ); ?>"></p>
			</form>
		</details>
		<?php
		return ob_get_clean();
	}
}
