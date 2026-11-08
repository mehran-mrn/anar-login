<?php
/**
 * Central message index and a single, purpose-specific editor.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Infrastructure\Features;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\MessageCatalog;
use Anar\Login\Messaging\MessageSettings;
use Anar\Login\Messaging\ProviderRegistry;

defined( 'ABSPATH' ) || exit;

final class MessageCenter {
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_anar_message', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_footer', array( $this, 'dialog' ) );
		add_action( 'wp_ajax_anar_message_editor', array( $this, 'load_editor' ) );
		add_action( 'wp_ajax_anar_message_save', array( $this, 'save' ) );
	}

	public function menu() {
		// Preserve bookmarks without a second visible settings entry.
		$hook = add_submenu_page( null, __( 'Messages and templates', 'anar-toolkit' ), __( 'Messages and templates', 'anar-toolkit' ), 'manage_options', 'anar-messages', array( $this, 'render' ) );
		add_action( 'load-' . $hook, array( $this, 'redirect_legacy' ) );
	}

	public function assets( $hook ) {
		if ( false !== strpos( $hook, 'anar-' ) ) {
			wp_enqueue_style( 'anar-login-admin', ANAR_LOGIN_URL . 'assets/css/admin.css', array(), ANAR_LOGIN_VERSION );
			wp_enqueue_style( 'anar-admin-controls', ANAR_LOGIN_URL . 'assets/css/admin-controls.css', array( 'anar-login-admin' ), ANAR_LOGIN_VERSION );
			wp_enqueue_style( 'anar-messages', ANAR_LOGIN_URL . 'assets/css/messages.css', array( 'anar-admin-controls' ), ANAR_LOGIN_VERSION );
			wp_enqueue_script( 'anar-messages', ANAR_LOGIN_URL . 'assets/js/messages.js', array(), ANAR_LOGIN_VERSION, true );
			wp_localize_script(
				'anar-messages',
				'AnarMessages',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'anar_message_editor' ),
					'loading' => __( 'Loading message…', 'anar-toolkit' ),
					'network' => __( 'The operation could not be confirmed. Your changes are still here. Please try again.', 'anar-toolkit' ),
					'saveFirst' => __( 'Save the message before sending a test.', 'anar-toolkit' ),
					'saving' => __( 'Saving…', 'anar-toolkit' ),
					'sending' => __( 'Sending…', 'anar-toolkit' ),
				)
			);
		}
	}

	public static function url( $id = '', $back = '' ) {
		return add_query_arg( array_filter( array( 'page' => 'anar-login-settings', 'section' => 'shared' === $id ? 'sending' : 'messages', 'edit_message' => 'shared' === $id ? '' : $id, 'back' => $back ) ), admin_url( 'admin.php' ) );
	}

	private static function back_url( $back ) {
		if ( in_array( $back, array( 'login', 'reset', 'two_factor', 'orders', 'recovery' ), true ) || isset( \Anar\Login\Marketing\Settings::modules()[ $back ] ) ) {
			return FeaturesPage::url( $back );
		}
		return self::url();
	}

	public static function summary( $id, $back = '' ) {
		$value = MessageSettings::get( $id );
		$errors = MessageSettings::errors( $id, $value );
		?>
		<div class="anar-message-summary" data-message-summary="<?php echo esc_attr( $id ); ?>"><div><strong><?php echo esc_html( MessageCatalog::all()[ $id ]['label'] ?? $id ); ?></strong><span data-message-status><?php echo esc_html( $errors ? __( 'Needs configuration', 'anar-toolkit' ) : __( 'Configuration complete', 'anar-toolkit' ) ); ?></span></div><a class="button" data-message-open href="<?php echo esc_url( self::url( $id, $back ) ); ?>"><?php esc_html_e( 'Edit message', 'anar-toolkit' ); ?></a></div>
		<?php
	}

	private function active( $id, $item ) {
		if ( ! Features::enabled( $item['module'] ) ) {
			return false;
		}
		if ( 'marketing' === $item['source'] ) {
			return ! empty( ( new \Anar\Login\Marketing\Settings() )->all()['rules'][ $item['key'] ]['enabled'] );
		}
		if ( 'commerce' === $item['source'] ) {
			$config = ( new \Anar\Login\Commerce\Settings() )->all();
			return 'recovery_request' === $id || ( 'recovery' === $item['group'] ? ! empty( $config['events'][ $id ]['minutes'] ) : ! empty( $config[ $id ] ) );
		}
		return true;
	}

	public function redirect_legacy() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only bookmark migration.
		$id = isset( $_GET['message'] ) && is_string( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';
		$url = self::url( $id );
		foreach ( array( 'variant', 'group', 's', 'all', 'back' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
				$url = add_query_arg( $key, sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), $url );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		wp_safe_redirect( $url );
		exit;
	}

	public function render() {
		( new SettingsPage( new Settings(), new ProviderRegistry( new Settings() ) ) )->render();
	}

	public static function notice() {
		$notice = get_transient( 'anar_message_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'anar_message_notice_' . get_current_user_id() );
			?>
			<div class="notice <?php echo $notice['ok'] ? 'notice-success' : 'notice-error'; ?> inline"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php
		}
	}

	public function dialog() {
		if ( ! wp_script_is( 'anar-messages', 'enqueued' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<dialog class="anar-admin anar-message-dialog" data-message-dialog aria-labelledby="anar-message-dialog-title" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
			<header class="anar-message-dialog__header"><h2 id="anar-message-dialog-title" tabindex="-1"><?php esc_html_e( 'Edit message', 'anar-toolkit' ); ?></h2><button type="button" class="button" data-message-close aria-label="<?php esc_attr_e( 'Close', 'anar-toolkit' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></header>
			<div class="anar-message-feedback" data-message-feedback role="status" aria-live="polite" hidden></div>
			<button type="button" class="button anar-message-reload" data-message-reload hidden><?php esc_html_e( 'Reload saved message', 'anar-toolkit' ); ?></button>
			<div class="anar-message-unsaved" data-message-unsaved role="alert" hidden><p><?php esc_html_e( 'This message has unsaved changes.', 'anar-toolkit' ); ?></p><div><button type="button" class="button" data-message-keep><?php esc_html_e( 'Continue editing', 'anar-toolkit' ); ?></button><button type="button" class="button" data-message-discard><?php esc_html_e( 'Discard changes', 'anar-toolkit' ); ?></button></div></div>
			<div class="anar-message-dialog__body" data-message-body></div>
		</dialog>
		<?php
	}

	public function load_editor() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'text' => __( 'You cannot manage these settings.', 'anar-toolkit' ) ), 403 );
		}
		check_ajax_referer( 'anar_message_editor', 'nonce' );
		$id = isset( $_GET['id'] ) && is_string( $_GET['id'] ) ? sanitize_key( $_GET['id'] ) : '';
		if ( ! isset( MessageCatalog::all()[ $id ] ) ) {
			wp_send_json_error( array( 'text' => __( 'This message is unavailable.', 'anar-toolkit' ) ), 404 );
		}
		ob_start();
		$this->editor( $id, '' );
		$html = ob_get_clean();
		wp_send_json_success( array( 'title' => MessageCatalog::all()[ $id ]['label'], 'html' => $html ) );
	}

	public function catalog() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$providers = ( new ProviderRegistry( new Settings() ) )->all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter selection.
		$group = isset( $_GET['group'] ) && is_string( $_GET['group'] ) ? sanitize_key( $_GET['group'] ) : '';
		$search = isset( $_GET['s'] ) && is_string( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$all = ! empty( $_GET['all'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="anar-card anar-message-catalog">
			<form method="get" class="anar-message-filters" data-message-filters><input type="hidden" name="page" value="anar-login-settings"><input type="hidden" name="section" value="messages">
				<label><?php esc_html_e( 'Search messages', 'anar-toolkit' ); ?><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"></label>
				<label><?php esc_html_e( 'Category', 'anar-toolkit' ); ?><select name="group"><option value=""><?php esc_html_e( 'All categories', 'anar-toolkit' ); ?></option>
				<?php foreach ( MessageCatalog::groups() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $group, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?></select></label>
				<label class="anar-message-show-all"><input type="checkbox" name="all" value="1" <?php checked( $all ); ?>> <?php esc_html_e( 'Include inactive messages', 'anar-toolkit' ); ?></label>
				<button class="button" data-message-filter-submit><?php esc_html_e( 'Filter', 'anar-toolkit' ); ?></button>
			</form>
			<div class="anar-message-table"><table aria-label="<?php esc_attr_e( 'Messages and templates', 'anar-toolkit' ); ?>"><thead><tr>
			<?php foreach ( array( __( 'Message', 'anar-toolkit' ), __( 'Recipient', 'anar-toolkit' ), __( 'Delivery method', 'anar-toolkit' ), __( 'Configuration', 'anar-toolkit' ), __( 'Actions', 'anar-toolkit' ) ) as $label ) : ?>
				<th scope="col"><?php echo esc_html( $label ); ?></th>
			<?php endforeach; ?></tr></thead><tbody>
			<?php
			foreach ( MessageCatalog::all() as $id => $item ) :
				$active = $this->active( $id, $item );
				$value = MessageSettings::get( $id );
				$errors = MessageSettings::errors( $id, $value );
				$visible = ( $all || $active ) && ( ! $group || $group === $item['group'] ) && ( ! $search || false !== stripos( $item['label'] . ' ' . $id, $search ) );
				?>
				<tr data-message-row="<?php echo esc_attr( $id ); ?>" data-group="<?php echo esc_attr( $item['group'] ); ?>" data-active="<?php echo $active ? '1' : '0'; ?>" data-search="<?php echo esc_attr( $item['label'] . ' ' . $id ); ?>" <?php echo $visible ? '' : 'hidden'; ?>>
					<td><a data-message-open href="<?php echo esc_url( self::url( $id ) ); ?>"><strong><?php echo esc_html( $item['label'] ); ?></strong></a><small><?php echo esc_html( $active ? __( 'Enabled', 'anar-toolkit' ) : __( 'Disabled', 'anar-toolkit' ) ); ?></small></td>
					<td data-label="<?php esc_attr_e( 'Recipient', 'anar-toolkit' ); ?>"><?php echo esc_html( false !== strpos( $id, 'admin' ) ? __( 'Administrators', 'anar-toolkit' ) : __( 'Customer', 'anar-toolkit' ) ); ?></td>
					<td data-label="<?php esc_attr_e( 'Delivery method', 'anar-toolkit' ); ?>"><span data-message-method><?php echo esc_html( 'text' === $value['mode'] ? __( 'Plain text', 'anar-toolkit' ) : __( 'Approved template', 'anar-toolkit' ) ); ?></span><small data-message-provider-label><?php echo esc_html( isset( $providers[ $value['provider'] ] ) ? $providers[ $value['provider'] ]->title() : $value['provider'] ); ?></small></td>
					<td><span class="anar-message-state <?php echo $errors ? 'needs-setup' : 'is-complete'; ?>" data-message-status><?php echo esc_html( $errors ? __( 'Needs configuration', 'anar-toolkit' ) : __( 'Configuration complete', 'anar-toolkit' ) ); ?></span></td>
					<td><a class="button" data-message-open href="<?php echo esc_url( self::url( $id ) ); ?>"><?php esc_html_e( 'Edit message', 'anar-toolkit' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
				<tr data-message-empty hidden><td colspan="5"><?php esc_html_e( 'No messages match these filters.', 'anar-toolkit' ); ?></td></tr>
			</tbody></table></div>
		</div>
		<?php
	}

	private function editor( $id, $back ) {
		$item = MessageCatalog::all()[ $id ];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$variant = isset( $_GET['variant'] ) && 'gift' === $_GET['variant'] ? 'gift' : ( isset( $_GET['variant'] ) ? 'plain' : MessageSettings::variant( $id ) );
		$value = MessageSettings::get( $id, $variant );
		$draft = get_transient( 'anar_message_draft_' . get_current_user_id() );
		if ( is_array( $draft ) && $id === $draft['id'] && hash_equals( $variant, (string) ( $draft['variant'] ?? 'plain' ) ) ) {
			$value = $draft['value'];
			delete_transient( 'anar_message_draft_' . get_current_user_id() );
		}
		$providers = ( new ProviderRegistry( new Settings() ) )->all();
		$sample = MessageSettings::preview( $id, $value );
		?>
		<?php
		if ( MessageCatalog::variants( $id ) ) {
			?>
			<nav class="anar-message-variants" aria-label="<?php esc_attr_e( 'Message variant', 'anar-toolkit' ); ?>"><a class="button <?php echo 'plain' === $variant ? 'is-active' : ''; ?>" data-message-variant="plain" aria-current="<?php echo 'plain' === $variant ? 'true' : 'false'; ?>" href="<?php echo esc_url( add_query_arg( 'variant', 'plain', self::url( $id, $back ) ) ); ?>"><?php esc_html_e( 'Without a gift', 'anar-toolkit' ); ?></a> <a class="button <?php echo 'gift' === $variant ? 'is-active' : ''; ?>" data-message-variant="gift" aria-current="<?php echo 'gift' === $variant ? 'true' : 'false'; ?>" href="<?php echo esc_url( add_query_arg( 'variant', 'gift', self::url( $id, $back ) ) ); ?>"><?php esc_html_e( 'With a gift', 'anar-toolkit' ); ?></a></nav><?php } ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="anar-message-editor" data-message-editor data-samples="<?php echo esc_attr( wp_json_encode( $sample['variables'] ) ); ?>">
		<?php wp_nonce_field( 'anar_message_' . $id ); ?><input type="hidden" name="action" value="anar_message"><input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>"><input type="hidden" name="variant" value="<?php echo esc_attr( $variant ); ?>"><input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>"><input type="hidden" name="revision" value="<?php echo esc_attr( MessageSettings::fingerprint( MessageSettings::get( $id, $variant ) ) ); ?>">
		<?php
		if ( 'auth' === $item['source'] && 'login_otp' !== $id ) {
			?>
			<label class="anar-message-inherit"><input type="checkbox" name="value[inherit]" value="1" <?php checked( ! empty( $value['inherit'] ) ); ?>> <?php esc_html_e( 'Use the sign-in template for this verification message', 'anar-toolkit' ); ?><?php Help::button( __( 'Use a generic verification template approved for these purposes. Changing the shared sign-in template affects the messages that use it.', 'anar-toolkit' ) ); ?></label><?php } ?>
		<div class="anar-message-fields" data-message-fields><label><?php esc_html_e( 'Delivery method', 'anar-toolkit' ); ?><select name="value[mode]" data-message-mode><option value="template" <?php selected( $value['mode'], 'template' ); ?>><?php esc_html_e( 'Approved template', 'anar-toolkit' ); ?></option><option value="text" <?php selected( $value['mode'], 'text' ); ?>><?php esc_html_e( 'Plain text', 'anar-toolkit' ); ?></option></select></label><label><?php esc_html_e( 'SMS provider', 'anar-toolkit' ); ?><select name="value[provider]" data-message-provider>
		<?php
		foreach ( $providers as $key => $provider ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value['provider'], $key ); ?>><?php echo esc_html( $provider->title() ); ?></option><?php } ?></select></label>
		<label data-template-field><?php esc_html_e( 'Approved provider template ID or name', 'anar-toolkit' ); ?><input name="value[template]" value="<?php echo esc_attr( $value['template'] ); ?>" dir="ltr"></label>
		<div class="anar-message-reference"><label for="anar-message-text" data-message-text-label data-text-label="<?php esc_attr_e( 'Message text', 'anar-toolkit' ); ?>" data-template-label="<?php esc_attr_e( 'Panel template reference', 'anar-toolkit' ); ?>"><?php echo esc_html( 'text' === $value['mode'] ? __( 'Message text', 'anar-toolkit' ) : __( 'Panel template reference', 'anar-toolkit' ) ); ?></label><?php Help::button( __( 'In template mode this is a reference only. Editing it does not change the approved text in your SMS panel.', 'anar-toolkit' ) ); ?><textarea id="anar-message-text" name="value[message]" rows="4" data-message-text><?php echo esc_textarea( $value['message'] ); ?></textarea></div>
		<fieldset data-template-field><legend><?php esc_html_e( 'Panel variable mapping', 'anar-toolkit' ); ?><?php Help::button( __( 'Use the exact panel variable names. For Meli Payamak, row order must match the approved template.', 'anar-toolkit' ) ); ?></legend><div data-mapping-rows></div><button type="button" class="button" data-add-mapping><?php esc_html_e( 'Add variable', 'anar-toolkit' ); ?></button><details><summary><?php esc_html_e( 'Advanced mapping', 'anar-toolkit' ); ?></summary><textarea name="value[params]" rows="4" dir="ltr" data-mapping-source><?php echo esc_textarea( $value['params'] ); ?></textarea></details></fieldset></div>
		<datalist id="anar-message-variables">
		<?php
		foreach ( $item['variables'] as $variable ) {
			?>
			<option value="<?php echo esc_attr( '{' . $variable . '}' ); ?>"><?php echo esc_html( MessageCatalog::variable_labels()[ $variable ] ?? $variable ); ?></option><?php } ?></datalist>
		<template data-mapping-template><div class="anar-message-map-row"><input aria-label="<?php esc_attr_e( 'Panel variable name', 'anar-toolkit' ); ?>" placeholder="<?php esc_attr_e( 'Panel variable name', 'anar-toolkit' ); ?>"><input list="anar-message-variables" aria-label="<?php esc_attr_e( 'Store value or variable', 'anar-toolkit' ); ?>" placeholder="<?php esc_attr_e( 'Store value or variable', 'anar-toolkit' ); ?>"><span><button type="button" class="button" data-up aria-label="<?php esc_attr_e( 'Move up', 'anar-toolkit' ); ?>">↑</button><button type="button" class="button" data-down aria-label="<?php esc_attr_e( 'Move down', 'anar-toolkit' ); ?>">↓</button><button type="button" class="button" data-remove><?php esc_html_e( 'Remove', 'anar-toolkit' ); ?></button></span></div></template>
		<p><strong><?php esc_html_e( 'Required variables', 'anar-toolkit' ); ?>:</strong>
		<?php
		echo esc_html(
			implode(
				', ',
				array_map(
					static function ( $key ) {
						return ( MessageCatalog::variable_labels()[ $key ] ?? $key ) . ' {' . $key . '}';
					},
					MessageCatalog::required( $id, $variant )
				)
			)
		);
		?>
					</p>
		<div class="anar-message-preview"><h3><?php esc_html_e( 'Sample preview', 'anar-toolkit' ); ?></h3><?php Help::button( __( 'Template preview uses your reference text; provider approval and handset delivery are not verified here.', 'anar-toolkit' ) ); ?><pre data-message-preview><?php echo esc_html( $sample['text'] ); ?></pre></div>
		<details class="anar-message-test"><summary><?php esc_html_e( 'Send a test SMS', 'anar-toolkit' ); ?></summary><p><?php esc_html_e( 'Save first. This sends sample values through this exact message configuration and may use SMS credit. No order, campaign or real discount is created.', 'anar-toolkit' ); ?></p><div class="anar-message-test__fields"><label><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><input type="tel" name="test_phone" dir="ltr"></label><button type="submit" class="button" name="operation" value="test"><?php esc_html_e( 'Send test', 'anar-toolkit' ); ?></button></div></details>
		<?php
		$test = get_option( MessageSettings::OPTION . '_test_' . $id ); if ( is_array( $test ) && hash_equals( $test['fingerprint'], MessageSettings::fingerprint( MessageSettings::get( $id, $variant ) ) ) ) {
			?>
			<p role="status"><?php echo esc_html( $test['accepted'] ? __( 'Last test accepted by the provider; handset delivery is not confirmed.', 'anar-toolkit' ) : __( 'The last test was not confirmed by the provider.', 'anar-toolkit' ) ); ?></p><?php } ?>
		<footer class="anar-message-actions"><button type="submit" class="button button-primary" name="operation" value="save"><?php esc_html_e( 'Save message', 'anar-toolkit' ); ?></button><button type="submit" class="button" name="operation" value="return"><?php esc_html_e( 'Save and close', 'anar-toolkit' ); ?></button></footer>
		</form>
		<?php
	}

	public function shared() {
		$value = ( new \Anar\Login\Marketing\Settings() )->all();
		?>
		<p class="anar-message-scope"><?php esc_html_e( 'These settings affect all customer campaigns, including birthday and repeat-purchase messages.', 'anar-toolkit' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="anar-card anar-message-shared" data-message-shared><?php wp_nonce_field( 'anar_message_shared' ); ?><input type="hidden" name="action" value="anar_message"><input type="hidden" name="id" value="shared"><input type="hidden" name="operation" value="shared">
		<label><?php esc_html_e( 'SMS provider', 'anar-toolkit' ); ?><select name="shared[provider]"><option value=""><?php esc_html_e( 'Use commerce provider', 'anar-toolkit' ); ?></option>
		<?php
		foreach ( ( new ProviderRegistry( new Settings() ) )->all() as $provider_key => $provider ) {
			?>
			<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $value['provider'], $provider_key ); ?>><?php echo esc_html( $provider->title() ); ?></option><?php } ?></select></label>
		<?php
		foreach ( array( 'daily_limit' => __( 'Maximum campaign messages per phone / day', 'anar-toolkit' ), 'gap_hours' => __( 'Minimum hours between campaign messages', 'anar-toolkit' ), 'retention' => __( 'Marketing retention in days', 'anar-toolkit' ), 'minimum_orders' => __( 'Minimum purchases for automated customer campaigns', 'anar-toolkit' ), 'minimum_spent' => __( 'Minimum lifetime spend', 'anar-toolkit' ), 'maximum_spent' => __( 'Maximum lifetime spend (0 = no maximum)', 'anar-toolkit' ), 'vip_amount' => __( 'VIP lifetime spend threshold', 'anar-toolkit' ) ) as $key => $label ) {
			?>
			<label><?php echo esc_html( $label ); ?><input type="number" step="any" min="0" name="shared[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value[ $key ] ); ?>"></label><?php } ?>
			<?php
			foreach ( array( 'products' => __( 'Included product IDs (blank = all)', 'anar-toolkit' ), 'exclude_products' => __( 'Excluded product IDs', 'anar-toolkit' ), 'categories' => __( 'Included category IDs (blank = all)', 'anar-toolkit' ) ) as $field => $label ) {
				?>
				<label><?php echo esc_html( $label ); ?><input name="shared[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( $value[ $field ] ); ?>" dir="ltr"></label><?php } ?><div class="anar-message-feedback" data-shared-feedback role="status" hidden></div><footer class="anar-admin__save"><?php submit_button( __( 'Save settings', 'anar-toolkit' ), 'primary', 'submit', false ); ?></footer></form>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( wp_doing_ajax() ) {
				wp_send_json_error( array( 'text' => __( 'You cannot manage these settings.', 'anar-toolkit' ) ), 403 );
			}
			wp_die( esc_html__( 'You cannot manage these settings.', 'anar-toolkit' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( $_POST['id'] ) : '';
		if ( wp_doing_ajax() ) {
			check_ajax_referer( 'anar_message_' . $id, '_wpnonce' );
		} else {
			check_admin_referer( 'anar_message_' . $id );
		}
		$back = isset( $_POST['back'] ) && is_string( $_POST['back'] ) ? sanitize_key( $_POST['back'] ) : '';
		$variant = isset( $_POST['variant'] ) && 'gift' === $_POST['variant'] ? 'gift' : 'plain';
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( $_POST['operation'] ) : '';
		$ok = false;
		$conflict = false;
		$text = __( 'The message changed in another session. Reload before saving.', 'anar-toolkit' );
		if ( 'shared' === $id && 'shared' === $operation ) {
			$settings = new \Anar\Login\Marketing\Settings();
			$raw = isset( $_POST['shared'] ) && is_array( $_POST['shared'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['shared'] ) ) : array();
			$keys = array( 'daily_limit', 'gap_hours', 'retention', 'minimum_orders', 'minimum_spent', 'maximum_spent', 'vip_amount', 'provider', 'products', 'exclude_products', 'categories' );
			$value = array_replace( $settings->all(), array_intersect_key( $raw, array_flip( $keys ) ) );
			update_option( \Anar\Login\Marketing\Settings::OPTION, $settings->sanitize( $value ) );
			$ok = true;
			$text = __( 'Shared campaign settings saved.', 'anar-toolkit' );
		} elseif ( isset( MessageCatalog::all()[ $id ] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each scalar is sanitized in the callback and MessageSettings::sanitize().
			$raw = isset( $_POST['value'] ) && is_array( $_POST['value'] ) ? array_map(
				static function ( $field ) {
					return is_scalar( $field ) ? sanitize_textarea_field( (string) $field ) : '';
				},
				wp_unslash( $_POST['value'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Scalars are sanitized by the preceding callback.
			) : array();
			$value = MessageSettings::sanitize( $raw );
			if ( 'auth' !== MessageCatalog::all()[ $id ]['source'] || 'login_otp' === $id ) {
				$value['inherit'] = false;
			}
			$value['_variant'] = $variant;
			$effective = $value['inherit'] ? array_replace( MessageSettings::get( 'login_otp' ), array( 'inherit' => true ) ) : $value;
			$errors = MessageSettings::errors( $id, $effective );
			$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
			$current = MessageSettings::get( $id, $variant );
			if ( $errors ) {
				$text = implode( ' ', $errors );
			} elseif ( 'test' === $operation ) {
				if ( hash_equals( MessageSettings::fingerprint( $current ), $revision ) && hash_equals( wp_json_encode( MessageSettings::sanitize( $current ) ), wp_json_encode( MessageSettings::sanitize( $effective ) ) ) ) {
					$phone = isset( $_POST['test_phone'] ) && is_string( $_POST['test_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['test_phone'] ) ) : '';
					$sample = MessageSettings::preview( $id, $current );
					$message_group = MessageCatalog::all()[ $id ]['group'];
					$is_recovery = 'recovery' === $message_group;
					$source = MessageCatalog::all()[ $id ]['source'];
					if ( 'auth' === $source ) {
						$result = ( new ProviderRegistry( new Settings() ) )->send( $phone, '123456', $sample['text'], $id );
					} else {
						$result = ( new \Anar\Login\Messaging\NotificationSender( new Settings(), new \Anar\Login\Messaging\HttpClient() ) )->send( $current['provider'], $phone, $current, $sample['variables'], $is_recovery );
					}
					$ok = ! is_wp_error( $result );
					$text = $ok ? __( 'Test accepted by the provider. Check the recipient phone.', 'anar-toolkit' ) : $result->get_error_message();
					update_option( MessageSettings::OPTION . '_test_' . $id, array( 'fingerprint' => MessageSettings::fingerprint( $current ), 'accepted' => $ok, 'time' => time() ), false );
				} else {
					$text = __( 'Save the message before sending a test.', 'anar-toolkit' );
				}
			} elseif ( in_array( $operation, array( 'save', 'return' ), true ) ) {
				$ok = MessageSettings::save( $id, $value, $revision, $variant );
				$conflict = ! $ok;
				if ( $ok ) {
					$text = __( 'Message saved.', 'anar-toolkit' );
				}
			}
			if ( ! wp_doing_ajax() && $errors && 'test' !== $operation ) {
				set_transient( 'anar_message_draft_' . get_current_user_id(), array( 'id' => $id, 'variant' => $variant, 'value' => $value ), 10 * MINUTE_IN_SECONDS );
			}
		}
		if ( wp_doing_ajax() ) {
			$response = array( 'text' => $text, 'id' => $id, 'variant' => $variant, 'operation' => $operation, 'reload' => $conflict );
			if ( $ok && 'shared' === $id ) {
				$response['values'] = array_intersect_key( ( new \Anar\Login\Marketing\Settings() )->all(), array_flip( $keys ) );
			}
			if ( $ok && isset( MessageCatalog::all()[ $id ] ) ) {
				$saved = MessageSettings::get( $id, $variant );
				$registry = ( new ProviderRegistry( new Settings() ) )->all();
				$response['revision'] = MessageSettings::fingerprint( $saved );
				$response['value'] = MessageSettings::sanitize( $saved );
				// The list follows the module's active variant, which can differ from the editor.
				$listed = MessageSettings::get( $id );
				$incomplete = (bool) MessageSettings::errors( $id, $listed );
				$response['incomplete'] = $incomplete;
				$response['status'] = $incomplete ? __( 'Needs configuration', 'anar-toolkit' ) : __( 'Configuration complete', 'anar-toolkit' );
				$response['method'] = 'text' === $listed['mode'] ? __( 'Plain text', 'anar-toolkit' ) : __( 'Approved template', 'anar-toolkit' );
				$response['provider'] = isset( $registry[ $listed['provider'] ] ) ? $registry[ $listed['provider'] ]->title() : $listed['provider'];
			}
			$ok ? wp_send_json_success( $response ) : wp_send_json_error( $response, 422 );
		}
		set_transient( 'anar_message_notice_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $text ), MINUTE_IN_SECONDS );
		wp_safe_redirect( $ok && 'return' === $operation ? self::back_url( $back ) : add_query_arg( 'variant', $variant, self::url( $id, $back ) ) );
		exit;
	}
}
