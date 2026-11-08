<?php
/**
 * Multi-variable commerce messages; OTP provider contracts remain compatible.
 *
 * Validates and renders a message, then hands transport to the provider object.
 * Endpoints and response parsing live only in the provider classes.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Commerce\Templates;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class NotificationSender {

	private $settings;
	private $registry;

	public function __construct( Settings $settings, HttpClient $http, ?ProviderRegistry $registry = null ) {
		$this->settings = $settings;
		$this->registry = $registry ? $registry : new ProviderRegistry( $settings, $http );
	}

	public function send( $provider, $recipient, $event, $variables, $recovery = false ) {
		if ( ! empty( $event['account_changed'] ) ) {
			return new WP_Error( 'anar_message_account_changed', __( 'The provider account changed. Review and save this message again.', 'anar-toolkit' ) );
		}
		$identity = Identity::normalize( $recipient );
		if ( is_wp_error( $identity ) || 'sms' !== $identity['channel'] ) {
			return new WP_Error( 'anar_invalid_recipient', __( 'A valid mobile number is required.', 'anar-toolkit' ) );
		}
		$recipient = $identity['identity'];
		$transport = $this->registry->all()[ $provider ] ?? null;
		if ( ! $transport instanceof AbstractProvider ) {
			return new WP_Error( 'anar_provider_missing', __( 'The selected commerce SMS provider is unavailable.', 'anar-toolkit' ) );
		}
		$text_mode = 'text' === ( $event['mode'] ?? ( 'custom' === $provider ? 'text' : 'template' ) );
		$mapping   = $text_mode ? (string) $event['message'] : (string) $event['params'];
		if ( $recovery && ( false === strpos( $mapping, '{code}' ) || false === strpos( $mapping, '{recovery_page}' ) || false === strpos( $mapping, '{unsubscribe_url}' ) ) ) {
			return new WP_Error( 'anar_recovery_code_required', __( 'Recovery messages must include the code, recovery page and unsubscribe link.', 'anar-toolkit' ) );
		}
		if ( $text_mode || 'custom' === $provider ) {
			return $transport->send_text( $recipient, Templates::render( $event['message'], $variables ) );
		}
		$params   = Templates::parameters( $event['params'], $variables );
		$template = trim( (string) $event['template'] );
		if ( '' === $template || empty( $params ) ) {
			return new WP_Error( 'anar_notification_template_required', __( 'Configure an approved commerce template and its parameter mapping.', 'anar-toolkit' ) );
		}
		if ( $recovery && ( ! in_array( $variables['code'], $params, true ) || ! in_array( $variables['recovery_page'], $params, true ) || ! in_array( $variables['unsubscribe_url'], $params, true ) ) ) {
			return new WP_Error( 'anar_recovery_code_required', __( 'Recovery messages must include the code, recovery page and unsubscribe link.', 'anar-toolkit' ) );
		}

		return $transport->send_template( $recipient, $template, $params );
	}
}
