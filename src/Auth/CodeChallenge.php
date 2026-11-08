<?php
/**
 * The one implementation of "send a one-time code" and "check a one-time code".
 *
 * Login, second factor, password recovery, number linking and stock alerts all
 * issue and verify codes here. Each caller only chooses a storage key (what the
 * code authorizes), a message and its limits, so hashing, expiry, attempt
 * counting, delivery and failure cleanup cannot drift between features.
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

final class CodeChallenge {
	/** @var Settings */
	private $settings;

	/** @var OtpRepository */
	private $codes;

	/** @var RateLimiter */
	private $limiter;

	/** @var ProviderRegistry */
	private $providers;

	public function __construct( Settings $settings, OtpRepository $codes, RateLimiter $limiter, ProviderRegistry $providers ) {
		$this->settings  = $settings;
		$this->codes     = $codes;
		$this->limiter   = $limiter;
		$this->providers = $providers;
	}

	/** Build the service with default collaborators. */
	public static function create( ?Settings $settings = null ) {
		$settings = $settings ? $settings : new Settings();

		return new self( $settings, new OtpRepository(), new RateLimiter( $settings ), new ProviderRegistry( $settings ) );
	}

	/** @return RateLimiter */
	public function limiter() {
		return $this->limiter;
	}

	/**
	 * Create, store and deliver a code.
	 *
	 * @param array{identity:string,channel:string} $recipient Normalized phone or email.
	 * @param string                                $key       What the code authorizes; bind it to a purpose.
	 * @param callable                              $render    fn( $code, $ttl ): SMS text, or array( subject, body ) for email.
	 * @param array<string,mixed>                   $args      ttl, length, channel (storage), context (message id), scope (limit) and limit (bool).
	 * @return true|WP_Error
	 */
	public function issue( $recipient, $key, $render, $args = array() ) {
		$args = array_merge(
			array(
				'ttl'     => 300,
				'length'  => 6,
				'channel' => $recipient['channel'],
				'context' => 'login_otp',
				'scope'   => '',
				'limit'   => true,
			),
			$args
		);
		if ( $args['limit'] ) {
			$guard = $this->limiter->guard_request( $recipient['identity'], $args['scope'] );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}

		$ttl  = absint( $args['ttl'] );
		$code = self::generate( absint( $args['length'] ) );
		if ( ! $this->codes->create( $key, $args['channel'], $code, $ttl ) ) {
			return new WP_Error( 'anar_otp_storage', __( 'The login code could not be created.', 'anar-toolkit' ), array( 'status' => 500 ) );
		}

		$message = call_user_func( $render, $code, $ttl );
		if ( 'sms' === $recipient['channel'] ) {
			$sent = $this->providers->send( $recipient['identity'], $code, (string) $message, $args['context'] );
		} else {
			$message = (array) $message;
			$sent    = wp_mail( $recipient['identity'], (string) ( $message[0] ?? '' ), (string) ( $message[1] ?? '' ) )
				? true
				: new WP_Error( 'anar_email_failed', __( 'The email could not be sent. Please check the site email settings.', 'anar-toolkit' ) );
		}

		if ( is_wp_error( $sent ) ) {
			// The undelivered code must not stay usable, and the user may retry at once.
			$challenge = $this->codes->latest( $key, $args['channel'] );
			if ( $challenge ) {
				$this->codes->consume( $challenge->id );
			}
			$this->limiter->release( $recipient['identity'], $args['scope'] );

			return $sent;
		}
		if ( 'sms' === $recipient['channel'] ) {
			// Site-wide volume drives the adaptive bot check during a flood.
			$this->limiter->count_site_request();
		}

		return true;
	}

	/**
	 * Check a submitted code; optionally consume it.
	 *
	 * @param string              $key      Storage key used at issue time.
	 * @param string              $channel  Storage channel used at issue time.
	 * @param string              $raw_code Submitted code, any digit script.
	 * @param array<string,mixed> $args     attempts, length (exact digits, 0 = any) and consume (bool).
	 * @return object|WP_Error The challenge row on success.
	 */
	public function verify( $key, $channel, $raw_code, $args = array() ) {
		$args = array_merge(
			array(
				'attempts' => 5,
				'length'   => 0,
				'consume'  => true,
			),
			$args
		);
		$invalid = new WP_Error( 'anar_invalid_code', __( 'The code is invalid or has expired.', 'anar-toolkit' ), array( 'status' => 400 ) );
		$code    = (string) preg_replace( '/\D/', '', Identity::latin_digits( (string) $raw_code ) );
		if ( '' === $code || ( $args['length'] && strlen( $code ) !== (int) $args['length'] ) ) {
			return $invalid;
		}

		$challenge = $this->codes->latest( $key, $channel );
		if ( ! $challenge || strtotime( $challenge->expires_at . ' UTC' ) < time() ) {
			return $invalid;
		}
		if ( ! $this->codes->claim_attempt( $challenge->id, absint( $args['attempts'] ) ) ) {
			return new WP_Error( 'anar_too_many_attempts', __( 'Too many incorrect attempts. Please request a new code.', 'anar-toolkit' ), array( 'status' => 429 ) );
		}
		if ( ! $this->codes->matches( $challenge, $key, $code ) || ( $args['consume'] && ! $this->codes->consume( $challenge->id ) ) ) {
			return $invalid;
		}

		return $challenge;
	}

	/**
	 * Consume a challenge returned by verify( ..., consume: false ).
	 *
	 * @param object $challenge Challenge row.
	 * @return bool
	 */
	public function consume( $challenge ) {
		return $this->codes->consume( $challenge->id );
	}

	/**
	 * Uniformly random numeric code of an exact length.
	 *
	 * @param int $length Digits.
	 * @return string
	 */
	public static function generate( $length ) {
		$length = max( 4, min( 8, (int) $length ) );

		return (string) random_int( 10 ** ( $length - 1 ), ( 10 ** $length ) - 1 );
	}
}
