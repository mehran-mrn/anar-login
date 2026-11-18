<?php
/**
 * Ordered mobile-code delivery, using one challenge across both services.
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
		if ( ! in_array( $settings->get( 'mobile_delivery_order', 'single' ), array( 'bale_sms', 'sms_bale', 'parallel' ), true ) || 'login_sms' === $context ) {
			return false;
		}
		if ( 'login_otp' === $context ) {
			return true;
		}
		$item = MessageCatalog::all()[ $context ] ?? array();
		$binding = MessageSettings::stored( $context );
		return 'auth' === ( $item['source'] ?? '' ) && ( null === $binding || ! empty( $binding['inherit'] ) );
	}

	/** Only a definite rejection or a failure before delivery permits an automatic retry. */
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

	private static function uncertain( WP_Error $error ) {
		$data = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		return ! self::may_fallback( $error ) && ( $status >= 500 || 408 === $status || in_array( $error->get_error_code(), array( 'anar_sms_network', 'anar_sms_rejected', 'anar_bale_rejected' ), true ) );
	}

	public function send( $recipient, $code, $message, $context ) {
		$identity = Identity::normalize( $recipient );
		if ( is_wp_error( $identity ) || 'sms' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_recipient', __( 'A valid mobile number is required.', 'anar-toolkit' ) );
		}
		$order = 'bale_sms' === $this->settings->get( 'mobile_delivery_order' ) ? array( 'bale', 'sms' ) : array( 'sms', 'bale' );
		$parallel = 'parallel' === $this->settings->get( 'mobile_delivery_order' );
		$accepted = false;
		foreach ( $order as $index => $channel ) {
			if ( 'sms' === $channel ) {
				$result = $this->registry->send_single( $identity['identity'], $code, $message, $context, 'login_sms' );
			} else {
				$provider = $this->registry->all()['bale'] ?? null;
				$result = $provider ? $provider->send( $identity['identity'], $code, $message ) : new WP_Error( 'anar_provider_missing', __( 'The selected SMS provider is unavailable.', 'anar-toolkit' ) );
				do_action( 'anar_toolkit_sms_result', $identity['identity'], sanitize_key( $context ), 'bale', $result );
			}
			if ( ! is_wp_error( $result ) ) {
				if ( ! $parallel ) {
					return $result; }
				$accepted = true;
				continue;
			}
			if ( self::uncertain( $result ) ) {
				$accepted = $parallel || $accepted;
				$result->add_data( array_merge( (array) $result->get_error_data(), array( 'delivery_uncertain' => true ) ) );
			}
			if ( ! $parallel && ( 1 === $index || ! self::may_fallback( $result ) ) ) {
				return $result;
			}
		}
		return $accepted ? true : $result;
	}
}
