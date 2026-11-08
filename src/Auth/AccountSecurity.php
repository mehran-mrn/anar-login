<?php
/**
 * Authenticator enrollment and a shared strong-factor gate for interactive login.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class AccountSecurity {
	private $otp;
	private $users;
	private static $approved = 0;
	private $session_token = '';
	private $remember = false;

	public function __construct( OtpService $otp, UserService $users ) {
		$this->otp = $otp;
		$this->users = $users;
	}

	public static function link() {
		return is_user_logged_in() ? '<p><a class="anar-btn anar-btn--soft" href="' . esc_url( add_query_arg( 'action', 'anar_security', wp_login_url() ) ) . '">' . esc_html__( 'Account security', 'anar-toolkit' ) . '</a></p>' : '';
	}

	private function required( $user ) {
		return ( new CredentialService() )->enabled( $user->ID );
	}

	public function gate( $allowed, $user, $remember = false ) {
		if ( is_wp_error( $allowed ) || ! $allowed || self::$approved === (int) $user->ID || ! $this->required( $user ) ) {
			return $allowed;
		}
		$token = ( new LoginFlowService() )->issue( 'strong_login', array( 'user_id' => (int) $user->ID, 'remember' => (bool) $remember, 'password' => hash( 'sha256', $user->user_pass ), 'credential' => hash( 'sha256', wp_json_encode( ( new CredentialService() )->record( $user->ID ) ) ) ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return new WP_Error( 'anar_factor_required', __( 'Complete account verification to sign in.', 'anar-toolkit' ), array( 'status' => 401, 'redirect' => add_query_arg( array( 'action' => 'anar_authenticator', 'flow' => $token ), wp_login_url() ) ) );
	}

	public function remember( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		$this->session_token = $token;
		$this->remember = $expire > 0;
	}

	public function intercept( $login, $user ) {
		if ( ! $this->required( $user ) || self::$approved === (int) $user->ID ) {
			return;
		}
		\WP_Session_Tokens::get_instance( $user->ID )->destroy( $this->session_token );
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
		$result = $this->gate( true, $user, $this->remember );
		if ( ! is_wp_error( $result ) || 'anar_factor_required' !== $result->get_error_code() ) {
			wp_die( esc_html__( 'Please sign in again.', 'anar-toolkit' ) );
		}
		$data = $result->get_error_data();
		$redirect = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification -- Destination is validated below.
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( wp_validate_redirect( $redirect, home_url( '/' ) ) ), $data['redirect'] ) );
		exit;
	}

	public function block_xmlrpc( $user ) {
		return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $user instanceof \WP_User && $this->required( $user ) ? new WP_Error( 'anar_factor_required', __( 'Use interactive sign-in for this account.', 'anar-toolkit' ) ) : $user;
	}

	public function application_passwords( $available, $user ) {
		return $this->required( $user ) ? false : $available;
	}

	private function value( $key ) {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Callers validate their action nonce.
	}

	public function screen() {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$token = isset( $_GET['flow'] ) && is_string( $_GET['flow'] ) ? sanitize_key( wp_unslash( $_GET['flow'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Browser-bound capability; POST verifies nonce.
		$flows = new LoginFlowService();
		$state = $flows->read( $token, 'strong_login' );
		$user = $state ? get_userdata( $state['data']['user_id'] ) : false;
		if ( ! $user || ! hash_equals( $state['data']['password'], hash( 'sha256', $user->user_pass ) ) || ! $this->required( $user ) ) {
			wp_die( esc_html__( 'This verification expired. Please sign in again.', 'anar-toolkit' ) );
		}
		$errors = new WP_Error();
		if ( 'post' === sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( 'anar_strong_' . $token );
			$attempt = $flows->attempt( $token, 'strong_login' );
			$credential = hash( 'sha256', wp_json_encode( ( new CredentialService() )->record( $user->ID ) ) );
			if ( $attempt && hash_equals( $attempt['data']['credential'], $credential ) && ( new CredentialService() )->verify( $user->ID, $this->value( 'code' ) ) && $flows->consume( $token, 'strong_login', $attempt ) ) {
				self::$approved = (int) $user->ID;
				$result = $this->users->login( $user, ! empty( $attempt['data']['remember'] ) );
				if ( ! is_wp_error( $result ) ) {
					$redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- UserService validates the destination.
					wp_safe_redirect( $this->users->redirect_url( $user, $redirect ) );
					exit;
				}
				$errors = $result;
			} else {
				$errors->add( 'anar_factor_invalid', __( 'The code is invalid or this verification expired. Please sign in again if needed.', 'anar-toolkit' ) );
			}
		}
		login_header( __( 'Account verification', 'anar-toolkit' ), '', $errors );
		echo '<form method="post"><p>' . esc_html__( 'Enter your authenticator code or a recovery code.', 'anar-toolkit' ) . '</p><p><label for="anar-factor-code">' . esc_html__( 'Verification code', 'anar-toolkit' ) . '</label><input class="input" id="anar-factor-code" name="code" autocomplete="one-time-code" dir="ltr" required autofocus></p>';
		wp_nonce_field( 'anar_strong_' . $token );
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Verify and sign in', 'anar-toolkit' ) . '</button></form>';
		login_footer();
		exit;
	}

	public function manage() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( 'action', 'anar_security', wp_login_url() ) ) );
			exit;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$user = wp_get_current_user();
		$credentials = new CredentialService();
		$flows = new LoginFlowService();
		$enabled = $credentials->enabled( $user->ID );
		$identity = ( new TwoFactor( new Settings(), $this->otp, $this->users ) )->identity_for( $user );
		$errors = new WP_Error();
		$notice = '';
		$secret = '';
		$token = '';
		$codes = array();
		if ( 'post' === sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( 'anar_security_manage' );
			$operation = $this->value( 'operation' );
			$purpose = 'security_manage_' . $user->ID;
			if ( 'send' === $operation && ! $enabled && $identity ) {
				$result = $this->otp->challenge( $identity, 'login_otp', $purpose );
				if ( is_wp_error( $result ) ) {
					$errors = $result;
				} else {
					$notice = __( 'A verification code has been sent to your linked contact.', 'anar-toolkit' );
				}
			} elseif ( 'start' === $operation && ! $enabled && $identity && ! is_wp_error( $this->otp->check( $identity, $this->value( 'proof' ), $purpose ) ) ) {
				$secret = Totp::secret();
				$token = $flows->issue( 'totp_enroll', array( 'user_id' => (int) $user->ID, 'secret' => $credentials->encrypt( $secret ) ) );
				if ( is_wp_error( $token ) ) {
					$errors = $token;
					$secret = '';
					$token = '';
				}
			} elseif ( 'confirm' === $operation && ! $enabled ) {
				$token = $this->value( 'flow' );
				$attempt = $flows->attempt( $token, 'totp_enroll' );
				if ( $attempt && (int) $attempt['data']['user_id'] === (int) $user->ID ) {
					$secret = $credentials->decrypt( $attempt['data']['secret'] );
				}
				if ( $secret && false !== Totp::match_counter( $secret, $this->value( 'code' ), time() ) && $flows->consume( $token, 'totp_enroll', $attempt ) ) {
					$codes = $credentials->enroll_encrypted( $user->ID, $attempt['data']['secret'], $this->value( 'code' ) );
				}
				if ( ! $codes ) {
					$errors->add( 'anar_factor_invalid', __( 'The code is invalid. Start setup again.', 'anar-toolkit' ) );
				}
				$enabled = $credentials->enabled( $user->ID );
			} elseif ( 'remove' === $operation && $enabled ) {
				if ( $credentials->verify( $user->ID, $this->value( 'code' ) ) ) {
					$credentials->remove( $user->ID );
					$enabled = false;
					$notice = __( 'Authenticator removed.', 'anar-toolkit' );
				} else {
					$errors->add( 'anar_factor_invalid', __( 'The code is invalid. Please try again later.', 'anar-toolkit' ) );
				}
			} else {
				$errors->add( 'anar_factor_invalid', __( 'The code is invalid. Please try again.', 'anar-toolkit' ) );
			}
		}
		login_header( __( 'Account security', 'anar-toolkit' ), $notice ? '<p class="message">' . esc_html( $notice ) . '</p>' : '', $errors );
		if ( $codes ) {
			echo '<div class="message"><strong>' . esc_html__( 'Save these recovery codes now. Each code works once.', 'anar-toolkit' ) . '</strong><pre dir="ltr">' . esc_html( implode( "\n", $codes ) ) . '</pre></div>';
		}
		echo '<form method="post">';
		wp_nonce_field( 'anar_security_manage' );
		if ( $enabled ) {
			echo '<p>' . esc_html__( 'Authenticator protection is active for every sign-in method.', 'anar-toolkit' ) . '</p><label for="anar-remove-code">' . esc_html__( 'Authenticator or recovery code', 'anar-toolkit' ) . '</label><input class="input" id="anar-remove-code" name="code" autocomplete="one-time-code" dir="ltr" required><button class="button" name="operation" value="remove">' . esc_html__( 'Remove authenticator', 'anar-toolkit' ) . '</button>';
		} elseif ( $secret ) {
			echo '<p>' . esc_html__( 'Add this setup key in your authenticator app, then enter its six-digit code.', 'anar-toolkit' ) . '</p><p dir="ltr"><code>' . esc_html( $secret ) . '</code></p><input type="hidden" name="flow" value="' . esc_attr( $token ) . '"><label for="anar-enroll-code">' . esc_html__( 'Authenticator code', 'anar-toolkit' ) . '</label><input class="input" id="anar-enroll-code" name="code" autocomplete="one-time-code" inputmode="numeric" dir="ltr" required><button class="button button-primary" name="operation" value="confirm">' . esc_html__( 'Enable authenticator', 'anar-toolkit' ) . '</button>';
		} else {
			echo '<p>' . esc_html__( 'First verify your linked contact to set up an authenticator.', 'anar-toolkit' ) . '</p><button class="button" name="operation" value="send" formnovalidate>' . esc_html__( 'Send verification code', 'anar-toolkit' ) . '</button><p><label for="anar-proof">' . esc_html__( 'Verification code', 'anar-toolkit' ) . '</label><input class="input" id="anar-proof" name="proof" autocomplete="one-time-code" inputmode="numeric" dir="ltr" required></p><button class="button button-primary" name="operation" value="start">' . esc_html__( 'Continue', 'anar-toolkit' ) . '</button>';
		}
		echo '</form>';
		login_footer();
		exit;
	}
}
