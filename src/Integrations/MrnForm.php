<?php
/**
 * Optional MRN Forms registration and SMS verification.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Integrations;

use Anar\Login\Auth\BotChallenge;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Frontend\FormView;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Support\Identity;
use MRN\Form\Anar_Integration;
use MRN\Form\Form_Repository;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class MrnForm {
	private const SCOPE = 'mrn_forms';

	/** Can this published form request and verify codes? */
	public static function accepts( array $form ) {
		if ( 'published' !== $form['status'] ) {
			return false;
		}
		if ( 'sms' === Anar_Integration::mode( $form ) ) {
			return true;
		}
		$settings = new Settings();
		return 'registration' === Anar_Integration::mode( $form )
			&& self::registration_enabled( $settings )
			&& absint( $settings->get( 'mrn_registration_form', 0 ) ) === $form['id']
			&& ! is_user_logged_in() && ! wp_validate_auth_cookie( '', 'logged_in' );
	}

	/** Send to the selected field with the login code's delivery configuration. */
	public static function send( array $form, $phone, array $proof ) {
		$identity = self::identity( $phone );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$settings  = new Settings();
		$guard     = ( new BotChallenge( $settings, new RateLimiter( $settings ) ) )->guard( array_filter( $proof, 'is_scalar' ) );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$challenge = bin2hex( random_bytes( 16 ) );
		$sent      = self::otp( $settings )->challenge( $identity, 'login_otp', self::purpose( $form, $challenge ), self::SCOPE );
		if ( is_wp_error( $sent ) ) {
			if ( in_array( $sent->get_error_code(), array( 'anar_too_soon', 'anar_rate_limited' ), true ) ) {
				return $sent;
			}
			return new WP_Error( 'anar_mrn_delivery', __( 'The verification code could not be sent. Please try again later.', 'anar-toolkit' ), array( 'status' => 502 ) );
		}
		return array(
			'challenge'   => $challenge,
			'resendAfter' => absint( $settings->get( 'resend_delay', 60 ) ),
			'message'     => __( 'The verification code was sent. Enter it below.', 'anar-toolkit' ),
		);
	}

	/** Check and atomically consume a code for this form, field and phone. */
	public static function verify( array $form, $phone, array $request ) {
		$identity = self::identity( $phone );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$challenge = $request['_mrnf_sms_challenge'] ?? '';
		$code      = $request['_mrnf_sms_code'] ?? '';
		if ( ! is_string( $challenge ) || ! preg_match( '/^[a-f0-9]{32}$/D', $challenge ) || ! is_string( $code ) || '' === trim( $code ) || strlen( $code ) > 128 ) {
			return new WP_Error( 'anar_mrn_code_required', __( 'Request and enter the SMS verification code.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		$checked = self::otp( new Settings() )->check( $identity, $code, self::purpose( $form, $challenge ) );
		return is_wp_error( $checked ) ? $checked : $identity;
	}

	/** Create the account only after MRN Forms has verified the number and stored the entry. */
	public static function complete_registration( array $form, array $identity, $entry_id ) {
		if ( ! self::accepts( $form ) ) {
			return Anar_Integration::unavailable();
		}
		$users = new UserService( new Settings() );
		$user  = $users->register_verified_form( $identity, $form['id'], $entry_id );
		if ( ! $user instanceof WP_User ) {
			if ( is_wp_error( $user ) && 'anar_mrn_existing_account' === $user->get_error_code() ) {
				return $user;
			}
			return new WP_Error( 'anar_mrn_registration_failed', __( 'Your account could not be created. Request a new code and try again.', 'anar-toolkit' ), array( 'status' => 409 ) );
		}
		$login = $users->login( $user, false );
		if ( $login instanceof WP_User ) {
			return array( 'redirect' => $users->redirect_url( $user, $form['settings']['redirectUrl'] ) );
		}
		$data     = $login->get_error_data();
		$redirect = self::signin_url();
		if ( 'anar_factor_required' === $login->get_error_code() && is_string( $data['redirect'] ?? null ) ) {
			$redirect = wp_validate_redirect( $data['redirect'], $redirect );
		}
		return array(
			'message'  => __( 'Your account was created. Continue to sign in.', 'anar-toolkit' ),
			'redirect' => $redirect,
		);
	}

	/** URL shared by inline login, WooCommerce and the lazy popup. */
	public static function registration_url( Settings $settings ) {
		return self::registration_form( $settings ) ? add_query_arg( 'action', 'anar_register', wp_login_url() ) : '';
	}

	private static function signin_url() {
		return add_query_arg( 'action', 'anar_signin', wp_login_url() );
	}

	/** Render the registration form in WordPress' login frame. */
	public static function page() {
		$settings = new Settings();
		$form     = self::registration_form( $settings );
		if ( is_user_logged_in() ) {
			wp_safe_redirect( ( new UserService( $settings ) )->redirect_url( wp_get_current_user() ) );
			exit;
		}
		if ( ! $form ) {
			wp_die( esc_html__( 'The registration form is currently unavailable.', 'anar-toolkit' ), '', array( 'response' => 404, 'back_link' => true ) );
		}

		$title = __( 'Create your account', 'anar-toolkit' );
		FormView::prepare_wordpress();
		$html = mrn_form_plugin()->renderer()->render( $form['id'], array( 'showTitle' => false ) );
		add_action( 'login_footer', 'wp_print_footer_scripts' );
		login_header( $title );
		echo FormView::open( $settings, 'anar-registration', '', $title, __( 'Complete the form and verify your mobile number to register.', 'anar-toolkit' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared frame escapes its values.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MRN Forms renderer escapes its output.
		printf( '<p class="anar-registration__signin"><a class="anar-btn anar-btn--soft" href="%s">%s</a></p>', esc_url( self::signin_url() ), esc_html__( 'Already have an account? Sign in', 'anar-toolkit' ) );
		echo FormView::close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared frame escapes its values.
		login_footer();
		exit;
	}

	/** Published registration forms with a valid phone field. */
	public static function registration_forms() {
		if ( ! class_exists( Anar_Integration::class ) ) {
			return array();
		}
		return array_filter(
			( new Form_Repository() )->all( array( 'status' => 'published', 'limit' => 500 ) ),
			static function ( $form ) {
				return 'registration' === Anar_Integration::mode( $form ) && '' !== Anar_Integration::phone_key( $form );
			}
		);
	}

	private static function registration_form( Settings $settings ) {
		$id = absint( $settings->get( 'mrn_registration_form', 0 ) );
		if ( ! $id || ! self::registration_enabled( $settings ) || ! class_exists( Anar_Integration::class ) ) {
			return null;
		}
		$form = ( new Form_Repository() )->find( $id );
		return $form && self::accepts( $form ) && Anar_Integration::phone_key( $form ) ? $form : null;
	}

	private static function registration_enabled( Settings $settings ) {
		return Features::enabled( 'login' ) && $settings->get( 'allow_registration', 1 ) && 'email' !== $settings->get( 'identity_mode', 'both' );
	}

	private static function otp( Settings $settings ) {
		return new OtpService( $settings, new OtpRepository(), new RateLimiter( $settings ), ProviderRegistry::shared(), new UserService( $settings ) );
	}

	private static function purpose( array $form, $challenge ) {
		return implode( '|', array( self::SCOPE, $form['id'], Anar_Integration::mode( $form ), Anar_Integration::phone_key( $form ), $challenge ) );
	}

	private static function identity( $phone ) {
		$identity = Identity::normalize( is_string( $phone ) ? $phone : '' );
		return ! is_wp_error( $identity ) && 'sms' === $identity['channel'] ? $identity : new WP_Error( 'anar_mrn_phone', __( 'Please enter a valid Iranian mobile number.', 'anar-toolkit' ), array( 'status' => 400 ) );
	}
}
