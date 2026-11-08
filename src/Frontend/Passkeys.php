<?php
/**
 * Shared passkey button; the browser adapter loads only after an explicit action.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

use Anar\Login\Auth\PasskeyServer;
use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class Passkeys {
	public static function enabled() {
		return \Anar\Login\Infrastructure\Features::enabled( 'login' ) && ( new Settings() )->get( 'passkeys_enabled', 0 ) && PasskeyServer::available();
	}

	public static function button( $operation = 'login', $flow = '' ) {
		if ( ! self::enabled() ) {
			return '';
		}
		if ( 'register' === $operation ) {
			wp_enqueue_script( 'anar-passkeys', ANAR_LOGIN_URL . 'assets/js/passkeys.js', array(), ANAR_LOGIN_VERSION, true );
		}
		return '<button type="button" class="anar-btn anar-btn--soft" data-passkey="' . esc_attr( $operation ) . '" data-passkey-flow="' . esc_attr( $flow ) . '" data-passkey-rest="' . esc_url( rest_url( 'anar-login/v1/passkey/' ) ) . '" data-passkey-script="' . esc_url( ANAR_LOGIN_URL . 'assets/js/passkeys.js?ver=' . ANAR_LOGIN_VERSION ) . '" data-passkey-nonce="' . esc_attr( 'register' === $operation ? wp_create_nonce( 'wp_rest' ) : '' ) . '" data-passkey-error="' . esc_attr__( 'The passkey could not be verified. Please try again or use another sign-in method.', 'anar-toolkit' ) . '" data-passkey-cancelled="' . esc_attr( 'register' === $operation ? __( 'Passkey setup was cancelled. You can try again when ready.', 'anar-toolkit' ) : __( 'Passkey sign-in was cancelled. You can try again or use a verification code.', 'anar-toolkit' ) ) . '">' . esc_html( 'register' === $operation ? __( 'Register passkey', 'anar-toolkit' ) : __( 'Sign in with a passkey', 'anar-toolkit' ) ) . '</button><p role="status" aria-live="polite" data-passkey-status></p>';
	}
}
