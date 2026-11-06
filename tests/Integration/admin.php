<?php
/**
 * Read-only admin isolation checks in the disposable stock-only QA installation.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\FeaturesPage;
use Anar\Login\Admin\SettingsPage;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() || 'stock' !== getenv( 'ANAR_QA_FEATURE_MODE' ) ) {
	throw new RuntimeException( 'Disposable stock-only QA required.' );
}
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$login_settings = new Settings();
( new SettingsPage( $login_settings, new ProviderRegistry( $login_settings ) ) )->menu();
( new FeaturesPage() )->menu();
$catalog_hook = $GLOBALS['wp_filter']['toplevel_page_anar-login'];
$catalog_callbacks = 0;
foreach ( $catalog_hook->callbacks as $callbacks ) {
	$catalog_callbacks += count( $callbacks );
}
if ( 1 !== $catalog_callbacks ) {
	throw new RuntimeException( 'The root menu must render the feature catalog exactly once.' );
}
$GLOBALS['anar_qa_feature_queries'] = array();
ob_start();
// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress generates this hook from the existing menu slug.
do_action( 'toplevel_page_anar-login' );
$catalog = ob_get_clean();
if ( 18 !== substr_count( $catalog, 'class="anar-feature-card ' ) || 1 !== substr_count( $catalog, '<form ' ) ) {
	throw new RuntimeException( 'The feature catalog must contain all 18 switches in one form.' );
}
if ( strpos( $catalog, 'id="active"' ) >= strpos( $catalog, 'id="other"' ) || strpos( $catalog, 'id="feature-stock"' ) >= strpos( $catalog, 'id="other"' ) ) {
	throw new RuntimeException( 'The enabled module must precede the other available capabilities.' );
}
if ( $GLOBALS['anar_qa_feature_queries'] ) {
	throw new RuntimeException( 'Opening the catalog must not run a customer report.' );
}
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route fixture.
$_GET['page'] = 'anar-toolkit-stock';
$GLOBALS['anar_qa_feature_queries'] = array();
ob_start();
( new \Anar\Login\Marketing\Admin() )->render();
$stock_page = ob_get_clean();
if ( false === strpos( $stock_page, 'id="campaigns"' ) ) {
	throw new RuntimeException( 'The selected module settings and report must render.' );
}
foreach ( $GLOBALS['anar_qa_feature_queries'] as $query ) {
	if ( preg_match( '/anar_marketing_(orders|purchases|jobs|plays|referrals|feedback|visits|bulk)\b/', $query ) ) {
		throw new RuntimeException( 'Opening stock settings ran another module report.' );
	}
}
WP_CLI::success( 'Admin isolation: one catalog, 18 visible capabilities, active-first ordering, no unrelated report queries.' );
