<?php
/**
 * Public support destination for Anar users.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

defined( 'ABSPATH' ) || exit;

final class Support {

	const URL = 'https://mrnwp.ir/anar/#respond';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
	}

	public function menu() {
		add_submenu_page( 'anar-login', __( 'Anar support', 'anar-toolkit' ), __( 'Support and suggestions', 'anar-toolkit' ), 'manage_options', 'anar-toolkit-support', array( self::class, 'render' ) );
	}

	public static function notice() {
		?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Have a question or suggestion about Anar? Send it through the discussion section on the plugin homepage. I will do my best to resolve your issue as quickly as possible.', 'anar-toolkit' ); ?>
				<a href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ask a question or share a suggestion', 'anar-toolkit' ); ?></a>
			</p>
		</div>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Workspace::start( __( 'Anar support', 'anar-toolkit' ) );
		?>
		<div class="anar-support">
			<section class="anar-card">
				<h2><?php esc_html_e( 'Questions, problems, and suggestions', 'anar-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'You can send questions, report problems, or share suggestions about Anar through the discussion section on the plugin homepage at mrnwp.ir/anar/. I will do my best to help you and resolve your issue as quickly as possible.', 'anar-toolkit' ); ?></p>
				<p><?php esc_html_e( 'Describe what you were trying to do, what happened, and the Anar version you use. Replies appear under your question so other users can benefit from the solution too.', 'anar-toolkit' ); ?></p>
				<p><?php esc_html_e( 'Email and phone are optional and are not displayed publicly. Your name and message become public after approval. You can ask a question without leaving a rating.', 'anar-toolkit' ); ?></p>
				<p><a class="button button-primary" href="<?php echo esc_url( self::URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ask a question or share a suggestion', 'anar-toolkit' ); ?></a></p>
			</section>
		</div>
		<?php
		Workspace::end();
	}
}
