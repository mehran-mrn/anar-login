<?php
/**
 * Block editor wrappers for the existing shortcodes.
 *
 * Blocks render through the very same shortcode callbacks, so a block and a
 * shortcode can never drift apart. Registration is in-memory only (no
 * block.json reads), and the editor script loads only inside the editor.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Frontend;

defined( 'ABSPATH' ) || exit;

final class Blocks {
	/** @var Shortcodes */
	private $shortcodes;

	/** @var Popup */
	private $popup;

	/** @var bool */
	private $reset;

	public function __construct( Shortcodes $shortcodes, Popup $popup, $reset ) {
		$this->shortcodes = $shortcodes;
		$this->popup      = $popup;
		$this->reset      = (bool) $reset;
	}

	public function register() {
		if ( function_exists( 'register_block_type' ) ) {
			add_action( 'init', array( $this, 'blocks' ), 20 );
		}
	}

	public function blocks() {
		wp_register_script( 'anar-blocks', ANAR_LOGIN_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ), ANAR_LOGIN_VERSION, true );
		wp_add_inline_script(
			'anar-blocks',
			'window.AnarBlocks=' . wp_json_encode(
				array(
					'reset'   => $this->reset,
					'strings' => array(
						'login'       => __( 'Anar sign-in form', 'anar-toolkit' ),
						'loginInfo'   => __( 'Passwordless sign-in and sign-up with a mobile or email code.', 'anar-toolkit' ),
						'button'      => __( 'Anar sign-in button', 'anar-toolkit' ),
						'buttonInfo'  => __( 'Opens the sign-in popup, or the account page when signed in.', 'anar-toolkit' ),
						'resetTitle'  => __( 'Anar SMS password reset', 'anar-toolkit' ),
						'resetInfo'   => __( 'Lets customers reset their password with a code sent to their linked mobile.', 'anar-toolkit' ),
						'redirect'    => __( 'Return address after sign-in (optional)', 'anar-toolkit' ),
						'label'       => __( 'Button text', 'anar-toolkit' ),
						'settings'    => __( 'Settings', 'anar-toolkit' ),
					),
				)
			) . ';',
			'before'
		);
		wp_register_style( 'anar-blocks-editor', ANAR_LOGIN_URL . 'assets/css/frontend.css', array(), ANAR_LOGIN_VERSION );

		$common = array(
			'editor_script' => 'anar-blocks',
			'editor_style'  => 'anar-blocks-editor',
			'supports'      => array( 'html' => false ),
		);
		register_block_type(
			'anar/login',
			$common + array(
				'attributes'      => array( 'redirect' => array( 'type' => 'string', 'default' => '' ) ),
				'render_callback' => function ( $attributes ) {
					return $this->shortcodes->login( array( 'redirect' => (string) ( $attributes['redirect'] ?? '' ) ) );
				},
			)
		);
		register_block_type(
			'anar/login-button',
			$common + array(
				'attributes'      => array( 'label' => array( 'type' => 'string', 'default' => '' ) ),
				'render_callback' => function ( $attributes ) {
					$label = trim( (string) ( $attributes['label'] ?? '' ) );
					return $this->popup->button( $label ? array( 'label' => $label ) : array() );
				},
			)
		);
		if ( $this->reset ) {
			register_block_type(
				'anar/reset-password',
				$common + array(
					'render_callback' => array( \Anar\Login\Auth\PasswordRecovery::class, 'form' ),
				)
			);
		}
	}
}
