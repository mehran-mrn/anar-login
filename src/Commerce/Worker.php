<?php
/**
 * Bounded background processing with consent and order-state revalidation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Commerce;

use Anar\Login\Messaging\NotificationSender;

defined( 'ABSPATH' ) || exit;

final class Worker {

	const HOOK = 'anar_toolkit_process_queue';
	private $settings;
	private $repository;
	private $module;
	private $sender;

	public function __construct( Settings $settings, Repository $repository, Module $module, NotificationSender $sender ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->module     = $module;
		$this->sender     = $sender;
	}

	public function register() {
		add_action( self::HOOK, array( $this, 'run' ) );
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Bounded opt-in queue; minute-level reminders need this precision.
		add_filter( 'cron_schedules', array( $this, 'interval' ) );
		// Reconcile schedules on administrative/cron requests and option changes, not storefront views.
		if ( is_admin() || wp_doing_cron() ) {
			add_action( 'init', array( $this, 'schedule' ), 20 ); }
	}

	public function interval( $schedules ) {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Bounded opt-in queue; minute-level reminders need this precision.
		$schedules['anar_toolkit_minute'] = array( 'interval' => 60, 'display' => __( 'Every minute (Anar Toolkit)', 'anar-toolkit' ) );
		return $schedules;
	}

	public function schedule() {
		if ( ! $this->settings->enabled() || ! class_exists( 'WooCommerce' ) ) {
			self::unschedule();
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
			if ( ! as_has_scheduled_action( self::HOOK, array(), 'anar-toolkit' ) ) {
				as_schedule_recurring_action( time() + 60, 60, self::HOOK, array(), 'anar-toolkit', true );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'anar_toolkit_minute', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), 'anar-toolkit' );
		}
	}

	public function run() {
		if ( ! class_exists( 'WooCommerce' ) || ! $this->settings->enabled() ) {
			return;
		}
		$lease = $this->repository->lock();
		if ( ! $lease ) {
			return;
		}
		try {
			$this->repository->expire_running();
			$this->scan();
			do_action( 'anar_toolkit_scan_campaigns' );
			$start = time();
			foreach ( $this->repository->due() as $job ) {
				if ( time() - $start > 40 ) {
					break;
				}
				if ( $this->repository->claim( $job->id ) ) {
					$this->process( $job );
				}
			}
		} finally {
			$this->repository->unlock( $lease );
		}
	}

	private function scan() {
		if ( ! $this->settings->get( 'recovery' ) ) {
			return;
		}
		$config = $this->settings->all();
		foreach ( $this->repository->active_carts() as $cart ) {
			if ( ! $this->module->valid_cart( $cart ) ) {
				$this->repository->set_cart_status( $cart->id, 'expired' );
				continue;
			}
			$contact = $this->repository->contact( $cart->contact_id );
			foreach ( array( 'recovery_1', 'recovery_2', 'recovery_3' ) as $event ) {
				$minutes = (int) $config['events'][ $event ]['minutes'];
				if ( $minutes > 0 && $cart->updated_at + $minutes * 60 <= time() ) {
					$this->repository->enqueue( $event, $cart->id, $contact->phone, $this->settings->allowed_time( time() ) );
				}
			}
		}
	}

	private function process( $job ) {
		if ( 0 === strpos( $job->event, 'mkt_' ) ) {
			if ( ! has_action( 'anar_toolkit_marketing_job' ) ) {
				$this->repository->finish( $job->id, 'cancelled', 'module_disabled' );
				return; }
			do_action( 'anar_toolkit_marketing_job', $job );
			return; }
		$config   = $this->settings->all();
		$recovery = 0 === strpos( $job->event, 'recovery_' );
		$requested = 'recovery_request' === $job->event;
		$enabled  = $recovery ? $config['recovery'] : ( $config[ $job->event ] ?? false );
		$event    = $config['events'][ $requested ? 'recovery_1' : $job->event ] ?? null;
		if ( ! $enabled || ! $event ) {
			$this->repository->finish( $job->id, 'cancelled', 'module_disabled' );
			return;
		}
		if ( $recovery ) {
			$cart    = $this->repository->cart( $job->object_id );
			$contact = $cart ? $this->repository->contact( $cart->contact_id ) : null;
			if ( ! $this->module->valid_cart( $cart ) || ! $contact || $contact->phone !== $job->recipient || ( ! $requested && empty( $event['minutes'] ) ) ) {
				$this->repository->finish( $job->id, 'cancelled', 'recovery_no_longer_eligible' );
				return;
			}
			$due = $requested ? time() : $this->settings->allowed_time( max( time(), $cart->updated_at + $event['minutes'] * 60 ) );
			if ( $due > time() || ( ! $requested && $this->repository->recent_count( $job->recipient ) > $config['daily_limit'] ) ) {
				$this->repository->postpone( $job->id, max( $due, time() + HOUR_IN_SECONDS ) );
				return;
			}
			$order     = $cart->order_id ? wc_get_order( $cart->order_id ) : null;
			$variables = $this->module->variables( $order );
			$variables['total']           = $order ? $variables['total'] : wc_format_decimal( $cart->total, wc_get_price_decimals() );
			$variables['currency']        = $order ? $variables['currency'] : $cart->currency;
			$code = $this->module->issue_code( $cart );
			if ( is_wp_error( $code ) ) {
				$this->repository->finish( $job->id, 'failed', $code->get_error_code() );
				return;
			}
			$stage = $requested ? 1 : (int) substr( $job->event, -1 );
			$discount = ( new Coupons( $this->settings, $this->repository ) )->issue( $cart, $stage );
			if ( is_wp_error( $discount ) ) {
				$this->repository->finish( $job->id, 'failed', $discount->get_error_code() );
				return; }
			if ( $discount['coupon_code'] && false === strpos( 'custom' === $config['provider'] ? $event['message'] : $event['params'], '{coupon_code}' ) ) {
				$this->repository->finish( $job->id, 'failed', 'coupon_mapping_missing' );
				return; }
			$variables = array_merge( $variables, $discount );
			$variables['code']            = $code;
			$variables['minutes']         = (string) $config['code_minutes'];
			$variables['recovery_page']   = $this->module->recovery_page();
			$variables['unsubscribe_url'] = Module::link( $cart, 'unsubscribe' );
		} else {
			$order = wc_get_order( $job->object_id );
			if ( ! $order || ( in_array( $job->event, array( 'paid_customer', 'paid_admin' ), true ) && ( ! $order->is_paid() || ! $order->get_date_paid() || (float) $order->get_total() <= 0 ) ) ) {
				$this->repository->finish( $job->id, 'cancelled', 'order_not_paid' );
				return;
			}
			if ( 0 === strpos( $job->event, 'status_' ) && substr( $job->event, 7 ) !== $order->get_status() ) {
				$this->repository->finish( $job->id, 'cancelled', 'status_superseded' );
				return; }
			if ( 'failed_admin' === $job->event && 'failed' !== $order->get_status() ) {
				$this->repository->finish( $job->id, 'cancelled', 'status_superseded' );
				return; }
			$variables = array_merge( $this->module->variables( $order ), OrderEvents::tracking( $order ) );
			if ( 'shipment_customer' === $job->event && ( ! $variables['tracking_number'] || ! hash_equals( (string) $job->context, hash( 'sha256', $variables['tracking_number'] . '|' . $variables['carrier'] ) ) || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) ) {
				$this->repository->finish( $job->id, 'cancelled', 'shipment_superseded' );
				return; }
		}
		global $wpdb;
		$wpdb->update( $this->repository->table( 'messages' ), array( 'provider' => $config['provider'] ), array( 'id' => $job->id ) );
		$result = $this->sender->send( $config['provider'], $job->recipient, $event, $variables, $recovery );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			// A network failure or server error may happen after the provider accepted a message.
			$uncertain = in_array( $code, array( 'anar_sms_network', 'anar_sms_http' ), true );
			$this->repository->finish( $job->id, $uncertain ? 'uncertain' : 'failed', $code );
		} else {
			$this->repository->finish( $job->id, 'accepted' );
		}
	}
}
