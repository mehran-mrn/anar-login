<?php
/**
 * Plugin composition root.
 *
 * @package AnarLogin
 */

namespace Anar\Login;

use Anar\Login\Admin\SettingsPage;
use Anar\Login\Api\AdminController;
use Anar\Login\Api\AuthController;
use Anar\Login\Api\ProfileController;
use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Frontend\Popup;
use Anar\Login\Frontend\Shortcodes;
use Anar\Login\Frontend\WpLogin;
use Anar\Login\Integrations\WooCommerce;
use Anar\Login\Integrations\WoodMart;
use Anar\Login\Infrastructure\Activator;
use Anar\Login\Infrastructure\OtpRepository;
use Anar\Login\Infrastructure\RateLimiter;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Get plugin instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire the plugin services.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'init', array( $this, 'init' ), 0 );
		add_action( 'init', array( $this, 'runtime' ), 1 );
		add_action( 'updated_option', array( \Anar\Login\Infrastructure\Features::class, 'changed' ), 10, 3 );
		add_action(
			'added_option',
			static function ( $option, $value ) {
				\Anar\Login\Infrastructure\Features::changed( $option, null, $value );
			},
			10,
			2
		);
		add_action( 'anar_login_daily_cleanup', array( $this, 'cleanup' ) );
		add_action(
			'deleted_user',
			static function ( $user_id ) {
				( new \Anar\Login\Auth\CredentialService() )->remove( $user_id );
				( new \Anar\Login\Auth\PasskeyRepository() )->remove_user( $user_id );
			}
		);
		// Diagnostics are built only for an authenticated administrator's REST request.
		add_action(
			'rest_api_init',
			function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return; }
				$settings = new Settings();
				$providers = new ProviderRegistry( $settings );
				$users = new UserService( $settings );
				$otp = new OtpService( $settings, new OtpRepository(), new RateLimiter( $settings ), $providers, $users );
				( new AdminController( $otp, $settings, $providers ) )->routes();
				( new \Anar\Login\Api\MeliSignupController() )->routes();
			},
			9
		);
		if ( is_admin() ) {
			$settings = new Settings();
			$providers = ProviderRegistry::shared();
			( new SettingsPage( $settings, $providers ) )->register();
			( new \Anar\Login\Admin\FeaturesPage() )->register();
			( new \Anar\Login\Admin\BalancePage() )->register();
			( new \Anar\Login\Admin\MessageCenter() )->register();
			( new \Anar\Login\Commerce\Admin( new \Anar\Login\Commerce\Settings(), new \Anar\Login\Commerce\Repository(), $providers ) )->register();
			( new \Anar\Login\Commerce\Privacy( new \Anar\Login\Commerce\Repository(), new \Anar\Login\Commerce\Settings() ) )->register();
			( new \Anar\Login\Marketing\Privacy() )->register();
			if ( class_exists( 'WooCommerce' ) ) {
				( new \Anar\Login\Marketing\Admin() )->register(); }
		}
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			( new \Anar\Login\Balance\Monitor() )->register();
		}
	}

	public function runtime() {
		$flags = \Anar\Login\Infrastructure\Features::all();
		foreach ( array( 'login' => array( 'anar_login', 'anar_account', 'anar_login_button', 'anar_account_security' ), 'reset' => array( 'anar_reset_password' ), 'recovery' => array( 'anar_cart_reminders', 'anar_recover_cart' ), 'games' => array( 'anar_game', 'anar_games' ), 'birthday' => array( 'anar_customer_preferences' ), 'referral' => array( 'anar_referrals' ) ) as $key => $shortcodes ) {
			if ( ! $flags[ $key ] ) {
				foreach ( $shortcodes as $shortcode ) {
					add_shortcode( $shortcode, '__return_empty_string' ); }
			}
		}
		// Historic opt-out links remain usable independently of a feature's runtime switch.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Query presence only; each handler verifies its HMAC and POST nonce.
		if ( isset( $_GET['ar'] ) ) {
			add_action(
				'template_redirect',
				static function () {
					( new \Anar\Login\Commerce\Module( new \Anar\Login\Commerce\Settings(), new \Anar\Login\Commerce\Repository() ) )->link_page();
				},
				0
			);
		}
		if ( isset( $_GET['am'] ) ) {
			add_action(
				'template_redirect',
				static function () {
					( new \Anar\Login\Marketing\Frontend( new \Anar\Login\Marketing\Settings(), new \Anar\Login\Marketing\Repository() ) )->route();
				},
				0
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$settings = $flags['login'] || $flags['reset'] || $flags['two_factor'] || \Anar\Login\Infrastructure\Features::queue_needed() ? new Settings() : null;
		if ( $flags['login'] || $flags['reset'] || $flags['two_factor'] ) {
			$codes = new OtpRepository();
			$limiter = new RateLimiter( $settings );
			$providers = new ProviderRegistry( $settings );
			if ( $flags['reset'] ) {
				( new \Anar\Login\Auth\PasswordRecovery( $settings, $codes, $limiter, $providers ) )->register(); }
			if ( $flags['login'] || $flags['two_factor'] ) {
				$users = new UserService( $settings );
				$otp = new OtpService( $settings, $codes, $limiter, $providers, $users );
				self::security_hooks( $otp, $users );
			}
			if ( $flags['login'] ) {
				$google = $settings->get( 'google_enabled', 0 ) ? new GoogleAuth( $settings, $users, $limiter ) : null;
				$shortcodes = new Shortcodes( $settings );
				$shortcodes->register();
				( new AuthController( $otp, $google, $settings ) )->register();
				( new ProfileController( $users ) )->register();
				$popup = new Popup( $settings, $shortcodes );
				$popup->register();
				( new \Anar\Login\Frontend\Blocks( $shortcodes, $popup, $flags['reset'] ) )->register();
				( new WpLogin( $settings, $shortcodes ) )->register();
				if ( $settings->get( 'replace_theme_login', 1 ) && WoodMart::active() ) {
					( new WoodMart() )->register(); }
				if ( class_exists( 'WooCommerce' ) ) {
					( new WooCommerce( $settings ) )->register(); }
			}
		}
		if ( $flags['login_log'] ) {
			add_action(
				'anar_toolkit_sms_result',
				static function ( $recipient, $context, $provider, $result ) {
					( new \Anar\Login\Commerce\Repository() )->sms_result( $recipient, $context, $provider, $result );
				},
				10,
				4
			);
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return; }
		if ( $flags['recovery'] || $flags['orders'] || \Anar\Login\Infrastructure\Features::queue_needed() ) {
			$commerce = new \Anar\Login\Commerce\Settings();
			$repo = new \Anar\Login\Commerce\Repository();
			$module = new \Anar\Login\Commerce\Module( $commerce, $repo );
			if ( $flags['recovery'] || $flags['orders'] ) {
				$module->register(); }
			if ( $flags['recovery'] && 'automatic' === $commerce->get( 'capture_mode' ) ) {
				( new \Anar\Login\Commerce\AutomaticCapture( $commerce, $module ) )->register(); }
			if ( $flags['orders'] ) {
				( new \Anar\Login\Commerce\OrderEvents( $commerce, $repo ) )->register(); }
			if ( \Anar\Login\Infrastructure\Features::queue_needed() ) {
				( new \Anar\Login\Commerce\Worker( $commerce, $repo, $module, new \Anar\Login\Messaging\NotificationSender( $settings, new \Anar\Login\Messaging\HttpClient() ) ) )->register();
			}
		}
		// Existing personal coupons keep their restrictions even if their creating module is stopped.
		if ( $flags['games'] || get_option( 'anar_game_budget_coupons' ) ) {
			( new \Anar\Login\Marketing\GameBudget() )->register(); }
		add_filter(
			'woocommerce_coupon_is_valid',
			static function ( $valid, $coupon ) {
				if ( ! $coupon->get_meta( '_anar_phone_hash' ) ) {
					return $valid; }
				return ( new \Anar\Login\Commerce\Coupons( new \Anar\Login\Commerce\Settings(), new \Anar\Login\Commerce\Repository() ) )->valid( $valid, $coupon );
			},
			10,
			2
		);
		$validate = static function ( $order ) {
			if ( $order->get_coupon_codes() ) {
				( new \Anar\Login\Commerce\Coupons( new \Anar\Login\Commerce\Settings(), new \Anar\Login\Commerce\Repository() ) )->validate_order( $order ); }
		};
		add_action( 'woocommerce_checkout_create_order', $validate, 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', $validate, 20 );
		if ( ! \Anar\Login\Infrastructure\Features::marketing() ) {
			return; }
		$marketing = new \Anar\Login\Marketing\Settings();
		$repository = new \Anar\Login\Marketing\Repository();
		if ( \Anar\Login\Infrastructure\Features::facts_needed() || \Anar\Login\Infrastructure\Features::queue_needed() ) {
			( new \Anar\Login\Marketing\Engine( $marketing, $repository ) )->register(); }
		if ( $flags['stock'] || $flags['birthday'] || $flags['survey'] || \Anar\Login\Infrastructure\Features::queue_needed() ) {
			( new \Anar\Login\Marketing\Frontend( $marketing, $repository ) )->register(); }
		if ( $flags['invoice'] ) {
			( new \Anar\Login\Marketing\Invoices() )->register(); }
		if ( $flags['games'] ) {
			( new \Anar\Login\Marketing\Games() )->register(); }
		if ( $flags['referral'] ) {
			( new \Anar\Login\Marketing\Referrals() )->register(); }
		if ( $flags['traffic'] ) {
			( new \Anar\Login\Marketing\Insights() )->register(); }
	}

	/** Register lazy security callbacks without loading account security on public pages. */
	public static function security_hooks( $otp, $users ) {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		$service = static function () use ( $otp, $users ) {
			static $instance;
			if ( ! $instance ) {
				$instance = new \Anar\Login\Auth\AccountSecurity( $otp, $users );
			}
			return $instance;
		};

		add_action(
			'rest_api_init',
			static function () use ( $service ) {
				foreach ( array( 'options', 'register', 'verify' ) as $operation ) {
					register_rest_route(
						'anar-login/v1',
						'/passkey/' . $operation,
						array(
							'methods' => 'POST',
							'permission_callback' => '__return_true',
							'callback' => static function ( $request ) use ( $service, $operation ) {
								return ( new \Anar\Login\Api\PasskeyController( $service() ) )->handle( $operation, $request );
							},
						)
					);
				}
			}
		);
		add_filter(
			'anar_login_can_authenticate',
			function ( $allowed, $user, $remember = false ) use ( $service ) {
				return $service()->gate( $allowed, $user, $remember );
			},
			100,
			3
		);
		add_action(
			'anar_login_verified_session',
			function ( $user ) use ( $service ) {
				$service()->verified_session( $user );
			}
		);
		add_action(
			'login_form_anar_authenticator',
			function () use ( $service ) {
				$service()->screen();
			}
		);
		add_action(
			'login_form_anar_security',
			function () use ( $service ) {
				$service()->manage();
			}
		);
		add_action(
			'set_logged_in_cookie',
			function ( ...$args ) use ( $service ) {
				$service()->remember( ...$args );
			},
			9,
			6
		);
		add_action(
			'wp_login',
			function ( $login, $user ) use ( $service ) {
				$service()->intercept( $login, $user );
			},
			0,
			2
		);
		add_filter(
			'authenticate',
			function ( $user ) use ( $service ) {
				return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ? $service()->block_xmlrpc( $user ) : $user;
			},
			101
		);
		add_filter(
			'wp_is_application_passwords_available_for_user',
			function ( $available, $user ) use ( $service ) {
				return $service()->application_passwords( $available, $user );
			},
			100,
			2
		);
		foreach ( array( 'show_user_profile', 'woocommerce_edit_account_form' ) as $hook ) {
			add_action(
				$hook,
				static function () {
					echo Shortcodes::security_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes the link.
				}
			);
		}
		add_shortcode(
			'anar_account_security',
			static function () {
				return Shortcodes::security_link();
			}
		);
	}

	public function cleanup() {
		( new OtpRepository() )->cleanup();
		\Anar\Login\Auth\LoginFlowService::cleanup();
		RateLimiter::cleanup();
		( new \Anar\Login\Commerce\Repository() )->cleanup( (int) ( new \Anar\Login\Commerce\Settings() )->get( 'retention' ) );
		( new \Anar\Login\Marketing\Engine( new \Anar\Login\Marketing\Settings(), new \Anar\Login\Marketing\Repository() ) )->cleanup();
	}

	/**
	 * Load translations and run pending upgrades.
	 *
	 * @return void
	 */
	public function init() {
		// Register the bundled fallback; WordPress keeps global language packs first.
		load_plugin_textdomain( 'anar-toolkit', false, dirname( plugin_basename( ANAR_LOGIN_FILE ) ) . '/languages' );
		if ( ANAR_LOGIN_VERSION !== get_option( 'anar_login_db_version' ) || '1' !== get_option( 'anar_game_money_schema' ) || false === get_option( 'anar_game_budget_coupons' ) ) {
			Activator::activate();
		}
		$features = (array) get_option( \Anar\Login\Infrastructure\Features::OPTION, array() );
		if ( empty( $features['_snapshot'] ) ) {
			\Anar\Login\Infrastructure\Features::refresh(); }
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
