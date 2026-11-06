<?php
/**
 * Bounded administrator import of phone-only campaign contacts.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Contacts {
	public static function parse( $text ) {
		$phones = array();
		$invalid = 0;
		foreach ( array_slice( preg_split( '/\r\n|\n|\r/', trim( $text ) ), 0, 1000 ) as $line ) {
			if ( ! trim( $line ) ) {
				continue; }
			$columns = str_getcsv( $line, ',', '"', '' );
			$phone = Identity::normalize( trim( $columns[0] ?? '', "\xEF\xBB\xBF \t" ) );
			if ( is_wp_error( $phone ) || 'sms' !== $phone['channel'] ) {
				++$invalid;
				continue; }
			$phones[ $phone['identity'] ] = true;
		}
		return array( 'phones' => array_keys( $phones ), 'invalid' => $invalid );
	}
}
