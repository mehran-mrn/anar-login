<?php
/**
 * Fresh-process frontend isolation checks; requires the local QA instrumentation.
 *
 * @package AnarLogin
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() || ! getenv( 'ANAR_QA_FEATURE_MODE' ) ) {
	throw new RuntimeException( 'Disposable instrumented QA only.' ); }
$feature_mode = getenv( 'ANAR_QA_FEATURE_MODE' );
$loaded = array();
foreach ( array( 'Auth\AccountSecurity', 'Auth\CredentialService', 'Auth\LoginFlowService', 'Auth\Totp', 'Auth\OtpService', 'Auth\GoogleAuth', 'Auth\TwoFactor', 'Auth\PasswordRecovery', 'Commerce\Module', 'Commerce\AutomaticCapture', 'Commerce\OrderEvents', 'Commerce\Worker', 'Marketing\Engine', 'Marketing\Frontend', 'Marketing\Games', 'Marketing\Invoices', 'Marketing\Insights', 'Marketing\Referrals' ) as $class ) {
	$loaded[ $class ] = class_exists( 'Anar' . chr( 92 ) . 'Login' . chr( 92 ) . $class, false ); }
$expected = array(
	'off' => array(),
	'login' => array( 'Auth\OtpService' ),
	'two_factor' => array( 'Auth\OtpService' ),
	'invoice' => array( 'Marketing\Invoices' ),
	'games' => array( 'Marketing\Engine', 'Marketing\Games' ),
	'traffic' => array( 'Marketing\Engine', 'Marketing\Insights' ),
	'stock' => array( 'Commerce\Module', 'Commerce\Worker', 'Marketing\Engine', 'Marketing\Frontend' ),
	'recovery' => array( 'Commerce\Module', 'Commerce\AutomaticCapture', 'Commerce\Worker' ),
	'orders' => array( 'Commerce\Module', 'Commerce\OrderEvents', 'Commerce\Worker' ),
);
foreach ( $loaded as $class => $present ) {
	if ( in_array( $class, $expected[ $feature_mode ], true ) !== $present ) {
		throw new RuntimeException( esc_html( 'Unexpected component load: ' . $feature_mode . ' / ' . $class ) ); }
}
if ( ! empty( $GLOBALS['anar_qa_feature_queries'] ) ) {
	throw new RuntimeException( esc_html( 'Storefront bootstrap ran a feature database query: ' . $feature_mode ) ); }
do_action( 'wp_enqueue_scripts' );
global $wp_scripts, $wp_styles;
$active_assets = 'traffic' === $feature_mode ? array( 'anar-insights' ) : array();
$assets = array_filter(
	array_diff( array_merge( $wp_scripts->queue, $wp_styles->queue ), $active_assets ),
	static function ( $handle ) {
		return 0 === strpos( $handle, 'anar-' );
	}
);
if ( $assets ) {
	throw new RuntimeException( 'An ordinary page loaded unused feature assets.' ); }
WP_CLI::log( wp_json_encode( array( 'mode' => $feature_mode, 'loaded' => array_keys( array_filter( $loaded ) ), 'feature_queries' => count( $GLOBALS['anar_qa_feature_queries'] ), 'unused_assets' => count( $assets ), 'passed' => true ) ) );
