<?php
/**
 * Shared administration frame with contextual navigation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Features;

defined( 'ABSPATH' ) || exit;

final class Workspace {
	public static function header( $title, $preview = false ) {
		?>
		<header class="anar-admin__header"><div class="anar-admin__title"><img src="<?php echo esc_url( ANAR_LOGIN_URL . 'assets/images/anar-logo.png' ); ?>" alt=""><div><div><h1>Anar Toolkit</h1><span>v<?php echo esc_html( ANAR_LOGIN_VERSION ); ?></span></div><p><?php echo esc_html( $title ); ?></p></div></div><div class="anar-admin__header-actions"><div class="anar-admin__health"><span aria-hidden="true"></span><?php esc_html_e( 'Core is active', 'anar-toolkit' ); ?></div>
		<?php
		if ( $preview && Features::enabled( 'login' ) ) :
			?>
			<button type="button" class="button" data-login-preview><?php esc_html_e( 'Preview saved form', 'anar-toolkit' ); ?></button><?php endif; ?></div></header>
		<?php
	}

	public static function navigation( $settings = false ) {
		$page = $GLOBALS['plugin_page'] ?? '';
		$commerce = 'anar-toolkit-commerce' === $page || (bool) Features::current_module();
		$tabs = Features::workspace_tabs();
		$groups = array(
			__( 'Features', 'anar-toolkit' ) => array( 'features' => __( 'Features', 'anar-toolkit' ) ),
			__( 'Sign-in and accounts', 'anar-toolkit' ) => array( 'general' => __( 'Sign-in settings', 'anar-toolkit' ), 'security' => __( 'Security', 'anar-toolkit' ), 'google' => __( 'Google sign-in', 'anar-toolkit' ), 'setup' => __( 'Sign-in setup guide', 'anar-toolkit' ), 'install' => __( 'Add to pages', 'anar-toolkit' ) ),
			__( 'Store and marketing', 'anar-toolkit' ) => array(),
			__( 'Messages and sending report', 'anar-toolkit' ) => array( 'messages' => __( 'Messages and templates', 'anar-toolkit' ) ),
			__( 'Shared settings', 'anar-toolkit' ) => array( 'sms' => __( 'Service providers', 'anar-toolkit' ), 'sending' => __( 'Campaign sending rules', 'anar-toolkit' ), 'woocommerce' => __( 'WooCommerce', 'anar-toolkit' ) ),
		);
		foreach ( $groups as $heading => $items ) {
			if ( __( 'Store and marketing', 'anar-toolkit' ) === $heading && ! class_exists( 'WooCommerce' ) ) {
				continue; }
			?>
			<div class="anar-admin__nav-label" role="heading" aria-level="2"><?php echo esc_html( $heading ); ?></div>
			<?php
			foreach ( $items as $key => $label ) {
				if ( 'woocommerce' === $key && ! class_exists( 'WooCommerce' ) ) {
					continue; }
				if ( $settings ) {
					?>
					<button type="button" data-tab="<?php echo esc_attr( $key ); ?>" <?php echo 'general' === $key ? 'class="is-active"' : ''; ?>><?php echo esc_html( $label ); ?></button>
					<?php
				} else {
					self::link( admin_url( 'admin.php?page=anar-login-settings&section=' . $key ), $label, 'features' === $key && 'anar-features-other' === $page );
				}
			}
			if ( __( 'Store and marketing', 'anar-toolkit' ) === $heading ) {
				foreach ( Features::workspace_tabs() as $key => $label ) {
					if ( in_array( $key, array( 'messages', 'privacy', 'campaigns' ), true ) ) {
						continue; }
					self::link( admin_url( 'admin.php?page=anar-toolkit-commerce&tab=' . $key ), $label, ! Features::current_module() && Features::current_tab() === $key && $commerce );
				}
				foreach ( \Anar\Login\Marketing\Settings::modules() as $key => $label ) {
					if ( Features::enabled( $key ) ) {
						self::link( FeaturesPage::url( $key ), $label, Features::current_module() === $key ); }
				}
			}
			if ( __( 'Messages and sending report', 'anar-toolkit' ) === $heading && isset( $tabs['messages'] ) ) {
				self::link( admin_url( 'admin.php?page=anar-toolkit-commerce&tab=messages' ), __( 'Sending report', 'anar-toolkit' ), $commerce && 'messages' === Features::current_tab() );
			}
			if ( __( 'Shared settings', 'anar-toolkit' ) === $heading ) {
				self::link( BalancePage::url(), __( 'SMS credit', 'anar-toolkit' ), 'anar-toolkit-balance' === $page );
				if ( class_exists( 'WooCommerce' ) ) {
					self::link( admin_url( 'admin.php?page=anar-toolkit-commerce&tab=privacy' ), __( 'Personal data', 'anar-toolkit' ), $commerce && 'privacy' === Features::current_tab() ); }
			}
		}
	}

	private static function link( $url, $label, $active = false ) {
		?>
		<a class="anar-workspace-link <?php echo $active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?><span aria-hidden="true"><?php echo is_rtl() ? '‹' : '›'; ?></span></a>
		<?php
	}

	public static function start( $title ) {
		?>
		<div class="wrap anar-admin anar-workspace" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>"><?php self::header( $title ); ?><hr class="wp-header-end"><div class="anar-admin__layout"><aside class="anar-admin__nav"><?php self::navigation(); ?></aside><main class="anar-workspace-content">
		<?php
	}
	public static function end() {
		?>
		</main></div></div>
		<?php
	}
}
