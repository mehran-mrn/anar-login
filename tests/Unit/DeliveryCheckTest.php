<?php
/**
 * Configuration inspection must never send, persist or disclose account data.
 *
 * @package AnarLogin
 */

use Anar\Login\Admin\DeliveryCheck;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageSettings;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/src/Autoloader.php';
\Anar\Login\Autoloader::register();

final class DeliveryCheckTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['anar_test_options'] = array(
			Settings::OPTION_KEY => array(
				'sms_provider'      => 'panelchi',
				'provider_panelchi' => array( 'api_key' => 'PRIVATE-ACCOUNT-SECRET', 'template_id' => 'PRIVATE-TEMPLATE', 'code_parameter' => 'otp' ),
			),
		);
		$GLOBALS['anar_test_http_requests'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['anar_test_options'], $GLOBALS['anar_test_http_requests'] );
	}

	public function test_inspection_is_read_only_and_never_sends_or_discloses_secrets() {
		$before = $GLOBALS['anar_test_options'];
		$report = DeliveryCheck::report();
		$this->assertTrue( $report['ready'] );
		$this->assertSame( $before, $GLOBALS['anar_test_options'] );
		$this->assertSame( array(), $GLOBALS['anar_test_http_requests'] );
		$this->assertStringNotContainsString( 'PRIVATE-', wp_json_encode( $report ) );
		$this->assertStringNotContainsString( 'PRIVATE-', DeliveryCheck::support_text( $report ) );
	}

	public function test_it_requires_review_when_a_message_comes_from_another_provider() {
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = array( 'sender_schema' => 2, 'provider' => 'smsir', 'mode' => 'template', 'template' => 'private-template', 'params' => 'otp={code}', 'message' => '' );
		$report = DeliveryCheck::report();
		$this->assertFalse( $report['ready'], 'A message from another provider must be reviewed before testing.' );
		$this->assertSame( 'Panelchi', $report['provider'] );
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ]['provider'] = 'panelchi';
		$this->assertTrue( DeliveryCheck::report()['ready'] );
	}

	public function test_changes_in_credentials_or_message_invalidate_the_revision() {
		$original = DeliveryCheck::report()['revision'];
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_panelchi']['api_key'] = 'NEW-ACCOUNT';
		$this->assertNotSame( $original, DeliveryCheck::report()['revision'] );
		$original = DeliveryCheck::report()['revision'];
		$GLOBALS['anar_test_options'][ Settings::OPTION_KEY ]['provider_panelchi']['template_id'] = 'NEW-TEMPLATE';
		$this->assertNotSame( $original, DeliveryCheck::report()['revision'] );
	}

	public function test_support_report_excludes_even_invalid_message_values() {
		$GLOBALS['anar_test_options'][ MessageSettings::OPTION . '_login_otp' ] = array( 'sender_schema' => 2, 'provider' => 'panelchi', 'mode' => 'template', 'template' => 'PRIVATE-TEMPLATE', 'params' => 'otp={code}{PRIVATE_DATA}', 'message' => '' );
		$report = DeliveryCheck::report();
		$this->assertFalse( $report['ready'] );
		$this->assertStringNotContainsString( 'PRIVATE', DeliveryCheck::support_text( $report ) );
		$this->assertSame( array(), $GLOBALS['anar_test_http_requests'] );
	}
}
