<?php
/**
 * WordPress user lookup, creation and login.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Auth;

use Anar\Login\Infrastructure\Settings;
use Anar\Login\Support\Identity;
use Anar\Login\Support\PhoneDirectory;
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

	/** @var bool Whether the last login_or_create() call registered the account. */
	private $created = false;

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
	 * @param bool                                  $adopt_legacy Whether the caller has verified the identity.
	 * @return WP_User|WP_Error|false
	 */
	public function find_by_identity( $identity, $adopt_legacy = false ) {
		if ( 'email' === $identity['channel'] ) {
			return get_user_by( 'email', $identity['identity'] );
		}

		$found = PhoneDirectory::find( $identity['identity'], 1 );
		if ( ! $found ) {
			return false;
		}
		$user = get_user_by( 'id', (int) key( $found ) );
		if ( $user && $adopt_legacy && PhoneDirectory::META_KEY !== current( $found ) ) {
			// Migrate a legacy phone without replacing a canonical phone set after the lookup.
			$linked = PhoneDirectory::claim( $user->ID, $identity['identity'], current( $found ), false );
			if ( is_wp_error( $linked ) ) {
				return $linked;
			}
		}

		return $user ? $user : false;
	}

	/** Whether the last login_or_create() call registered a new account. */
	public function created() {
		return $this->created;
	}

	/**
	 * Log in or register an OTP-authenticated user.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @param bool                                  $remember Remember login.
	 * @return WP_User|WP_Error
	 */
	public function login_or_create( $identity, $remember ) {
		$this->created = false;
		$user = $this->find_by_identity( $identity, true );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! $user ) {
			if ( ! $this->settings->get( 'allow_registration', 1 ) ) {
				return new WP_Error( 'anar_registration_disabled', __( 'No account was found for these details and registration is disabled.', 'anar-toolkit' ), array( 'status' => 403 ) );
			}
			if ( $this->settings->get( 'mrn_registration_form', 0 ) ) {
				return $this->registration_form_required();
			}

			$user = $this->create_from_identity( $identity );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			$this->created = true;
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

		if ( empty( $sub ) || ! is_email( $email ) || true !== ( $profile['email_verified'] ?? false ) ) {
			return new WP_Error( 'anar_google_profile', __( 'The Google account information is incomplete.', 'anar-toolkit' ) );
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
				return new WP_Error( 'anar_registration_disabled', __( 'Registration for new users is disabled.', 'anar-toolkit' ) );
			}
			if ( $this->settings->get( 'mrn_registration_form', 0 ) ) {
				return $this->registration_form_required();
			}

			$email_base = strtok( $email, '@' );
			$login      = $this->unique_login( $email_base ? $email_base : 'anar-user' );
			$role       = $this->role();
			if ( is_wp_error( $role ) ) {
				return $role;
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'user_email'   => $email,
					'display_name' => sanitize_text_field( $profile['name'] ?? $login ),
					'first_name'   => sanitize_text_field( $profile['given_name'] ?? '' ),
					'last_name'    => sanitize_text_field( $profile['family_name'] ?? '' ),
					'role'         => $role,
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
			return new WP_Error( 'anar_profile_name', __( 'Display name cannot be empty.', 'anar-toolkit' ), array( 'status' => 400 ) );
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
		return array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'email'        => $user->user_email,
			'phone'        => get_user_meta( $user->ID, '_anar_phone', true ),
			'avatar'       => self::avatar( $user, 160 ),
		);
	}

	/**
	 * Resolve an account image while respecting WordPress avatar settings.
	 *
	 * @param WP_User $user User.
	 * @param int     $size Image size.
	 * @return string
	 */
	public static function avatar( WP_User $user, $size = 160 ) {
		if ( ! get_option( 'show_avatars', 1 ) ) {
			return ANAR_LOGIN_URL . 'assets/images/avatar.svg';
		}
		$image = (string) get_user_meta( $user->ID, '_anar_google_avatar', true );

		return $image ? esc_url_raw( $image ) : get_avatar_url( $user->ID, array( 'size' => $size ) );
	}

	/**
	 * Safe post-login destination.
	 *
	 * A requested redirect_to wins over the configured page, then core's
	 * login_redirect filter runs so redirect plugins keep working.
	 *
	 * @param WP_User $user      User.
	 * @param string  $requested Requested redirect.
	 * @return string
	 */
	public function redirect_url( WP_User $user, $requested = '' ) {
		$configured = (string) $this->settings->get( 'login_redirect', '' );
		// A destination for the user's role wins over the general one, e.g. staff to the dashboard.
		$by_role = (array) $this->settings->get( 'role_redirects', array() );
		foreach ( (array) $user->roles as $role ) {
			if ( ! empty( $by_role[ $role ] ) ) {
				$configured = (string) $by_role[ $role ];
				break;
			}
		}
		$panel_id   = absint( $this->settings->get( 'panel_page_id', 0 ) );
		$panel_url  = $panel_id ? get_permalink( $panel_id ) : '';
		/**
		 * Filter the destination used when neither redirect_to nor a setting applies.
		 *
		 * @param string  $url  Account page or home URL.
		 * @param WP_User $user User.
		 */
		$fallback = (string) apply_filters( 'anar_login_default_redirect', $panel_url ? $panel_url : home_url( '/' ), $user );
		$default  = $configured ? $configured : $fallback;
		$requested  = $requested ? wp_validate_redirect( (string) $requested, '' ) : '';
		$url        = $requested ? $requested : $default;
		$url        = (string) apply_filters( 'login_redirect', $url, $requested, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, applied for compatibility.
		$url        = (string) apply_filters( 'anar_login_redirect_url', $url, $user->ID );

		return wp_validate_redirect( $url, home_url( '/' ) );
	}

	/**
	 * Create from OTP identity.
	 *
	 * @param array{identity:string,channel:string} $identity Identity.
	 * @return WP_User|WP_Error
	 */
	private function create_from_identity( $identity, $form_id = 0, $entry_id = 0 ) {
		$role = $this->role();
		if ( is_wp_error( $role ) ) {
			return $role;
		}
		$is_email = 'email' === $identity['channel'];
		// Phone users get a random login so the number never leaks through author URLs or usernames.
		$base = $is_email ? strtok( $identity['identity'], '@' ) : 'user-' . strtolower( wp_generate_password( 8, false ) );
		/**
		 * Filter the username base for a new OTP account.
		 *
		 * @param string                                $base     Username base; uniqueness is enforced afterwards.
		 * @param array{identity:string,channel:string} $identity Verified identity.
		 */
		$base  = (string) apply_filters( 'anar_login_new_user_login', $base, $identity );
		$login = $this->unique_login( $base ? $base : 'anar-user' );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => $is_email ? $identity['identity'] : '',
				'display_name' => $is_email ? $login : $this->masked_phone( $identity['identity'] ),
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( $form_id ) {
			$linked = PhoneDirectory::claim( $user_id, $identity['identity'], 'mrn_forms', false );
			if ( is_wp_error( $linked ) ) {
				if ( is_multisite() ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
					wpmu_delete_user( $user_id );
				} else {
					require_once ABSPATH . 'wp-admin/includes/user.php';
					wp_delete_user( $user_id );
				}
				return $linked;
			}
			update_user_meta( $user_id, '_anar_mrn_form_entry', array( 'form_id' => $form_id, 'entry_id' => $entry_id ) );
		} elseif ( ! $is_email ) {
			update_user_meta( $user_id, '_anar_phone', $identity['identity'] );
		}

		do_action( 'anar_login_user_registered', $user_id, $identity['channel'], $identity );

		return get_user_by( 'id', $user_id );
	}

	/** Create a new account after the selected form's mobile challenge was consumed. */
	public function register_verified_form( array $identity, $form_id, $entry_id ) {
		if ( ! $this->settings->get( 'allow_registration', 1 ) || ! $entry_id || absint( $this->settings->get( 'mrn_registration_form', 0 ) ) !== $form_id || 'sms' !== $identity['channel'] ) {
			return $this->registration_form_required();
		}
		if ( $this->find_by_identity( $identity ) ) {
			return new WP_Error( 'anar_mrn_existing_account', __( 'This mobile number already has an account. Sign in to continue.', 'anar-toolkit' ), array( 'status' => 409 ) );
		}
		return $this->create_from_identity( $identity, $form_id, $entry_id );
	}

	private function registration_form_required() {
		return new WP_Error( 'anar_mrn_registration_required', __( 'To create an account, use the registration form.', 'anar-toolkit' ), array( 'status' => 403 ) );
	}

	/**
	 * Complete WordPress login.
	 *
	 * @param WP_User $user     User.
	 * @param bool    $remember Remember.
	 * @return WP_User|WP_Error
	 */
	public function login( WP_User $user, $remember ) {
		if ( is_multisite() && is_user_spammy( $user ) ) {
			return new WP_Error( 'anar_account_blocked', __( 'This account cannot sign in.', 'anar-toolkit' ), array( 'status' => 403 ) );
		}

		// The identity was verified by OTP or Google, so no password is supplied.
		// Core and security plugins may still refuse this specific account/session.
		$checked = apply_filters( 'wp_authenticate_user', $user, '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core authentication hook.
		$checked = $this->check_authentication_result( $checked, $user );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$checked = apply_filters( 'authenticate', $checked, $user->user_login, '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core authentication hook.
		$checked = $this->check_authentication_result( $checked, $user );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		/**
		 * Allow or block a verified user before the session is created.
		 *
		 * Return a WP_Error to stop the sign-in, for example for suspended accounts.
		 *
		 * @param true|WP_Error $allowed Whether sign-in may continue.
		 * @param WP_User       $user    Verified user.
		 */
		$allowed = apply_filters( 'anar_login_can_authenticate', true, $user, $remember );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( ! $allowed ) {
			return new WP_Error( 'anar_account_blocked', __( 'This account cannot sign in.', 'anar-toolkit' ), array( 'status' => 403 ) );
		}

		/**
		 * Fires before a session created by a verified code or Google is announced via wp_login.
		 *
		 * Two-step verification uses it to skip a second factor for these sign-ins.
		 *
		 * @param WP_User $user User.
		 */
		do_action( 'anar_login_verified_session', $user );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, (bool) $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core authentication hook.
		do_action( 'anar_login_authenticated', $user, $remember );

		return $user;
	}

	/**
	 * Preserve authentication denials and prevent switching the verified identity.
	 *
	 * @param mixed   $result Authentication filter result.
	 * @param WP_User $user   Identity verified before authentication.
	 * @return WP_User|WP_Error
	 */
	private function check_authentication_result( $result, WP_User $user ) {
		if ( ! is_wp_error( $result ) && ( ! $result instanceof WP_User || (int) $result->ID !== (int) $user->ID ) ) {
			$result = new WP_Error( 'anar_account_blocked', __( 'This account cannot sign in.', 'anar-toolkit' ), array( 'status' => 403 ) );
		}
		if ( is_wp_error( $result ) ) {
			do_action( 'wp_login_failed', $user->user_login, $result ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core authentication hook.
		}

		return $result;
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
	 * Resolve a low-privilege role immediately before either registration path.
	 *
	 * @return string|WP_Error
	 */
	private function role() {
		$role = $this->settings->get( 'default_role', 'subscriber' );
		$role = Settings::is_assignable_role( $role ) ? $role : 'subscriber';

		// Revalidate AFTER filters, including the WooCommerce customer preference.
		$role = apply_filters( 'anar_login_new_user_role', $role );
		if ( Settings::is_assignable_role( $role ) ) {
			return $role;
		}
		if ( Settings::is_assignable_role( 'subscriber' ) ) {
			return 'subscriber';
		}

		// Never fall back to WordPress's configurable default_role or a missing role.
		return new WP_Error( 'anar_registration_role', __( 'Registration is unavailable because no safe new-user role is configured. Please contact the site administrator.', 'anar-toolkit' ), array( 'status' => 503 ) );
	}

	/**
	 * Mask a phone for display, e.g. 0912•••4567.
	 *
	 * @param string $phone Canonical phone.
	 * @return string
	 */
	private function masked_phone( $phone ) {
		return Identity::mask( $phone );
	}
}
