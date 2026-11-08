<?php
/**
 * Bounded discovery and delivery of customer campaigns.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Commerce\Repository as CommerceRepository;
use Anar\Login\Commerce\Settings as CommerceSettings;
use Anar\Login\Messaging\HttpClient;
use Anar\Login\Messaging\NotificationSender;

defined( 'ABSPATH' ) || exit;

final class Engine {
	private $settings;
	private $repository;

	public function __construct( Settings $settings, Repository $repository ) {
		$this->settings = $settings;
		$this->repository = $repository; }

	public function register() {
		add_filter(
			'anar_toolkit_extra_modules_enabled',
			function () {
				return \Anar\Login\Infrastructure\Features::queue_needed();
			}
		);
		add_action( 'anar_toolkit_scan_campaigns', array( $this, 'scan' ) );
		add_action( 'anar_toolkit_marketing_job', array( $this, 'process' ) );
		if ( \Anar\Login\Infrastructure\Features::facts_needed() ) {
			add_action( 'anar_marketing_import_batch', array( $this, 'import' ) );
			add_action( 'woocommerce_payment_complete', array( $this, 'order' ), 30 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'order' ), 30 );
			add_action( 'woocommerce_order_refunded', array( $this, 'order' ), 30 );
		}
		if ( $this->settings->enabled( 'stock' ) ) {
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'restock' ), 20, 2 );
			add_action( 'woocommerce_variation_set_stock_status', array( $this, 'restock' ), 20, 2 );
		}
	}

	public static function module( $event ) {
		if ( 0 === strpos( $event, 'return_' ) || in_array( $event, array( 'welcome', 'vip' ), true ) ) {
			return 'lifecycle'; }
		return 0 === strpos( $event, 'prediction_' ) ? 'prediction' : $event;
	}

	public static function label( $event ) {
		$labels = array_merge( Settings::modules(), array( 'welcome' => __( 'First purchase', 'anar-toolkit' ), 'vip' => __( 'VIP threshold', 'anar-toolkit' ), 'return_1' => __( 'First inactivity reminder', 'anar-toolkit' ), 'return_2' => __( 'Second inactivity reminder', 'anar-toolkit' ), 'return_3' => __( 'Third inactivity reminder', 'anar-toolkit' ), 'prediction_before' => __( 'Before predicted purchase', 'anar-toolkit' ), 'prediction_on' => __( 'Predicted purchase day', 'anar-toolkit' ), 'prediction_after' => __( 'After predicted purchase', 'anar-toolkit' ) ) );
		return $labels[ $event ] ?? $event;
	}

	private function qualifies( $profile, $facts, $config ) {
		return $this->repository->allowed( $profile ) && $facts->orders >= $config['minimum_orders'] && (float) $facts->spent >= (float) $config['minimum_spent'] && ( (float) $config['maximum_spent'] <= 0 || (float) $facts->spent <= (float) $config['maximum_spent'] );
	}

	public static function segment_matches( $segment, $facts, $config ) {
		if ( 'vip' === $segment ) {
			return (float) $facts->spent >= (float) $config['vip_amount']; }
		if ( 'inactive' === $segment ) {
			return $facts->last_paid && $facts->last_paid < time() - 30 * DAY_IN_SECONDS; }
		return 'new' !== $segment || (int) $facts->orders <= 1;
	}

	public function product_allowed( $product, $config ) {
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false; }
		$ids = array( $product->get_id(), $product->get_parent_id() );
		if ( array_intersect( $ids, wp_parse_id_list( $config['exclude_products'] ) ) ) {
			return false; }
		if ( $config['products'] && ! array_intersect( $ids, wp_parse_id_list( $config['products'] ) ) ) {
			return false; }
		return ! $config['categories'] || has_term( wp_parse_id_list( $config['categories'] ), 'product_cat', $product->get_parent_id() ?: $product->get_id() );
	}

	public function queue( $event, $profile, $variables, $source, $bucket, $time = 0 ) {
		$config = $this->settings->all();
		if ( ! $this->settings->enabled( self::module( $event ) ) || empty( $config['rules'][ $event ]['enabled'] ) ) {
			return false; }
		return $this->repository->enqueue( $event, $profile, $variables, $source, $bucket, $time );
	}

	public function order( $order ) {
		global $wpdb;
		if ( ! $this->settings->enabled() ) {
			return; }
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );
		if ( $order ) {
			$wpdb->update( $this->repository->table( 'jobs' ), array( 'converted_total' => $order->is_paid() ? max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() ) : 0 ), array( 'converted_order' => $order->get_id() ) );
		}
		$id = $this->repository->order( $order );
		if ( ! $id || ! $order->is_paid() ) {
			return; }
		$profile = $this->repository->profile( $id );
		$config = $this->settings->all();
		$facts = $this->repository->facts( $id );
		if ( ! $this->qualifies( $profile, $facts, $config ) ) {
			return; }
		$variables = array( 'order_id' => $order->get_order_number(), 'order_source' => $order->get_id() );
		if ( 1 === (int) $facts->orders ) {
			$this->queue( 'welcome', $profile, $variables, $order->get_id(), 'first-purchase', $order->get_date_paid()->getTimestamp() + max( 0, $config['rules']['welcome']['days'] ) * DAY_IN_SECONDS ); }
		if ( (float) $facts->spent >= (float) $config['vip_amount'] ) {
			$this->queue( 'vip', $profile, $variables, $order->get_id(), 'vip-entry' ); }
		foreach ( array( 'survey', 'next_discount', 'invoice' ) as $event ) {
			$date = 'survey' === $event ? $order->get_date_completed() : $order->get_date_paid();
			if ( $date ) {
				$this->queue( $event, $profile, $variables, $order->get_id(), (string) $order->get_id(), $date->getTimestamp() + max( 0, $config['rules'][ $event ]['days'] ) * DAY_IN_SECONDS ); }
		}
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new \WC_Coupon( $code );
			$origin = (string) $coupon->get_meta( '_anar_marketing_origin' );
			if ( 0 === strpos( $origin, 'job:' ) && (int) $coupon->get_meta( '_anar_marketing_profile' ) === $id ) {
				$wpdb->update( $this->repository->table( 'jobs' ), array( 'converted_order' => $order->get_id(), 'converted_total' => max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() ), 'converted_currency' => $order->get_currency() ), array( 'id' => (int) substr( $origin, 4 ), 'converted_order' => 0 ) );
				break;
			}
		}
		do_action( 'anar_marketing_order_recorded', $order, $profile );
	}

	public function scan() {
		global $wpdb;
		if ( ! $this->settings->enabled() ) {
			return; }
		$config = $this->settings->all();
		if ( array_intersect( array( 'lifecycle', 'prediction', 'smart', 'birthday' ), array_keys( array_filter( $config['modules'] ) ) ) ) {
			$cursor = (int) get_option( 'anar_marketing_profile_cursor', 0 );
			$profiles = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id LIMIT 10', $this->repository->table( 'profiles' ), $cursor ) );
			$start = time();
			foreach ( $profiles as $profile ) {
				$facts = $this->repository->facts( $profile->id );
				if ( $this->qualifies( $profile, $facts, $config ) ) {
					$this->discover( $profile, $facts, $config );
				}
				$cursor = $profile->id;
				if ( time() - $start > 15 ) {
					break; }
			}
			update_option( 'anar_marketing_profile_cursor', count( $profiles ) < 10 ? 0 : $cursor, false );
		}
		$this->bulk();
		if ( $this->settings->enabled( 'stock' ) ) {
			$stock_products = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT product_id FROM %i WHERE state = 'waiting' LIMIT 10", $this->repository->table( 'stock' ) ) );
			foreach ( $stock_products as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product && $product->is_in_stock() ) {
					$this->restock( $product_id, 'instock' ); }
			}
		}
		do_action( 'anar_marketing_discover' );
		$this->import();
	}

	private function discover( $profile, $facts, $config ) {
		global $wpdb;
		$inactive = ( time() - (int) $facts->last_paid ) / DAY_IN_SECONDS;
		foreach ( array( 'return_3', 'return_2', 'return_1' ) as $event ) {
			if ( $facts->last_paid && $config['rules'][ $event ]['enabled'] && $inactive >= max( 1, $config['rules'][ $event ]['days'] ) ) {
				$this->queue( $event, $profile, array(), $facts->last_order, (string) $facts->last_order );
				break; }
		}
		if ( $this->settings->enabled( 'birthday' ) && $profile->birthday && Calendar::month_day( $profile->calendar ) === $profile->birthday ) {
			$this->queue( 'birthday', $profile, array(), 0, wp_date( 'Y' ) ); }
		if ( ! $this->settings->enabled( 'prediction' ) && ! $this->settings->enabled( 'smart' ) ) {
			return; }
		$products = $wpdb->get_col( $wpdb->prepare( 'SELECT product_id FROM %i WHERE profile_id = %d GROUP BY product_id ORDER BY MAX(paid_at) DESC LIMIT 10', $this->repository->table( 'purchases' ), $profile->id ) );
		foreach ( $products as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $this->product_allowed( $product, $config ) ) {
				continue; }
			$prediction = $this->settings->enabled( 'prediction' ) ? ( new Prediction() )->for_product( $profile->id, $product_id, $config ) : null;
			if ( $prediction ) {
				foreach ( array( 'prediction_before', 'prediction_on', 'prediction_after' ) as $event ) {
					$when = $prediction['timestamp'] + $config['rules'][ $event ]['days'] * DAY_IN_SECONDS;
					if ( $when <= time() && $when >= time() - 30 * DAY_IN_SECONDS ) {
						$this->queue( $event, $profile, array( 'product_id' => $product_id, 'prediction_date' => wp_date( 'Y-m-d', $prediction['timestamp'] ), 'confidence' => $prediction['confidence'], 'last_product_purchase' => $prediction['last_paid'] ), $product_id, $product_id . ':' . $prediction['last_paid'] ); }
				}
			}
		}
		if ( $this->settings->enabled( 'smart' ) && $inactive >= max( 1, $config['rules']['smart']['days'] ) ) {
			$candidates = wc_get_products( array( 'limit' => 12, 'status' => 'publish', 'stock_status' => 'instock', 'orderby' => 'date', 'order' => 'DESC', 'exclude' => array_map( 'intval', $products ) ) );
			foreach ( $candidates as $product ) {
				if ( $this->product_allowed( $product, $config ) ) {
					$this->queue( 'smart', $profile, array( 'product_id' => $product->get_id() ), $product->get_id(), $facts->last_order . ':' . floor( time() / ( 30 * DAY_IN_SECONDS ) ) );
					break; }
			}
		}
	}

	public function restock( $id, $status ) {
		global $wpdb;
		if ( 'instock' !== $status || ! $this->settings->enabled( 'stock' ) ) {
			return; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE product_id = %d AND state = 'waiting' ORDER BY id LIMIT 100", $this->repository->table( 'stock' ), $id ) );
		foreach ( $rows as $row ) {
			if ( $this->queue( 'stock', $this->repository->profile( $row->profile_id ), array( 'product_id' => $id, 'subscription_id' => $row->id ), $id, (string) $row->id . ':' . $row->created_at ) ) {
				$wpdb->update( $this->repository->table( 'stock' ), array( 'state' => 'queued', 'updated_at' => time() ), array( 'id' => $row->id, 'state' => 'waiting' ) ); }
		}
	}

	public function import() {
		if ( ! get_option( 'anar_marketing_import_active' ) ) {
			return; }
		global $wpdb;
		$lock = 'anar_marketing_import_lock';
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $lock, time() - 300 ) );
		wp_cache_delete( $lock, 'options' );
		if ( ! add_option( $lock, time(), '', false ) ) {
			return; }
		try {
			$page = max( 1, (int) get_option( 'anar_marketing_import_page', 1 ) );
			$orders = wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => 25, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC' ) );
			foreach ( $orders as $order ) {
				$this->repository->order( $order ); }
			update_option( 'anar_marketing_import_page', $page + 1, false );
			if ( count( $orders ) < 25 ) {
				update_option( 'anar_marketing_import_active', 0, false ); } elseif ( ! wp_next_scheduled( 'anar_marketing_import_batch' ) ) {
				wp_schedule_single_event( time() + 30, 'anar_marketing_import_batch' ); }
		} finally {
			delete_option( $lock ); }
	}

	private function bulk() {
		global $wpdb;
		if ( ! $this->settings->enabled( 'bulk' ) ) {
			return; }
		$campaign = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE state = 'sending' AND scheduled_at <= %d ORDER BY id LIMIT 1", $this->repository->table( 'bulk' ), time() ) );
		if ( ! $campaign || ! $this->settings->enabled( 'bulk' ) ) {
			return; }
		$filters = json_decode( $campaign->filters, true );
		$config = $this->settings->all();
		$ids = array_slice( array_map( 'absint', (array) ( $filters['ids'] ?? array() ) ), (int) $campaign->cursor_id, 50 );
		$profiles = array();
		foreach ( $ids as $id ) {
			$profile = $this->repository->profile( $id );
			if ( $profile ) {
				$profiles[] = $profile; }
		}
		foreach ( $profiles as $profile ) {
			$facts = $this->repository->facts( $profile->id );
			$eligible = $this->repository->allowed( $profile );
			if ( 'vip' === ( $filters['segment'] ?? '' ) ) {
				$eligible = $eligible && (float) $facts->spent >= (float) $config['vip_amount']; }
			if ( 'inactive' === ( $filters['segment'] ?? '' ) ) {
				$eligible = $eligible && $facts->last_paid && $facts->last_paid < time() - 30 * DAY_IN_SECONDS; }
			if ( 'new' === ( $filters['segment'] ?? '' ) ) {
				$eligible = $eligible && (int) $facts->orders <= 1; }
			if ( $eligible ) {
				$this->queue( 'bulk', $profile, array( 'bulk_id' => $campaign->id, 'message' => $filters['message'] ?? '' ), $campaign->id, (string) $campaign->id ); }
		}
		$wpdb->update( $this->repository->table( 'bulk' ), array( 'cursor_id' => (int) $campaign->cursor_id + count( $ids ), 'state' => count( $ids ) < 50 ? 'queued' : 'sending' ), array( 'id' => $campaign->id ) );
	}

	public function process( $job ) {
		global $wpdb;
		$queue = new CommerceRepository();
		$config = $this->settings->all();
		$task = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->repository->table( 'jobs' ), $job->object_id ) );
		$profile = $task ? $this->repository->profile( $task->profile_id ) : null;
		$rule = $task ? ( $config['rules'][ $task->event ] ?? null ) : null;
		if ( ! $task || ! $rule || ! $rule['enabled'] || ! $this->settings->enabled( self::module( $task->event ) ) || ! $this->repository->allowed( $profile ) || $task->expires_at < time() || $profile->phone !== $job->recipient ) {
			$queue->finish( $job->id, 'cancelled', 'campaign_no_longer_eligible' );
			return; }
		$vars = json_decode( $task->variables, true );
		$vars = is_array( $vars ) ? $vars : array();
		if ( 'bulk' === $task->event ) {
			$campaign = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->repository->table( 'bulk' ), $task->source_id ) );
			$state = $campaign ? $campaign->state : '';
			if ( 'paused' === $state ) {
				$queue->postpone( $job->id, time() + 900 );
				return; }
			if ( ! in_array( $state, array( 'sending', 'queued' ), true ) ) {
				$queue->finish( $job->id, 'cancelled', 'campaign_cancelled' );
				return; }
			$filters = json_decode( $campaign->filters, true );
			if ( ! self::segment_matches( $filters['segment'] ?? 'all', $this->repository->facts( $profile->id ), $config ) ) {
				$queue->finish( $job->id, 'cancelled', 'audience_changed' );
				return;
			}
		}
		$facts = in_array( $task->event, array( 'bulk', 'stock' ), true ) ? null : $this->repository->facts( $profile->id );
		if ( $facts && ! $this->qualifies( $profile, $facts, $config ) ) {
			$queue->finish( $job->id, 'cancelled', 'audience_changed' );
			return;
		}
		if ( 'birthday' === $task->event && Calendar::month_day( $profile->calendar ) !== $profile->birthday ) {
			$queue->finish( $job->id, 'cancelled', 'birthday_passed' );
			return;
		}
		if ( 'stock' === $task->event ) {
			$subscription = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->repository->table( 'stock' ), $vars['subscription_id'] ?? 0 ) );
			if ( ! $subscription || 'queued' !== $subscription->state || (int) $subscription->profile_id !== (int) $profile->id || (int) $subscription->product_id !== (int) $task->source_id ) {
				$queue->finish( $job->id, 'cancelled', 'subscription_changed' );
				return;
			}
		}
		if ( 0 === strpos( $task->event, 'return_' ) && ( (int) $facts->last_order !== (int) $task->source_id || $facts->last_paid + max( 1, $rule['days'] ) * DAY_IN_SECONDS > time() ) ) {
			$queue->finish( $job->id, 'cancelled', 'new_purchase' );
			return; }
		if ( isset( $vars['order_source'] ) ) {
			$order = wc_get_order( (int) $vars['order_source'] );
			if ( ! $order || ! $order->is_paid() || $order->get_total_refunded() > 0 ) {
				$queue->finish( $job->id, 'cancelled', 'order_no_longer_paid' );
				return; }
		}
		$product = isset( $vars['product_id'] ) ? wc_get_product( $vars['product_id'] ) : null;
		if ( isset( $vars['product_id'] ) && ! $this->product_allowed( $product, $config ) ) {
			$queue->finish( $job->id, 'cancelled', 'product_unavailable' );
			return; }
		if ( isset( $vars['last_product_purchase'] ) ) {
			$last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(paid_at) FROM %i WHERE profile_id = %d AND product_id = %d', $this->repository->table( 'purchases' ), $profile->id, $vars['product_id'] ) );
			if ( $last !== (int) $vars['last_product_purchase'] ) {
				$queue->finish( $job->id, 'cancelled', 'new_purchase' );
				return; }
		}
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE recipient = %s AND event LIKE %s AND status IN ('accepted','uncertain','running') AND id <> %d AND updated_at > %d", $queue->table( 'messages' ), $profile->phone, $wpdb->esc_like( 'mkt_' ) . '%', $job->id, time() - DAY_IN_SECONDS ) );
		$last = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(updated_at) FROM %i WHERE recipient = %s AND event LIKE %s AND status IN ('accepted','uncertain')", $queue->table( 'messages' ), $profile->phone, $wpdb->esc_like( 'mkt_' ) . '%' ) );
		$when = ( new CommerceSettings() )->allowed_time( max( time(), $last + $config['gap_hours'] * HOUR_IN_SECONDS ) );
		if ( $recent >= $config['daily_limit'] || $when > time() ) {
			$queue->postpone( $job->id, max( time() + HOUR_IN_SECONDS, $when ) );
			return; }
		$rule = \Anar\Login\Messaging\MessageSettings::apply( 'mkt_' . $task->event, $rule );
		$provider = $rule['provider'];
		$mapping = 'text' === $rule['mode'] ? $rule['message'] : $rule['params'];
		if ( false === strpos( $mapping, '{unsubscribe_url}' ) || ( 'survey' === $task->event && false === strpos( $mapping, '{action_url}' ) ) || ( 'survey' !== $task->event && 'none' !== $rule['discount_type'] && false === strpos( $mapping, '{coupon_code}' ) ) ) {
			$queue->finish( $job->id, 'failed', 'campaign_mapping_incomplete' );
			return; }
		$defaults = array( 'site' => get_bloginfo( 'name' ), 'name' => $profile->name, 'order_id' => '', 'message' => self::description( $task->event ), 'product_name' => $product ? $product->get_name() : '', 'product_url' => $product ? $product->get_permalink() : '', 'coupon_code' => '', 'action_url' => '', 'invoice_url' => '', 'unsubscribe_url' => Frontend::link( 'stop', $profile->id ), 'prediction_date' => '' );
		$vars = array_merge( $defaults, $vars );
		if ( 'invoice' === $task->event && ! empty( $vars['order_source'] ) && wc_get_order( $vars['order_source'] ) ) {
			$vars['action_url'] = Invoices::url( wc_get_order( $vars['order_source'] ) );
			$vars['invoice_url'] = $vars['action_url']; }
		if ( 'survey' === $task->event ) {
			$vars['action_url'] = Frontend::link( 'survey', $task->source_id ); }
		if ( 'survey' !== $task->event ) {
			$vars['coupon_code'] = ( new Rewards() )->coupon( $profile, $rule, 'job:' . $task->id ); }
		if ( 'survey' !== $task->event && 'none' !== $rule['discount_type'] && ! $vars['coupon_code'] ) {
			$queue->finish( $job->id, 'failed', 'campaign_gift_unavailable' );
			return;
		}
		$rendered = 'text' === $rule['mode'] ? \Anar\Login\Commerce\Templates::render( $rule['message'], $vars ) : implode( "\n", \Anar\Login\Commerce\Templates::parameters( $rule['params'], $vars ) );
		if ( false === strpos( $rendered, $vars['unsubscribe_url'] ) || ( 'survey' === $task->event && false === strpos( $rendered, $vars['action_url'] ) ) || ( 'survey' !== $task->event && 'none' !== $rule['discount_type'] && false === strpos( $rendered, $vars['coupon_code'] ) ) ) {
			$queue->finish( $job->id, 'failed', 'campaign_mapping_incomplete' );
			return;
		}
		$wpdb->update( $queue->table( 'messages' ), array( 'provider' => $provider ), array( 'id' => $job->id ) );
		$result = ( new NotificationSender( new \Anar\Login\Infrastructure\Settings(), new HttpClient() ) )->send( $provider, $profile->phone, $rule, $vars );
		if ( is_wp_error( $result ) ) {
			$queue->finish( $job->id, in_array( $result->get_error_code(), array( 'anar_sms_network', 'anar_sms_http' ), true ) ? 'uncertain' : 'failed', $result->get_error_code() ); } else {
			$queue->finish( $job->id, 'accepted' );
			if ( 'stock' === $task->event && ! empty( $vars['subscription_id'] ) ) {
				$wpdb->update( $this->repository->table( 'stock' ), array( 'state' => 'notified', 'updated_at' => time() ), array( 'id' => (int) $vars['subscription_id'] ) ); }
			}
	}

	public static function description( $event ) {
		$messages = array( 'welcome' => __( 'Thank you for your first purchase.', 'anar-toolkit' ), 'vip' => __( 'Thank you for being one of our valued regular customers.', 'anar-toolkit' ), 'birthday' => __( 'Happy birthday! Here is a gift from our store.', 'anar-toolkit' ), 'stock' => __( 'The product you requested is back in stock.', 'anar-toolkit' ), 'survey' => __( 'Please share your purchase feedback.', 'anar-toolkit' ), 'next_discount' => __( 'Your next-purchase gift is ready.', 'anar-toolkit' ), 'smart' => __( 'A new recommendation for your next visit.', 'anar-toolkit' ) );
		return $messages[ $event ] ?? ( 0 === strpos( $event, 'prediction_' ) ? __( 'It may be time to reorder your product.', 'anar-toolkit' ) : __( 'We would be happy to see you again.', 'anar-toolkit' ) );
	}

	public function cleanup() {
		global $wpdb;
		$cutoff = time() - $this->settings->all()['retention'] * DAY_IN_SECONDS;
		foreach ( array( 'jobs', 'feedback', 'plays', 'visits' ) as $name ) {
			$column = 'visits' === $name ? 'updated_at' : 'created_at';
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i < %d', $this->repository->table( $name ), $column, $cutoff ) ); }
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %d', $this->repository->table( 'stock' ), $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', $this->repository->table( 'referrals' ), $cutoff ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', $this->repository->table( 'bulk' ), $cutoff ) );
		$expired = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE updated_at < %d ORDER BY id LIMIT 25', $this->repository->table( 'profiles' ), $cutoff ) );
		foreach ( $expired as $profile ) {
			( new Privacy() )->erase_profile( $profile ); }
		// Remove abandoned creation/launch locks after their corresponding request has ended.
		foreach ( array( 'anar_reward_', 'anar_bulk_launch_' ) as $prefix ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $wpdb->esc_like( $prefix ) . '%', time() - DAY_IN_SECONDS ) );
		}
	}
}
