<?php
/**
 * Plugin composition root.
 *
 * @package AnarLogin
 */

namespace Anar\Login;

use Anar\Login\Admin\SettingsPage;
use Anar\Login\Api\AuthController;
use Anar\Login\Api\ProfileController;
use Anar\Login\Auth\GoogleAuth;
use Anar\Login\Auth\OtpService;
use Anar\Login\Auth\UserService;
use Anar\Login\Frontend\Shortcodes;
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
		load_plugin_textdomain( 'anar-login', false, dirname( plugin_basename( ANAR_LOGIN_FILE ) ) . '/languages' );

		if ( ANAR_LOGIN_VERSION !== get_option( 'anar_login_db_version' ) ) {
			Activator::activate();
		}

		$settings   = new Settings();
		$repository = new OtpRepository();
		$limiter    = new RateLimiter( $settings );
		$providers  = new ProviderRegistry( $settings );
		$users      = new UserService( $settings );
		$otp        = new OtpService( $settings, $repository, $limiter, $providers, $users );
		$google     = new GoogleAuth( $settings, $users, $limiter );

		( new AuthController( $otp, $google, $settings ) )->register();
		( new ProfileController( $users ) )->register();
		( new Shortcodes( $settings ) )->register();
		add_action( 'anar_login_daily_cleanup', array( $repository, 'cleanup' ) );

		if ( is_admin() ) {
			( new SettingsPage( $settings, $providers ) )->register();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( ANAR_LOGIN_FILE ),
			static function ( $links ) {
				array_unshift(
					$links,
					'<a href="' . esc_url( admin_url( 'admin.php?page=anar-login' ) ) . '">' .
					esc_html__( 'Settings', 'anar-login' ) .
					'</a>'
				);

				return $links;
			}
		);
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}
}
