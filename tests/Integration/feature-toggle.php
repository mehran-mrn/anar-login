<?php
/**
 * Individual capability changes against real local WordPress options.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\FeaturesPage;
use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}

$option_names = array( Features::OPTION, Settings::OPTION_KEY, 'anar_toolkit_commerce', 'anar_toolkit_marketing', 'anar_toolkit_sms_balance', 'cron', '_anar_recovery_codes_1', '_anar_recovery_codes_1_pending' );
$original = array();
foreach ( $option_names as $name ) {
	$original[ $name ] = get_option( $name );
}
$checks = 0;
$original_user = get_current_user_id();
wp_set_current_user( 1 );
// This fixture supplies the recovery prerequisite without contacting a provider.
( new \Anar\Login\Auth\RecoveryCodes() )->issue( 1, true );
$block_http = static function () {
	throw new RuntimeException( 'Feature toggles must never contact an SMS service.' );
};
add_filter( 'pre_http_request', $block_http );
try {
	foreach ( array_keys( FeaturesPage::labels() ) as $key ) {
		$before = Features::all();
		$target = ! $before[ $key ];
		if ( true !== FeaturesPage::set_feature( $key, $target ) || Features::enabled( $key ) !== $target ) {
			throw new RuntimeException( esc_html( 'Feature did not persist: ' . $key ) );
		}
		++$checks;
		$after = Features::all();
		foreach ( array_keys( FeaturesPage::labels() ) as $other ) {
			if ( $key !== $other && $after[ $other ] !== $before[ $other ] ) {
				throw new RuntimeException( esc_html( 'An unrelated feature changed: ' . $key . ' / ' . $other ) );
			}
		}
		++$checks;
		$restored = FeaturesPage::set_feature( $key, $before[ $key ] );
		$actual = Features::enabled( $key );
		if ( true !== $restored || $actual !== $before[ $key ] ) {
			throw new RuntimeException( esc_html( 'Feature did not restore: ' . $key ) );
		}
		++$checks;
	}
	$before = Features::all();
	$unknown = FeaturesPage::set_feature( 'untrusted-key', true );
	$after = Features::all();
	if ( ! is_wp_error( $unknown ) || $before !== $after ) {
		throw new RuntimeException( 'Unknown capability changed the saved configuration.' );
	}
	++$checks;
	WP_CLI::success( $checks . ' feature toggle checks passed; no provider requests occurred.' );
} finally {
	wp_set_current_user( $original_user );
	remove_filter( 'pre_http_request', $block_http );
	foreach ( $original as $name => $value ) {
		if ( false === $value ) {
			delete_option( $name );
		} else {
			update_option( $name, $value, false );
		}
	}
	Features::refresh();
}
