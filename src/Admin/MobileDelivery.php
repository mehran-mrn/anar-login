<?php
/**
 * Plain-language mobile delivery choices and saved configuration status.
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
			'bale_sms' => __( '1. Bale, then 2. SMS', 'anar-toolkit' ),
			'sms_bale' => __( '1. SMS, then 2. Bale', 'anar-toolkit' ),
		);
	}

	public static function render( $value ) {
		$sms = MessageSettings::get( 'login_sms' );
		$bale = ( new Settings() )->provider( 'bale' );
		?>
		<div class="anar-card anar-mobile-delivery" data-mobile-delivery data-sms-provider="<?php echo esc_attr( $sms['provider'] ); ?>">
			<h3><?php esc_html_e( 'Mobile code delivery order', 'anar-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Customers can use mobile number and email together. This order applies only to mobile verification codes; email and store messages keep their own settings.', 'anar-toolkit' ); ?></p>
			<label for="anar-mobile-delivery-order"><strong><?php esc_html_e( 'Where should the mobile code be sent first?', 'anar-toolkit' ); ?></strong></label>
			<select id="anar-mobile-delivery-order" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[mobile_delivery_order]">
				<?php foreach ( self::choices() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value['mobile_delivery_order'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<div data-mobile-priority hidden>
				<p><?php esc_html_e( 'If the first service rejects delivery, Anar sends the same code through the second service. A number without a Bale account automatically receives SMS when Bale is first.', 'anar-toolkit' ); ?></p>
				<p class="description"><?php esc_html_e( 'If a service stops responding after a send request, delivery may already have happened. Anar keeps that code valid and waits for the resend timer instead of automatically sending it twice.', 'anar-toolkit' ); ?></p>
				<p><strong><?php esc_html_e( 'Bale connection', 'anar-toolkit' ); ?>:</strong> <?php echo esc_html( ! empty( $bale['username'] ) && ! empty( $bale['password'] ) ? __( 'Connection details saved', 'anar-toolkit' ) : __( 'Needs configuration', 'anar-toolkit' ) ); ?></p>
				<label for="anar-mobile-sms-provider"><strong><?php esc_html_e( 'SMS provider', 'anar-toolkit' ); ?></strong></label>
				<select id="anar-mobile-sms-provider" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[mobile_sms_provider]" data-saved-sms-provider="<?php echo esc_attr( $sms['provider'] ); ?>">
				<option value=""><?php esc_html_e( 'No SMS provider selected', 'anar-toolkit' ); ?></option>
				<?php foreach ( ProviderRegistry::shared()->all() as $key => $provider ) : ?>
					<?php
					if ( 'bale' === $key ) {
						continue; }
					?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $sms['provider'], $key ); ?>><?php echo esc_html( $provider->title() ); ?></option>
				<?php endforeach; ?>
				</select>
				<p data-mobile-save-first hidden><?php esc_html_e( 'Save the SMS provider and connection details first, then edit its code message.', 'anar-toolkit' ); ?></p>
				<?php MessageCenter::summary( 'login_sms', 'sms' ); ?>
				<p class="description"><?php esc_html_e( 'Choose the SMS provider here, complete both connection forms below and save settings. Then use Edit message to enter the approved SMS template and test sign-in.', 'anar-toolkit' ); ?></p>
			</div>
		</div>
		<?php
	}
}
