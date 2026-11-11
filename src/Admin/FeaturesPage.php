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
		if ( 'recovery' === $key || 'orders' === $key ) {
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
		add_submenu_page( 'anar-login', __( 'Features', 'anar-toolkit' ), __( 'Features', 'anar-toolkit' ), 'manage_options', 'anar-login', array( self::class, 'render' ), 0 );
		$flags = Features::all();
		$commerce = new \Anar\Login\Commerce\Admin( new \Anar\Login\Commerce\Settings(), new \Anar\Login\Commerce\Repository(), \Anar\Login\Messaging\ProviderRegistry::shared() );
		if ( $flags['recovery'] || $flags['orders'] || $flags['login_log'] || Features::queue_needed() ) {
			add_submenu_page( 'anar-login', __( 'Commerce toolkit', 'anar-toolkit' ), __( 'Commerce toolkit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-commerce', array( $commerce, 'render' ) ); }
		if ( ! $flags['recovery'] && ! $flags['orders'] && ! $flags['login_log'] && ! Features::queue_needed() ) {
			add_submenu_page( null, __( 'Commerce toolkit', 'anar-toolkit' ), __( 'Commerce toolkit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-commerce', array( $commerce, 'render' ) ); }
		foreach ( Features::marketing_keys() as $key ) {
			if ( $flags[ $key ] && class_exists( 'WooCommerce' ) ) {
				add_submenu_page( 'anar-login', self::labels()[ $key ], self::labels()[ $key ], 'manage_options', 'anar-toolkit-' . $key, array( $commerce, 'render' ) ); }
		}
		add_submenu_page( 'anar-login', __( 'Settings', 'anar-toolkit' ), __( 'Settings', 'anar-toolkit' ), 'manage_options', 'anar-login-settings', array( new SettingsPage( new Settings(), \Anar\Login\Messaging\ProviderRegistry::shared() ), 'render' ) );
		add_submenu_page( $flags['balance'] ? 'anar-login' : null, __( 'SMS credit', 'anar-toolkit' ), __( 'SMS credit', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-balance', array( BalancePage::class, 'render' ) );
		// Keep older bookmarked URLs working without a second copy of the catalog in the menu.
		add_submenu_page( null, __( 'Other features', 'anar-toolkit' ), __( 'Other features', 'anar-toolkit' ), 'manage_options', 'anar-features-other', array( $this, 'render' ) );
	}

	public function assets( $hook ) {
		if ( false !== strpos( $hook, 'anar-' ) ) {
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

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$flags = Features::all();
		$labels = self::labels();
		$cards = self::cards();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only catalog selection.
		$other_first = isset( $_GET['page'] ) && 'anar-features-other' === $_GET['page'];
		$groups = $other_first ? array( false => __( 'Other features', 'anar-toolkit' ), true => __( 'Active features', 'anar-toolkit' ) ) : array( true => __( 'Active features', 'anar-toolkit' ), false => __( 'Other features', 'anar-toolkit' ) );
		?>
		<div class="wrap anar-features" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<header class="anar-features-banner">
				<img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png?ver=' . ANAR_LOGIN_VERSION ); ?>" alt="" width="64" height="64">
				<div>
					<h1><?php esc_html_e( 'Features', 'anar-toolkit' ); ?></h1>
					<p><?php esc_html_e( 'Enable the features your store needs.', 'anar-toolkit' ); ?></p>
				</div>
			</header>
			<hr class="wp-header-end">
			<?php StatusPanel::credit(); ?>
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
										<?php Help::button( Help::text( $key ) ); ?>
									</div>
									<h3 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $label ); ?></h3>
									<p><?php echo esc_html( $description ); ?></p>
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
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
				<div class="anar-feature-save">
					<p><?php esc_html_e( 'Turning a feature off keeps its settings and stored records.', 'anar-toolkit' ); ?></p>
					<?php submit_button( __( 'Save feature selection', 'anar-toolkit' ) ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ) ); }
		check_admin_referer( 'anar_features' );
		// Values are converted to booleans against this fixed allowlist; no submitted text is stored.
		$selected = isset( $_POST['features'] ) && is_array( $_POST['features'] ) ? array_map( 'absint', wp_unslash( $_POST['features'] ) ) : array();
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
		wp_safe_redirect( admin_url( 'admin.php?page=anar-login&saved=1' ) );
		exit;
	}
}
