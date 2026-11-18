<?php
/**
 * Login delivery controls; provider credentials live in the connection screen.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;
defined( 'ABSPATH' ) || exit;
final class MobileDelivery {
	public static function choices() {
		return array(
			'single' => __( 'Only the selected service', 'anar-toolkit' ),
			'bale_sms' => __( 'Bale first, SMS as backup', 'anar-toolkit' ),
			'sms_bale' => __( 'SMS first, Bale as backup', 'anar-toolkit' ),
			'parallel' => __( 'Bale and SMS together', 'anar-toolkit' ),
		);
	}
	private static function select( $key, $label, $options, $selected, $attributes = '' ) {
		?>
		<div class="anar-field-row"><label for="anar-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
		<select id="anar-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>" name="<?php echo esc_attr( Settings::OPTION_KEY . '[' . $key . ']' ); ?>" <?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Internal static attributes only. ?>>
		<?php foreach ( $options as $id => $text ) : ?>
			<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?>><?php echo esc_html( $text ); ?></option>
		<?php endforeach; ?></select></div>
		<?php
	}
	public static function render( $value ) {
		$providers = array();
		foreach ( ProviderRegistry::shared()->all() as $key => $provider ) {
			$providers[ $key ] = $provider->title(); }
		$sms = MessageSettings::get( 'login_sms' );
		?>
		<div class="anar-card anar-mobile-delivery" data-mobile-delivery>
		<h3><?php esc_html_e( 'Sign-in code delivery', 'anar-toolkit' ); ?></h3>
		<?php
		self::select( 'login_delivery_mode', __( 'How should sign-in methods work?', 'anar-toolkit' ), array( 'choice' => __( 'Use the contact entered by the customer', 'anar-toolkit' ), 'simultaneous' => __( 'Send to linked email and mobile together', 'anar-toolkit' ) ), $value['login_delivery_mode'] );
		self::select( 'login_provider', __( 'Primary mobile service', 'anar-toolkit' ), $providers, MessageSettings::get( 'login_otp' )['provider'] );
		self::select( 'mobile_delivery_order', __( 'Mobile delivery and backup', 'anar-toolkit' ), self::choices(), $value['mobile_delivery_order'] );
		?>
		<div data-mobile-priority>
		<?php
		unset( $providers['bale'] );
		self::select( 'mobile_sms_provider', __( 'SMS service for this route', 'anar-toolkit' ), array( '' => __( 'No SMS provider selected', 'anar-toolkit' ) ) + $providers, $sms['provider'], 'data-saved-sms-provider="' . esc_attr( $sms['provider'] ) . '"' );
		?>
		<p data-mobile-save-first hidden><?php esc_html_e( 'Save the SMS provider and connection details first, then edit its code message.', 'anar-toolkit' ); ?></p>
		<?php MessageCenter::summary( 'login_sms', 'login' ); ?>
		</div>
		<?php self::select( 'login_email_backup', __( 'If email sending fails', 'anar-toolkit' ), array( 'none' => __( 'No backup', 'anar-toolkit' ), 'mobile' => __( 'Use the mobile delivery route', 'anar-toolkit' ) ), $value['login_email_backup'] ); ?>
		<p><?php esc_html_e( 'Together means sending one code to the email and canonical mobile linked to the same existing account. A new customer receives the code only at the contact they entered.', 'anar-toolkit' ); ?></p>
		<p><?php esc_html_e( 'Each mobile route runs independently of email: if Bale rejects a code, its SMS backup still runs even when email succeeds. Uncertain send timeouts retain the valid code without an automatic duplicate.', 'anar-toolkit' ); ?></p>
		<a class="button" data-setup-target="sms" href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-settings&section=sms' ) ); ?>"><?php esc_html_e( 'Configure service connections', 'anar-toolkit' ); ?></a>
		</div>
		<?php
	}
}
