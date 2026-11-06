<?php
/**
 * Verify a packaged install works without an external Persian language pack.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\Help;
use Anar\Login\Frontend\Shortcodes;
use Anar\Login\Infrastructure\Settings;

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}
foreach ( array( 'mo', 'l10n.php' ) as $suffix ) {
	if ( file_exists( WP_LANG_DIR . '/plugins/anar-toolkit-fa_IR.' . $suffix ) ) {
		throw new RuntimeException( 'This regression fixture requires no external Anar Persian language pack.' );
	}
}
$locale_changed = 'fa_IR' !== determine_locale() && switch_to_locale( 'fa_IR' );
$checks = array();
$verify = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
	$checks[] = $message;
};
try {
	$verify( 'مدیریت قابلیت‌ها' === __( 'Features', 'anar-toolkit' ), 'Bundled Persian translation is available without a separate pack' );
	$verify( is_rtl(), 'Persian locale uses RTL layout' );
	$verify( false !== strpos( Help::text( 'stock' ), 'فرم درخواست' ), 'Contextual setup guidance is Persian' );
	wp_set_current_user( 0 );
	$form = new Shortcodes( new Settings() );
	$form->register_assets();
	$html = $form->login();
	$verify( false !== strpos( $html, 'dir="rtl"' ) && false !== strpos( $html, 'ورود' ), 'Signed-out login form renders in Persian RTL' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$metadata = get_plugin_data( ANAR_LOGIN_FILE, false, true );
	$verify( false !== strpos( $metadata['Name'], 'جعبه‌ابزار انار' ), 'Installed plugin metadata is translated' );

	$verify( switch_to_locale( 'en_US' ), 'WordPress can switch to English' );
	$verify( 'Features' === __( 'Features', 'anar-toolkit' ) && ! is_rtl(), 'English locale keeps its source text and LTR direction' );
	$html = ( new Shortcodes( new Settings() ) )->login();
	$verify( false !== strpos( $html, 'dir="ltr"' ), 'English login form remains usable' );
	restore_previous_locale();
	$verify( 'مدیریت قابلیت‌ها' === __( 'Features', 'anar-toolkit' ), 'Restoring the Persian locale reloads the bundled catalog' );

	$mo_only = static function ( $format, $domain ) {
		return 'anar-toolkit' === $domain ? 'mo' : $format;
	};
	add_filter( 'translation_file_format', $mo_only, 10, 2 );
	unload_textdomain( 'anar-toolkit', true );
	load_plugin_textdomain( 'anar-toolkit', false, dirname( plugin_basename( ANAR_LOGIN_FILE ) ) . '/languages' );
	$verify( 'مدیریت قابلیت‌ها' === __( 'Features', 'anar-toolkit' ), 'Legacy MO format also provides the Persian translation' );
	remove_filter( 'translation_file_format', $mo_only );
	WP_CLI::log( wp_json_encode( array( 'passed' => true, 'checks' => $checks, 'external_language_pack' => false ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
} finally {
	if ( $locale_changed ) {
		restore_previous_locale();
	}
}
