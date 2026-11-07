<?php
/**
 * MaxSMS provider using the shared Panelchi pattern transport.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Messaging\Providers;

defined( 'ABSPATH' ) || exit;

final class MaxSms extends Panelchi {

	/** @inheritDoc */
	public function key() {
		return 'maxsms';
	}

	/** @inheritDoc */
	public function title() {
		return 'MaxSMS';
	}

	/** @inheritDoc */
	public function fields() {
		$fields = parent::fields();
		$fields['from']['help'] = __( 'MaxSMS uses source number 10001. Leave blank to use this default.', 'anar-toolkit' );
		return $fields;
	}
}
