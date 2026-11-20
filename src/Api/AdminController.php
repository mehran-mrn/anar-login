<?php
/**
 * Administrator-only diagnostic endpoints.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Api;

use Anar\Login\Auth\OtpService;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class AdminController {
	/** @var OtpService */
	private $otp;

	/** @var Settings */
	private $settings;

	/** @var ProviderRegistry */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param OtpService       $otp       OTP service.
	 * @param Settings         $settings  Settings.
	 * @param ProviderRegistry $providers Providers.
	 */
	public function __construct( OtpService $otp, Settings $settings, ProviderRegistry $providers ) {
		$this->otp       = $otp;
		$this->settings  = $settings;
		$this->providers = $providers;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Register endpoint.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			AuthController::NAMESPACE,
			'/admin/delivery-check',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					$report = \Anar\Login\Admin\DeliveryCheck::report();
					$report['support'] = \Anar\Login\Admin\DeliveryCheck::support_text( $report );
					return $report;
				},
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			AuthController::NAMESPACE,
			'/admin/test-sms',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_sms' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'phone' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Send a test SMS.
	 *
	 * When the settings form is submitted along with the number, the unsaved
	 * values (selected provider, credentials, message) are used for this one
	 * send, so administrators can test before saving. Nothing is stored; blank
	 * secret fields fall back to the saved values, exactly as on save.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,string>|\WP_Error
	 */
	public function test_sms( WP_REST_Request $request ) {
		$revision = $request->get_param( 'revision' );
		if ( null !== $revision ) {
			$report = \Anar\Login\Admin\DeliveryCheck::report();
			if ( ! is_string( $revision ) || ! hash_equals( $report['revision'], $revision ) ) {
				return new \WP_Error( 'anar_test_changed', __( 'Saved settings changed. Check the configuration again before sending.', 'anar-toolkit' ), array( 'status' => 409 ) );
			}
			if ( ! $report['ready'] ) {
				return new \WP_Error( 'anar_test_incomplete', __( 'Complete the connection and sign-in message before sending a test.', 'anar-toolkit' ), array( 'status' => 400 ) );
			}
		}
		// Revision-bound tests always use the saved configuration shown to the admin.
		$draft  = null !== $revision ? null : $request->get_param( Settings::OPTION_KEY );
		$filter = null;

		if ( is_array( $draft ) ) {
			$values = $this->settings->sanitize( $draft, $this->providers->all() );
			$filter = static function () use ( $values ) {
				return $values;
			};
			add_filter( 'pre_option_' . Settings::OPTION_KEY, $filter );
		}

		try {
			$result = $this->otp->send_test( (string) $request->get_param( 'phone' ), null !== $revision );
		} finally {
			if ( $filter ) {
				remove_filter( 'pre_option_' . Settings::OPTION_KEY, $filter );
			}
		}

		return $result;
	}
}
