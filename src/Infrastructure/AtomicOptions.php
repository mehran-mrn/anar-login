<?php
/**
 * Conditional option mutations shared by expiring proofs and credentials.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class AtomicOptions {
	public static function replace( $key, $previous, $next ) {
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, maybe_serialize( $next ), $key, maybe_serialize( $previous ) ) );
		wp_cache_delete( $key, 'options' );
		return 1 === $changed;
	}

	public static function remove( $key, $previous ) {
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, $key, maybe_serialize( $previous ) ) );
		wp_cache_delete( $key, 'options' );
		return 1 === $changed;
	}
}
