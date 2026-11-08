<?php
/**
 * Bot check, visitor-address and shared code-generation tests; no network or real database.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\BotChallenge;
use Anar\Login\Auth\CodeChallenge;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'wp_roles' ) ) {
	function wp_roles() {
		return new class() {
			public function is_role( $role ) {
				return in_array( $role, array( 'administrator', 'shop_manager', 'customer' ), true );
			}
		};
	}
}

final class AbuseGuardTest extends TestCase {

	protected function setUp(): void {
		// In-memory stand-in for the anar_login_limits queries RateLimiter issues.
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';
			public $rows   = array();

			public function prepare( $sql, ...$args ) {
				return array( $sql, $args );
			}

			public function query( $prepared ) {
				list( $sql, $args ) = $prepared;
				if ( 0 === strpos( $sql, 'DELETE' ) ) {
					if ( isset( $this->rows[ $args[1] ] ) && $this->rows[ $args[1] ]['expires'] <= $args[2] ) {
						unset( $this->rows[ $args[1] ] );
						return 1;
					}
					return 0;
				}
				if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
					if ( isset( $this->rows[ $args[1] ] ) ) {
						return 0;
					}
					$this->rows[ $args[1] ] = array( 'attempts' => 0, 'expires' => $args[2] );
					return 1;
				}
				$row = $this->rows[ $args[1] ] ?? null;
				if ( $row && $row['attempts'] < $args[2] && $row['expires'] > $args[3] ) {
					++$this->rows[ $args[1] ]['attempts'];
					return 1;
				}
				return 0;
			}

			public function get_var( $prepared ) {
				$row = $this->rows[ $prepared[1][1] ] ?? null;
				return $row && $row['expires'] > $prepared[1][2] ? $row['attempts'] : null;
			}
		};
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] = array( 'bot_challenge' => 'adaptive', 'challenge_threshold' => 200 );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_AR_REAL_IP'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] );
	}

	private function solved( $bits = BotChallenge::BITS, $expires = null ) {
		$seed    = bin2hex( random_bytes( 16 ) );
		$expires = $expires ?? time() + 60;
		$nonce   = 0;
		while ( BotChallenge::zero_bits( hash( 'sha256', $seed . ':' . $nonce, true ) ) < $bits ) {
			++$nonce;
		}
		return array(
			'seed'    => $seed,
			'expires' => $expires,
			'bits'    => $bits,
			'sig'     => hash_hmac( 'sha256', $seed . '|' . $expires . '|' . $bits, wp_salt( 'nonce' ) ),
			'nonce'   => (string) $nonce,
		);
	}

	public function test_zero_bit_counting() {
		$this->assertSame( 0, BotChallenge::zero_bits( "\x80" . str_repeat( "\0", 31 ) ) );
		$this->assertSame( 8, BotChallenge::zero_bits( "\0\xff" ) );
		$this->assertSame( 17, BotChallenge::zero_bits( "\0\0\x40" ) );
	}

	public function test_solved_puzzle_is_accepted_once() {
		$guard = new BotChallenge( new Settings(), new RateLimiter( new Settings() ) );
		$proof = $this->solved();
		$this->assertTrue( $guard->valid( $proof ) );
		$this->assertFalse( $guard->valid( $proof ), 'A solution must not be replayable.' );
	}

	public function test_tampered_weak_or_expired_puzzles_are_rejected() {
		$guard  = new BotChallenge( new Settings(), new RateLimiter( new Settings() ) );
		$proof  = $this->solved();
		$forged = array_merge( $proof, array( 'expires' => $proof['expires'] + 30 ) );
		$this->assertFalse( $guard->valid( $forged ) );
		$this->assertFalse( $guard->valid( $this->solved( 8 ) ), 'Fewer bits than required.' );
		$this->assertFalse( $guard->valid( $this->solved( BotChallenge::BITS, time() - 1 ) ) );
		$this->assertFalse( $guard->valid( 'not-an-array' ) );
		$wrong = $proof;
		do {
			$wrong['nonce'] = (string) ( (int) $wrong['nonce'] + 1 );
		} while ( BotChallenge::zero_bits( hash( 'sha256', $wrong['seed'] . ':' . $wrong['nonce'], true ) ) >= BotChallenge::BITS );
		$this->assertFalse( $guard->valid( $wrong ), 'An unsolved nonce is rejected even with a genuine signature.' );
	}

	public function test_adaptive_mode_only_asks_after_repeated_requests() {
		$settings = new Settings();
		$limiter  = new RateLimiter( $settings );
		$guard    = new BotChallenge( $settings, $limiter );
		$this->assertFalse( $guard->required() );
		$this->assertTrue( $limiter->guard_request( '+989121111111' ) );
		$this->assertFalse( $guard->required(), 'The first request from an address is never challenged.' );
		$this->assertTrue( $limiter->guard_request( '+989122222222' ) );
		$this->assertTrue( $guard->required() );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['bot_challenge'] = 'off';
		$this->assertFalse( $guard->required() );
	}

	public function test_hourly_allowance_is_shared_across_purposes() {
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ] += array( 'max_requests_hour' => 2, 'resend_delay' => 30 );
		$limiter = new RateLimiter( new Settings() );
		$this->assertTrue( $limiter->guard_request( '+989123333333' ) );
		$this->assertTrue( $limiter->guard_request( '+989123333333', 'password' ), 'Another purpose has its own resend cooldown.' );
		$this->assertSame( 'anar_rate_limited', $limiter->guard_request( '+989123333333', 'stock' )->get_error_code(), 'The hourly allowance per number is shared.' );
	}

	public function test_visitor_address_behind_a_proxy_and_ipv6_grouping() {
		$limiter = new RateLimiter( new Settings() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.1';
		$this->assertSame( '203.0.113.7', $limiter->client_ip(), 'Headers are ignored until the administrator trusts one.' );
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['client_ip_header'] = 'x-forwarded-for';
		$this->assertSame( '198.51.100.9', ( new RateLimiter( new Settings() ) )->client_ip() );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
		$this->assertSame( '203.0.113.7', ( new RateLimiter( new Settings() ) )->client_ip() );
		$this->assertSame( '', RateLimiter::proxy_header( 'X-Evil-Header' ) );
		unset( $GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['client_ip_header'] );
		$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12:1::5';
		$first = ( new RateLimiter( new Settings() ) )->client_ip();
		$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12:ffff::9';
		$this->assertSame( $first, ( new RateLimiter( new Settings() ) )->client_ip(), 'One /64 is one subscriber.' );
	}

	public function test_codes_have_the_exact_length() {
		foreach ( array( 4, 5, 6, 8 ) as $length ) {
			$this->assertMatchesRegularExpression( '/^[1-9]\d{' . ( $length - 1 ) . '}$/D', CodeChallenge::generate( $length ) );
		}
		$this->assertSame( 4, strlen( CodeChallenge::generate( 1 ) ) );
	}

	public function test_role_redirect_lines() {
		$map = Settings::role_redirects( "shop_manager = https://example.org/wp-admin/\nghost=https://example.org/\ncustomer|/my-account/\nbroken line" );
		$this->assertSame( array( 'shop_manager' => 'https://example.org/wp-admin/', 'customer' => '/my-account/' ), $map );
		$this->assertSame( $map, Settings::role_redirects( $map ) );
	}
}
