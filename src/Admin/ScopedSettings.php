<?php
/**
 * Independent saves for connection credentials and OTP generation settings.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Messaging\ProviderStatus;
use Anar\Login\Balance\Gateways;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ScopedSettings {

	public static function register() {
		add_action( 'wp_ajax_anar_settings_save', array( self::class, 'save' ) );
		add_action( 'admin_post_anar_settings_save', array( self::class, 'save' ) );
		add_action( 'wp_ajax_anar_connection_check', array( self::class, 'check' ) );
	}

	public static function otp_fields() {
		return array( 'otp_length' => array( 4, 8 ), 'otp_ttl' => array( 60, 600 ), 'resend_delay' => array( 30, 300 ), 'max_requests_hour' => array( 2, 30 ), 'max_verify_attempts' => array( 3, 10 ) );
	}

	/** Only merge the allowed scope, preserving settings in every other form. */
	public static function values( $scope, $input ) {
		$settings = new Settings();
		$current = $settings->all();
		$providers = ProviderRegistry::shared()->all();
		if ( 'otp' === $scope ) {
			$keys = array_keys( self::otp_fields() );
			foreach ( self::otp_fields() as $key => $range ) {
				$number = \Anar\Login\Support\Identity::latin_digits( (string) ( is_scalar( $input[ $key ] ?? null ) ? $input[ $key ] : '' ) );
				if ( ! preg_match( '/^[0-9]+$/D', $number ) || (int) $number < $range[0] || (int) $number > $range[1] ) {
					return new WP_Error( 'anar_otp_range', __( 'Enter a valid number within the range shown for each code setting.', 'anar-toolkit' ) );
				}
				$input[ $key ] = $number;
			}
		} elseif ( 'connections' === $scope ) {
			$selected = is_scalar( $input['sms_provider'] ?? null ) ? sanitize_key( $input['sms_provider'] ) : '';
			if ( 'bale' === $selected || ! isset( $providers[ $selected ] ) ) {
				return new WP_Error( 'anar_provider', __( 'Choose an available SMS provider.', 'anar-toolkit' ) );
			}
			$keys = array_merge(
				array( 'sms_provider', 'sms_provider_confirmed' ),
				array_map(
					static function ( $key ) {
						return 'provider_' . $key;
					},
					array_keys( $providers )
				)
			);
		} else {
			return new WP_Error( 'anar_scope', __( 'Unknown settings section.', 'anar-toolkit' ) );
		}
		$merged = array_replace( $current, array_intersect_key( $input, array_flip( $keys ) ) );
		$clean = $settings->sanitize( $merged, $providers );
		if ( 'connections' === $scope ) {
			$key = $clean['sms_provider'];
			if ( ! isset( $providers[ $key ] ) || 'bale' === $key ) {
				return new WP_Error( 'anar_provider', __( 'Choose an available SMS provider.', 'anar-toolkit' ) );
			}
			$ready = ProviderStatus::ready_config( $providers[ $key ], $clean[ 'provider_' . $key ] ?? array() );
			if ( ! $ready ) {
				return new WP_Error( 'anar_connection_incomplete', __( 'Complete the selected provider credentials, then save. Your active provider has not changed.', 'anar-toolkit' ) );
			}
		}
		return array_replace( $current, array_intersect_key( $clean, array_flip( $keys ) ) );
	}

	private static function authorize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'text' => __( 'You cannot manage these settings.', 'anar-toolkit' ) ), 403 );
		}
	}

	public static function save() {
		self::authorize();
		check_admin_referer( 'anar_settings_save' );
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$input = isset( $_POST[ Settings::OPTION_KEY ] ) && is_array( $_POST[ Settings::OPTION_KEY ] ) ? wp_unslash( $_POST[ Settings::OPTION_KEY ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scope allowlist and Settings sanitizer in values().
		$values = self::values( $scope, $input );
		if ( ! is_wp_error( $values ) ) {
			update_option( Settings::OPTION_KEY, $values, false );
			$saved = (array) get_option( Settings::OPTION_KEY );
			$verify = 'otp' === $scope ? array_keys( self::otp_fields() ) : array( 'sms_provider', 'provider_' . $values['sms_provider'] );
			foreach ( $verify as $key ) {
				if ( ( $saved[ $key ] ?? null ) !== $values[ $key ] ) {
					$values = new WP_Error( 'anar_save_failed', __( 'Settings could not be saved. Your changes are still here; please try again.', 'anar-toolkit' ) );
					break;
				}
			}
		}
		$text = is_wp_error( $values ) ? $values->get_error_message() : ( 'otp' === $scope ? __( 'Code generation settings saved.', 'anar-toolkit' ) : __( 'Connection settings saved. This provider now sends all SMS.', 'anar-toolkit' ) );
		if ( wp_doing_ajax() ) {
			if ( is_wp_error( $values ) ) {
				wp_send_json_error( array( 'text' => $text ), 422 );
			}
			$provider = ProviderRegistry::shared()->all()[ $values['sms_provider'] ] ?? null;
			$states = array();
			if ( 'connections' === $scope ) {
				foreach ( \Anar\Login\Messaging\MessageCatalog::all() as $id => $item ) {
					$message = \Anar\Login\Messaging\MessageSettings::get( $id );
					$errors = \Anar\Login\Messaging\MessageSettings::errors( $id, $message );
					$states[ $id ] = $errors ? __( 'Needs configuration', 'anar-toolkit' ) : __( 'Configuration complete', 'anar-toolkit' );
				}
			}
			wp_send_json_success( array( 'text' => $text, 'provider' => $values['sms_provider'], 'title' => $provider ? $provider->title() : $values['sms_provider'], 'states' => $states ) );
		}
		set_transient( 'anar_message_notice_' . get_current_user_id(), array( 'ok' => ! is_wp_error( $values ), 'text' => $text ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=anar-login-settings&section=' . ( 'connections' === $scope ? 'sms' : 'general' ) ) );
		exit;
	}

	public static function check() {
		self::authorize();
		check_admin_referer( 'anar_settings_save' );
		$input = isset( $_POST[ Settings::OPTION_KEY ] ) && is_array( $_POST[ Settings::OPTION_KEY ] ) ? wp_unslash( $_POST[ Settings::OPTION_KEY ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Same scope sanitizer as saving.
		$values = self::values( 'connections', $input );
		if ( is_wp_error( $values ) ) {
			wp_send_json_error( array( 'text' => $values->get_error_message() ), 422 );
		}
		$key = $values['sms_provider'];
		if ( ! in_array( $key, Gateways::providers(), true ) ) {
			wp_send_json_error( array( 'text' => __( 'This provider has no account-check endpoint. Save the connection and test a configured message instead.', 'anar-toolkit' ) ), 422 );
		}
		$result = ( new Gateways() )->fetch( $key, $values[ 'provider_' . $key ] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'text' => $result->get_error_message() ), 422 );
		}
		wp_send_json_success( array( 'text' => __( 'The provider accepted your account credentials. No SMS was sent and no settings were saved.', 'anar-toolkit' ) ) );
	}

	/** External forms let controls inside the workspace save independently without nesting forms. */
	public static function forms() {
		foreach ( array( 'otp', 'connections' ) as $scope ) {
			?>
			<form id="anar-<?php echo esc_attr( $scope ); ?>-form" data-scoped-settings="<?php echo esc_attr( $scope ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="anar_settings_save"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>"><?php wp_nonce_field( 'anar_settings_save' ); ?>
			</form>
			<?php
		}
	}
}
