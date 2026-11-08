<?php
/**
 * Copy to mu-plugins only in a disposable local installation for isolation tests.
 *
 * @package AnarLogin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
	return;
}
$anar_qa_mode = getenv( 'ANAR_QA_FEATURE_MODE' );
if ( ! in_array( $anar_qa_mode, array( 'off', 'login', 'passkeys', 'two_factor', 'invoice', 'games', 'traffic', 'stock', 'recovery', 'orders' ), true ) ) {
	return;
}
add_filter(
	'pre_option_anar_toolkit_features',
	static function () use ( $anar_qa_mode ) {
		$flags = array_fill_keys( array( 'login', 'reset', 'two_factor', 'login_log', 'recovery', 'orders', '_invoice_sms', 'lifecycle', 'prediction', 'smart', 'bulk', 'stock', 'survey', 'next_discount', 'birthday', 'invoice', 'games', 'referral', 'traffic' ), false );
		if ( 'passkeys' === $anar_qa_mode ) {
			$flags['login'] = true; }
		if ( array_key_exists( $anar_qa_mode, $flags ) ) {
			$flags[ $anar_qa_mode ] = true;
		}
		return array( '_snapshot' => 1, 'enabled' => $flags, 'switches' => array( 'login' => in_array( $anar_qa_mode, array( 'login', 'passkeys' ), true ) ? 1 : 0, 'reset' => 0, 'login_log' => 0, 'two_factor' => 'two_factor' === $anar_qa_mode ? 1 : 0, 'orders' => 'orders' === $anar_qa_mode ? 1 : 0 ) );
	}
);
add_filter(
	'pre_option_anar_login_settings',
	static function () use ( $anar_qa_mode ) {
		return array( 'passkeys_enabled' => 'passkeys' === $anar_qa_mode ? 1 : 0, 'identity_mode' => 'phone', 'password_reset_enabled' => 0, 'replace_theme_login' => 0, 'two_factor' => 'two_factor' === $anar_qa_mode ? 'privileged' : 'off' );
	}
);
add_filter(
	'pre_option_anar_toolkit_commerce',
	static function () use ( $anar_qa_mode ) {
		return array( 'recovery' => 'recovery' === $anar_qa_mode ? 1 : 0, 'paid_customer' => 'orders' === $anar_qa_mode ? 1 : 0 );
	}
);
add_filter(
	'pre_option_anar_toolkit_marketing',
	static function () use ( $anar_qa_mode ) {
		return array( 'modules' => array( $anar_qa_mode => 1 ) );
	}
);
add_filter(
	'pre_option_anar_login_db_version',
	static function () {
		return ANAR_LOGIN_VERSION;
	}
);
$GLOBALS['anar_qa_feature_queries'] = array();
add_filter(
	'query',
	static function ( $sql ) {
		if ( false !== strpos( $sql, 'anar_marketing_' ) || false !== strpos( $sql, 'anar_toolkit_' ) ) {
			$GLOBALS['anar_qa_feature_queries'][] = $sql;
		}
		return $sql;
	}
);
