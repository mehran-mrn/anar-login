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
			wp_enqueue_style( 'anar-features', ANAR_LOGIN_URL . 'assets/css/features.css', array(), ANAR_LOGIN_VERSION ); }
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$flags = Features::all();
		$labels = self::labels();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only catalog selection.
		$other_first = isset( $_GET['page'] ) && 'anar-features-other' === $_GET['page'];
		$groups = $other_first ? array( false => __( 'Other features', 'anar-toolkit' ), true => __( 'Active features', 'anar-toolkit' ) ) : array( true => __( 'Active features', 'anar-toolkit' ), false => __( 'Other features', 'anar-toolkit' ) );
		?>
		<div class="wrap anar-features" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>"><header><img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png?ver=' . ANAR_LOGIN_VERSION ); ?>" alt=""><div><h1><?php esc_html_e( 'Anar Toolkit', 'anar-toolkit' ); ?></h1><p><?php esc_html_e( 'Enable the features your store needs.', 'anar-toolkit' ); ?></p></div></header><hr class="wp-header-end">
		<?php StatusPanel::credit(); ?>
		<p class="anar-features-intro"><?php esc_html_e( 'Active features appear first. Other features remain below with their instructions and activation switch. Turning a feature off keeps its settings and stored records.', 'anar-toolkit' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_features' ); ?><input type="hidden" name="action" value="anar_features">
		<?php foreach ( $groups as $active => $heading ) : ?>
		<section id="<?php echo $active ? 'active' : 'other'; ?>"><h2><?php echo esc_html( $heading ); ?></h2><div class="anar-feature-grid">
			<?php
			foreach ( $labels as $key => $label ) :
				if ( (bool) $flags[ $key ] !== (bool) $active ) {
					continue; }
				?>
		<article class="anar-feature-card <?php echo $active ? 'is-active' : ''; ?>" id="feature-<?php echo esc_attr( $key ); ?>"><div class="anar-feature-heading"><h3><?php echo esc_html( $label ); ?></h3><?php Help::button( Help::text( $key ) ); ?></div><p><?php echo esc_html( wp_html_excerpt( Help::text( $key ), $active ? 220 : 95, '…' ) ); ?></p><footer><label><input type="checkbox" name="features[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $flags[ $key ] ); ?>> <?php esc_html_e( 'Enabled', 'anar-toolkit' ); ?></label>
				<?php
				if ( $active ) :
					?>
			<a class="button" href="<?php echo esc_url( self::url( $key ) ); ?>"><?php esc_html_e( 'Configure', 'anar-toolkit' ); ?></a><?php endif; ?></footer></article>
		<?php endforeach; ?></div></section><?php endforeach; ?><div class="anar-feature-save"><?php submit_button( __( 'Save feature selection', 'anar-toolkit' ) ); ?><p><?php esc_html_e( 'Disabled modules do not load storefront assets or run feature queries. Existing personal-coupon restrictions and scheduled data cleanup remain in effect.', 'anar-toolkit' ); ?></p></div></form></div>
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
