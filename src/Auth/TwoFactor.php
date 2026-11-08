<?php
/**
 * Shared second-step policy and account contact resolution.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\Settings;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class TwoFactor {
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function required_for( WP_User $user ) {
		if ( ! \Anar\Login\Infrastructure\Features::enabled( 'two_factor' ) ) {
			return false;
		}
		$mode     = (string) $this->settings->get( 'two_factor', 'off' );
		$required = 'all' === $mode
			|| ( 'privileged' === $mode && ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_others_posts' ) || user_can( $user, 'manage_woocommerce' ) ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce shop manager capability.

		/**
		 * Filter whether a password sign-in needs a one-time code.
		 *
		 * @param bool    $required Whether verification is required.
		 * @param WP_User $user     User.
		 */
		return (bool) apply_filters( 'anar_login_two_factor_required', $required, $user );
	}

	/**
	 * Where the second-step code goes: the linked phone, else the account email.
	 *
	 * @param WP_User $user User.
	 * @return array{identity:string,channel:string}|null
	 */
	public function identity_for( WP_User $user ) {
		$phone = (string) get_user_meta( $user->ID, '_anar_phone', true );
		if ( $phone ) {
			return array(
				'identity' => $phone,
				'channel'  => 'sms',
			);
		}

		return is_email( $user->user_email )
			? array(
				'identity' => strtolower( $user->user_email ),
				'channel'  => 'email',
			)
			: null;
	}
}
