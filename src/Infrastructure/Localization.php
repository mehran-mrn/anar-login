<?php
/**
 * Fill gaps in installed language packs from the bundled Persian catalog.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Localization {

	public static function register() {
		add_filter( 'gettext_anar-toolkit', array( self::class, 'fallback' ), 10, 2 );
	}

	/**
	 * Preserve installed translations and fill only untranslated Persian messages.
	 *
	 * @param string $translation Translation selected by WordPress.
	 * @param string $text        English source message.
	 * @return string
	 */
	public static function fallback( $translation, $text ) {
		if ( $translation !== $text || 'fa_IR' !== determine_locale() ) {
			return $translation;
		}

		static $messages = null;
		if ( null === $messages ) {
			$catalog  = require ANAR_LOGIN_PATH . 'languages/anar-toolkit-fa_IR.l10n.php';
			$messages = $catalog['messages'];
		}

		return isset( $messages[ $text ] ) && '' !== $messages[ $text ] ? $messages[ $text ] : $translation;
	}
}
