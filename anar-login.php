<?php
/**
 * Plugin Name:       Anar Toolkit – SMS Login, Recovery & Marketing for WooCommerce
 * Plugin URI:        https://mrnwp.ir/anar/
 * Description:       SMS, email and Google login, SMS password reset, cart recovery and customer communication tools for WooCommerce.
 * Version:           1.5.7
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Mehran Marandi
 * Author URI:        https://mehranmarandi.ir
 * Text Domain:       anar-toolkit
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AnarLogin
 */

defined( 'ABSPATH' ) || exit;

define( 'ANAR_LOGIN_VERSION', '1.5.7' );
define( 'ANAR_LOGIN_FILE', __FILE__ );
define( 'ANAR_LOGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANAR_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ANAR_LOGIN_PATH . 'src/Autoloader.php';

\Anar\Login\Autoloader::register();

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ANAR_LOGIN_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \Anar\Login\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Anar\Login\Infrastructure\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Anar\Login\Plugin::instance()->boot();
	}
);
