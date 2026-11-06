<?php
/**
 * Independently switchable customer marketing modules.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private $resolved;
	private $source;
	const OPTION = 'anar_toolkit_marketing';

	public static function modules() {
		return array( 'lifecycle' => __( 'Customer lifecycle', 'anar-toolkit' ), 'prediction' => __( 'Next-purchase prediction', 'anar-toolkit' ), 'smart' => __( 'Smart product offers', 'anar-toolkit' ), 'bulk' => __( 'Segmented bulk messages', 'anar-toolkit' ), 'stock' => __( 'Back in stock', 'anar-toolkit' ), 'survey' => __( 'Post-purchase surveys', 'anar-toolkit' ), 'next_discount' => __( 'Next-purchase gifts', 'anar-toolkit' ), 'birthday' => __( 'Birthday greetings', 'anar-toolkit' ), 'invoice' => __( 'Printable invoices', 'anar-toolkit' ), 'games' => __( 'Promotional games', 'anar-toolkit' ), 'referral' => __( 'Customer referrals', 'anar-toolkit' ), 'traffic' => __( 'Traffic and sales insights', 'anar-toolkit' ) );
	}

	public function defaults() {
		$rules = array();
		foreach ( array( 'welcome' => 1, 'return_1' => 30, 'return_2' => 60, 'return_3' => 90, 'vip' => 0, 'prediction_before' => -3, 'prediction_on' => 0, 'prediction_after' => 4, 'smart' => 14, 'survey' => 7, 'next_discount' => 1, 'invoice' => 0, 'birthday' => 0, 'stock' => 0, 'bulk' => 0 ) as $key => $days ) {
			$rules[ $key ] = array( 'enabled' => 'invoice' === $key ? 0 : 1, 'days' => $days, 'message' => __( '{site}: Hello {name}. {message} {product_url} {coupon_code} {action_url} Stop messages: {unsubscribe_url}', 'anar-toolkit' ), 'template' => '', 'params' => '', 'discount_type' => 'none', 'discount_amount' => '0', 'discount_hours' => 72, 'static_coupon' => '' );
		}
		return array( 'modules' => array_fill_keys( array_keys( self::modules() ), 0 ), 'provider' => '', 'daily_limit' => 3, 'gap_hours' => 24, 'vip_amount' => '10000000', 'minimum_orders' => 1, 'minimum_spent' => '0', 'maximum_spent' => '0', 'products' => '', 'exclude_products' => '', 'categories' => '', 'prediction_mode' => 'hybrid', 'prediction_minimum' => 3, 'prediction_confidence' => 60, 'retention' => 90, 'survey_reviews' => 0, 'store_name' => '', 'store_address' => '', 'store_tax_id' => '', 'invoice_prefix' => 'INV-', 'games_limit' => 1, 'game_reward_amount' => '5', 'game_reward_type' => 'percent', 'game_reward_chance' => 25, 'game_quiz_question' => '', 'game_quiz_options' => '', 'game_quiz_answer' => 1, 'game_milestone_orders' => 3, 'referral_wait_days' => 7, 'referral_amount' => '5', 'referral_minimum' => '0', 'rules' => $rules );
	}

	public function all() {
		$saved = get_option( self::OPTION, array() );
		if ( null !== $this->resolved && $saved === $this->source ) {
			return $this->resolved; }
		$this->source = $saved;
		$defaults = $this->defaults();
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array( 'modules', 'rules' ) as $section ) {
			foreach ( $defaults[ $section ] as $key => $value ) {
				$defaults[ $section ][ $key ] = is_array( $value ) ? wp_parse_args( (array) ( $saved[ $section ][ $key ] ?? array() ), $value ) : (int) ( $saved[ $section ][ $key ] ?? $value );
			}
			unset( $saved[ $section ] );
		}
		$this->resolved = wp_parse_args( $saved, $defaults );
		return $this->resolved;
	}

	public function enabled( $module = '' ) {
		$modules = $this->all()['modules'];
		return $module ? ! empty( $modules[ $module ] ) : (bool) array_filter( $modules );
	}

	public function sanitize( $raw ) {
		if ( is_array( $raw ) && ! empty( $raw['_partial'] ) ) {
			unset( $raw['_partial'] );
			$raw = array_replace_recursive( $this->all(), $raw ); }
		$raw = is_array( $raw ) ? $raw : array();
		$out = $this->defaults();
		foreach ( $out['modules'] as $key => &$value ) {
			$value = empty( $raw['modules'][ $key ] ) ? 0 : 1;
		} unset( $value );
		foreach ( array( 'daily_limit' => array( 1, 10 ), 'gap_hours' => array( 1, 168 ), 'minimum_orders' => array( 0, 1000 ), 'prediction_minimum' => array( 3, 20 ), 'prediction_confidence' => array( 10, 100 ), 'retention' => array( 7, 365 ), 'games_limit' => array( 1, 5 ), 'game_reward_chance' => array( 0, 100 ), 'game_quiz_answer' => array( 1, 8 ), 'game_milestone_orders' => array( 1, 100 ), 'referral_wait_days' => array( 1, 90 ) ) as $key => $bounds ) {
			$out[ $key ] = max( $bounds[0], min( $bounds[1], (int) ( $raw[ $key ] ?? $out[ $key ] ) ) );
		}
		foreach ( array( 'vip_amount', 'minimum_spent', 'maximum_spent', 'game_reward_amount', 'referral_amount', 'referral_minimum' ) as $key ) {
			$out[ $key ] = (string) round( max( 0, min( 1000000000, (float) ( $raw[ $key ] ?? $out[ $key ] ) ) ), 2 ); }
		foreach ( array( 'products', 'exclude_products', 'categories' ) as $key ) {
			$out[ $key ] = implode( ',', array_slice( wp_parse_id_list( $raw[ $key ] ?? '' ), 0, 100 ) ); }
		foreach ( array( 'provider', 'store_name', 'store_address', 'store_tax_id', 'invoice_prefix', 'game_quiz_question', 'game_quiz_options' ) as $key ) {
			$out[ $key ] = sanitize_textarea_field( is_scalar( $raw[ $key ] ?? '' ) ? $raw[ $key ] ?? '' : '' ); }
		$out['provider'] = sanitize_key( $out['provider'] );
		$out['prediction_mode'] = in_array( $raw['prediction_mode'] ?? '', array( 'personal', 'product', 'hybrid' ), true ) ? $raw['prediction_mode'] : 'hybrid';
		$out['game_reward_type'] = 'fixed_cart' === ( $raw['game_reward_type'] ?? '' ) ? 'fixed_cart' : 'percent';
		if ( 'percent' === $out['game_reward_type'] ) {
			$out['game_reward_amount'] = (string) min( 100, (float) $out['game_reward_amount'] ); }
		$out['referral_amount'] = (string) min( 100, (float) $out['referral_amount'] );
		$out['survey_reviews'] = empty( $raw['survey_reviews'] ) ? 0 : 1;
		foreach ( $out['rules'] as $key => &$rule ) {
			$input = isset( $raw['rules'][ $key ] ) && is_array( $raw['rules'][ $key ] ) ? $raw['rules'][ $key ] : array();
			$rule['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
			foreach ( array( 'message', 'template', 'params', 'static_coupon' ) as $field ) {
				$rule[ $field ] = sanitize_textarea_field( is_scalar( $input[ $field ] ?? '' ) ? $input[ $field ] ?? '' : '' ); }
			$rule['days'] = max( -30, min( 365, (int) ( $input['days'] ?? $rule['days'] ) ) );
			$rule['discount_type'] = in_array( $input['discount_type'] ?? '', array( 'none', 'percent', 'fixed_cart', 'static' ), true ) ? $input['discount_type'] : 'none';
			$rule['discount_amount'] = (string) max( 0, min( 'percent' === $rule['discount_type'] ? 100 : 1000000000, (float) ( $input['discount_amount'] ?? 0 ) ) );
			$rule['discount_hours'] = max( 1, min( 720, (int) ( $input['discount_hours'] ?? 72 ) ) );
		} unset( $rule );
		return $out;
	}
}
