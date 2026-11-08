<?php
/**
 * Two-step verification for password sign-ins.
 *
 * After WordPress accepts a password (wp-login.php, WooCommerce, any form using
 * wp_signon), the fresh session is revoked and a one-time code is sent to the
 * account's phone, or its email when no phone is linked. The session is created
 * again only after the code is verified. Sign-ins that already proved possession
 * of the phone, email or Google account skip this step.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class TwoFactor {
	const ACTION = 'anar_2fa';

	/** @var Settings */
	private $settings;

	/** @var OtpService */
	private $otp;

	/** @var UserService */
	private $users;

	/**
	 * User whose current sign-in was verified by a code or Google.
	 *
	 * @var int
	 */
	private $verified_user = 0;

	/**
	 * Session token created by the password sign-in, revoked until the code is verified.
	 *
	 * @var string
	 */
	private $pending_token = '';

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param OtpService  $otp      OTP service.
	 * @param UserService $users    Users.
	 */
	public function __construct( Settings $settings, OtpService $otp, UserService $users ) {
		$this->settings = $settings;
		$this->otp      = $otp;
		$this->users    = $users;
	}

	/**
	 * Register hooks only when the feature is on, so it costs nothing otherwise.
	 *
	 * @return void
	 */
	public function register() {
		if ( 'off' === $this->settings->get( 'two_factor', 'off' ) ) {
			return;
		}

		add_action(
			'anar_login_verified_session',
			function ( $user ) {
				$this->verified_user = (int) $user->ID;
			}
		);
		add_action( 'set_logged_in_cookie', array( $this, 'remember_token' ), 10, 6 );
		add_action( 'wp_login', array( $this, 'intercept' ), 1, 2 );
		add_action( 'login_form_' . self::ACTION, array( $this, 'screen' ) );
		add_filter( 'authenticate', array( $this, 'block_xmlrpc' ), 100 );
		add_filter( 'wp_login_errors', array( $this, 'expired_notice' ) );
	}

	/**
	 * Explain an expired code step on the login form.
	 *
	 * @param WP_Error $errors Login errors.
	 * @return WP_Error
	 */
	public function expired_notice( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
		if ( isset( $_GET['anar_2fa'] ) && 'expired' === $_GET['anar_2fa'] ) {
			$errors->add( 'anar_2fa_expired', __( 'The verification step expired. Please sign in again.', 'anar-toolkit' ) );
		}

		return $errors;
	}

	/**
	 * Whether a user must pass the second step.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public function required_for( WP_User $user ) {
		$mode     = (string) $this->settings->get( 'two_factor', 'off' );
		$required = 'all' === $mode
			|| ( 'privileged' === $mode && ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_others_posts' ) || user_can( $user, 'manage_woocommerce' ) ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce shop manager capability.

		/**
		 * Filter whether a password sign-in needs a one-time code.
		 *
		 * @param bool    $required Whether verification is required.
		 * @param WP_User $user     User.
		 */
		return (bool) apply_filters( 'anar_login_two_factor_required', $required, $user );
	}

	/**
	 * Where the second-step code goes: the linked phone, else the account email.
	 *
	 * @param WP_User $user User.
	 * @return array{identity:string,channel:string}|null
	 */
	public function identity_for( WP_User $user ) {
		$phone = (string) get_user_meta( $user->ID, '_anar_phone', true );
		if ( $phone ) {
			return array(
				'identity' => $phone,
				'channel'  => 'sms',
			);
		}

		return is_email( $user->user_email )
			? array(
				'identity' => strtolower( $user->user_email ),
				'channel'  => 'email',
			)
			: null;
	}

	/**
	 * Capture the session token of the password sign-in so it can be revoked.
	 *
	 * @param string $cookie     Cookie value.
	 * @param int    $expire     Cookie expiry.
	 * @param int    $expiration Session expiry.
	 * @param int    $user_id    User ID.
	 * @param string $scheme     Scheme.
	 * @param string $token      Session token.
	 * @return void
	 */
	public function remember_token( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		$this->pending_token = (string) $token;
	}

	/**
	 * Replace a password sign-in with the code step.
	 *
	 * @param string  $login Username.
	 * @param WP_User $user  User.
	 * @return void
	 */
	public function intercept( $login, $user ) {
		if ( ! $user instanceof WP_User || $this->verified_user === (int) $user->ID || ! $this->required_for( $user ) ) {
			return;
		}

		$identity = $this->identity_for( $user );
		if ( ! $identity ) {
			return;
		}

		// Revoke the session WordPress just created; it is recreated after verification.
		if ( $this->pending_token ) {
			WP_Session_Tokens::get_instance( $user->ID )->destroy( $this->pending_token );
		}
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );

		// phpcs:disable WordPress.Security.NonceVerification -- The password form was just verified by WordPress itself.
		$requested = '';
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$requested = esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) );
		} elseif ( ! empty( $_POST['redirect'] ) ) {
			$requested = esc_url_raw( wp_unslash( $_POST['redirect'] ) );
		}
		$remember = ! empty( $_POST['rememberme'] );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! $requested && 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			$requested = admin_url();
		}

		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			$this->key( $token ),
			array(
				'user_id'  => (int) $user->ID,
				'remember' => $remember,
				'redirect' => $requested,
			),
			10 * MINUTE_IN_SECONDS
		);

		$sent = $this->otp->challenge( $identity, 'two_factor' );

		wp_safe_redirect( $this->screen_url( $token, is_wp_error( $sent ) ? 'failed' : 'sent' ) );
		exit;
	}

	/**
	 * Render and process the code screen on wp-login.php.
	 *
	 * @return void
	 */
	public function screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token itself is the capability; POST actions check a nonce below.
		$token   = isset( $_REQUEST['anar_token'] ) ? sanitize_key( wp_unslash( $_REQUEST['anar_token'] ) ) : '';
		$pending = $token ? get_transient( $this->key( $token ) ) : false;
		$user    = is_array( $pending ) ? get_userdata( (int) $pending['user_id'] ) : false;

		if ( ! $user ) {
			wp_safe_redirect( add_query_arg( 'anar_2fa', 'expired', wp_login_url() ) );
			exit;
		}

		$identity = $this->identity_for( $user );
		$errors   = new WP_Error();
		$message  = '';
		$is_post  = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only status flag.
		if ( ! $is_post && isset( $_GET['status'] ) && 'failed' === $_GET['status'] ) {
			$errors->add( 'anar_dispatch_failed', __( 'The login code could not be sent. Please try again later.', 'anar-toolkit' ) );
		}

		if ( $is_post && $identity ) {
			check_admin_referer( self::ACTION . '_' . $token );

			if ( isset( $_POST['anar_resend'] ) ) {
				$sent = $this->otp->challenge( $identity, 'two_factor' );
				if ( is_wp_error( $sent ) ) {
					$errors->add( $sent->get_error_code(), $sent->get_error_message() );
				} else {
					$message = __( 'A new code has been sent.', 'anar-toolkit' );
				}
			} else {
				$code    = isset( $_POST['anar_code'] ) ? sanitize_text_field( wp_unslash( $_POST['anar_code'] ) ) : '';
				$checked = $this->otp->check( $identity, $code );

				if ( is_wp_error( $checked ) ) {
					$errors->add( $checked->get_error_code(), $checked->get_error_message() );
				} else {
					delete_transient( $this->key( $token ) );
					$this->verified_user = (int) $user->ID;
					wp_set_auth_cookie( $user->ID, ! empty( $pending['remember'] ), is_ssl() );
					wp_set_current_user( $user->ID );
					// Announce the completed sign-in; verified_user keeps intercept() from running again.
					do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core authentication hook.

					/**
					 * Fires after a password sign-in passed two-step verification.
					 *
					 * @param WP_User $user User.
					 */
					do_action( 'anar_login_two_factor_passed', $user );

					wp_safe_redirect( $this->users->redirect_url( $user, (string) $pending['redirect'] ) );
					exit;
				}
			}
		}

		$this->render( $token, $identity, $errors, $message );
		exit;
	}

	/**
	 * Refuse XML-RPC password sign-ins for accounts that need a second step.
	 *
	 * XML-RPC authenticates without wp_login, so it would otherwise bypass the code.
	 *
	 * @param WP_User|WP_Error|null $user Authentication result.
	 * @return WP_User|WP_Error|null
	 */
	public function block_xmlrpc( $user ) {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $user instanceof WP_User && $this->required_for( $user ) && $this->identity_for( $user ) ) {
			return new WP_Error( 'anar_2fa_required', __( 'This account uses two-step verification, so XML-RPC sign-in is disabled.', 'anar-toolkit' ) );
		}

		return $user;
	}

	/**
	 * Output the code form inside the standard login screen.
	 *
	 * @param string                                     $token    Pending sign-in token.
	 * @param array{identity:string,channel:string}|null $identity Destination.
	 * @param WP_Error                                   $errors   Errors.
	 * @param string                                     $message  Notice.
	 * @return void
	 */
	private function render( $token, $identity, WP_Error $errors, $message ) {
		$length = absint( $this->settings->get( 'otp_length', 5 ) );
		$intro  = $identity
			? sprintf(
				/* translators: %s: masked phone number or email address. */
				__( 'Enter the code we sent to %s.', 'anar-toolkit' ),
				'<bdi dir="ltr">' . esc_html( Identity::mask( $identity['identity'] ) ) . '</bdi>'
			)
			: esc_html__( 'No phone number or email is linked to this account.', 'anar-toolkit' );

		login_header( __( 'Two-step verification', 'anar-toolkit' ), $message ? '<p class="message">' . esc_html( $message ) . '</p>' : '', $errors );
		?>
		<form name="anar2fa" id="anar2fa" action="<?php echo esc_url( $this->screen_url( $token ) ); ?>" method="post" autocomplete="off">
			<p class="anar-2fa__intro"><?php echo wp_kses( $intro, array( 'bdi' => array( 'dir' => true ) ) ); ?></p>
			<p>
				<label for="anar_code"><?php esc_html_e( 'Verification code', 'anar-toolkit' ); ?></label>
				<input type="text" name="anar_code" id="anar_code" class="input" inputmode="numeric" pattern="[0-9۰-۹]*" autocomplete="one-time-code" dir="ltr" maxlength="<?php echo esc_attr( (string) ( $length * 2 ) ); ?>" style="text-align:center;letter-spacing:.4em;font-size:24px" required autofocus>
			</p>
			<?php wp_nonce_field( self::ACTION . '_' . $token ); ?>
			<p class="submit">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Verify and sign in', 'anar-toolkit' ); ?></button>
			</p>
			<p style="clear:both;padding-top:16px">
				<button type="submit" name="anar_resend" value="1" class="button-link" formnovalidate><?php esc_html_e( 'Resend code', 'anar-toolkit' ); ?></button>
			</p>
		</form>
		<?php
		login_footer( 'anar_code' );
	}

	/**
	 * Code screen URL.
	 *
	 * @param string $token  Pending sign-in token.
	 * @param string $status Optional status flag.
	 * @return string
	 */
	private function screen_url( $token, $status = '' ) {
		$args = array(
			'action'     => self::ACTION,
			'anar_token' => $token,
		);
		if ( $status ) {
			$args['status'] = $status;
		}

		return add_query_arg( $args, wp_login_url() );
	}

	/**
	 * Transient key for a pending sign-in; only the hash of the token is stored.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function key( $token ) {
		return 'anar_2fa_' . hash( 'sha256', $token );
	}
}
