<?php
/**
 * Short, keyboard-accessible contextual instructions. No external help widget.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

defined( 'ABSPATH' ) || exit;

final class Help {
	public static function text( $key ) {
		$guides = array(
			'two_factor' => __( 'Select which accounts require a code after password sign-in. Confirm SMS or email delivery before enabling this option. This protection can remain active independently of the Anar login form.', 'anar-toolkit' ),
			'login' => __( 'Select SMS, email or Google in connection settings. Configure the selected service, then add the account shortcode or enable the login popup. Test sign-in before replacing an existing login form.', 'anar-toolkit' ),
			'reset' => __( 'Only a mobile number already linked to an account can reset its password. Configure the SMS provider and place [anar_reset_password] on a page. The code expires after five minutes.', 'anar-toolkit' ),
			'login_log' => __( 'Records the outcome, provider and time of login/reset SMS requests. It does not store the code or full message. View results under Messages.', 'anar-toolkit' ),
			'recovery' => __( 'Configure the reminder templates and sending times first. Automatic mode saves the account or checkout number with the cart; no separate checkbox is required. Add a discount if needed, then test recovery with your own number.', 'anar-toolkit' ),
			'orders' => __( 'Select the required order events and configure a template for each one. Enter administrator numbers for store alerts. Shipment messages use the tracking details saved in the order.', 'anar-toolkit' ),
			'lifecycle' => __( 'Set the first-purchase, inactivity and VIP rules. Configure a template and optional gift for each enabled stage. Import previous orders if older customer history is needed.', 'anar-toolkit' ),
			'prediction' => __( 'Select a history model, minimum purchases and confidence threshold. Configure the before/on/after stages. Customers without sufficient repeat-purchase history receive no prediction.', 'anar-toolkit' ),
			'smart' => __( 'Select the eligible customers and products. Configure the message template and optional gift. The offer uses an available product outside the customer’s recent purchase history.', 'anar-toolkit' ),
			'bulk' => __( 'Import contacts or use existing customers. Enter the message, audience and schedule, then preview the recipient count. Sending begins only after you confirm that preview. Remaining messages can be paused or cancelled.', 'anar-toolkit' ),
			'stock' => __( 'The request form appears on out-of-stock product pages. The customer verifies their mobile number and selects a product or variation. One notification is queued when that item becomes available.', 'anar-toolkit' ),
			'survey' => __( 'Set the delay after order completion and configure a template containing {action_url}. Each paid order accepts one response. Optional product reviews require moderation; the gift is independent of the rating.', 'anar-toolkit' ),
			'next_discount' => __( 'Set the delay, discount and expiry after a paid order. Include {coupon_code} in the message mapping. Generated coupons are single-use and limited to the recipient billing phone.', 'anar-toolkit' ),
			'birthday' => __( 'Customers save their month and day in account details or [anar_customer_preferences]. Configure the greeting and optional gift. Persian dates require PHP Intl; no birth year is requested.', 'anar-toolkit' ),
			'invoice' => __( 'Enter the store details. Customers can print an invoice from My Account or use an expiring signed link. Enable the invoice message stage separately if its link should also be sent by SMS.', 'anar-toolkit' ),
			'games' => __( 'Set the reward, probability and daily limit. Place the shortcode for the required game on a page. Configure the quiz options before using the quiz. Customers must sign in with a verified mobile number.', 'anar-toolkit' ),
			'referral' => __( 'Set the reward and refund waiting period, then add [anar_referrals]. A customer receives a referral link after verified sign-in. An eligible first order must be completed before its reward is issued.', 'anar-toolkit' ),
			'traffic' => __( 'Counts page views locally and shows recorded sales and products. Checkout, account pages and administrator visits are excluded. Visitor days are daily browser counts, not a count of unique people.', 'anar-toolkit' ),
			'messages' => __( 'Accepted means the SMS provider accepted the request; it does not confirm delivery to the phone. Investigate uncertain results before retrying. Codes and full messages are not shown in the report.', 'anar-toolkit' ),
			'privacy' => __( 'Verify the requester’s identity before erasing records by phone. Local campaign records are removed; WooCommerce orders and the provider’s records remain separate. A minimal suppression hash remembers stopped messages.', 'anar-toolkit' ),
		);
		return $guides[ $key ] ?? '';
	}

	public static function button( $text ) {
		if ( ! $text ) {
			return; }
		?>
		<details class="anar-help"><summary aria-label="<?php esc_attr_e( 'Help', 'anar-toolkit' ); ?>"><span aria-hidden="true">i</span></summary><div role="note"><?php echo esc_html( $text ); ?></div></details>
		<?php
	}

	public static function section( $key ) {
		$text = self::text( $key );
		if ( ! $text ) {
			return; }
		?>
		<aside class="anar-section-help"><strong><?php esc_html_e( 'How to use this section', 'anar-toolkit' ); ?></strong><p><?php echo esc_html( $text ); ?></p></aside>
		<?php
	}
}
