<?php
/**
 * OTP application service.
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

final class OtpService {
	/** @var Settings */
	private $settings;

	/** @var OtpRepository */
	private $repository;

	/** @var RateLimiter */
	private $limiter;

	/** @var ProviderRegistry */
	private $providers;

	/** @var UserService */
	private $users;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings   Settings.
	 * @param OtpRepository    $repository Repository.
	 * @param RateLimiter      $limiter    Limiter.
	 * @param ProviderRegistry $providers  Providers.
	 * @param UserService      $users      Users.
	 */
	public function __construct( Settings $settings, OtpRepository $repository, RateLimiter $limiter, ProviderRegistry $providers, UserService $users ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->limiter    = $limiter;
		$this->providers  = $providers;
		$this->users      = $users;
	}

	/**
	 * Request a new code.
	 *
	 * @param string $raw_identity User input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function request( $raw_identity ) {
		$identity = Identity::normalize( $raw_identity );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$guard = $this->limiter->guard_request( $identity['identity'] );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$allowed = apply_filters( 'anar_login_allow_otp_request', true, $identity );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new WP_Error( 'anar_request_blocked', __( 'A login code cannot be sent to this identifier.', 'anar-login' ), array( 'status' => 403 ) );
		}

		// Avoid account enumeration when registration is disabled.
		if ( ! $this->settings->get( 'allow_registration', 1 ) && ! $this->users->find_by_identity( $identity ) ) {
			return $this->generic_request_response( $identity['channel'] );
		}

		$length = absint( $this->settings->get( 'otp_length', 5 ) );
		$ttl    = absint( $this->settings->get( 'otp_ttl', 120 ) );
		$min    = 10 ** ( $length - 1 );
		$max    = ( 10 ** $length ) - 1;
		$code   = (string) random_int( $min, $max );

		if ( ! $this->repository->create( $identity['identity'], $identity['channel'], $code, $ttl ) ) {
			return new WP_Error( 'anar_otp_storage', __( 'The login code could not be created.', 'anar-login' ), array( 'status' => 500 ) );
		}

		$message = $this->render(
			(string) $this->settings->get( 'sms_message', '' ),
			$code,
			$ttl
		);

		if ( 'sms' === $identity['channel'] ) {
			$sent = $this->providers->send( $identity['identity'], $code, $message );
		} else {
			$subject = $this->render( (string) $this->settings->get( 'email_subject', '' ), $code, $ttl );
			$body    = $this->render( (string) $this->settings->get( 'email_message', '' ), $code, $ttl );
			$sent    = wp_mail( $identity['identity'], $subject, $body )
				? true
				: new WP_Error( 'anar_email_failed', __( 'The email could not be sent. Please check the site email settings.', 'anar-login' ) );
		}

		if ( is_wp_error( $sent ) ) {
			$challenge = $this->repository->latest( $identity['identity'], $identity['channel'] );
			if ( $challenge ) {
				$this->repository->consume( $challenge->id );
			}
			$this->log_error( $sent, $identity['channel'] );

			return new WP_Error(
				'anar_dispatch_failed',
				__( 'The login code could not be sent. Please try again later.', 'anar-login' ),
				array( 'status' => 502 )
			);
		}

		do_action( 'anar_login_otp_sent', $identity['channel'], $identity['identity'] );

		return $this->generic_request_response( $identity['channel'] );
	}

	/**
	 * Verify code and create a WordPress session.
	 *
	 * @param string $raw_identity User input.
	 * @param string $raw_code     User code.
	 * @param bool   $remember     Remember login.
	 * @return array<string,mixed>|WP_Error
	 */
	public function verify( $raw_identity, $raw_code, $remember = true ) {
		$identity = Identity::normalize( $raw_identity );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$code = preg_replace( '/\D/', '', Identity::latin_digits( (string) $raw_code ) );
		if ( ! $code ) {
			return $this->invalid_code();
		}

		$challenge = $this->repository->latest( $identity['identity'], $identity['channel'] );
		$max       = absint( $this->settings->get( 'max_verify_attempts', 5 ) );

		if ( ! $challenge || strtotime( $challenge->expires_at . ' UTC' ) < time() || (int) $challenge->attempts >= $max ) {
			return $this->invalid_code();
		}

		if ( ! wp_check_password( $code, $challenge->code_hash ) ) {
			$this->repository->increment_attempts( $challenge->id );

			return $this->invalid_code();
		}

		if ( ! $this->repository->consume( $challenge->id ) ) {
			return $this->invalid_code();
		}

		$user = $this->users->login_or_create( $identity, (bool) $remember );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$redirect = $this->redirect_url( $user->ID );

		return array(
			'message'  => __( 'You have signed in successfully.', 'anar-login' ),
			'redirect' => $redirect,
			'user'     => $this->users->profile( $user ),
		);
	}

	/**
	 * Generic successful request response.
	 *
	 * @param string $channel Channel.
	 * @return array<string,mixed>
	 */
	private function generic_request_response( $channel ) {
		return array(
			'message'      => 'email' === $channel
				? __( 'If sign-in is available, a code has been sent to your email.', 'anar-login' )
				: __( 'If sign-in is available, a code has been sent to your mobile.', 'anar-login' ),
			'channel'      => $channel,
			'resend_after' => absint( $this->settings->get( 'resend_delay', 60 ) ),
			'expires_in'   => absint( $this->settings->get( 'otp_ttl', 120 ) ),
		);
	}

	/**
	 * Render placeholders.
	 *
	 * @param string $template Template.
	 * @param string $code     Code.
	 * @param int    $ttl      Lifetime.
	 * @return string
	 */
	private function render( $template, $code, $ttl ) {
		$template = strtr(
			$template,
			array(
				'{code}'    => $code,
				'{site}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{minutes}' => (string) max( 1, (int) ceil( $ttl / 60 ) ),
			)
		);

		return (string) apply_filters( 'anar_login_otp_message', $template, $code, $ttl );
	}

	/**
	 * Standard invalid-code error.
	 *
	 * @return WP_Error
	 */
	private function invalid_code() {
		return new WP_Error( 'anar_invalid_code', __( 'The code is invalid or has expired.', 'anar-login' ), array( 'status' => 400 ) );
	}

	/**
	 * Determine safe redirect.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function redirect_url( $user_id ) {
		$configured = (string) $this->settings->get( 'login_redirect', '' );
		$panel_id   = absint( $this->settings->get( 'panel_page_id', 0 ) );
		$fallback   = $panel_id ? get_permalink( $panel_id ) : home_url( '/' );
		$url        = $configured ? $configured : $fallback;
		$url        = (string) apply_filters( 'anar_login_redirect_url', $url, $user_id );

		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	/**
	 * Log safe diagnostics when enabled.
	 *
	 * @param WP_Error $error   Error.
	 * @param string   $channel Channel.
	 * @return void
	 */
	private function log_error( WP_Error $error, $channel ) {
		if ( $this->settings->get( 'debug_log', 0 ) ) {
			error_log( sprintf( '[Anar Login] %s dispatch failed: %s', sanitize_key( $channel ), $error->get_error_code() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
