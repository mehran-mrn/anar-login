<?php
/**
 * Verify one visible feature catalog despite automatic or duplicate parent entries.
 *
 * @package AnarLogin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Disposable local WordPress only.' );
}
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
( new \Anar\Login\Admin\SettingsPage( new \Anar\Login\Infrastructure\Settings(), new \Anar\Login\Messaging\ProviderRegistry( new \Anar\Login\Infrastructure\Settings() ) ) )->menu();
add_submenu_page( 'anar-login', 'Duplicate catalog', 'Duplicate catalog', 'manage_options', 'anar-login' );
add_submenu_page( 'anar-login', 'Duplicate catalog', 'Duplicate catalog', 'manage_options', 'anar-login' );
( new \Anar\Login\Admin\FeaturesPage() )->menu();
$entries = $GLOBALS['submenu']['anar-login'];
$catalogs = array_filter(
	$entries,
	static function ( $entry ) {
		return 'anar-login' === $entry[2];
	}
);
$others = array_filter(
	$entries,
	static function ( $entry ) {
		return 'anar-features-other' === $entry[2];
	}
);
if ( 1 !== count( $catalogs ) || $others ) {
	throw new RuntimeException( 'The catalog must appear exactly once in the visible menu.' );
}
WP_CLI::success( 'One visible feature catalog; legacy alternate route stays hidden.' );
