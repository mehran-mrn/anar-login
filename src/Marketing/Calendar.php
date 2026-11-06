<?php
/**
 * Birthday month/day handling without retaining birth years.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Calendar {
	public static function month_day( $calendar, $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : $timestamp;
		if ( 'jalali' === $calendar ) {
			if ( ! class_exists( 'IntlDateFormatter' ) ) {
				return ''; }
			$formatter = new \IntlDateFormatter( 'fa_IR@calendar=persian', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, wp_timezone_string(), \IntlDateFormatter::TRADITIONAL, 'MM-dd' );
			return Identity::latin_digits( (string) $formatter->format( $timestamp ) );
		}
		return wp_date( 'm-d', $timestamp );
	}

	public static function valid( $month, $day, $calendar ) {
		if ( 'jalali' === $calendar ) {
			return class_exists( 'IntlDateFormatter' ) && $month >= 1 && $month <= 12 && $day >= 1 && $day <= ( $month <= 6 ? 31 : 30 ); }
		return checkdate( $month, $day, 2000 );
	}
}
