<?php
/**
 * Feature switches must control rendered workspace navigation.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\Workspace;
use Anar\Login\Infrastructure\Features;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class NavigationTest extends TestCase {
	private $original;

	protected function setUp(): void {
		$this->original = $GLOBALS['anar_test_options'] ?? array();
		$GLOBALS['anar_test_options'] = array( Features::OPTION => array( '_snapshot' => 1, 'enabled' => array() ), 'anar_digits_migration_detected' => 'no' );
	}

	protected function tearDown(): void {
		$GLOBALS['anar_test_options'] = $this->original;
	}

	private function navigation( $settings = true ) {
		ob_start();
		Workspace::navigation( $settings );
		return ob_get_clean();
	}

	public function test_disabled_settings_are_absent_in_both_navigation_modes() {
		foreach ( array( true, false ) as $settings ) {
			$html = $this->navigation( $settings );
			foreach ( array( 'general', 'security', 'google', 'setup', 'install', 'woocommerce', 'sending' ) as $tab ) {
				$this->assertStringNotContainsString( $settings ? 'data-tab="' . $tab . '"' : 'section=' . $tab, $html );
			}
			$this->assertStringNotContainsString( 'page=anar-toolkit-balance', $html );
			$this->assertStringContainsString( $settings ? 'data-tab="features"' : 'section=features', $html );
			$this->assertStringContainsString( $settings ? 'data-tab="sms"' : 'section=sms', $html );
		}
	}

	public function test_credit_link_disappears_and_returns_with_its_switch() {
		foreach ( array( true, false, true ) as $enabled ) {
			$GLOBALS['anar_test_options'][ Features::OPTION ]['enabled']['balance'] = $enabled;
			$this->assertSame( $enabled, strpos( $this->navigation(), 'page=anar-toolkit-balance' ) !== false );
		}
	}

	public function test_user_tools_require_unmatched_source_data_and_hide_after_completion() {
		$this->assertStringNotContainsString( 'page=anar-login-user-tools', $this->navigation() );
		$GLOBALS['anar_test_options']['anar_digits_migration_detected'] = 'yes';
		$this->assertStringContainsString( 'page=anar-login-user-tools', $this->navigation() );
		$GLOBALS['anar_test_options']['anar_digits_migration_complete'] = array( 'at' => 123 );
		$this->assertStringNotContainsString( 'page=anar-login-user-tools', $this->navigation() );
	}

	public function test_sign_in_settings_restore_and_shared_security_survives_password_recovery() {
		$GLOBALS['anar_test_options'][ Features::OPTION ]['enabled']['login'] = true;
		foreach ( array( 'general', 'security', 'google', 'setup', 'install' ) as $tab ) {
			$this->assertStringContainsString( 'data-tab="' . $tab . '"', $this->navigation() );
		}
		$GLOBALS['anar_test_options'][ Features::OPTION ]['enabled'] = array( 'reset' => true );
		$html = $this->navigation();
		$this->assertStringContainsString( 'data-tab="security"', $html );
		$this->assertStringNotContainsString( 'data-tab="google"', $html );
		$this->assertStringNotContainsString( 'data-tab="setup"', $html );
	}
}
