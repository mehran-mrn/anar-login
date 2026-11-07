<?php
/**
 * Minimal WordPress compatibility layer for pure unit tests.
 *
 * @package AnarLogin
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'ANAR_LOGIN_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );

function wp_parse_id_list( $value ) {
	return array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : preg_split( '/[\s,]+/', $value ) ) ) );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

function __( $text ) {
	return $text;
}

function sanitize_email( $email ) {
	return filter_var( $email, FILTER_SANITIZE_EMAIL );
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

function do_action( $hook, ...$args ) {}

function apply_filters( $hook, $value ) {
	return $value;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['anar_test_options'][ $name ] ?? $default;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, $args );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function wp_strip_all_tags( $value ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Minimal WordPress test stub.
	return strip_tags( $value );
}

function sanitize_textarea_field( $value ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Minimal WordPress test stub.
	return trim( strip_tags( $value ) );
}

function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( $value ) );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_timezone() {
	return new DateTimeZone( $GLOBALS['anar_test_timezone'] ?? 'Asia/Tehran' );
}

function wp_parse_url( $url, $component = -1 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Minimal WordPress test stub.
	return parse_url( $url, $component );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function esc_url_raw( $url ) {
	return $url;
}

function wp_json_encode( $value, $flags = 0 ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Minimal WordPress test stub.
	return json_encode( $value, $flags );
}

function wp_safe_remote_request( $url, $args ) {
	$GLOBALS['anar_test_http_requests'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['anar_test_http_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

require_once dirname( __DIR__ ) . '/src/Commerce/Settings.php';
require_once dirname( __DIR__ ) . '/src/Commerce/Templates.php';

require_once dirname( __DIR__ ) . '/src/Support/Identity.php';
require_once dirname( __DIR__ ) . '/src/Marketing/Prediction.php';
require_once dirname( __DIR__ ) . '/src/Marketing/Contacts.php';
require_once dirname( __DIR__ ) . '/src/Marketing/Settings.php';

require_once dirname( __DIR__ ) . '/src/Infrastructure/Features.php';
