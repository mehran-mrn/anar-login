<?php
/**
 * Authenticator enrollment and a shared strong-factor gate for interactive login.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\Settings;
use WP_Error;
use Anar\Login\Frontend\FormView;

defined( 'ABSPATH' ) || exit;

final class AccountSecurity {
	private $otp;
	private $users;
	private static $approved = 0;
	private $session_token = '';
	private $remember = false;
	private $verified_user = 0;
	private $policy;
	private $credentials;
	private $flows;

	public function __construct( OtpService $otp, UserService $users ) {
		$this->otp = $otp;
		$this->users = $users;
		$this->policy = new TwoFactor( new Settings() );
		$this->credentials = new CredentialService();
		$this->flows = new LoginFlowService();
	}


	private function required( $user ) {
		return $this->credentials->enabled( $user->ID );
	}

	public function gate( $allowed, $user, $remember = false ) {
		if ( is_wp_error( $allowed ) || ! $allowed || self::$approved === (int) $user->ID || ! $this->required( $user ) ) {
			return $allowed;
		}
		return $this->begin( $user, $remember, 'totp' );
	}

	public function verified_session( $user ) {
		$this->verified_user = (int) $user->ID;
	}

	private function begin( $user, $remember, $factor ) {
		if ( 'totp' === $factor && ! SecureTransport::available() ) {
			return new WP_Error( 'anar_secure_connection', __( 'Open the secure HTTPS address of this site to manage or verify account security.', 'anar-toolkit' ) );
		}
		$token = $this->flows->issue( 'strong_login', array( 'user_id' => (int) $user->ID, 'remember' => (bool) $remember, 'factor' => $factor, 'password' => hash( 'sha256', $user->user_pass ), 'credential' => 'totp' === $factor ? hash( 'sha256', wp_json_encode( $this->credentials->record( $user->ID ) ) ) : '' ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		if ( 'otp' === $factor ) {
			$identity = $this->policy->identity_for( $user );
			if ( ! $identity ) {
				return new WP_Error( 'anar_factor_contact', __( 'No phone number or email is linked to this account.', 'anar-toolkit' ) );
			}
			$sent = $this->otp->challenge( $identity, 'two_factor', 'password_second_' . hash( 'sha256', $token ) );
			if ( is_wp_error( $sent ) ) {
				return $sent;
			}
		}
		return new WP_Error( 'anar_factor_required', __( 'Complete account verification to sign in.', 'anar-toolkit' ), array( 'status' => 401, 'redirect' => add_query_arg( array( 'action' => 'anar_authenticator', 'flow' => $token ), wp_login_url() ) ) );
	}

	public function remember( $cookie, $expire, $expiration, $user_id, $scheme, $token ) {
		$this->session_token = $token;
		$this->remember = $expire > 0;
	}

	public function intercept( $login, $user ) {
		if ( self::$approved === (int) $user->ID || ( ! $this->required( $user ) && ( $this->verified_user === (int) $user->ID || ! $this->policy->required_for( $user ) ) ) ) {
			return;
		}
		\WP_Session_Tokens::get_instance( $user->ID )->destroy( $this->session_token );
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
		$result = $this->begin( $user, $this->remember, $this->required( $user ) ? 'totp' : 'otp' );
		if ( ! is_wp_error( $result ) || 'anar_factor_required' !== $result->get_error_code() ) {
			wp_die( esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'Please sign in again.', 'anar-toolkit' ) ) );
		}
		$data = $result->get_error_data();
		$redirect = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Destination is validated below.
		// phpcs:ignore WordPress.Security.NonceVerification -- Read-only destination following successful core password authentication.
		if ( ! $redirect && isset( $_POST['redirect'] ) && is_string( $_POST['redirect'] ) ) {
			$redirect = esc_url_raw( wp_unslash( $_POST['redirect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Destination is validated below.
		}
		if ( ! $redirect && 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			$redirect = admin_url();
		}
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( wp_validate_redirect( $redirect, home_url( '/' ) ) ), $data['redirect'] ) );
		exit;
	}

	public function block_xmlrpc( $user ) {
		return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $user instanceof \WP_User && ( $this->required( $user ) || $this->policy->required_for( $user ) ) ? new WP_Error( 'anar_factor_required', __( 'Use interactive sign-in for this account.', 'anar-toolkit' ) ) : $user;
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
		$flows = $this->flows;
		$state = $flows->read( $token, 'strong_login' );
		$user = $state ? get_userdata( $state['data']['user_id'] ) : false;
		$is_totp = $state && 'totp' === $state['data']['factor'];
		$required = $user && ( $is_totp ? $this->required( $user ) : ( ! $this->required( $user ) && $this->policy->required_for( $user ) ) );
		if ( ! $user || ! hash_equals( $state['data']['password'], hash( 'sha256', $user->user_pass ) ) || ! $required ) {
			wp_die( esc_html__( 'This verification expired. Please sign in again.', 'anar-toolkit' ) );
		}
		$errors = new WP_Error();
		if ( 'post' === sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( 'anar_strong_' . $token );
			if ( ! $is_totp && isset( $_POST['resend'] ) ) {
				$identity = $this->policy->identity_for( $user );
				$sent = $identity ? $this->otp->challenge( $identity, 'two_factor', 'password_second_' . hash( 'sha256', $token ) ) : false;
				if ( is_wp_error( $sent ) ) {
					$errors = $sent;
				}
				$this->render_factor( $token, $is_totp, $errors );
				exit;
			}
			$attempt = $flows->attempt( $token, 'strong_login' );
			$credential = hash( 'sha256', wp_json_encode( $this->credentials->record( $user->ID ) ) );
			if ( $attempt && $this->verify_factor( $token, $attempt, $user, $this->value( 'code' ), $credential ) && $flows->consume( $token, 'strong_login', $attempt ) ) {
				$redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- Destination is validated by UserService.
				$result = $this->complete( $user, ! empty( $attempt['data']['remember'] ), $redirect );
				if ( ! is_wp_error( $result ) ) {
					do_action( 'anar_login_two_factor_passed', $user );
					wp_safe_redirect( $result['redirect'] );
					exit;
				}
				$errors = $result;
			} else {
				$errors->add( 'anar_factor_invalid', __( 'The code is invalid or this verification expired. Please sign in again if needed.', 'anar-toolkit' ) );
			}
		}
		$this->render_factor( $token, $is_totp, $errors );
		exit;
	}

	/** Called only after the server consumed a valid second-factor or passkey proof. */
	public function complete( $user, $remember, $redirect = '' ) {
		self::$approved = (int) $user->ID;
		$result = $this->users->login( $user, $remember );
		return is_wp_error( $result ) ? $result : array( 'redirect' => $this->users->redirect_url( $user, $redirect ) );
	}

	private function verify_factor( $token, $state, $user, $code, $credential ) {
		if ( 'totp' === $state['data']['factor'] ) {
			return hash_equals( $state['data']['credential'], $credential ) && $this->credentials->verify( $user->ID, $code );
		}
		$identity = $this->policy->identity_for( $user );
		return $identity && ! is_wp_error( $this->otp->check( $identity, $code, 'password_second_' . hash( 'sha256', $token ) ) );
	}

	private function render_factor( $token, $is_totp, $errors ) {
		FormView::prepare_wordpress();
		login_header( __( 'Account verification', 'anar-toolkit' ), '', $errors );
		echo FormView::open( new Settings(), 'anar-security-card', '', __( 'Account verification', 'anar-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared frame escapes values.
		echo '<form method="post" class="anar-form"><p>' . esc_html( $is_totp ? __( 'Enter your authenticator code or a recovery code.', 'anar-toolkit' ) : __( 'Enter the verification code sent to your linked contact.', 'anar-toolkit' ) ) . '</p><p><label for="anar-factor-code">' . esc_html__( 'Verification code', 'anar-toolkit' ) . '</label><input type="text" class="input" id="anar-factor-code" name="code" autocomplete="one-time-code" dir="ltr" required autofocus></p>';
		wp_nonce_field( 'anar_strong_' . $token );
		echo '<button class="anar-btn anar-btn--primary" type="submit">' . esc_html__( 'Verify and sign in', 'anar-toolkit' ) . '</button></form>';
		if ( ! $is_totp ) {
			echo '<form method="post" class="anar-form">';
			wp_nonce_field( 'anar_strong_' . $token );
			echo '<button class="anar-auth__back" name="resend" value="1">' . esc_html__( 'Resend code', 'anar-toolkit' ) . '</button></form>';
		}
		echo FormView::close( __( 'Changes to account security require verification.', 'anar-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared frame escapes values.
		login_footer();
	}

	public function manage() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( add_query_arg( 'action', 'anar_security', wp_login_url() ) ) );
			exit;
		}
		if ( ! SecureTransport::available() ) {
			wp_die( esc_html__( 'Open the secure HTTPS address of this site to manage or verify account security.', 'anar-toolkit' ) );
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$user = wp_get_current_user();
		$credentials = $this->credentials;
		$flows = $this->flows;
		$enabled = $credentials->enabled( $user->ID );
		$identity = $this->policy->identity_for( $user );
		$errors = new WP_Error();
		$notice = '';
		$secret = '';
		$token = '';
		$codes = array();
		$passkey_flow = '';
		if ( 'post' === sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			check_admin_referer( 'anar_security_manage' );
			$operation = $this->value( 'passkey_rename' ) ? 'passkey_rename' : ( $this->value( 'passkey_key' ) ? 'passkey_remove' : $this->value( 'operation' ) );
			$purpose = 'security_manage_' . $user->ID;
			if ( 'send' === $operation && ! $enabled && $identity ) {
				$result = $this->otp->challenge( $identity, 'login_otp', $purpose );
				if ( is_wp_error( $result ) ) {
					$errors = $result;
				} else {
					$notice = __( 'A verification code has been sent to your linked contact.', 'anar-toolkit' );
				}
			} elseif ( 'start' === $operation && ! CredentialService::available() ) {
				$errors->add( 'anar_factor_unavailable', __( 'Authenticator setup requires OpenSSL with AES-256-GCM on this server.', 'anar-toolkit' ) );
			} elseif ( 'start' === $operation && ! $enabled && $this->authorize_management( $user, $this->value( 'proof' ) ) ) {
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
					$codes = $credentials->enroll( $user->ID, $secret, $this->value( 'code' ) );
				}
				if ( ! $codes ) {
					$errors->add( 'anar_factor_invalid', __( 'The code is invalid. Start setup again.', 'anar-toolkit' ) );
				}
				$enabled = $credentials->enabled( $user->ID );
			} elseif ( in_array( $operation, array( 'remove', 'recovery', 'passkey_start', 'passkey_remove', 'passkey_rename' ), true ) && $this->authorize_management( $user, $this->value( 'proof' ) ) ) {
				if ( 'remove' === $operation && $enabled ) {
					$credentials->remove( $user->ID );
					$enabled = false;
					$notice = __( 'Authenticator removed.', 'anar-toolkit' );
				} elseif ( 'recovery' === $operation && $enabled ) {
					$codes = $credentials->regenerate_recovery( $user->ID );
				} elseif ( 'passkey_start' === $operation && \Anar\Login\Frontend\Passkeys::enabled() ) {
					$passkey_flow = $flows->issue( 'passkey_register', array( 'user_id' => (int) $user->ID, 'password' => hash( 'sha256', $user->user_pass ), 'credential' => hash( 'sha256', wp_json_encode( $credentials->record( $user->ID ) ) ) ) );
					if ( is_wp_error( $passkey_flow ) ) {
						$errors = $passkey_flow;
						$passkey_flow = '';
					}
				} elseif ( 'passkey_rename' === $operation ) {
					$key = $this->value( 'passkey_rename' );
					if ( ( new PasskeyRepository() )->rename( $user->ID, $key, $this->value( 'passkey_name_' . $key ) ) ) {
						$notice = __( 'Passkey name updated.', 'anar-toolkit' );
					}
				} elseif ( 'passkey_remove' === $operation ) {
					if ( ( new PasskeyRepository() )->remove( $user->ID, $this->value( 'passkey_key' ) ) ) {
						$notice = __( 'Passkey removed.', 'anar-toolkit' );
					}
				}
			} else {
				$errors->add( 'anar_factor_invalid', __( 'The code is invalid. Please try again.', 'anar-toolkit' ) );
			}
		}
		FormView::prepare_wordpress();
		login_header( __( 'Account security', 'anar-toolkit' ), $notice ? '<p class="message">' . esc_html( $notice ) . '</p>' : '', $errors );
		echo FormView::open( new Settings(), 'anar-security-card', '', __( 'Account security', 'anar-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared frame escapes values.
		if ( $codes ) {
			echo '<div class="message"><strong>' . esc_html__( 'Save these recovery codes now. Each code works once.', 'anar-toolkit' ) . '</strong><pre dir="ltr">' . esc_html( implode( "\n", $codes ) ) . '</pre></div>';
		}
		echo '<form method="post" class="anar-form">';
		wp_nonce_field( 'anar_security_manage' );
		if ( $passkey_flow ) {
			echo '<label for="anar-passkey-name">' . esc_html__( 'Passkey name', 'anar-toolkit' ) . '</label><input type="text" class="input" id="anar-passkey-name" name="passkey_name" maxlength="80" value="' . esc_attr__( 'My device', 'anar-toolkit' ) . '">';
			echo \Anar\Login\Frontend\Passkeys::button( 'register', $passkey_flow ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes values.
		} elseif ( $enabled ) {
			echo '<p>' . esc_html__( 'Verify your authenticator or a recovery code to change account security.', 'anar-toolkit' ) . '</p><label for="anar-remove-code">' . esc_html__( 'Authenticator or recovery code', 'anar-toolkit' ) . '</label><input type="text" class="input" id="anar-remove-code" name="proof" autocomplete="one-time-code" dir="ltr" required><button class="anar-btn anar-btn--soft" name="operation" value="remove">' . esc_html__( 'Remove authenticator', 'anar-toolkit' ) . '</button><button class="anar-btn anar-btn--soft" name="operation" value="recovery">' . esc_html__( 'Generate new recovery codes', 'anar-toolkit' ) . '</button>';
		} elseif ( $secret ) {
			echo '<p>' . esc_html__( 'Add this setup key in your authenticator app, then enter its six-digit code.', 'anar-toolkit' ) . '</p><p dir="ltr"><code>' . esc_html( $secret ) . '</code></p><input type="hidden" name="flow" value="' . esc_attr( $token ) . '"><label for="anar-enroll-code">' . esc_html__( 'Authenticator code', 'anar-toolkit' ) . '</label><input type="text" class="input" id="anar-enroll-code" name="code" autocomplete="one-time-code" inputmode="numeric" dir="ltr" required><button class="anar-btn anar-btn--primary" name="operation" value="confirm">' . esc_html__( 'Enable authenticator', 'anar-toolkit' ) . '</button>';
		} else {
				echo '<p>' . esc_html__( 'First verify your linked phone number or email to change account security.', 'anar-toolkit' ) . '</p><button class="anar-btn anar-btn--soft" name="operation" value="send" formnovalidate>' . esc_html__( 'Send verification code', 'anar-toolkit' ) . '</button><p><label for="anar-proof">' . esc_html__( 'Verification code', 'anar-toolkit' ) . '</label><input type="text" class="input" id="anar-proof" name="proof" autocomplete="one-time-code" inputmode="numeric" dir="ltr" required></p><button class="anar-btn anar-btn--primary" name="operation" value="start">' . esc_html__( 'Set up authenticator', 'anar-toolkit' ) . '</button>';
		}
		if ( ! $secret && ! $passkey_flow && \Anar\Login\Frontend\Passkeys::enabled() ) {
			echo '<p><button class="anar-btn anar-btn--soft" name="operation" value="passkey_start">' . esc_html__( 'Add a passkey', 'anar-toolkit' ) . '</button></p>';
			foreach ( ( new PasskeyRepository() )->all( $user->ID ) as $key => $record ) {
				echo '<p><label>' . esc_html__( 'Passkey name', 'anar-toolkit' ) . '<input type="text" class="input" name="passkey_name_' . esc_attr( $key ) . '" value="' . esc_attr( $record['name'] ) . '" maxlength="80"></label><small>' . esc_html( $record['last_used'] ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $record['last_used'] ) : __( 'Not used yet', 'anar-toolkit' ) ) . '</small><button class="anar-auth__back" name="passkey_rename" value="' . esc_attr( $key ) . '">' . esc_html__( 'Save name', 'anar-toolkit' ) . '</button> <button class="anar-auth__back" name="passkey_key" value="' . esc_attr( $key ) . '">' . esc_html__( 'Remove passkey', 'anar-toolkit' ) . '</button></p>';
			}
		}
		echo '</form>';
		echo FormView::close( __( 'Changes to account security require verification.', 'anar-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared frame escapes values.
		login_footer();
		exit;
	}

	private function authorize_management( $user, $proof ) {
		if ( $this->credentials->enabled( $user->ID ) ) {
			return $this->credentials->verify( $user->ID, $proof );
		}
		$identity = $this->policy->identity_for( $user );
		return $identity && ! is_wp_error( $this->otp->check( $identity, $proof, 'security_manage_' . $user->ID ) );
	}
}
