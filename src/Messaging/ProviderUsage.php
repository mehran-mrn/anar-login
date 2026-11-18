<?php
/**
 * Resolved sender assignments shared by the connection and balance screens.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class ProviderUsage {
	/** Saved assignment records, including overrides and inactive capabilities. */
	public static function all() {
		$settings = new Settings();
		$methods = $settings->get( 'login_methods' );
		$records = array();
		foreach ( MessageCatalog::all() as $id => $item ) {
			if ( 'login_sms' === $id ) {
				continue;
			}
			$value = MessageSettings::get( $id );
			$keys = array( $value['provider'] );
			if ( 'login_otp' === $id || ( 'auth' === $item['source'] && ! empty( $value['inherit'] ) ) ) {
				$keys = array();
				if ( in_array( 'sms', $methods, true ) || ( 'login_otp' !== $id && ! in_array( 'bale', $methods, true ) ) ) {
					$keys[] = MessageSettings::get( 'login_otp' )['provider'];
				}
				if ( in_array( 'bale', $methods, true ) ) {
					$keys[] = 'bale';
				}
			} elseif ( MessageCatalog::variants( $id ) ) {
				$keys = array( MessageSettings::get( $id, 'plain' )['provider'], MessageSettings::get( $id, 'gift' )['provider'] );
			}
			$active = Features::enabled( $item['module'] );
			if ( 'commerce' === $item['source'] ) {
				$config = ( new \Anar\Login\Commerce\Settings() )->all();
				$active = $active && ( 'recovery_request' === $id || ( 'recovery' === $item['group'] ? ! empty( $config['events'][ $id ]['minutes'] ) : ! empty( $config[ $id ] ) ) );
			} elseif ( 'marketing' === $item['source'] ) {
				$active = $active && ! empty( ( new \Anar\Login\Marketing\Settings() )->all()['rules'][ $item['key'] ]['enabled'] );
			} elseif ( 'login_otp' === $id ) {
				$active = $active && (bool) array_intersect( $methods, array( 'sms', 'bale' ) );
			}
			$records[] = array( 'id' => $id, 'label' => $item['label'], 'keys' => array_unique( $keys ), 'active' => $active, 'auth' => 'auth' === $item['source'] );
		}
		return $records;
	}
}
