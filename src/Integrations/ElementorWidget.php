<?php
/**
 * Anar toolkit update: configuration, delivery and presentation.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Integrations;

defined( 'ABSPATH' ) || exit;
class ElementorWidget extends \Elementor\Widget_Base {
	private $kind;
	public function __construct( $data = array(), $args = null ) {
		$kind = $args['anar_kind'] ?? str_replace( 'anar-', '', $data['widgetType'] ?? 'login' );
		$this->kind = in_array( $kind, array( 'login', 'button', 'recovery' ), true ) ? $kind : 'login';
		parent::__construct( $data, $args ); }
	public function get_name() {
		return 'anar-' . $this->kind; }
	public function get_title() {
		return array( 'login' => __( 'Anar sign-in form', 'anar-toolkit' ), 'button' => __( 'Anar account button', 'anar-toolkit' ), 'recovery' => __( 'Anar cart recovery', 'anar-toolkit' ) )[ $this->kind ]; }
	public function get_icon() {
		return 'eicon-lock-user'; }
	public function get_categories() {
		return array( 'general' ); }
	public function get_style_depends() {
		return 'login' === $this->kind && \Anar\Login\Infrastructure\Features::enabled( 'login' ) ? array( 'anar-login' ) : array();
	}

	public function get_script_depends() {
		return $this->get_style_depends();
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => __( 'Content', 'anar-toolkit' ) ) );
		if ( 'button' === $this->kind ) {
			$this->add_control( 'label', array( 'label' => __( 'Button text', 'anar-toolkit' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Sign in / Account', 'anar-toolkit' ) ) ); }
		$this->end_controls_section();
	}
	protected function render() {
		$feature = 'recovery' === $this->kind ? 'recovery' : 'login';
		if ( ! \Anar\Login\Infrastructure\Features::enabled( $feature ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p>' . esc_html__( 'Enable this feature in Anar settings.', 'anar-toolkit' ) . '</p>'; }
			return;
		}
		if ( 'button' === $this->kind ) {
			echo do_shortcode( '[anar_login_button label="' . esc_attr( str_replace( array( '[', ']', '"' ), '', $this->get_settings_for_display( 'label' ) ) ) . '"]' ); } else {
			echo do_shortcode( 'recovery' === $this->kind ? '[anar_recover_cart]' : '[anar_login]' ); }
	}
}
