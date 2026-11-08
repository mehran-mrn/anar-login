<?php
/**
 * Stable phone metadata interoperability.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Support;

defined( 'ABSPATH' ) || exit;

final class PhoneKeys {
	/** Historical storage identifier; retain its bytes to prevent duplicate ownership. */
	public static function legacy() {
		return hex2bin( '6469676974735f70686f6e65' );
	}
}
