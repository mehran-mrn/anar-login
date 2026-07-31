<?php
/**
 * WordPress user lookup, creation and login.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\Settings;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class UserService {
	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Find a user by normalized identity.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @return WP_User|false
	 */
	public function find_by_identity( $identity ) {
		if ( 'email' === $identity['channel'] ) {
			return get_user_by( 'email', $identity['identity'] );
		}

		$meta_keys = (array) apply_filters(
			'anar_login_phone_meta_keys',
			array( '_anar_phone', 'digits_phone' )
		);
		$values    = array_unique(
			array(
				$identity['identity'],
				ltrim( $identity['identity'], '+' ),
				'0' . preg_replace( '/^\+98/', '', $identity['identity'] ),
			)
		);

		foreach ( $meta_keys as $meta_key ) {
			foreach ( $values as $value ) {
				$users = get_users(
					array(
						'number'      => 1,
						'count_total' => false,
						'meta_key'    => sanitize_key( $meta_key ),
						'meta_value'  => $value,
					)
				);

				if ( ! empty( $users ) ) {
					if ( '_anar_phone' !== $meta_key ) {
						update_user_meta( $users[0]->ID, '_anar_phone', $identity['identity'] );
						do_action( 'anar_login_phone_identity_linked', $users[0]->ID, $meta_key );
					}

					return $users[0];
				}
			}
		}

		return false;
	}

	/**
	 * Log in or register an OTP-authenticated user.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @param bool                                  $remember Remember login.
	 * @return WP_User|WP_Error
	 */
	public function login_or_create( $identity, $remember ) {
		$user = $this->find_by_identity( $identity );

		if ( ! $user ) {
			if ( ! $this->settings->get( 'allow_registration', 1 ) ) {
				return new WP_Error( 'anar_registration_disabled', __( 'No account was found for these details and registration is disabled.', 'anar-login' ), array( 'status' => 403 ) );
			}

			$user = $this->create_from_identity( $identity );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
		}

		return $this->login( $user, $remember );
	}

	/**
	 * Log in with verified Google profile.
	 *
	 * @param array<string,mixed> $profile Google profile.
	 * @param bool                $remember Remember login.
	 * @return WP_User|WP_Error
	 */
	public function login_google( $profile, $remember = true ) {
		$sub   = sanitize_text_field( $profile['sub'] ?? '' );
		$email = sanitize_email( $profile['email'] ?? '' );

		if ( empty( $sub ) || ! is_email( $email ) ) {
			return new WP_Error( 'anar_google_profile', __( 'The Google account information is incomplete.', 'anar-login' ) );
		}

		$users = get_users(
			array(
				'number'      => 1,
				'count_total' => false,
				'meta_key'    => '_anar_google_sub',
				'meta_value'  => $sub,
			)
		);
		$user  = empty( $users ) ? get_user_by( 'email', $email ) : $users[0];

		if ( ! $user ) {
			if ( ! $this->settings->get( 'allow_registration', 1 ) ) {
				return new WP_Error( 'anar_registration_disabled', __( 'Registration for new users is disabled.', 'anar-login' ) );
			}

			$email_base = strtok( $email, '@' );
			$login      = $this->unique_login( $email_base ? $email_base : 'anar-user' );
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'user_email'   => $email,
					'display_name' => sanitize_text_field( $profile['name'] ?? $login ),
					'first_name'   => sanitize_text_field( $profile['given_name'] ?? '' ),
					'last_name'    => sanitize_text_field( $profile['family_name'] ?? '' ),
					'role'         => $this->role(),
				)
			);

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$user = get_user_by( 'id', $user_id );
			do_action( 'anar_login_user_registered', $user_id, 'google', $profile );
		}

		update_user_meta( $user->ID, '_anar_google_sub', $sub );
		if ( ! empty( $profile['picture'] ) ) {
			update_user_meta( $user->ID, '_anar_google_avatar', esc_url_raw( $profile['picture'] ) );
		}

		return $this->login( $user, $remember );
	}

	/**
	 * Update editable account fields.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $data    Submitted data.
	 * @return WP_User|WP_Error
	 */
	public function update_profile( $user_id, $data ) {
		$userdata = array(
			'ID'           => absint( $user_id ),
			'display_name' => sanitize_text_field( $data['display_name'] ?? '' ),
			'first_name'   => sanitize_text_field( $data['first_name'] ?? '' ),
			'last_name'    => sanitize_text_field( $data['last_name'] ?? '' ),
		);

		if ( empty( $userdata['display_name'] ) ) {
			return new WP_Error( 'anar_profile_name', __( 'Display name cannot be empty.', 'anar-login' ), array( 'status' => 400 ) );
		}

		$result = wp_update_user( $userdata );

		return is_wp_error( $result ) ? $result : get_user_by( 'id', $result );
	}

	/**
	 * Safe public profile payload.
	 *
	 * @param WP_User $user User.
	 * @return array<string,mixed>
	 */
	public function profile( WP_User $user ) {
		$avatar = get_user_meta( $user->ID, '_anar_google_avatar', true );

		return array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'email'        => $user->user_email,
			'phone'        => get_user_meta( $user->ID, '_anar_phone', true ),
			'avatar'       => $avatar ? esc_url( $avatar ) : get_avatar_url( $user->ID, array( 'size' => 160 ) ),
		);
	}

	/**
	 * Create from OTP identity.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @return WP_User|WP_Error
	 */
	private function create_from_identity( $identity ) {
		$is_email = 'email' === $identity['channel'];
		$base     = $is_email ? strtok( $identity['identity'], '@' ) : str_replace( '+', '', $identity['identity'] );
		$login    = $this->unique_login( $base ? $base : 'anar-user' );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $is_email ? $identity['identity'] : '',
				'display_name' => $is_email ? $login : $this->masked_phone( $identity['identity'] ),
				'role'         => $this->role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! $is_email ) {
			update_user_meta( $user_id, '_anar_phone', $identity['identity'] );
		}

		do_action( 'anar_login_user_registered', $user_id, $identity['channel'], $identity );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Complete WordPress login.
	 *
	 * @param WP_User $user     User.
	 * @param bool    $remember Remember.
	 * @return WP_User
	 */
	private function login( WP_User $user, $remember ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, (bool) $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'anar_login_authenticated', $user, $remember );

		return $user;
	}

	/**
	 * Generate collision-safe user login.
	 *
	 * @param string $base Base.
	 * @return string
	 */
	private function unique_login( $base ) {
		$base      = sanitize_user( $base, true );
		$base      = $base ? $base : 'anar-user';
		$candidate = $base;
		$counter   = 1;

		while ( username_exists( $candidate ) ) {
			$candidate = $base . '-' . $counter;
			++$counter;
		}

		return $candidate;
	}

	/**
	 * Registration role.
	 *
	 * @return string
	 */
	private function role() {
		$role = sanitize_key( $this->settings->get( 'default_role', 'subscriber' ) );

		return get_role( $role ) ? (string) apply_filters( 'anar_login_new_user_role', $role ) : 'subscriber';
	}

	/**
	 * Mask a phone for display.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	private function masked_phone( $phone ) {
		return substr( $phone, 0, 5 ) . '•••' . substr( $phone, -3 );
	}
}
