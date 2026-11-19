<?php
/**
 * Check missing strings with a real, partial WordPress language catalog.
 *
 * @package AnarLogin
 */

if ( ! defined( 'WP_CLI' ) || 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Use a disposable local installation.' );
}

$fixture = get_temp_dir() . 'anar-partial-' . wp_generate_uuid4() . '.mo';
$pack    = new MO();
$pack->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
$pack->set_header( 'Language', 'fa_IR' );
$pack->add_entry( new Translation_Entry( array( 'singular' => 'Features', 'translations' => array( 'ترجمهٔ اختصاصی آزمایشی' ) ) ) );
$pack->export_to_file( $fixture );
$changed = 'fa_IR' !== determine_locale() && switch_to_locale( 'fa_IR' );
$catalog = require ANAR_LOGIN_PATH . 'languages/anar-toolkit-fa_IR.l10n.php';
$verify  = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
};
try {
	unload_textdomain( 'anar-toolkit' );
	$verify( load_textdomain( 'anar-toolkit', $fixture, 'fa_IR' ), 'Partial MO catalog loads' );
	remove_filter( 'gettext_anar-toolkit', array( \Anar\Login\Infrastructure\Localization::class, 'fallback' ), 10 );
	$verify( 'Default SMS provider' === __( 'Default SMS provider', 'anar-toolkit' ), 'Partial pack reproduces the original missing translation' );
	\Anar\Login\Infrastructure\Localization::register();
	$verify( 'ترجمهٔ اختصاصی آزمایشی' === __( 'Features', 'anar-toolkit' ), 'Installed translation keeps priority' );
	foreach ( array( 'Default SMS provider', 'New features use this SMS provider automatically. Features with their own provider keep that selection. Each message still needs its own text or approved template.' ) as $source ) {
		$expected = $catalog['messages'][ $source ];
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Exercise existing source strings against a real partial pack.
		$actual = __( $source, 'anar-toolkit' );
		$verify( $actual === $expected, 'New settings text falls back to bundled Persian' );
	}
	$verify( 'Unknown QA message' === __( 'Unknown QA message', 'anar-toolkit' ), 'Unknown messages remain unchanged' );
	$verify( switch_to_locale( 'en_US' ), 'English locale is available' );
	$verify( 'Default SMS provider' === __( 'Default SMS provider', 'anar-toolkit' ), 'Persian fallback never leaks into English' );
	restore_previous_locale();
	$actual   = __( 'Default SMS provider', 'anar-toolkit' );
	$expected = $catalog['messages']['Default SMS provider'];
	$verify( $actual === $expected, 'Restored Persian locale still translates new messages' );
	WP_CLI::success( 'Partial-pack fallback, installed translation priority, unknown strings and English/Persian switching passed.' );
} finally {
	unload_textdomain( 'anar-toolkit' );
	wp_delete_file( $fixture );
	load_plugin_textdomain( 'anar-toolkit', false, dirname( plugin_basename( ANAR_LOGIN_FILE ) ) . '/languages' );
	if ( $changed ) {
		restore_previous_locale();
	}
}
