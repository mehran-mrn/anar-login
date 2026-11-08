<?php
/**
 * Invisible, self-hosted bot check for code requests.
 *
 * Every code costs the store an SMS, so scripted floods are a direct expense.
 * Instead of a third-party captcha (often unreachable or slow for Iranian
 * visitors), the browser solves a short proof-of-work puzzle in the background.
 * In adaptive mode the puzzle appears only for an address that already asked
 * for codes this hour, or while the whole site is under an unusual load, so
 * ordinary visitors never see or wait for it.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class BotChallenge {
	/** Default work: about 130k hashes, well under a second on a mid-range phone. */
	const BITS = 17;

	/** Seconds a puzzle stays solvable. */
	const TTL = 300;

	/** @var Settings */
	private $settings;

	/** @var RateLimiter */
	private $limiter;

	public function __construct( Settings $settings, RateLimiter $limiter ) {
		$this->settings = $settings;
		$this->limiter  = $limiter;
	}

	/**
	 * Whether this request must carry a solved puzzle.
	 *
	 * @return bool
	 */
	public function required() {
		$mode = (string) $this->settings->get( 'bot_challenge', 'adaptive' );
		if ( 'off' === $mode ) {
			return false;
		}
		if ( 'always' === $mode ) {
			return true;
		}

		return $this->limiter->ip_requests() >= 2 || $this->limiter->site_requests() >= absint( $this->settings->get( 'challenge_threshold', 200 ) );
	}

	/**
	 * Accept the request, or ask the browser to solve a fresh puzzle.
	 *
	 * @param mixed $proof Submitted proof.
	 * @return true|WP_Error
	 */
	public function guard( $proof ) {
		if ( ! $this->required() || $this->valid( $proof ) ) {
			return true;
		}

		return new WP_Error(
			'anar_challenge_required',
			__( 'Checking your connection. Please try again.', 'anar-toolkit' ),
			array(
				'status'    => 428,
				'challenge' => $this->issue(),
			)
		);
	}

	/**
	 * A signed puzzle the server can verify without storing it.
	 *
	 * @return array<string,mixed>
	 */
	public function issue() {
		$seed    = bin2hex( random_bytes( 16 ) );
		$expires = time() + self::TTL;
		$bits    = $this->bits();

		return array(
			'seed'    => $seed,
			'expires' => $expires,
			'bits'    => $bits,
			'sig'     => $this->sign( $seed, $expires, $bits ),
			'script'  => add_query_arg( 'ver', ANAR_LOGIN_VERSION, ANAR_LOGIN_URL . 'assets/js/challenge.js' ),
		);
	}

	/**
	 * Verify a solved, unexpired, unused puzzle.
	 *
	 * @param mixed $proof Proof fields: seed, expires, bits, sig and nonce.
	 * @return bool
	 */
	public function valid( $proof ) {
		if ( ! is_array( $proof ) ) {
			return false;
		}
		$seed    = (string) ( $proof['seed'] ?? '' );
		$expires = (int) ( $proof['expires'] ?? 0 );
		$bits    = (int) ( $proof['bits'] ?? 0 );
		$nonce   = (string) ( $proof['nonce'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $seed ) || ! preg_match( '/^\d{1,12}$/D', $nonce ) || $expires < time() || $expires > time() + self::TTL || $bits < $this->bits() ) {
			return false;
		}
		if ( ! hash_equals( $this->sign( $seed, $expires, $bits ), (string) ( $proof['sig'] ?? '' ) ) || self::zero_bits( hash( 'sha256', $seed . ':' . $nonce, true ) ) < $bits ) {
			return false;
		}

		// A solution is single-use; the database claim is the replay guard.
		return $this->limiter->once( 'challenge', $seed, self::TTL * 2 );
	}

	/** Leading zero bits of a binary digest. */
	public static function zero_bits( $digest ) {
		$bits = 0;
		foreach ( str_split( $digest ) as $byte ) {
			$value = ord( $byte );
			if ( 0 === $value ) {
				$bits += 8;
				continue;
			}
			while ( 0 === ( $value & 0x80 ) ) {
				++$bits;
				$value <<= 1;
			}
			break;
		}

		return $bits;
	}

	private function bits() {
		return max( 8, min( 24, (int) apply_filters( 'anar_login_challenge_bits', self::BITS ) ) );
	}

	private function sign( $seed, $expires, $bits ) {
		return hash_hmac( 'sha256', $seed . '|' . $expires . '|' . $bits, wp_salt( 'nonce' ) );
	}
}
