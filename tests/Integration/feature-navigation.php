<?php
/**
 * Check each capability against both rendered menus in a disposable WordPress.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\FeaturesPage;
use Anar\Login\Admin\Workspace;
use Anar\Login\Infrastructure\Features;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$original = get_option( Features::OPTION );
$original_user = get_current_user_id();
$original_submenu = $GLOBALS['submenu'] ?? array();
$listener = array( Features::class, 'changed' );
$priority = has_action( 'updated_option', $listener );
remove_action( 'updated_option', $listener, $priority );
wp_set_current_user( 1 );
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	++$checks;
};
try {
	foreach ( array_merge( array( 'off' ), array_keys( FeaturesPage::labels() ) ) as $active ) {
		update_option( Features::OPTION, array( '_snapshot' => 1, 'enabled' => array( $active => true ) ), true );
		foreach ( array( true, false ) as $settings ) {
			ob_start();
			Workspace::navigation( $settings );
			$html = ob_get_clean();
			foreach ( array( 'general', 'security', 'google', 'setup', 'install', 'woocommerce', 'sending' ) as $section_key ) {
				$visible = in_array( $section_key, Features::settings_tabs(), true );
				$needle = $settings ? 'data-tab="' . $section_key . '"' : 'section=' . $section_key;
				$assert( ( strpos( $html, $needle ) !== false ) === $visible, $active . ': incorrect settings link ' . $section_key );
			}
			$assert( ( 'balance' === $active ) === ( false !== strpos( $html, 'page=anar-toolkit-balance' ) ), $active . ': incorrect credit link' );
			foreach ( Features::marketing_keys() as $module ) {
				$visible = class_exists( 'WooCommerce' ) && $active === $module;
				$assert( ( strpos( $html, 'page=anar-toolkit-' . $module . '"' ) !== false ) === $visible, $active . ': incorrect module link ' . $module );
			}
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset only the menu under test between snapshots.
		$GLOBALS['submenu']['anar-login'] = array();
		( new FeaturesPage() )->menu();
		$menu_pages = array_column( $GLOBALS['submenu']['anar-login'], 2 );
		$assert( ( 'balance' === $active ) === in_array( 'anar-toolkit-balance', $menu_pages, true ), $active . ': incorrect WordPress credit submenu' );
		$visible = in_array( $active, array( 'recovery', 'orders', 'login_log', 'lifecycle', 'prediction', 'smart', 'bulk', 'stock', 'survey', 'next_discount', 'birthday', 'referral' ), true );
		$assert( in_array( 'anar-toolkit-commerce', $menu_pages, true ) === $visible, $active . ': incorrect WordPress commerce submenu' );
	}
	WP_CLI::success( $checks . ' navigation checks passed.' );
} finally {
	if ( false === $original ) {
		delete_option( Features::OPTION );
	} else {
		update_option( Features::OPTION, $original, true );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original menu fixture.
	$GLOBALS['submenu'] = $original_submenu;
	wp_set_current_user( $original_user );
	if ( false !== $priority ) {
		add_action( 'updated_option', $listener, $priority, 3 );
	}
}
