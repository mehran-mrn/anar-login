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
		<header class="anar-section-head"><div><h2><?php esc_html_e( 'Service providers', 'anar-toolkit' ); ?></h2><p><?php esc_html_e( 'Configure each connection once, then select it in sign-in, store or club settings.', 'anar-toolkit' ); ?></p></div></header>
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
					<div class="anar-card"><div class="anar-field-row"><label for="anar-default-provider"><strong><?php esc_html_e( 'Default SMS provider', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'New features use this SMS provider automatically. Features with their own provider keep that selection. Each message still needs its own text or approved template.', 'anar-toolkit' ); ?></small></label><select id="anar-default-provider" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[sms_provider]"><?php ProviderPicker::options( $value['sms_provider'] ); ?></select></div></div>
					<?php else : ?>
					<p><?php esc_html_e( 'Bale sends verification codes only. No message template is needed.', 'anar-toolkit' ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Select a card to edit its connection. This selection does not change which service sends your messages.', 'anar-toolkit' ); ?></p>
						<div class="anar-provider-grid" data-provider-connections>
							<?php foreach ( $group as $key => $provider ) : ?>
								<label class="anar-provider <?php echo $key === $view ? 'is-selected' : ''; ?>">
									<input type="radio" name="anar_connection_view_<?php echo esc_attr( $category ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $view, $key ); ?>>
									<span class="anar-provider__mark" aria-hidden="true">✦</span>
									<span><strong><?php echo esc_html( $provider->title() ); ?></strong><small class="anar-connection-state <?php echo ProviderStatus::ready( $provider ) ? 'is-ready' : 'needs-setup'; ?>" data-connection-state="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( ProviderStatus::ready( $provider ) ? __( 'Connection configured', 'anar-toolkit' ) : '⚠ ' . __( 'Connection incomplete', 'anar-toolkit' ) ); ?></small>
									<?php
									if ( 'melipayamak' === $key ) :
										?>
										<span class="anar-meli-badge"><?php esc_html_e( 'Anar user discount', 'anar-toolkit' ); ?></span><?php endif; ?><small><?php echo esc_html( method_exists( $provider, 'description' ) ? $provider->description() : __( 'Service template delivery', 'anar-toolkit' ) ); ?></small></span>
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
								<div class="anar-card__head"><div><strong>
									<?php
									/* translators: %s: SMS provider title. */
									echo esc_html( sprintf( __( 'Connect to %s', 'anar-toolkit' ), $provider->title() ) );
									?>
								</strong><small><?php esc_html_e( 'Get these credentials from your SMS provider dashboard.', 'anar-toolkit' ); ?></small></div><span><?php esc_html_e( 'Secure HTTPS', 'anar-toolkit' ); ?></span></div>
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
												<select name="<?php echo esc_attr( $name ); ?>">
													<?php foreach ( $definition['options'] as $option => $option_label ) : ?>
														<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $current, (string) $option ); ?>><?php echo esc_html( $option_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( 'textarea' === $definition['type'] ) : ?>
												<textarea name="<?php echo esc_attr( $name ); ?>" rows="4" dir="ltr" placeholder="<?php echo esc_attr( $definition['placeholder'] ); ?>"><?php echo esc_textarea( $current ); ?></textarea>
											<?php else : ?>
												<input type="<?php echo $is_secret ? 'password' : 'text'; ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $is_secret ? '' : esc_attr( $current ); ?>" data-saved-value="<?php echo $is_secret && $current ? '1' : '0'; ?>" dir="ltr" placeholder="<?php echo esc_attr( $is_secret && $current ? __( 'Saved — enter a new value to change it', 'anar-toolkit' ) : $definition['placeholder'] ); ?>">
											<?php endif; ?>
											<?php if ( $definition['help'] ) : ?>
												<small><?php echo esc_html( $definition['help'] ); ?></small>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description" data-connection-unsaved hidden><?php esc_html_e( 'Save settings to apply these connection changes. Usage below reflects saved settings.', 'anar-toolkit' ); ?></p>
								<?php self::usage( $key ); ?>
							</div>
						<?php endforeach; ?>


				<?php endif; ?>
			</div>
			<?php
		endforeach;
	}

	private static function required( $provider ) {
		return 'custom' === $provider->key() ? array( 'url' ) : ( $provider instanceof AbstractProvider ? $provider->template_credentials() : array_diff( array_keys( $provider->fields() ), array( 'template_id', 'code_parameter', 'balance_api_key' ) ) );
	}

	public static function email_message( $value ) {
		?>
		<details class="anar-email-message" data-login-email><summary><?php esc_html_e( 'Email sign-in message', 'anar-toolkit' ); ?></summary>
						<div class="anar-card">
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-subject"><strong><?php esc_html_e( 'Email subject', 'anar-toolkit' ); ?></strong></label>
								<input id="anar-email-subject" type="text" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_subject]" value="<?php echo esc_attr( $value['email_subject'] ); ?>">
							</div>
							<div class="anar-field-row anar-field-row--stack">
								<label for="anar-email-message"><strong><?php esc_html_e( 'Email message', 'anar-toolkit' ); ?></strong><small><?php esc_html_e( 'Email is sent with the standard wp_mail function and is compatible with SMTP plugins.', 'anar-toolkit' ); ?></small></label>
								<textarea id="anar-email-message" name="<?php echo esc_attr( Settings::OPTION_KEY ); ?>[email_message]" rows="6"><?php echo esc_textarea( $value['email_message'] ); ?></textarea>
								<div class="anar-placeholders"><code>{code}</code><code>{site}</code><code>{minutes}</code></div>
							</div>
						</div>
		</details>
		<?php
	}


	private static function usage( $key ) {
		static $records;
		$records = $records ?? \Anar\Login\Messaging\ProviderUsage::all();
		$selected = array();
		$other = array();
		$inactive = array();
		foreach ( $records as $record ) {
			if ( 'bale' === $key && ! $record['auth'] ) {
				continue;
			}
			if ( in_array( $key, $record['keys'], true ) ) {
				if ( $record['active'] ) {
					$selected[] = $record;
				} else {
					$inactive[] = $record;
				}
			} else {
				$other[] = $record;
			}
		}
		?>
		<aside class="anar-provider-usage" data-provider-usage="<?php echo esc_attr( $key ); ?>">
			<h4><?php esc_html_e( 'Where this connection is used', 'anar-toolkit' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Based on saved settings. Selecting a provider does not enable the feature or complete its message template.', 'anar-toolkit' ); ?></p>
			<?php if ( ( new Settings() )->get( 'sms_provider' ) === $key ) : ?>
				<p class="anar-usage-default"><?php esc_html_e( 'Default SMS provider for new features', 'anar-toolkit' ); ?></p>
			<?php endif; ?>
			<strong><?php esc_html_e( 'Selected for', 'anar-toolkit' ); ?></strong>
			<?php if ( ! $selected && ! $inactive ) : ?>
				<p><?php esc_html_e( 'No features currently use this connection.', 'anar-toolkit' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $selected as $record ) : ?>
						<li><span aria-hidden="true">✓</span>
							<?php if ( 'bale' === $key ) : ?>
								<span><?php echo esc_html( $record['label'] ); ?></span>
							<?php else : ?>
								<a data-message-open href="<?php echo esc_url( MessageCenter::url( $record['id'] ) ); ?>"><?php echo esc_html( $record['label'] ); ?></a>
							<?php endif; ?>
							<small><?php echo esc_html( $record['active'] ? __( 'Enabled', 'anar-toolkit' ) : __( 'Feature disabled', 'anar-toolkit' ) ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $inactive ) : ?>
				<details><summary><?php esc_html_e( 'Selected for inactive features', 'anar-toolkit' ); ?> (<?php echo (int) count( $inactive ); ?>)</summary><ul>
					<?php foreach ( $inactive as $record ) : ?>
						<li>✓ <?php echo esc_html( $record['label'] ); ?><small><?php esc_html_e( 'Feature disabled', 'anar-toolkit' ); ?></small></li>
					<?php endforeach; ?>
				</ul></details>
			<?php endif; ?>

			<details>
				<summary><?php esc_html_e( 'Not selected for', 'anar-toolkit' ); ?> (<?php echo (int) count( $other ); ?>)</summary>
				<ul>
					<?php foreach ( $other as $record ) : ?>
						<li><span aria-hidden="true">—</span> <?php echo esc_html( $record['label'] ); ?><small><?php echo esc_html( $record['active'] ? __( 'Enabled', 'anar-toolkit' ) : __( 'Feature disabled', 'anar-toolkit' ) ); ?></small></li>
					<?php endforeach; ?>
				</ul>
			</details>
		</aside>
		<?php
	}
}
