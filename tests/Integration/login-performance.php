<?php
/**
 * Public-request load budget. Run in a fresh disposable WP-CLI process.
 *
 * @package AnarLogin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}
$classes = array( 'AccountSecurity', 'CredentialService', 'Totp', 'LoginFlowService' );
foreach ( $classes as $class ) {
	if ( class_exists( 'Anar\\Login\\Auth\\' . $class, false ) ) {
		throw new RuntimeException( 'Authentication engine loaded on an unrelated request: ' . esc_html( $class ) );
	}
}
foreach ( array( 'anar-login', 'anar-passkeys', 'anar-security' ) as $handle ) {
	if ( wp_script_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'enqueued' ) ) {
		throw new RuntimeException( 'Authentication asset enqueued on an unrelated request.' );
	}
}
global $wpdb;
$before = $wpdb->num_queries;
$settings = new \Anar\Login\Infrastructure\Settings();
$users = new \Anar\Login\Auth\UserService( $settings );
$otp = new \Anar\Login\Auth\OtpService( $settings, new \Anar\Login\Infrastructure\OtpRepository(), new \Anar\Login\Infrastructure\RateLimiter( $settings ), new \Anar\Login\Messaging\ProviderRegistry( $settings ), $users );
\Anar\Login\Plugin::security_hooks( $otp, $users );
if ( $wpdb->num_queries !== $before ) {
	throw new RuntimeException( 'Security registration queried the database.' );
}
WP_CLI::success( 'Public request: zero security queries, zero authentication assets, credential engines remain unloaded.' );
