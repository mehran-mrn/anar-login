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
		$identity = $this->identity( $raw_identity );
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
			return new WP_Error( 'anar_request_blocked', __( 'A login code cannot be sent to this identifier.', 'anar-toolkit' ), array( 'status' => 403 ) );
		}

		// Avoid account enumeration when registration is disabled.
		if ( ! $this->settings->get( 'allow_registration', 1 ) && ! $this->users->find_by_identity( $identity ) ) {
			return $this->generic_request_response( $identity['channel'] );
		}

		$sent = $this->send_code( $identity );

		return is_wp_error( $sent ) ? $sent : $this->generic_request_response( $identity['channel'] );
	}

	/**
	 * Send a code to a known identity, e.g. as a second factor.
	 *
	 * Rate limits apply; registration and sign-in-mode checks do not, since the
	 * identity comes from an existing account rather than user input.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @return true|WP_Error
	 */
	public function challenge( $identity ) {
		$guard = $this->limiter->guard_request( $identity['identity'] );

		return is_wp_error( $guard ) ? $guard : $this->send_code( $identity );
	}

	/**
	 * Check and consume a code without creating a session.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @param string                                $raw_code Submitted code.
	 * @return true|WP_Error
	 */
	public function check( $identity, $raw_code ) {
		$code = preg_replace( '/\D/', '', Identity::latin_digits( (string) $raw_code ) );
		if ( ! $code ) {
			return $this->invalid_code();
		}

		$challenge = $this->repository->latest( $identity['identity'], $identity['channel'] );
		if ( ! $challenge || strtotime( $challenge->expires_at . ' UTC' ) < time() ) {
			return $this->invalid_code();
		}

		if ( ! $this->repository->claim_attempt( $challenge->id, absint( $this->settings->get( 'max_verify_attempts', 5 ) ) ) ) {
			return new WP_Error( 'anar_too_many_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'anar-toolkit' ), array( 'status' => 429 ) );
		}

		if ( ! $this->repository->matches( $challenge, $identity['identity'], $code ) || ! $this->repository->consume( $challenge->id ) ) {
			return $this->invalid_code();
		}

		return true;
	}

	/**
	 * Create, store and deliver a code.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @return true|WP_Error
	 */
	private function send_code( $identity ) {
		$ttl  = absint( $this->settings->get( 'otp_ttl', 120 ) );
		$code = $this->generate_code();

		if ( ! $this->repository->create( $identity['identity'], $identity['channel'], $code, $ttl ) ) {
			return new WP_Error( 'anar_otp_storage', __( 'The login code could not be created.', 'anar-toolkit' ), array( 'status' => 500 ) );
		}

		$sent = $this->dispatch( $identity, $code, $ttl );

		if ( is_wp_error( $sent ) ) {
			$challenge = $this->repository->latest( $identity['identity'], $identity['channel'] );
			if ( $challenge ) {
				$this->repository->consume( $challenge->id );
			}
			$this->limiter->release( $identity['identity'] );
			$this->log_error( $sent, $identity['channel'] );

			return new WP_Error(
				'anar_dispatch_failed',
				__( 'The login code could not be sent. Please try again later.', 'anar-toolkit' ),
				array( 'status' => 502 )
			);
		}

		do_action( 'anar_login_otp_sent', $identity['channel'], $identity['identity'] );

		return true;
	}

	/**
	 * Verify code and create a WordPress session.
	 *
	 * @param string $raw_identity User input.
	 * @param string $raw_code     User code.
	 * @param bool   $remember     Remember login.
	 * @param string $redirect_to  Requested post-login destination.
	 * @return array<string,mixed>|WP_Error
	 */
	public function verify( $raw_identity, $raw_code, $remember = true, $redirect_to = '' ) {
		$identity = $this->identity( $raw_identity );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$checked = $this->check( $identity, $raw_code );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$user = $this->users->login_or_create( $identity, (bool) $remember );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		return array(
			'message'  => __( 'You have signed in successfully.', 'anar-toolkit' ),
			'redirect' => $this->users->redirect_url( $user, (string) $redirect_to ),
			'user'     => $this->users->profile( $user ),
		);
	}

	/**
	 * Send a test code with the saved provider settings, for administrators.
	 *
	 * Bypasses rate limits and stores nothing; the provider response is
	 * returned so configuration problems can be fixed from the settings page.
	 *
	 * @param string $raw_phone Recipient mobile number.
	 * @return array<string,string>|WP_Error
	 */
	public function send_test( $raw_phone ) {
		$identity = Identity::normalize( $raw_phone );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		if ( 'sms' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_identity', __( 'Please enter a valid Iranian mobile number.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}

		$sent = $this->dispatch( $identity, $this->generate_code(), absint( $this->settings->get( 'otp_ttl', 120 ) ) );
		if ( is_wp_error( $sent ) ) {
			$detail = $this->error_detail( $sent );

			return new WP_Error(
				$sent->get_error_code(),
				$detail ? $sent->get_error_message() . ' — ' . $detail : $sent->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return array(
			'message' => sprintf(
				/* translators: %s: recipient mobile number. */
				__( 'A test code was sent to %s.', 'anar-toolkit' ),
				Identity::local_phone( $identity['identity'] )
			),
		);
	}

	/**
	 * Normalize input and enforce the enabled sign-in methods.
	 *
	 * @param string $raw_identity User input.
	 * @return array{identity:string,channel:string}|WP_Error
	 */
	private function identity( $raw_identity ) {
		$identity = Identity::normalize( $raw_identity );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$mode = (string) $this->settings->get( 'identity_mode', 'both' );
		if ( 'phone' === $mode && 'sms' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_identity', __( 'Please enter a valid Iranian mobile number.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}
		if ( 'email' === $mode && 'email' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_identity', __( 'Please enter a valid email address.', 'anar-toolkit' ), array( 'status' => 400 ) );
		}

		return $identity;
	}

	/**
	 * Generate a uniformly random numeric code.
	 *
	 * @return string
	 */
	private function generate_code() {
		$length = absint( $this->settings->get( 'otp_length', 5 ) );

		return (string) random_int( 10 ** ( $length - 1 ), ( 10 ** $length ) - 1 );
	}

	/**
	 * Deliver a code over the identity's channel.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @param string                                $code     Code.
	 * @param int                                   $ttl      Lifetime.
	 * @return true|WP_Error
	 */
	private function dispatch( $identity, $code, $ttl ) {
		if ( 'sms' === $identity['channel'] ) {
			return $this->providers->send( $identity['identity'], $code, $this->render( (string) $this->settings->get( 'sms_message', '' ), $code, $ttl ) );
		}

		$subject = $this->render( (string) $this->settings->get( 'email_subject', '' ), $code, $ttl );
		$body    = $this->render( (string) $this->settings->get( 'email_message', '' ), $code, $ttl );

		return wp_mail( $identity['identity'], $subject, $body )
			? true
			: new WP_Error( 'anar_email_failed', __( 'The email could not be sent. Please check the site email settings.', 'anar-toolkit' ) );
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
				? __( 'If sign-in is available, a code has been sent to your email.', 'anar-toolkit' )
				: __( 'If sign-in is available, a code has been sent to your mobile.', 'anar-toolkit' ),
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
				'{domain}'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
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
		return new WP_Error( 'anar_invalid_code', __( 'The code is invalid or has expired.', 'anar-toolkit' ), array( 'status' => 400 ) );
	}

	/**
	 * Provider response detail from an error, if any.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private function error_detail( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( array( 'detail', 'body' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}

		return '';
	}

	/**
	 * Log safe diagnostics when enabled.
	 *
	 * @param WP_Error $error   Error.
	 * @param string   $channel Channel.
	 * @return void
	 */
	private function log_error( WP_Error $error, $channel ) {
		if ( ! $this->settings->get( 'debug_log', 0 ) ) {
			return;
		}

		error_log( sprintf( '[Anar Login] %s dispatch failed: %s', sanitize_key( $channel ), sanitize_key( $error->get_error_code() ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
