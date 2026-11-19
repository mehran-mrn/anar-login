<?php
/**
 * Shared sender connections and an explicit view of their saved assignments.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\AbstractProvider;
use Anar\Login\Messaging\ProviderStatus;

defined( 'ABSPATH' ) || exit;

final class ProviderConnections {
	public static function render( $value, $providers ) {
		$providers = ProviderStatus::sorted( $providers, false );
		$groups = array( 'sms' => __( 'SMS providers', 'anar-toolkit' ), 'email' => __( 'Email', 'anar-toolkit' ), 'messengers' => __( 'Messengers', 'anar-toolkit' ) );
		?>
		<header class="anar-section-head"><div><h2><?php esc_html_e( 'Service providers', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Choose one SMS provider for all SMS features, configure its connection and save.', 'anar-toolkit' ); ?></p></div></header>
		<div class="anar-connection-categories" role="group" aria-label="<?php esc_attr_e( 'Sender type', 'anar-toolkit' ); ?>">
			<?php
			foreach ( $groups as $category => $label ) :
				?>
				<button type="button" class="button" data-connection-category="<?php echo esc_attr( $category ); ?>" aria-pressed="<?php echo 'sms' === $category ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?>
		</div>
		<?php foreach ( $groups as $category => $label ) : ?>
			<div data-connection-group="<?php echo esc_attr( $category ); ?>" <?php echo 'sms' === $category ? '' : 'hidden'; ?>>
				<h3><?php echo esc_html( $label ); ?></h3>
				<?php if ( 'email' === $category ) : ?>
					<div class="anar-card"><h3><?php esc_html_e( 'Site email service', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Email is sent with the standard wp_mail function and is compatible with SMTP plugins.', 'anar-toolkit' ); ?></p><p><?php esc_html_e( 'Choose Email in sign-in settings. Its subject and message are in OTP settings and messages.', 'anar-toolkit' ); ?></p><button type="button" class="button" data-setup-target="general"><?php esc_html_e( 'Open sign-in settings', 'anar-toolkit' ); ?></button></div>
				<?php else : ?>
					<?php
					$group = array_filter(
						$providers,
						static function ( $provider ) use ( $category ) {
							return ( 'bale' === $provider->key() ) === ( 'messengers' === $category );
						}
					);
					$view = 'sms' === $category ? $value['sms_provider'] : 'bale';
					if ( 'sms' === $category ) :
						?>

					<?php else : ?>
					<p><?php esc_html_e( 'Bale sends verification codes only. No message template is needed.', 'anar-toolkit' ); ?></p>
					<?php endif; ?>

						<div class="anar-provider-grid" data-provider-connections>
							<?php foreach ( $group as $key => $provider ) : ?>
								<label class="anar-provider <?php echo $key === $view ? 'is-selected' : ''; ?>">
									<input form="anar-connections-form" type="radio" name="<?php echo 'sms' === $category ? esc_attr( Settings::OPTION_KEY . '[sms_provider]' ) : 'anar_connection_view_messengers'; ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $view, $key ); ?>>
									<?php
									if ( 'melipayamak' === $key ) :
										?>
										<span class="anar-provider-recommended"><?php esc_html_e( 'Recommended', 'anar-toolkit' ); ?></span><?php endif; ?><span class="anar-provider__mark" aria-hidden="true">✦</span>
									<span><strong><?php echo esc_html( $provider->title() ); ?></strong>
									<?php
									if ( 'melipayamak' === $key ) :
										?>
										<span class="anar-meli-badge"><?php esc_html_e( 'Anar user discount', 'anar-toolkit' ); ?></span><?php endif; ?></span>
									<span class="anar-provider__check" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 8 3 3 7-7"/></svg></span>
								</label>
							<?php endforeach; ?>
						</div>

						<?php foreach ( $group as $key => $provider ) : ?>
							<div class="anar-card anar-provider-settings" data-provider="<?php echo esc_attr( $key ); ?>" data-required-fields="<?php echo esc_attr( wp_json_encode( self::required( $provider ) ) ); ?>" <?php echo $key === $view ? '' : 'hidden'; ?>>
								<?php
								if ( 'melipayamak' === $key ) {
									MeliOffer::card(); }
								?>
								<div class="anar-card__head"><div><span class="anar-field-title"><strong>
									<?php
									/* translators: %s: SMS provider title. */
									echo esc_html( sprintf( __( 'Connect to %s', 'anar-toolkit' ), $provider->title() ) );
									?>
								</strong><?php Help::button( __( 'Get these credentials from your SMS provider dashboard.', 'anar-toolkit' ) ); ?></span></div><span><?php esc_html_e( 'Secure HTTPS', 'anar-toolkit' ); ?></span></div>
								<?php if ( 'ippanel' === $key ) : ?>
									<p><a class="button" href="https://ippanel.co/?ref=75" target="_blank" rel="sponsored noopener noreferrer"><?php esc_html_e( 'Register with IPPanel (referral link)', 'anar-toolkit' ); ?></a></p>
								<?php elseif ( 'smsir' === $key ) : ?>
									<p><a class="button" href="https://app.sms.ir/auth/sign-up?ref=3NQTF" target="_blank" rel="sponsored noopener noreferrer"><?php esc_html_e( 'Register with SMS.ir (referral link)', 'anar-toolkit' ); ?></a></p>
								<?php endif; ?>
								<div class="anar-fields-grid">
									<?php foreach ( $provider->fields() as $field => $definition ) : ?>
										<?php
										if ( in_array( $field, array( 'template_id', 'code_parameter' ), true ) ) {
											continue;
										}

										$config     = isset( $value[ 'provider_' . $key ] ) ? $value[ 'provider_' . $key ] : array();
										$definition = Settings::field_definition( $definition );
										$is_secret  = in_array( $field, ( new Settings() )->secret_fields(), true );
										$name       = Settings::OPTION_KEY . '[provider_' . $key . '][' . $field . ']';
										$current    = (string) ( $config[ $field ] ?? '' );
										?>
										<label class="<?php echo 'textarea' === $definition['type'] || 'url' === $definition['type'] ? 'anar-fields-grid__wide' : ''; ?>">
											<span><?php echo esc_html( $definition['label'] ); ?></span>
											<?php if ( 'select' === $definition['type'] ) : ?>
												<select form="anar-connections-form" name="<?php echo esc_attr( $name ); ?>">
													<?php foreach ( $definition['options'] as $option => $option_label ) : ?>
														<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $current, (string) $option ); ?>><?php echo esc_html( $option_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( 'textarea' === $definition['type'] ) : ?>
												<textarea form="anar-connections-form" name="<?php echo esc_attr( $name ); ?>" rows="4" dir="ltr" placeholder="<?php echo esc_attr( $definition['placeholder'] ); ?>"><?php echo esc_textarea( $current ); ?></textarea>
											<?php else : ?>
												<input form="anar-connections-form" type="<?php echo $is_secret ? 'password' : 'text'; ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $is_secret ? '' : esc_attr( $current ); ?>" data-saved-value="<?php echo $is_secret && $current ? '1' : '0'; ?>" dir="ltr" placeholder="<?php echo esc_attr( $is_secret && $current ? __( 'Saved — enter a new value to change it', 'anar-toolkit' ) : $definition['placeholder'] ); ?>">
											<?php endif; ?>
											<?php if ( $definition['help'] ) : ?>
												<small><?php echo esc_html( $definition['help'] ); ?></small>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</div>


							</div>
						<?php endforeach; ?>


				<?php endif; ?>
			</div>
			<?php
		endforeach;
		?>
		<div class="anar-scoped-actions"><button type="submit" form="anar-connections-form" class="button button-primary"><?php esc_html_e( 'Save connection settings', 'anar-toolkit' ); ?></button><button type="button" class="button" data-connection-check><?php esc_html_e( 'Check connection', 'anar-toolkit' ); ?></button><?php Help::button( __( 'Checks the selected account without sending an SMS. It does not save your changes.', 'anar-toolkit' ) ); ?><span data-scoped-feedback="connections" role="status" aria-live="polite" hidden></span></div>
		<?php
		self::usage();
	}

	private static function required( $provider ) {
		return 'custom' === $provider->key() ? array( 'url' ) : ( $provider instanceof AbstractProvider ? $provider->template_credentials() : array_diff( array_keys( $provider->fields() ), array( 'template_id', 'code_parameter', 'balance_api_key' ) ) );
	}

	public static function email_message( $value ) {
		?>
		<div class="anar-email-message" data-login-email><h4><?php esc_html_e( 'Email sign-in message', 'anar-toolkit' ); ?></h4>
						<div class="anar-card">
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-subject"><strong><?php esc_html_e( 'Email subject', 'anar-toolkit' ); ?></strong></label>
								<input id="anar-email-subject" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_subject]" value="<?php echo esc_attr( $value['email_subject'] ); ?>">
							</div>
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-message"><span class="anar-field-title"><strong><?php esc_html_e( 'Email message', 'anar-toolkit' ); ?></strong><?php Help::button( __( 'Email is sent with the standard wp_mail function and is compatible with SMTP plugins.', 'anar-toolkit' ) ); ?></span></label>
								<textarea id="anar-email-message" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_message]" rows="6"><?php echo esc_textarea( $value['email_message'] ); ?></textarea>
								<div class="anar-placeholders"><code>{code}</code><code>{site}</code><code>{minutes}</code></div>
							</div>
						</div>
		</div>
		<?php
	}


	private static function usage() {
		$key = ( new Settings() )->get( 'sms_provider' );
		$provider = \Anar\Login\Messaging\ProviderRegistry::shared()->all()[ $key ] ?? null;
		$records = \Anar\Login\Messaging\ProviderUsage::all();
		?>
		<section class="anar-card anar-provider-usage" data-active-messages data-saved-provider="<?php echo esc_attr( $key ); ?>">
			<h3><?php esc_html_e( 'Active feature messages', 'anar-toolkit' ); ?></h3>
			<p><?php esc_html_e( 'Write the message or configure its approved template for each active feature. Choose a message below to edit it or send a test.', 'anar-toolkit' ); ?></p>
			<p class="anar-active-provider"><?php esc_html_e( 'Active SMS provider:', 'anar-toolkit' ); ?> <strong data-active-provider-title><?php echo esc_html( $provider ? $provider->title() : $key ); ?></strong></p>
			<p class="anar-message-warning" role="status" data-provider-draft-warning hidden><?php esc_html_e( 'Your connection changes are not saved. These messages still use the active provider shown above until you save.', 'anar-toolkit' ); ?></p>
			<div class="anar-active-message-buttons">
			<?php foreach ( $records as $record ) : ?>
				<?php
				if ( ! $record['active'] ) {
					continue; }
				?>
				<button type="button" class="button" data-message-id="<?php echo esc_attr( $record['id'] ); ?>"><?php echo esc_html( $record['label'] ); ?><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>
			<?php endforeach; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Enable other features first to configure their messages here.', 'anar-toolkit' ); ?></p>
		</section>
		<?php
	}
}
