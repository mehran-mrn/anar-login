<?php
/**
 * Anar toolkit update: configuration, delivery and presentation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Integrations;

defined( 'ABSPATH' ) || exit;
final class Elementor {
	public function register() {
		add_action( 'elementor/widgets/register', array( $this, 'widgets' ) );
		add_filter( 'anar_toolkit_recovery_page_has_form', array( $this, 'recovery_page' ), 10, 2 ); }
	public function widgets( $manager ) {
		require_once __DIR__ . '/ElementorWidget.php';
		foreach ( array( 'login', 'button', 'recovery' ) as $kind ) {
			$manager->register( new ElementorWidget( array(), array( 'anar_kind' => $kind ) ) ); }
	}

	public function recovery_page( $found, $page ) {
		if ( $found || ! $page instanceof \WP_Post || ! did_action( 'elementor/loaded' ) ) {
			return $found; }
		$data = get_post_meta( $page->ID, '_elementor_data', true );
		return self::contains_recovery( is_string( $data ) ? json_decode( $data, true ) : $data );
	}
	public static function contains_recovery( $elements ) {
		foreach ( is_array( $elements ) ? $elements : array() as $element ) {
			if ( ! is_array( $element ) ) {
				continue; }
			if ( 'anar-recovery' === ( $element['widgetType'] ?? '' ) || self::contains_recovery( $element['elements'] ?? array() ) ) {
				return true; }
		}
		return false;
	}
}
