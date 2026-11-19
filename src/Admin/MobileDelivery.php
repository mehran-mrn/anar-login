<?php
/**
 * Three simple sign-in sender choices; credentials live in Service providers.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Messaging\ProviderStatus;

defined( 'ABSPATH' ) || exit;

final class MobileDelivery {
	public static function render( $value ) {
		$methods = $value['login_methods'];
		$bale = ProviderRegistry::shared()->all()['bale'] ?? null;
		?>
		<div class="anar-card" data-login-methods>
			<h3><?php esc_html_e( 'Sign-in methods', 'anar-toolkit' ); ?><?php Help::button( __( 'SMS and Bale send the same code to the entered mobile number. Email sends a code only to the email address entered by the customer.', 'anar-toolkit' ) ); ?></h3>
			<input type="hidden" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[login_methods_present]" value="1">
			<fieldset class="anar-login-methods"><legend><?php esc_html_e( 'Choose how customers receive their sign-in code.', 'anar-toolkit' ); ?></legend>
				<?php foreach ( array( 'sms' => __( 'SMS', 'anar-toolkit' ), 'email' => __( 'Email', 'anar-toolkit' ), 'bale' => __( 'Bale', 'anar-toolkit' ) ) as $key => $label ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[login_methods][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $methods, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</fieldset>

			<p data-login-methods-required hidden role="status"><?php esc_html_e( 'Choose at least one sign-in method.', 'anar-toolkit' ); ?></p>

			<p data-login-bale <?php echo in_array( 'bale', $methods, true ) ? '' : 'hidden'; ?>><?php esc_html_e( 'Bale sends verification codes only. No message template is needed.', 'anar-toolkit' ); ?></p>
			<?php
			if ( ! $bale || ! ProviderStatus::ready( $bale ) ) :
				?>
				<p class="anar-message-warning" data-login-bale <?php echo in_array( 'bale', $methods, true ) ? '' : 'hidden'; ?>>⚠ <?php esc_html_e( 'Complete the Bale connection before using it for sign-in.', 'anar-toolkit' ); ?></p><?php endif; ?>
			<a class="anar-inline-route" data-setup-target="sms" href="<?php echo esc_url( admin_url( 'admin.php?page=anar-login-settings&section=sms' ) ); ?>"><?php esc_html_e( 'Configure service connections', 'anar-toolkit' ); ?><span aria-hidden="true">↗</span></a>
		</div>
		<?php
	}
}
