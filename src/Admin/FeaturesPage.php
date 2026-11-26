<?php
/**
 * Active-first feature catalog. Disabled features remain available below.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class FeaturesPage {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_anar_features', array( $this, 'save' ) );
		add_action( 'wp_ajax_anar_feature_toggle', array( $this, 'toggle' ) );
		add_action( 'wp_ajax_anar_feature_state', array( $this, 'state' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public static function labels() {
		return array_merge( array( 'login' => __( 'Customer sign-in', 'anar-toolkit' ), 'reset' => __( 'SMS password recovery', 'anar-toolkit' ), 'two_factor' => __( 'Two-step verification', 'anar-toolkit' ), 'login_log' => __( 'Login SMS report', 'anar-toolkit' ), 'recovery' => __( 'Abandoned carts', 'anar-toolkit' ), 'orders' => __( 'Order notifications', 'anar-toolkit' ), 'balance' => __( 'SMS credit', 'anar-toolkit' ) ), \Anar\Login\Marketing\Settings::modules() );
	}

	public static function url( $key ) {
		if ( in_array( $key, array( 'login', 'reset', 'two_factor' ), true ) ) {
			return admin_url( 'admin.php?page=anar-login-settings' . ( 'two_factor' === $key ? '&section=security' : '' ) ); }
		if ( 'login_log' === $key ) {
			return admin_url( 'admin.php?page=anar-toolkit-commerce&tab=messages' ); }
		if ( 'recovery' === $key ) {
			return admin_url( 'admin.php?page=anar-toolkit-commerce&tab=carts&view=schedule' );
		}
		if ( 'orders' === $key ) {
			return admin_url( 'admin.php?page=anar-toolkit-commerce&tab=settings' ); }
		return admin_url( 'admin.php?page=anar-toolkit-' . $key );
	}

	public function menu() {
		// Replace WordPress's automatic parent entry, including an earlier registration.
		foreach ( $GLOBALS['submenu']['anar-login'] ?? array() as $entry ) {
			if ( 'anar-login' === $entry[2] ) {
				remove_submenu_page( 'anar-login', 'anar-login' );
			}
		}
		add_submenu_page( 'anar-login', __( 'Settings', 'anar-toolkit' ), __( 'Settings', 'anar-toolkit' ), 'manage_options', 'anar-login', array( SettingsPage::class, 'landing' ), 0 );
		$flags = Features::all();
		$commerce = new \Anar\Login\Commerce\Admin( new \Anar\Login\Commerce\Settings(), new \Anar\Login\Commerce\Repository(), \Anar\Login\Messaging\ProviderRegistry::shared() );
		if ( $flags['recovery'] || $flags['orders'] || $flags['login_log'] || Features::queue_needed() ) {
			add_submenu_page( 'anar-login', __( 'Commerce toolkit', 'anar-toolkit' ), __( 'Commerce toolkit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-commerce', array( $commerce, 'render' ) ); }
		if ( ! $flags['recovery'] && ! $flags['orders'] && ! $flags['login_log'] && ! Features::queue_needed() ) {
			add_submenu_page( null, __( 'Commerce toolkit', 'anar-toolkit' ), __( 'Commerce toolkit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-commerce', array( $commerce, 'render' ) ); }
		foreach ( Features::marketing_keys() as $key ) {
			if ( $flags[ $key ] && class_exists( 'WooCommerce' ) ) {
				add_submenu_page( null, self::labels()[ $key ], self::labels()[ $key ], 'manage_options', 'anar-toolkit-' . $key, array( $commerce, 'render' ) ); }
		}
		$settings_hook = add_submenu_page( null, __( 'Settings', 'anar-toolkit' ), __( 'Settings', 'anar-toolkit' ), 'manage_options', 'anar-login-settings', array( new SettingsPage( new Settings(), \Anar\Login\Messaging\ProviderRegistry::shared() ), 'render' ) );
		add_action(
			'load-' . $settings_hook,
			static function () {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Hidden compatibility page still needs its admin heading.
				$GLOBALS['title'] = __( 'Settings', 'anar-toolkit' );
			}
		);
		add_submenu_page( $flags['balance'] ? 'anar-login' : null, __( 'SMS credit', 'anar-toolkit' ), __( 'SMS credit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-balance', array( BalancePage::class, 'render' ) );
		// Keep older bookmarked URLs working without a second copy of the catalog in the menu.
		add_submenu_page( null, __( 'Other features', 'anar-toolkit' ), __( 'Other features', 'anar-toolkit' ), 'manage_options', 'anar-features-other', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( false !== strpos( $hook, 'anar-' ) ) {
			wp_enqueue_style( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/css/admin.css', array(), ANAR_LOGIN_VERSION );
			wp_enqueue_style( 'anar-workspace', ANAR_LOGIN_URL . 'assets/css/workspace.css', array( 'anar-login-admin', 'anar-features' ), ANAR_LOGIN_VERSION );
			wp_enqueue_script( 'anar-navigation', ANAR_LOGIN_URL . 'assets/js/navigation.js', array(), ANAR_LOGIN_VERSION, true );
			wp_enqueue_script( 'anar-features', ANAR_LOGIN_URL . 'assets/js/features.js', array(), ANAR_LOGIN_VERSION, true );
			wp_localize_script( 'anar-features', 'AnarFeatures', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'anar_features' ), 'configure' => __( 'Configure', 'anar-toolkit' ), 'saving' => __( 'Saving…', 'anar-toolkit' ), 'queued' => __( 'Queued to save…', 'anar-toolkit' ), 'enabling' => __( 'Enabling…', 'anar-toolkit' ), 'disabling' => __( 'Disabling…', 'anar-toolkit' ), 'enabled' => __( 'Enabled successfully.', 'anar-toolkit' ), 'disabled' => __( 'Disabled successfully.', 'anar-toolkit' ), 'setup' => __( 'Set up my recovery access', 'anar-toolkit' ), 'saved' => __( 'Feature updated.', 'anar-toolkit' ), 'failed' => __( 'The change could not be confirmed. Check the saved state before trying again.', 'anar-toolkit' ) ) );
			wp_enqueue_script( 'anar-help', ANAR_LOGIN_URL . 'assets/js/help.js', array(), ANAR_LOGIN_VERSION, true );
			wp_enqueue_style( 'anar-features', ANAR_LOGIN_URL . 'assets/css/features.css', array( 'dashicons' ), ANAR_LOGIN_VERSION ); }
	}

	/**
	 * Pair each capability with its icon and a plain-language summary.
	 *
	 * @return array<string, array{string, string}>
	 */
	private static function cards() {
		return array(
			'login'         => array( 'admin-users', __( 'Lets customers sign in with a one-time SMS or email code, or with Google or a passkey when configured.', 'anar-toolkit' ) ),
			'reset'         => array( 'unlock', __( 'Lets customers set a new password with a verification code sent to the mobile number already linked to their account.', 'anar-toolkit' ) ),
			'two_factor'    => array( 'shield-alt', __( 'Requires an extra verification step after password sign-in for the configured group of users. Anar can send a one-time code to their linked mobile number or account email.', 'anar-toolkit' ) ),
			'login_log'     => array( 'list-view', __( 'Shows the time, provider, and result of SMS requests for sign-in, two-step verification, and password reset.', 'anar-toolkit' ) ),
			'recovery'      => array( 'cart', __( 'Reminds customers by SMS to return to an unfinished WooCommerce cart. Reminders can include a recovery link and an optional discount.', 'anar-toolkit' ) ),
			'orders'        => array( 'megaphone', __( 'Texts customers or store administrators about selected WooCommerce order events, such as payment, shipping, or a change of status.', 'anar-toolkit' ) ),
			'balance'       => array( 'money-alt', __( 'Checks the balance of selected SMS accounts on a schedule and can warn administrators when credit falls below the threshold for an account.', 'anar-toolkit' ) ),
			'lifecycle'     => array( 'groups', __( 'Sends automated messages after a first purchase, after a period without purchases, or when a customer reaches the VIP spending threshold.', 'anar-toolkit' ) ),
			'prediction'    => array( 'chart-line', __( 'Estimates when a customer may buy a product again from past purchases. SMS reminders can go out before, on, or after that date.', 'anar-toolkit' ) ),
			'smart'         => array( 'lightbulb', __( 'Recommends an in-stock product the customer has not bought recently and sends the offer by SMS, with an optional discount.', 'anar-toolkit' ) ),
			'bulk'          => array( 'email-alt', __( 'Schedules bulk SMS for selected groups of store customers or imported contacts. You review the recipient count before sending.', 'anar-toolkit' ) ),
			'stock'         => array( 'archive', __( 'Lets customers request an SMS for an out-of-stock product or variation and notifies them once when it is available again.', 'anar-toolkit' ) ),
			'survey'        => array( 'feedback', __( 'Sends a feedback link after an order is completed and accepts one response per paid order. A reward can be offered for submitting feedback.', 'anar-toolkit' ) ),
			'next_discount' => array( 'tickets-alt', __( 'Sends a single-use discount after a paid order. The coupon is valid for the next purchase with the recipient’s billing phone number.', 'anar-toolkit' ) ),
			'birthday'      => array( 'calendar-alt', __( 'Sends a birthday SMS to customers who share their birth month and day, with an optional gift coupon.', 'anar-toolkit' ) ),
			'invoice'       => array( 'media-document', __( 'Gives customers a printable order invoice in My Account. The store can also send an expiring invoice link by SMS.', 'anar-toolkit' ) ),
			'games'         => array( 'games', __( 'Lets customers with verified mobile numbers play promotional games, such as a prize wheel or quiz, and earn discount coupons.', 'anar-toolkit' ) ),
			'referral'      => array( 'share', __( 'Gives customers a referral link and rewards them with a coupon after an invited customer completes an eligible first order and the refund waiting period passes.', 'anar-toolkit' ) ),
			'traffic'       => array( 'chart-area', __( 'Reports store page views and traffic sources alongside WooCommerce sales and products. Counts exclude checkout, account pages, and administrator visits.', 'anar-toolkit' ) ),
		);
	}

	public static function render( $embedded = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $embedded ) {
			Workspace::start( __( 'Features', 'anar-toolkit' ) ); }
		$flags = Features::all();
		$labels = self::labels();
		$cards = self::cards();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only catalog selection.
		$other_first = isset( $_GET['page'] ) && 'anar-features-other' === $_GET['page'];
		$groups = $other_first ? array( false => __( 'Other features', 'anar-toolkit' ), true => __( 'Active features', 'anar-toolkit' ) ) : array( true => __( 'Active features', 'anar-toolkit' ), false => __( 'Other features', 'anar-toolkit' ) );
		?>
		<div class="<?php echo $embedded ? 'anar-features' : 'wrap anar-features'; ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<h2><?php esc_html_e( 'Features', 'anar-toolkit' ); ?></h2>
			<!---->
			<?php
			if ( ! $embedded ) :
				?>
				<hr class="wp-header-end">
				<?php
				Support::notice();
				StatusPanel::credit();
endif;
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'anar_features' ); ?>
				<input type="hidden" name="action" value="anar_features">
				<?php foreach ( $groups as $active => $heading ) : ?>
					<section id="<?php echo $active ? 'active' : 'other'; ?>" class="anar-feature-section">
						<h2><?php echo esc_html( $heading ); ?></h2>
						<div class="anar-feature-grid">
							<?php foreach ( $labels as $key => $label ) : ?>
								<?php
								if ( (bool) $flags[ $key ] !== (bool) $active ) {
									continue;
								}
								list( $icon, $description ) = $cards[ $key ];
								$title_id = 'anar-feature-title-' . $key;
								?>
								<article class="anar-feature-card <?php echo $active ? 'is-active' : ''; ?>" id="feature-<?php echo esc_attr( $key ); ?>">
									<div class="anar-feature-art" aria-hidden="true">
										<span class="anar-feature-watermark dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
									</div>
									<div class="anar-feature-heading">
										<span class="anar-feature-icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
										<?php Help::button( $description . ' ' . Help::text( $key ) ); ?>
									</div>
									<h3 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $label ); ?></h3>

									<footer>
										<label class="anar-feature-switch">
											<input type="checkbox" role="switch" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" name="features[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $flags[ $key ] ); ?>>
											<span class="anar-feature-switch-track" aria-hidden="true"></span>
											<span class="anar-feature-switch-on" aria-hidden="true"><?php esc_html_e( 'Enabled', 'anar-toolkit' ); ?></span>
											<span class="anar-feature-switch-off" aria-hidden="true"><?php esc_html_e( 'Disabled', 'anar-toolkit' ); ?></span>
										</label>
										<?php if ( $active ) : ?>
											<a class="button anar-feature-configure" href="<?php echo esc_url( self::url( $key ) ); ?>">
												<?php esc_html_e( 'Configure', 'anar-toolkit' ); ?>
												<span class="dashicons <?php echo is_rtl() ? 'dashicons-arrow-left-alt2' : 'dashicons-arrow-right-alt2'; ?>" aria-hidden="true"></span>
											</a>
										<?php endif; ?>
									</footer>
									<div class="anar-feature-feedback" role="status" aria-live="polite" hidden></div>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
				<noscript><div class="anar-feature-save">
					<p><?php esc_html_e( 'Turning a feature off keeps its settings and stored records.', 'anar-toolkit' ); ?></p>
					<?php submit_button( __( 'Save feature selection', 'anar-toolkit' ) ); ?>
				</div></noscript>
			</form>
		</div>
		<?php
		if ( ! $embedded ) {
			Workspace::end(); }
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ) ); }
		check_admin_referer( 'anar_features' );
		// Values are converted to booleans against this fixed allowlist; no submitted text is stored.
		$selected = isset( $_POST['features'] ) && is_array( $_POST['features'] ) ? array_map( 'absint', wp_unslash( $_POST['features'] ) ) : array();
		if ( ! empty( $selected['two_factor'] ) && ! Features::enabled( 'two_factor' ) && ! \Anar\Login\Auth\RecoveryCodes::prepared( get_current_user_id() ) ) {
			wp_die( esc_html__( 'Before enabling two-step verification, open account security and confirm your saved recovery codes.', 'anar-toolkit' ) );
		}
		$core = array();
		foreach ( array( 'login', 'reset', 'two_factor', 'login_log', 'orders' ) as $key ) {
			$core[ $key ] = empty( $selected[ $key ] ) ? 0 : 1; }
		update_option( Features::OPTION, $core, true );
		$login = (array) get_option( Settings::OPTION_KEY, array() );
		$login['password_reset_enabled'] = $core['reset'];
		if ( $core['two_factor'] && 'off' === ( $login['two_factor'] ?? 'off' ) ) {
			$login['two_factor'] = 'privileged'; }
		update_option( Settings::OPTION_KEY, $login, false );
		$commerce = (array) get_option( 'anar_toolkit_commerce', array() );
		$commerce['recovery'] = empty( $selected['recovery'] ) ? 0 : 1;
		if ( $core['orders'] && ! array_filter( array_intersect_key( $commerce, array_flip( Features::order_events() ) ) ) ) {
			$commerce['paid_customer'] = 1; }
		update_option( 'anar_toolkit_commerce', $commerce, false );
		$marketing = (array) get_option( 'anar_toolkit_marketing', array() );
		foreach ( Features::marketing_keys() as $key ) {
			$marketing['modules'][ $key ] = empty( $selected[ $key ] ) ? 0 : 1; }
		update_option( 'anar_toolkit_marketing', $marketing, false );
		$balance = \Anar\Login\Balance\Monitor::settings();
		$balance['enabled'] = empty( $selected['balance'] ) ? 0 : 1;
		update_option( \Anar\Login\Balance\Monitor::OPTION, $balance, false );
		wp_safe_redirect( admin_url( 'admin.php?page=anar-login-settings&section=features&saved=1' ) );
		exit;
	}

	/** Update one capability without submitting a stale copy of the entire catalog. */
	public static function set_feature( $key, $enabled ) {
		if ( 'two_factor' === $key && $enabled && ! Features::enabled( $key ) && ! \Anar\Login\Auth\RecoveryCodes::prepared( get_current_user_id() ) ) {
			return new \WP_Error( 'anar_recovery_required', __( 'Before enabling two-step verification, open account security and confirm your saved recovery codes.', 'anar-toolkit' ) );
		}
		if ( ! array_key_exists( $key, self::labels() ) ) {
			return new \WP_Error( 'anar_feature_unknown', __( 'Unknown feature.', 'anar-toolkit' ) );
		}
		$enabled = (int) (bool) $enabled;
		if ( in_array( $key, array( 'login', 'reset', 'two_factor', 'login_log', 'orders' ), true ) ) {
			$saved = (array) get_option( Features::OPTION, array() );
			$core = $saved['switches'] ?? $saved;
			$core[ $key ] = $enabled;
			update_option( Features::OPTION, array( 'switches' => $core ), true );
			if ( 'reset' === $key || ( 'two_factor' === $key && $enabled ) ) {
				$login = (array) get_option( Settings::OPTION_KEY, array() );
				if ( 'reset' === $key ) {
					$login['password_reset_enabled'] = $enabled;
				} elseif ( 'off' === ( $login['two_factor'] ?? 'off' ) ) {
					$login['two_factor'] = 'privileged';
				}
				update_option( Settings::OPTION_KEY, $login, false );
			}
			if ( 'orders' === $key && $enabled ) {
				$commerce = (array) get_option( 'anar_toolkit_commerce', array() );
				if ( ! array_filter( array_intersect_key( $commerce, array_flip( Features::order_events() ) ) ) ) {
					$commerce['paid_customer'] = 1;
					update_option( 'anar_toolkit_commerce', $commerce, false );
				}
			}
		} elseif ( 'recovery' === $key || 'balance' === $key ) {
			$option = 'balance' === $key ? \Anar\Login\Balance\Monitor::OPTION : 'anar_toolkit_commerce';
			$value = (array) get_option( $option, array() );
			$value[ 'balance' === $key ? 'enabled' : 'recovery' ] = $enabled;
			update_option( $option, $value, false );
		} else {
			$value = (array) get_option( 'anar_toolkit_marketing', array() );
			$value['modules'][ $key ] = $enabled;
			update_option( 'anar_toolkit_marketing', $value, false );
		}
		Features::refresh();
		return Features::enabled( $key ) === (bool) $enabled;
	}

	public function state() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'text' => __( 'You cannot manage these settings.', 'anar-toolkit' ) ), 403 );
		}
		check_ajax_referer( 'anar_features', 'nonce' );
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! array_key_exists( $key, self::labels() ) ) {
			wp_send_json_error( array( 'text' => __( 'Unknown feature.', 'anar-toolkit' ) ), 422 );
		}
		wp_send_json_success( self::feature_state( $key, isset( $_POST['workspace'] ) && 'settings' === $_POST['workspace'] ) );
	}

	/** Return both navigation surfaces from the confirmed saved switches. */
	private static function feature_state( $key, $settings ) {
		ob_start();
		Workspace::navigation( $settings );
		$navigation = ob_get_clean();
		return array(
			'enabled' => Features::enabled( $key ),
			'url' => self::url( $key ),
			'navigation' => $navigation,
			'menu' => array(
				array( 'page' => 'anar-toolkit-commerce', 'label' => __( 'Commerce toolkit', 'anar-toolkit' ), 'url' => admin_url( 'admin.php?page=anar-toolkit-commerce' ), 'visible' => Features::enabled( 'recovery' ) || Features::enabled( 'orders' ) || Features::enabled( 'login_log' ) || Features::queue_needed() ),
				array( 'page' => 'anar-toolkit-balance', 'label' => __( 'SMS credit', 'anar-toolkit' ), 'url' => BalancePage::url(), 'visible' => Features::enabled( 'balance' ) ),
			),
		);
	}

	public function toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'text' => __( 'You cannot manage these settings.', 'anar-toolkit' ) ), 403 );
		}
		check_ajax_referer( 'anar_features', 'nonce' );
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! array_key_exists( $key, self::labels() ) || ! isset( $_POST['enabled'], $_POST['expected'] ) || ! in_array( $_POST['enabled'], array( '0', '1' ), true ) || ! in_array( $_POST['expected'], array( '0', '1' ), true ) ) {
			wp_send_json_error( array( 'text' => __( 'Unknown feature.', 'anar-toolkit' ) ), 422 );
		}
		$lease = array( 'expires' => time() + 30, 'token' => wp_generate_uuid4() );
		$lock_key = 'anar_feature_update_lock';
		$previous_lock = get_option( $lock_key );
		if ( is_array( $previous_lock ) && ( $previous_lock['expires'] ?? 0 ) < time() ) {
			\Anar\Login\Infrastructure\AtomicOptions::remove( $lock_key, $previous_lock );
		}
		if ( ! add_option( $lock_key, $lease, '', false ) ) {
			wp_send_json_error( array( 'text' => __( 'Another feature is being updated. Please try again in a moment.', 'anar-toolkit' ), 'retry' => true ), 409 );
		}
		// A request may have booted before another tab committed its change.
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( array( Features::OPTION, Settings::OPTION_KEY, 'anar_toolkit_commerce', 'anar_toolkit_marketing', 'anar_toolkit_sms_balance' ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		$conflict = Features::enabled( $key ) !== (bool) $_POST['expected'];
		try {
			$result = $conflict ? false : self::set_feature( $key, '1' === $_POST['enabled'] );
		} finally {
			\Anar\Login\Infrastructure\AtomicOptions::remove( $lock_key, $lease );
		}
		if ( $conflict ) {
			wp_send_json_error( array( 'text' => __( 'This feature was changed elsewhere. Reload to see its current state.', 'anar-toolkit' ), 'enabled' => Features::enabled( $key ) ), 409 );
		}
		if ( true !== $result ) {
			wp_send_json_error( array( 'text' => is_wp_error( $result ) ? $result->get_error_message() : __( 'The change could not be confirmed. Check the saved state before trying again.', 'anar-toolkit' ), 'enabled' => Features::enabled( $key ), 'setup' => 'two_factor' === $key ? add_query_arg( 'action', 'anar_security', wp_login_url() ) : '' ), 422 );
		}
		wp_send_json_success( self::feature_state( $key, isset( $_POST['workspace'] ) && 'settings' === $_POST['workspace'] ) );
	}
}
