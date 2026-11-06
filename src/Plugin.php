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
use Anar\Login\Auth\TwoFactor;
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
		// Translations and upgrades wait for init, since WordPress 6.7 flags earlier translation loading.
		add_action( 'init', array( $this, 'init' ), 0 );

		$settings   = new Settings();
		$repository = new OtpRepository();
		$limiter    = new RateLimiter( $settings );
		$providers  = new ProviderRegistry( $settings );
		$commerce_settings = new \Anar\Login\Commerce\Settings();
		$commerce_repository = new \Anar\Login\Commerce\Repository();
		$commerce_module = new \Anar\Login\Commerce\Module( $commerce_settings, $commerce_repository );
		( new \Anar\Login\Commerce\Privacy( $commerce_repository, $commerce_settings ) )->register();
		( new \Anar\Login\Commerce\Worker( $commerce_settings, $commerce_repository, $commerce_module, new \Anar\Login\Messaging\NotificationSender( $settings, new \Anar\Login\Messaging\HttpClient() ) ) )->register();
		$users      = new UserService( $settings );
		$otp        = new OtpService( $settings, $repository, $limiter, $providers, $users );
		$google     = new GoogleAuth( $settings, $users, $limiter );

		$shortcodes = new Shortcodes( $settings );
		$two_factor = new TwoFactor( $settings, $otp, $users );
		$popup      = new Popup( $settings, $shortcodes );
		$wp_login   = new WpLogin( $settings, $shortcodes );

		( new AuthController( $otp, $google, $settings ) )->register();
		( new ProfileController( $users ) )->register();
		( new AdminController( $otp, $settings, $providers ) )->register();
		$shortcodes->register();
		add_action( 'anar_login_daily_cleanup', array( $repository, 'cleanup' ) );
		add_action( 'anar_login_daily_cleanup', array( RateLimiter::class, 'cleanup' ) );

		// These read settings, whose defaults are translated, so they wait for the textdomain.
		add_action(
			'init',
			static function () use ( $settings, $two_factor, $popup, $wp_login ) {
				$two_factor->register();
				$popup->register();
				$wp_login->register();

				// Themes load after plugins, so theme integrations are detected here.
				if ( $settings->get( 'replace_theme_login', 1 ) && WoodMart::active() ) {
					( new WoodMart() )->register();
				}
			},
			1
		);

		if ( WooCommerce::active() ) {
			( new WooCommerce( $settings ) )->register();
			$commerce_module->register();
		}

		if ( is_admin() ) {
			( new SettingsPage( $settings, $providers ) )->register();
			( new \Anar\Login\Commerce\Admin( $commerce_settings, $commerce_repository, $providers ) )->register();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ANAR_LOGIN_FILE ),
			static function ( $links ) {
				array_unshift(
					$links,
					'<a href="' . esc_url( admin_url( 'admin.php?page=anar-login' ) ) . '">' .
					esc_html__( 'Settings', 'anar-toolkit' ) .
					'</a>'
				);

				return $links;
			}
		);
	}

	/**
	 * Load translations and run pending upgrades.
	 *
	 * @return void
	 */
	public function init() {
		if ( ANAR_LOGIN_VERSION !== get_option( 'anar_login_db_version' ) ) {
			Activator::activate();
		}
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
