<?php
/**
 * Shared SMS selectors with visible completeness and consistent ordering.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\ProviderRegistry;
use Anar\Login\Messaging\ProviderStatus;

defined( 'ABSPATH' ) || exit;

final class ProviderPicker {
	public static function default_label() {
		$key = ( new Settings() )->get( 'sms_provider' );
		$provider = ProviderRegistry::shared()->all()[ $key ] ?? null;
		/* translators: %s: default provider name, with connection warning if needed. */
		return sprintf( __( 'Use default: %s', 'anar-toolkit' ), $provider ? ProviderStatus::label( $provider ) : '⚠ ' . $key );
	}

	/** Render only options, so every existing form keeps its own field name. */
	public static function options( $selected, $inherit = false, $providers = null ) {
		$providers = ProviderStatus::sorted( $providers ?? ProviderRegistry::shared()->all() );
		$active = ( new Settings() )->get( 'sms_provider' );
		$providers = array_intersect_key( $providers, array( $active => true ) );
		$selected = $active;
		$inherit = false;
		if ( $inherit ) {
			?>
			<option value="" <?php selected( $selected, '' ); ?>><?php echo esc_html( self::default_label() ); ?></option>
			<?php
		}
		foreach ( $providers as $key => $provider ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( ProviderStatus::label( $provider ) ); ?></option>
			<?php
		}
		if ( $selected && ! isset( $providers[ $selected ] ) ) {
			?>
			<option value="<?php echo esc_attr( $selected ); ?>" selected><?php echo esc_html( $selected . ' — ⚠ ' . __( 'Unavailable for SMS', 'anar-toolkit' ) ); ?></option>
			<?php
		}
	}
}
