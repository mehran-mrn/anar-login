<?php
/**
 * Verify opt-in login styling without changing WordPress authentication handlers.
 *
 * @package AnarLogin
 */

use Anar\Login\Frontend\FormView;
use Anar\Login\Frontend\Shortcodes;
use Anar\Login\Frontend\WpLogin;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local WordPress required.' );
}
$original = get_option( Settings::OPTION_KEY );
$listener = array( Features::class, 'changed' );
$priority = has_action( 'updated_option', $listener );
remove_action( 'updated_option', $listener, $priority );
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	++$checks;
};
$settings = new Settings();
$login = new WpLogin( $settings, new Shortcodes( $settings ) );
try {
	update_option( Settings::OPTION_KEY, array_merge( $settings->defaults(), array( 'replace_wp_login' => 0 ) ), false );
	$login->register();
	$assert( false === has_action( 'login_enqueue_scripts', array( $login, 'theme' ) ), 'Disabled replacement registered the theme.' );
	$assert( false === has_filter( 'login_message', array( $login, 'native_header' ) ), 'Disabled replacement changed the core header.' );
	update_option( Settings::OPTION_KEY, array_merge( $settings->defaults(), array( 'replace_wp_login' => 1, 'brand_color' => '#156f62', 'form_preset' => 'bold' ) ), false );
	$login->register();
	$assert( false !== has_action( 'login_enqueue_scripts', array( $login, 'theme' ) ), 'Enabled replacement did not register the theme.' );
	$assert( false !== has_action( 'login_form_login', array( $login, 'render' ) ), 'Passwordless replacement was disconnected.' );
	$login->theme();
	$assert( wp_style_is( 'anar-wp-login', 'enqueued' ) && wp_style_is( 'anar-login', 'enqueued' ), 'The shared and core-page styles were not enqueued.' );
	$classes = $login->body_classes( array() );
	$assert( in_array( 'anar-wp-login-native', $classes, true ) && in_array( 'anar-wp-login--bold', $classes, true ), 'Core forms did not receive the configured theme.' );
	$header = $login->native_header( '<p>Existing message</p>' );
	$assert( strpos( $header, 'anar-wp-login-brand' ) !== false && strpos( $header, '<p>Existing message</p>' ) !== false, 'Branding discarded an existing core message.' );
	FormView::prepare_wordpress();
	$assert( ! in_array( 'anar-wp-login-native', $login->body_classes( array() ), true ), 'A custom Anar form received a duplicate core card.' );
	$assert( '<p>Existing message</p>' === $login->native_header( '<p>Existing message</p>' ), 'A custom Anar form received a duplicate header.' );
	WP_CLI::success( $checks . ' login-theme checks passed.' );
} finally {
	foreach ( array( 'login_form_login' => 'render', 'login_form_register' => 'register_page', 'login_enqueue_scripts' => 'theme', 'login_body_class' => 'body_classes', 'login_message' => 'native_header' ) as $hook => $method ) {
		remove_filter( $hook, array( $login, $method ) );
	}
	update_option( Settings::OPTION_KEY, $original, false );
	if ( false !== $priority ) {
		add_action( 'updated_option', $listener, $priority, 3 );
	}
}
