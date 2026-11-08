<?php
/**
 * Campaign setup, CRM, reports and a preview-first bulk workflow.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Marketing;

use Anar\Login\Commerce\Dashboard;
use Anar\Login\Commerce\Repository as CommerceRepository;
use Anar\Login\Support\Identity;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public function register() {
		add_action(
			'admin_init',
			function () {
				register_setting( 'anar_marketing', Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( new Settings(), 'sanitize' ), 'default' => ( new Settings() )->defaults() ) );
			}
		);
		add_action( 'anar_toolkit_campaign_workspace', array( $this, 'render' ) );
		add_action( 'admin_post_anar_bulk_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_anar_bulk_launch', array( $this, 'launch' ) );
		add_action( 'admin_post_anar_bulk_state', array( $this, 'state' ) );
		add_action( 'admin_post_anar_marketing_import', array( $this, 'import' ) );
		add_action( 'admin_post_anar_marketing_export', array( $this, 'export' ) );
		add_action( 'admin_post_anar_marketing_contacts', array( $this, 'contacts' ) );
	}

	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage these campaigns.', 'anar-toolkit' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	private function redirect() {
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=anar-toolkit-bulk' ) );
		exit; }

	private function field( $key, $label, $value, $type = 'text', $help = '', $popup_only = false ) {
		if ( ! $help && false !== strpos( $key, '][params' ) ) {
			$help = __( 'Use the exact variable names from the approved provider template, one name={placeholder} per line. Include {unsubscribe_url}; surveys also require {action_url}.', 'anar-toolkit' ); }
		if ( ! $help && false !== strpos( $key, '][template' ) ) {
			$help = __( 'Enter the ID or slug of the template approved in your SMS panel. A template must match the purpose of this message.', 'anar-toolkit' ); }
		$id = 'anar-mkt-' . sanitize_key( str_replace( array( '[', ']' ), '-', $key ) );
		?>
		<div class="anar-commerce-field"><label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><?php \Anar\Login\Admin\Help::button( $help ); ?><br>
		<?php
		if ( 'textarea' === $type ) :
			?>
			<textarea class="large-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
			<?php
else :
	?>
			<input id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo 'number' === $type ? 'step="any"' : ''; ?>><?php endif; ?>
			<?php
			if ( $help && ! $popup_only ) :
				?>
	<br><small class="description"><?php echo esc_html( $help ); ?></small><?php endif; ?></div>
		<?php
	}

	private function rules( $module, $config ) {
		return array_filter(
			$config['rules'],
			static function ( $key ) use ( $module ) {
				return Engine::module( $key ) === $module;
			},
			ARRAY_FILTER_USE_KEY
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return; }
		$config = ( new Settings() )->all();
		$active = array_keys( array_filter( $config['modules'] ) );
		$module = \Anar\Login\Infrastructure\Features::current_module() ?: ( $active[0] ?? '' );
		if ( ! $module ) {
			return; }
		?>
		<section class="anar-suite-panel" id="campaigns"><div class="anar-suite-toolbar"><h2><?php echo esc_html( Settings::modules()[ $module ] ); ?></h2><a href="<?php echo esc_url( admin_url( 'admin.php?page=anar-features-other' ) ); ?>"><?php esc_html_e( 'Other features', 'anar-toolkit' ); ?></a></div>
		<?php
		if ( 'games' === $module ) {
			\Anar\Login\Admin\Help::button( \Anar\Login\Admin\Help::text( $module ) );
		} else {
			\Anar\Login\Admin\Help::section( $module );
		}
		?>
		<details class="anar-commerce-card" open><summary><strong><?php esc_html_e( 'Settings', 'anar-toolkit' ); ?></strong></summary><form method="post" action="options.php"><?php settings_fields( 'anar_marketing' ); ?><input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[_partial]" value="1">
		<?php
		$this->module_settings( $module, $config );
		submit_button( __( 'Save campaign settings', 'anar-toolkit' ) );
		?>
		</form></details>
		<?php
		if ( 'bulk' === $module ) {
			$this->bulk_forms(); }
		if ( ! in_array( $module, array( 'stock', 'invoice' ), true ) ) {
			$this->history_form(); }
		$this->module_report( $module, $config );
		?>
		</section>
		<?php
	}

	private function game_settings( $config ) {
		?>
		<?php \Anar\Login\Admin\MessageCenter::summary( 'game_phone', 'games' ); ?>
		<?php GameAdmin::render( $config ); ?>
		<h3><?php esc_html_e( 'Winning chance and single-reward fallback', 'anar-toolkit' ); ?></h3><div class="anar-game-settings-grid">
		<?php
		$this->field( 'games_limit', __( 'Daily attempts in shared mode', 'anar-toolkit' ), $config['games_limit'], 'number', __( 'Used only in shared mode. Separate limits are set inside each game below.', 'anar-toolkit' ), true );
		?>
		<div class="anar-commerce-field"><label for="anar-game-reward-type"><strong><?php esc_html_e( 'Game reward type', 'anar-toolkit' ); ?></strong></label><?php \Anar\Login\Admin\Help::button( __( 'These two fields apply only when no named prize rows are configured. A win issues one personal, single-use coupon valid for 48 hours.', 'anar-toolkit' ) ); ?><br><select id="anar-game-reward-type" name="<?php echo esc_attr( Settings::OPTION ); ?>[game_reward_type]"><option value="percent" <?php selected( $config['game_reward_type'], 'percent' ); ?>><?php esc_html_e( 'Percentage', 'anar-toolkit' ); ?></option><option value="fixed_cart" <?php selected( $config['game_reward_type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Fixed cart amount', 'anar-toolkit' ); ?></option></select></div>
		<?php
		$this->field( 'game_reward_amount', __( 'Coupon discount amount', 'anar-toolkit' ), $config['game_reward_amount'], 'number', __( 'For a percentage coupon, enter a value from 0 to 100. For a fixed coupon, enter the amount in the store currency. The gift name below changes its displayed label, not its discount value.', 'anar-toolkit' ), true );
		$this->field( 'game_reward_chance', __( 'Chance of receiving a coupon (%)', 'anar-toolkit' ), $config['game_reward_chance'], 'number', __( 'Enter 0 to 100. For example, 25 means a 25% chance on each eligible attempt. A correct quiz answer or completing the memory game is also required in those games. Purchase milestones always award the configured coupon.', 'anar-toolkit' ), true );
		?>
		</div>
		<h3><?php esc_html_e( 'Game display and text', 'anar-toolkit' ); ?><?php \Anar\Login\Admin\Help::button( __( 'Open a game to edit its text and copy its shortcode into a page or shortcode block. These panels do not switch games on or off. Leave a text field empty to use its default wording.', 'anar-toolkit' ) ); ?></h3>
		<?php
		foreach ( Games::types() as $type => $label ) {
			$texts = Games::texts( $type, $config );
			?>
			<details class="anar-game-settings"><summary><strong><?php echo esc_html( $label ); ?></strong></summary><p><code dir="ltr">[anar_game type="<?php echo esc_attr( $type ); ?>"]</code></p><div class="anar-game-settings-grid">
			<?php
			GameAdmin::game( $type, $config );
			$fields = array(
				'title' => array( __( 'Game title', 'anar-toolkit' ), __( 'The heading displayed above this game on your site.', 'anar-toolkit' ) ),
				'intro' => array( __( 'Text above the game', 'anar-toolkit' ), __( 'A short introduction or instruction displayed below the game title.', 'anar-toolkit' ) ),
				'reward_label' => array( __( 'Gift display name', 'anar-toolkit' ), __( 'Default label for a single reward. Named prizes use the display name in their pool row.', 'anar-toolkit' ) ),
				'win_message' => array( __( 'Message when a coupon is issued', 'anar-toolkit' ), __( 'Shown after the customer wins and the coupon has been created. The coupon code is displayed separately below this message.', 'anar-toolkit' ) ),
				'lose_message' => array( __( 'Message when no coupon is issued', 'anar-toolkit' ), __( 'Shown when the attempt ends without a coupon. For example: No gift this time. We look forward to seeing you again tomorrow.', 'anar-toolkit' ) ),
			);
			if ( in_array( $type, array( 'wheel', 'slots', 'daily', 'milestone' ), true ) ) {
				$fields['button_label'] = array( __( 'Start button text', 'anar-toolkit' ), __( 'For example: Spin the wheel or Receive my daily gift.', 'anar-toolkit' ) );
			}
			if ( 'wheel' === $type ) {
				$fields['wheel_miss_label'] = array( __( 'Wheel label for no reward', 'anar-toolkit' ), __( 'The label on wheel segments without a prize. The chance of winning is configured above.', 'anar-toolkit' ) );
			}
			foreach ( $fields as $key => $field ) {
				$this->field( 'game_texts][' . $type . '][' . $key, $field[0], $texts[ $key ], in_array( $key, array( 'intro', 'win_message', 'lose_message' ), true ) ? 'textarea' : 'text', $field[1], true );
			}
			if ( 'quiz' === $type ) {
				$this->field( 'game_quiz_question', __( 'Quiz question', 'anar-toolkit' ), $config['game_quiz_question'], 'text', __( 'Only the store quiz uses this question. It has no effect on the wheel or other games.', 'anar-toolkit' ), true );
				$this->field( 'game_quiz_options', __( 'Answer choices, one per line', 'anar-toolkit' ), $config['game_quiz_options'], 'textarea', __( 'Enter 2 to 8 answers, one per line. Empty lines are ignored. These are quiz answers, not wheel prizes.', 'anar-toolkit' ), true );
				$this->field( 'game_quiz_answer', __( 'Correct answer number', 'anar-toolkit' ), $config['game_quiz_answer'], 'number', __( 'Count the non-empty answers from top to bottom, starting at 1. For example, enter 2 if the second answer is correct.', 'anar-toolkit' ), true );
			}
			if ( 'milestone' === $type ) {
				$this->field( 'game_milestone_orders', __( 'Paid orders per purchase milestone', 'anar-toolkit' ), $config['game_milestone_orders'], 'number', __( 'For example, 3 awards a gift after the third paid order, then after the sixth, and so on. Each milestone can be claimed once.', 'anar-toolkit' ), true );
			}
			?>
			</div></details>
			<?php
		}
	}

	private function module_settings( $module, $config ) {
		$fields = array();
		if ( ! in_array( $module, array( 'games', 'referral', 'traffic' ), true ) ) {
			?>
			<p><?php esc_html_e( 'Limits and customer filters use the shared campaign settings.', 'anar-toolkit' ); ?> <a href="<?php echo esc_url( \Anar\Login\Admin\MessageCenter::url( 'shared' ) ); ?>"><?php esc_html_e( 'Shared campaign settings', 'anar-toolkit' ); ?></a></p>
			<?php
		}
		if ( 'prediction' === $module ) {
			?>
			<p><label><?php esc_html_e( 'Prediction model', 'anar-toolkit' ); ?><select name="<?php echo esc_attr( Settings::OPTION ); ?>[prediction_mode]">
			<?php
			foreach ( array( 'personal' => __( 'Customer purchase history', 'anar-toolkit' ), 'product' => __( 'Product purchase intervals', 'anar-toolkit' ), 'hybrid' => __( 'Combined model', 'anar-toolkit' ) ) as $key => $label ) :
				?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $config['prediction_mode'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<?php
			$fields += array( 'prediction_minimum' => __( 'Minimum purchases for prediction', 'anar-toolkit' ), 'prediction_confidence' => __( 'Minimum prediction confidence (%)', 'anar-toolkit' ) );
		}
		if ( 'games' === $module ) {
			$this->game_settings( $config );
		}
		if ( 'stock' === $module ) {
			\Anar\Login\Admin\MessageCenter::summary( 'stock_verification', 'stock' );
		}
		if ( 'referral' === $module ) {
			$fields += array( 'referral_wait_days' => __( 'Referral refund waiting period in days', 'anar-toolkit' ), 'referral_amount' => __( 'Referral reward percentage', 'anar-toolkit' ), 'referral_minimum' => __( 'Minimum referred order amount', 'anar-toolkit' ) );
			?>
		<p><code>[anar_referrals]</code></p>
			<?php
		}
		if ( 'birthday' === $module ) {
			?>
		<p><code>[anar_customer_preferences]</code></p><p><?php esc_html_e( 'Jalali birthdays use the PHP Intl extension. When Intl is unavailable, the birthday form offers the Gregorian calendar.', 'anar-toolkit' ); ?></p>
			<?php
		}
		foreach ( $fields as $key => $label ) {
			$this->field( $key, $label, $config[ $key ], 'number' ); }
		if ( 'invoice' === $module ) {
			foreach ( array( 'store_name' => __( 'Store display name', 'anar-toolkit' ), 'store_address' => __( 'Store address', 'anar-toolkit' ), 'store_tax_id' => __( 'Store tax / registration ID', 'anar-toolkit' ), 'invoice_prefix' => __( 'Invoice number prefix', 'anar-toolkit' ) ) as $key => $label ) {
				$this->field( $key, $label, $config[ $key ] ); }
		}
		if ( 'survey' === $module ) {
			?>
		<p><input type="hidden" name="<?php echo esc_attr( Settings::OPTION ); ?>[survey_reviews]" value="0"><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[survey_reviews]" value="1" <?php checked( $config['survey_reviews'] ); ?>> <?php esc_html_e( 'Queue survey product reviews for moderation. Gifts never depend on a positive rating.', 'anar-toolkit' ); ?></label></p>
			<?php
		}
		foreach ( $this->rules( $module, $config ) as $event => $rule ) {
			?>
			<details class="anar-commerce-card"><summary><strong><?php echo esc_html( Engine::label( $event ) ); ?></strong></summary><p><input type="hidden" name="<?php echo esc_attr( Settings::OPTION . '[rules][' . $event . '][enabled]' ); ?>" value="0"><label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION . '[rules][' . $event . '][enabled]' ); ?>" value="1" <?php checked( $rule['enabled'] ); ?>> <?php esc_html_e( 'Enable this stage', 'anar-toolkit' ); ?></label></p>
			<?php
			$prefix = 'rules][' . $event . '][';
			$this->field( $prefix . 'days', __( 'Days from trigger (prediction can be negative)', 'anar-toolkit' ), $rule['days'], 'number' );
			\Anar\Login\Admin\MessageCenter::summary( 'mkt_' . $event, $module );
			?>
			<p><label><?php esc_html_e( 'Gift type', 'anar-toolkit' ); ?><select name="<?php echo esc_attr( Settings::OPTION . '[rules][' . $event . '][discount_type]' ); ?>">
			<?php
			foreach ( array( 'none' => __( 'No discount', 'anar-toolkit' ), 'percent' => __( 'Percentage', 'anar-toolkit' ), 'fixed_cart' => __( 'Fixed cart amount', 'anar-toolkit' ), 'static' => __( 'Existing coupon code', 'anar-toolkit' ) ) as $key => $label ) :
				?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $rule['discount_type'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p>
			<?php
			$this->field( $prefix . 'discount_amount', __( 'Gift amount', 'anar-toolkit' ), $rule['discount_amount'], 'number' );
			$this->field( $prefix . 'discount_hours', __( 'Gift validity in hours', 'anar-toolkit' ), $rule['discount_hours'], 'number' );
			$this->field( $prefix . 'static_coupon', __( 'Existing coupon code', 'anar-toolkit' ), $rule['static_coupon'] );
			?>
			</details>
			<?php
		}
	}

	private function history_form() {
		?>
		<details class="anar-commerce-card"><summary><strong><?php esc_html_e( 'Sync your customer history', 'anar-toolkit' ); ?></strong></summary><p><?php esc_html_e( 'Import paid WooCommerce orders in bounded background batches. This records sales facts and customers locally; enabled campaign rules apply during subsequent worker runs.', 'anar-toolkit' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_marketing_import' ); ?><input type="hidden" name="action" value="anar_marketing_import"><button class="button"><?php esc_html_e( 'Start / resume history import', 'anar-toolkit' ); ?></button></form></details>
		<?php
	}

	private function bulk_forms() {
		$preview = get_transient( 'anar_bulk_preview_' . get_current_user_id() );
		\Anar\Login\Admin\MessageCenter::summary( 'mkt_bulk', 'bulk' );
		?>
		<details class="anar-commerce-card"><summary><strong><?php esc_html_e( 'Import campaign contacts', 'anar-toolkit' ); ?></strong></summary><p><?php esc_html_e( 'Paste up to 1,000 mobile numbers, one per line. CSV rows are supported with the phone in the first column. Duplicates are merged and stopped numbers remain stopped. Importing never launches a bulk campaign; preview and confirm it separately.', 'anar-toolkit' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_marketing_contacts' ); ?><input type="hidden" name="action" value="anar_marketing_contacts"><textarea class="large-text" name="contacts" rows="5" maxlength="100000" required dir="ltr" aria-label="<?php esc_attr_e( 'Contact mobile numbers', 'anar-toolkit' ); ?>"></textarea><p><button class="button"><?php esc_html_e( 'Import contacts without sending', 'anar-toolkit' ); ?></button></p></form></details>
		<div class="anar-commerce-card"><h2><?php esc_html_e( 'Prepare a bulk campaign', 'anar-toolkit' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_bulk_preview' ); ?><input type="hidden" name="action" value="anar_bulk_preview"><p><label><?php esc_html_e( 'Campaign title', 'anar-toolkit' ); ?><input name="title" required maxlength="100"></label></p><p><label><?php esc_html_e( 'Audience segment', 'anar-toolkit' ); ?><select name="segment">
		<?php
		foreach ( array( 'all' => __( 'All eligible contacts', 'anar-toolkit' ), 'vip' => __( 'VIP customers', 'anar-toolkit' ), 'inactive' => __( 'No purchase in 30 days', 'anar-toolkit' ), 'new' => __( 'New customers', 'anar-toolkit' ) ) as $key => $label ) :
			?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p><p><label><?php esc_html_e( 'Personal message', 'anar-toolkit' ); ?><textarea class="large-text" name="message" rows="3" required maxlength="1500"></textarea></label></p><p><label><?php esc_html_e( 'Schedule (site timezone; blank = now)', 'anar-toolkit' ); ?><input name="scheduled" type="datetime-local"></label></p><button class="button"><?php esc_html_e( 'Preview eligible audience', 'anar-toolkit' ); ?></button></form>
		<?php
		if ( is_array( $preview ) && get_current_user_id() === $preview['user'] ) :
			?>
			<div class="anar-section-help"><h3><?php echo esc_html( $preview['title'] ); ?></h3><p><?php echo esc_html( number_format_i18n( count( $preview['ids'] ) ) ); ?> <?php esc_html_e( 'eligible contacts in this audience snapshot', 'anar-toolkit' ); ?></p><p><?php echo esc_html( $preview['message'] ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_bulk_launch' ); ?><input type="hidden" name="action" value="anar_bulk_launch"><input type="hidden" name="token" value="<?php echo esc_attr( $preview['token'] ); ?>"><button class="button button-primary"><?php esc_html_e( 'Confirm and queue this campaign', 'anar-toolkit' ); ?></button></form></div><?php endif; ?></div>
		<?php
	}

	private function module_report( $module, $config ) {
		global $wpdb;
		$repo = new Repository();
		if ( 'traffic' === $module ) {
			$report = Insights::report();
			?>
			<div class="anar-kpis"><article class="anar-kpi hero"><p><?php esc_html_e( 'Recorded net sales · 30 days', 'anar-toolkit' ); ?></p><strong><?php echo esc_html( number_format_i18n( $report['sales']->revenue ) ); ?></strong><small><?php echo esc_html( get_woocommerce_currency() ); ?></small></article><article class="anar-kpi"><p><?php esc_html_e( 'Paid orders', 'anar-toolkit' ); ?></p><strong><?php echo esc_html( number_format_i18n( $report['sales']->orders ) ); ?></strong></article><article class="anar-kpi"><p><?php esc_html_e( 'Page views', 'anar-toolkit' ); ?></p><strong><?php echo esc_html( number_format_i18n( $report['traffic']->views ) ); ?></strong></article><article class="anar-kpi"><p><?php esc_html_e( 'Visitor days', 'anar-toolkit' ); ?></p><strong><?php echo esc_html( number_format_i18n( $report['traffic']->visitor_days ) ); ?></strong></article></div><div class="anar-settings-grid"><article class="anar-commerce-card"><h2><?php esc_html_e( 'Top recorded products', 'anar-toolkit' ); ?></h2>
			<?php
			foreach ( $report['products'] as $row ) :
				$product = wc_get_product( $row->product_id );
				?>
				<p><?php echo esc_html( ( $product ? $product->get_name() : '#' . $row->product_id ) . ' · ' . $row->orders ); ?></p><?php endforeach; ?></article><article class="anar-commerce-card"><h2><?php esc_html_e( 'Traffic sources', 'anar-toolkit' ); ?></h2>
			<?php
			foreach ( $report['sources'] as $row ) :
				?>
				<p><?php echo esc_html( ( $row->source ?: __( 'Direct / unknown', 'anar-toolkit' ) ) . ' · ' . $row->visitors ); ?></p><?php endforeach; ?></article></div>
			<?php
			return;
		}
		$tables = array( 'stock' => 'stock', 'survey' => 'feedback', 'games' => 'plays', 'referral' => 'referrals' );
		if ( isset( $tables[ $module ] ) ) {
			$table = $tables[ $module ];
			$column = 'referral' === $module ? 'referrer' : 'profile_id';
			if ( 'survey' === $module ) {
				$summary = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) total, AVG(rating) rating FROM %i', $repo->table( $table ) ) ); } else {
				$summary = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) total FROM %i', $repo->table( $table ) ) ); }
				?>
			<div class="anar-kpis"><article class="anar-kpi hero"><p><?php echo esc_html( Settings::modules()[ $module ] ); ?></p><strong><?php echo esc_html( number_format_i18n( $summary->total ) ); ?></strong><small><?php esc_html_e( 'records', 'anar-toolkit' ); ?></small></article>
			<?php
			if ( 'survey' === $module ) :
				?>
				<article class="anar-kpi"><p><?php esc_html_e( 'Average rating out of 5', 'anar-toolkit' ); ?></p><strong><?php echo esc_html( $summary->total ? number_format_i18n( $summary->rating, 1 ) : '—' ); ?></strong></article><?php endif; ?></div>
			<?php
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT r.*, p.phone FROM %i r LEFT JOIN %i p ON p.id = r.%i ORDER BY r.id DESC LIMIT 20', $repo->table( $table ), $repo->table( 'profiles' ), $column ) );
			?>
			<div class="anar-commerce-card"><h2><?php esc_html_e( 'Engagement reports', 'anar-toolkit' ); ?></h2><div class="anar-commerce-table"><table><thead><tr><th><?php esc_html_e( 'Customer', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Reference', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'State', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Date', 'anar-toolkit' ); ?></th></tr></thead><tbody>
			<?php
			foreach ( $rows as $row ) :
				?>
				<tr><td dir="ltr"><?php echo esc_html( Identity::mask( $row->phone ?? '' ) ); ?></td><td><?php echo esc_html( $row->game ?? $row->product_id ?? $row->order_id ?? $row->id ); ?>
				<?php
				if ( isset( $row->comment ) ) :
					?>
				<small><?php echo esc_html( $row->comment ); ?></small><?php endif; ?></td><td><?php echo esc_html( isset( $row->rating ) ? $row->rating . '/5' : ( Dashboard::states()[ $row->state ?? $row->status ] ?? $row->state ?? $row->status ) ); ?></td><td><?php echo esc_html( wp_date( 'Y/m/d H:i', $row->created_at ) ); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
			<?php
			return;
		}
		$events = array_keys( $this->rules( $module, $config ) );
		if ( ! $events ) {
			return; }
		$holders = implode( ',', array_fill( 0, count( $events ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Generated %s placeholders and the two table identifiers/date are bound through the merged argument array.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT j.event, COUNT(*) jobs, SUM(CASE WHEN m.status = 'accepted' THEN 1 ELSE 0 END) accepted, SUM(CASE WHEN m.status IN ('failed','uncertain') THEN 1 ELSE 0 END) failed, COUNT(DISTINCT CASE WHEN j.converted_total > 0 THEN j.converted_order ELSE NULL END) converted FROM %i j LEFT JOIN %i m ON m.object_id = j.id AND m.event = CONCAT('mkt_',j.event) WHERE j.event IN ($holders) AND j.created_at >= %d GROUP BY j.event", array_merge( array( $repo->table( 'jobs' ), ( new CommerceRepository() )->table( 'messages' ) ), $events, array( time() - 30 * DAY_IN_SECONDS ) ) ) );
		?>
		<div class="anar-commerce-card"><h2><?php esc_html_e( 'Campaign performance · 30 days', 'anar-toolkit' ); ?></h2><div class="anar-commerce-table"><table><thead><tr>
		<?php
		foreach ( array( __( 'Campaign', 'anar-toolkit' ), __( 'Queued jobs', 'anar-toolkit' ), __( 'Accepted by provider', 'anar-toolkit' ), __( 'Failed / uncertain', 'anar-toolkit' ), __( 'Coupon-attributed purchases', 'anar-toolkit' ) ) as $heading ) :
			?>
			<th><?php echo esc_html( $heading ); ?></th><?php endforeach; ?></tr></thead><tbody>
			<?php
			foreach ( $rows as $row ) :
				?>
			<tr><td><?php echo esc_html( Engine::label( $row->event ) ); ?></td><td><?php echo esc_html( $row->jobs ); ?></td><td><?php echo esc_html( $row->accepted ); ?></td><td><?php echo esc_html( $row->failed ); ?></td><td><?php echo esc_html( $row->converted ); ?></td></tr><?php endforeach; ?></tbody></table></div><p class="anar-footnote"><?php esc_html_e( 'Campaign purchases are attributed through personal gift coupons. Attribution does not prove incremental revenue.', 'anar-toolkit' ); ?></p></div>
		<?php
		if ( 'bulk' === $module ) {
			$this->bulk_report(); }
		$customers = $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, COALESCE(f.orders,0) orders, COALESCE(f.spent,0) spent, f.last_paid FROM %i p LEFT JOIN (SELECT profile_id, COUNT(*) orders, SUM(total) spent, MAX(paid_at) last_paid FROM %i WHERE paid = 1 AND currency = %s GROUP BY profile_id) f ON f.profile_id = p.id ORDER BY p.id DESC LIMIT 20', $repo->table( 'profiles' ), $repo->table( 'orders' ), get_woocommerce_currency() ) );
		?>
		<div class="anar-commerce-card"><h2><?php esc_html_e( 'Recent customer profiles', 'anar-toolkit' ); ?></h2><div class="anar-commerce-table"><table><thead><tr>
		<?php
		foreach ( array( __( 'Customer', 'anar-toolkit' ), __( 'Mobile number', 'anar-toolkit' ), __( 'Orders', 'anar-toolkit' ), __( 'Spend', 'anar-toolkit' ), __( 'Last purchase', 'anar-toolkit' ) ) as $heading ) :
			?>
			<th><?php echo esc_html( $heading ); ?></th><?php endforeach; ?></tr></thead><tbody>
			<?php
			foreach ( $customers as $profile ) :
				?>
			<tr><td><?php echo esc_html( $profile->name ?: '#' . $profile->id ); ?></td><td dir="ltr"><?php echo esc_html( Identity::mask( $profile->phone ) ); ?></td><td><?php echo esc_html( $profile->orders ); ?></td><td><?php echo esc_html( number_format_i18n( $profile->spent ) . ' ' . get_woocommerce_currency() ); ?></td><td><?php echo esc_html( $profile->last_paid ? wp_date( 'Y/m/d', $profile->last_paid ) : '—' ); ?></td></tr><?php endforeach; ?></tbody></table></div></div>
		<?php
	}

	private function bulk_report() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 20', ( new Repository() )->table( 'bulk' ) ) );
		?>
		<div class="anar-commerce-card"><h2><?php esc_html_e( 'Bulk campaigns', 'anar-toolkit' ); ?></h2><div class="anar-commerce-table"><table><thead><tr><th><?php esc_html_e( 'Campaign', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'State', 'anar-toolkit' ); ?></th><th><?php esc_html_e( 'Actions', 'anar-toolkit' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr><td><?php echo esc_html( $row->title ); ?><small><?php echo esc_html( wp_date( 'Y/m/d H:i', $row->scheduled_at ) ); ?></small></td><td><?php echo esc_html( Dashboard::states()[ $row->state ] ?? $row->state ); ?></td><td>
			<?php
			if ( 'cancelled' !== $row->state ) :
				?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><?php wp_nonce_field( 'anar_bulk_state' ); ?><input type="hidden" name="action" value="anar_bulk_state"><input type="hidden" name="id" value="<?php echo esc_attr( $row->id ); ?>">
				<?php
				foreach ( array( 'paused' => __( 'Pause', 'anar-toolkit' ), 'sending' => __( 'Resume', 'anar-toolkit' ), 'cancelled' => __( 'Cancel remaining messages', 'anar-toolkit' ) ) as $key => $label ) :
					?>
	<button class="button" name="state" value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
		<?php
	}

	public function preview() {
		global $wpdb;
		$this->guard( 'anar_bulk_preview' );
		check_admin_referer( 'anar_bulk_preview' );
		$segment = isset( $_POST['segment'] ) ? sanitize_key( wp_unslash( $_POST['segment'] ) ) : 'all';
		if ( ! in_array( $segment, array( 'all', 'vip', 'inactive', 'new' ), true ) ) {
			$segment = 'all'; }
		$config = ( new Settings() )->all();
		$repo = new Repository();
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.id FROM %i p LEFT JOIN (SELECT profile_id, COUNT(*) orders, SUM(total) spent, MAX(paid_at) last_paid FROM %i WHERE paid = 1 AND currency = %s GROUP BY profile_id) f ON f.profile_id = p.id LEFT JOIN %i c ON c.phone_hash = p.phone_hash WHERE COALESCE(c.suppressed,0) = 0 AND (%s <> 'vip' OR COALESCE(f.spent,0) >= %f) AND (%s <> 'inactive' OR (f.last_paid > 0 AND f.last_paid < %d)) AND (%s <> 'new' OR COALESCE(f.orders,0) <= 1) ORDER BY p.id LIMIT 10001", $repo->table( 'profiles' ), $repo->table( 'orders' ), get_woocommerce_currency(), ( new CommerceRepository() )->table( 'contacts' ), $segment, $config['vip_amount'], $segment, time() - 30 * DAY_IN_SECONDS, $segment ) ) );
		if ( count( $ids ) > 10000 ) {
			wp_die( esc_html__( 'This preview supports up to 10,000 contacts. Narrow the audience before launching a larger campaign.', 'anar-toolkit' ) ); }

		$title = isset( $_POST['title'] ) && is_string( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$message = isset( $_POST['message'] ) && is_string( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$scheduled = time();
		if ( ! empty( $_POST['scheduled'] ) ) {
			try {
				$scheduled = ( new \DateTimeImmutable( sanitize_text_field( wp_unslash( $_POST['scheduled'] ) ), wp_timezone() ) )->getTimestamp();
			} catch ( \Exception $e ) {
				wp_die( esc_html__( 'Enter a valid schedule.', 'anar-toolkit' ) ); }
		}
		if ( ! $title || ! $message || ! $ids ) {
			wp_die( esc_html__( 'Enter a title and message, and choose a non-empty audience.', 'anar-toolkit' ) ); }
		set_transient( 'anar_bulk_preview_' . get_current_user_id(), array( 'user' => get_current_user_id(), 'token' => wp_generate_password( 32, false, false ), 'title' => $title, 'message' => $message, 'segment' => $segment, 'ids' => $ids, 'scheduled' => max( time(), $scheduled ) ), 10 * MINUTE_IN_SECONDS );
		$this->redirect();
	}

	public function launch() {
		global $wpdb;
		$this->guard( 'anar_bulk_launch' );
		check_admin_referer( 'anar_bulk_launch' );
		$preview = get_transient( 'anar_bulk_preview_' . get_current_user_id() );
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		if ( ! ( new Settings() )->enabled( 'bulk' ) || ! is_array( $preview ) || get_current_user_id() !== $preview['user'] || ! hash_equals( $preview['token'], $token ) ) {
			wp_die( esc_html__( 'The preview expired or bulk messages are disabled. Preview again before launching.', 'anar-toolkit' ) ); }
		$lock = 'anar_bulk_launch_' . hash( 'sha256', $token );
		if ( ! add_option( $lock, time(), '', false ) ) {
			wp_die( esc_html__( 'This campaign has already been queued.', 'anar-toolkit' ) ); }
		$repo = new Repository();
		$wpdb->insert( $repo->table( 'bulk' ), array( 'title' => $preview['title'], 'state' => 'sending', 'filters' => wp_json_encode( array( 'segment' => $preview['segment'], 'message' => $preview['message'], 'ids' => $preview['ids'] ) ), 'scheduled_at' => $preview['scheduled'], 'created_at' => time() ) );
		delete_transient( 'anar_bulk_preview_' . get_current_user_id() );
		$this->redirect();
	}

	public function state() {
		global $wpdb;
		$this->guard( 'anar_bulk_state' );
		check_admin_referer( 'anar_bulk_state' );
		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$current = $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE id = %d', ( new Repository() )->table( 'bulk' ), $id ) );
		if ( in_array( $current, array( 'paused', 'sending', 'queued' ), true ) && in_array( $state, array( 'paused', 'sending', 'cancelled' ), true ) ) {
			$wpdb->update( ( new Repository() )->table( 'bulk' ), array( 'state' => $state ), array( 'id' => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ) ); }
		$this->redirect();
	}

	public function import() {
		$this->guard( 'anar_marketing_import' );
		update_option( 'anar_marketing_import_active', 1, false );
		if ( ! wp_next_scheduled( 'anar_marketing_import_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'anar_marketing_import_batch' ); }
		$this->redirect(); }

	public function contacts() {
		$this->guard( 'anar_marketing_contacts' );
		check_admin_referer( 'anar_marketing_contacts' );
		$text = isset( $_POST['contacts'] ) && is_string( $_POST['contacts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['contacts'] ) ) : '';
		if ( strlen( $text ) > 100000 ) {
			wp_die( esc_html__( 'Import up to 1,000 phone numbers at a time.', 'anar-toolkit' ) ); }
		$parsed = Contacts::parse( $text );
		$repo = new Repository();
		$added = 0;
		foreach ( $parsed['phones'] as $phone ) {
			$id = $repo->remember( $phone );
			if ( $id && $repo->allowed( $repo->profile( $id ) ) ) {
				++$added; }
		}
		set_transient( 'anar_contacts_import_' . get_current_user_id(), array( 'added' => $added, 'invalid' => $parsed['invalid'] ), 5 * MINUTE_IN_SECONDS );
		$this->redirect();
	}

	public function export() {
		global $wpdb;
		$this->guard( 'anar_marketing_export' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT event, status, COUNT(*) count FROM %i WHERE event LIKE %s GROUP BY event, status', ( new CommerceRepository() )->table( 'messages' ), $wpdb->esc_like( 'mkt_' ) . '%' ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=anar-campaign-report.csv' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A direct administrator-requested CSV response.
		$stream = fopen( 'php://output', 'w' );
		fputcsv( $stream, array( 'Campaign', 'Status', 'Requests' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $stream, array( sanitize_key( $row->event ), sanitize_key( $row->status ), (int) $row->count ), ',', '"', '' ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the direct CSV output stream.
		fclose( $stream );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		exit;
	}
}
