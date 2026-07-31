<?php
/**
 * SMS provider contract.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging;

defined( 'ABSPATH' ) || exit;

interface SmsProviderInterface {
	/**
	 * Stable provider key.
	 *
	 * @return string
	 */
	public function key();

	/**
	 * Human-readable provider title.
	 *
	 * @return string
	 */
	public function title();

	/**
	 * Required settings and labels.
	 *
	 * @return array<string,string>
	 */
	public function fields();

	/**
	 * Send an OTP.
	 *
	 * @param string $recipient Canonical phone.
	 * @param string $code      OTP.
	 * @param string $message   Rendered fallback message.
	 * @return true|\WP_Error
	 */
	public function send( $recipient, $code, $message );
}
