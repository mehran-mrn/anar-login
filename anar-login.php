<?php
/**
 * Plugin Name:       Anar Login
 * Plugin URI:        https://github.com/mehran-mrn/anar-login
 * Description:       ورود سریع و امن با رمز یک‌بارمصرف پیامکی، ایمیل و حساب گوگل.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Mehran MRN
 * Author URI:        https://github.com/mehran-mrn
 * Text Domain:       anar-login
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 *
 * @package AnarLogin
 */

defined( 'ABSPATH' ) || exit;

define( 'ANAR_LOGIN_VERSION', '0.1.0' );
define( 'ANAR_LOGIN_FILE', __FILE__ );
define( 'ANAR_LOGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANAR_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ANAR_LOGIN_PATH . 'src/Autoloader.php';

\Anar\Login\Autoloader::register();

register_activation_hook( __FILE__, array( \Anar\Login\Infrastructure\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Anar\Login\Infrastructure\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Anar\Login\Plugin::instance()->boot();
	}
);
