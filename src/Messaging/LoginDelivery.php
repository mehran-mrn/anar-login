<?php
/**
 * Deliver one mobile challenge through the enabled SMS and Bale senders.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class LoginDelivery {
	private $settings;
	private $registry;

	public function __construct( Settings $settings, ProviderRegistry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
	}

	public static function applies( Settings $settings, $context ) {
		if ( 'login_otp' === $context ) {
			return true;
		}
		if ( 'login_sms' === $context ) {
			return false;
		}
		$item = MessageCatalog::all()[ $context ] ?? array();
		$binding = MessageSettings::stored( $context );
		return 'auth' === ( $item['source'] ?? '' ) && ( null === $binding || ! empty( $binding['inherit'] ) );
	}

	/** A definite failure lets the caller discard an undelivered challenge. */
	public static function may_fallback( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		if ( array_key_exists( 'delivery_safe_to_retry', $data ) ) {
			return true === $data['delivery_safe_to_retry'];
		}
		if ( in_array( $error->get_error_code(), array( 'anar_provider_missing', 'anar_sms_not_configured', 'anar_message_incomplete', 'anar_bale_code' ), true ) ) {
			return true;
		}
		$status = (int) ( $data['status'] ?? 0 );
		return $status >= 400 && $status < 500 && 408 !== $status;
	}

	public static function uncertain( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		return ! self::may_fallback( $error ) && ( $status >= 500 || 408 === $status || in_array( $error->get_error_code(), array( 'anar_sms_network', 'anar_sms_rejected', 'anar_bale_rejected' ), true ) );
	}

	public function send( $recipient, $code, $message, $context ) {
		$identity = Identity::normalize( $recipient );
		if ( is_wp_error( $identity ) || 'sms' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_recipient', __( 'A valid mobile number is required.', 'anar-toolkit' ) );
		}
		$methods = array_intersect( array( 'sms', 'bale' ), $this->settings->get( 'login_methods' ) );
		// Other verification features still need a mobile sender on email-only sites.
		$methods = $methods ?: array( 'sms' );
		$accepted = false;
		$uncertain = false;
		foreach ( $methods as $channel ) {
			if ( 'sms' === $channel ) {
				$result = $this->registry->send_single( $identity['identity'], $code, $message, $context, 'login_otp' );
			} else {
				$provider = $this->registry->all()['bale'] ?? null;
				$result = $provider ? $provider->send( $identity['identity'], $code, $message ) : new WP_Error( 'anar_provider_missing', __( 'The selected SMS provider is unavailable.', 'anar-toolkit' ) );
				do_action( 'anar_toolkit_sms_result', $identity['identity'], sanitize_key( $context ), 'bale', $result );
			}
			if ( ! is_wp_error( $result ) ) {
				$accepted = true;
			} else {
				$uncertain = $uncertain || self::uncertain( $result );
			}
		}
		if ( $accepted ) {
			return true;
		}
		if ( $uncertain ) {
			$result->add_data( array_merge( (array) $result->get_error_data(), array( 'delivery_uncertain' => true ) ) );
		}
		return $result;
	}
}
