<?php
/**
 * Exercise both plugins through WordPress REST with a local SMS fixture.
 * Run with wp eval-file on a disposable installation with both plugins active.
 *
 * @package AnarLogin
 */

use Anar\Login\Auth\UserService;
use Anar\Login\Infrastructure\Settings;
use Anar\Login\Messaging\SmsProviderInterface;
use Anar\Login\Support\Identity;
use MRN\Form\Entry_Repository;
use MRN\Form\Form_Repository;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! class_exists( Form_Repository::class ) ) {
	throw new RuntimeException( 'Use a disposable local installation with MRN Forms active.' );
}

$provider = new class() implements SmsProviderInterface {
	public $code = '';
	public $message = '';
	public function key() {
		return 'mrn_test';
	}
	public function title() {
		return 'MRN Forms test provider';
	}
	public function fields() {
		return array();
	}
	public function send( $recipient, $code, $message ) {
		$this->code = $code;
		$this->message = $message;
		return true;
	}
};
add_filter(
	'anar_login_sms_providers',
	static function ( $providers ) use ( $provider ) {
		$providers[ $provider->key() ] = $provider;
		return $providers;
	}
);
add_filter( 'send_auth_cookies', '__return_false' );
add_filter( 'pre_wp_mail', '__return_true' );

$forms = new Form_Repository();
$entries = new Entry_Repository();
$settings = new Settings();
$ids = array();
$created = 0;
$original = get_option( Settings::OPTION_KEY );
$phone = '091' . random_int( 10000000, 99999999 );
$identity = Identity::normalize( $phone );
$assert = static function ( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $label ) );
	}
};
$save = static function ( $mode ) use ( $forms, &$ids, $assert ) {
	$id = $forms->save(
		array(
			'title'  => 'Integration test ' . $mode,
			'status' => 'published',
			'fields' => array(
				array( 'type' => 'tel', 'key' => 'mobile', 'label' => 'Mobile', 'required' => true ),
				array( 'type' => 'text', 'key' => 'company', 'label' => 'Company', 'required' => true ),
			),
			'settings' => array( 'anarMode' => $mode, 'anarPhoneField' => 'mobile', 'emailNotifications' => false ),
		)
	);
	$assert( is_int( $id ), 'Fixture form could not be published.' );
	$ids[] = $id;
	return $id;
};
$send_request = static function ( $id, $route, $params ) {
	$request = new WP_REST_Request( 'POST', "/mrn-form/v1/forms/$id/$route" );
	$request->set_body_params( $params + array( '_mrnf_nonce' => wp_create_nonce( 'mrnf_submit_' . $id ) ) );
	return rest_do_request( $request );
};

try {
	$sms = $save( 'sms' );
	$signup = $save( 'registration' );
	$ordinary = $save( 'off' );
	update_option(
		Settings::OPTION_KEY,
		array_replace( $settings->defaults(), array( 'sms_provider' => $provider->key(), 'sms_message' => 'Form fixture {code}', 'bot_challenge' => 'off', 'mrn_registration_form' => $signup ) )
	);

	$users = new UserService( $settings );
	$blocked = $users->login_or_create( $identity, false );
	$assert( is_wp_error( $blocked ) && 'anar_mrn_registration_required' === $blocked->get_error_code(), 'Anar sign-in bypassed the registration form.' );

	$input = array( 'mrnf' => array( 'mobile' => $phone, 'company' => 'Custom field value' ) );
	$assert( 200 === $send_request( $ordinary, 'submit', $input )->get_status(), 'An ordinary form should accept submission without a code.' );
	$assert( 403 === $send_request( $sms, 'sms', $input + array( '_mrnf_nonce' => 'invalid' ) )->get_status(), 'The SMS route accepted an invalid nonce.' );
	$assert( 400 === $send_request( $signup, 'submit', $input )->get_status(), 'Registration accepted a missing code.' );

	$sent = $send_request( $sms, 'sms', $input );
	$assert( 200 === $sent->get_status() && $provider->code && isset( $sent->get_data()['challenge'] ), 'SMS code could not be issued.' );
	$assert( 'Form fixture ' . $provider->code === $provider->message, 'Form verification did not use the configured login message.' );
	$assert( ! array_key_exists( 'code', $sent->get_data() ), 'The REST response exposed the SMS code.' );
	$verification = array( '_mrnf_sms_code' => $provider->code, '_mrnf_sms_challenge' => $sent->get_data()['challenge'] );
	$assert( 400 === $send_request( $signup, 'submit', $input + $verification )->get_status(), 'An SMS-only code was accepted for registration.' );
	$wrong_phone = array( 'mrnf' => array_replace( $input['mrnf'], array( 'mobile' => '09201234567' ) ) );
	$assert( 400 === $send_request( $sms, 'submit', $wrong_phone + $verification )->get_status(), 'The code was accepted for another number.' );
	$assert( 200 === $send_request( $sms, 'submit', $input + $verification )->get_status(), 'A valid code did not submit the SMS form.' );
	$assert( 400 === $send_request( $sms, 'submit', $input + $verification )->get_status(), 'A consumed SMS code was accepted again.' );
	$assert( ! $users->find_by_identity( $identity ) && 1 === $entries->count( $sms ), 'SMS-only verification created an account or duplicated an entry.' );

	// Use a second number so this request is independent of the resend window.
	$input['mrnf']['mobile'] = '093' . random_int( 10000000, 99999999 );
	$identity = Identity::normalize( $input['mrnf']['mobile'] );
	$sent = $send_request( $signup, 'sms', $input );
	$assert( 200 === $sent->get_status(), 'Registration code could not be issued.' );
	$verification = array( '_mrnf_sms_code' => $provider->code, '_mrnf_sms_challenge' => $sent->get_data()['challenge'] );
	$invalid = array( 'mrnf' => array( 'mobile' => $input['mrnf']['mobile'] ) );
	$assert( 422 === $send_request( $signup, 'submit', $invalid + $verification )->get_status(), 'A required custom field could be skipped.' );
	$done = $send_request( $signup, 'submit', $input + $verification );
	$user = $users->find_by_identity( $identity );
	$created = $user ? $user->ID : 0;
	$assert( 200 === $done->get_status() && $created && get_current_user_id() === $created, 'Verified registration did not create and sign in a user.' );
	$entry = $entries->find( $done->get_data()['entryId'] );
	$assert( 'Custom field value' === $entry['values']['company'] && ! isset( $entry['values']['_mrnf_sms_code'] ), 'The entry lost the custom value or stored the code.' );
	$entry_link = get_user_meta( $created, '_anar_mrn_form_entry', true );
	$assert( array( 'form_id' => $signup, 'entry_id' => $entry['id'] ) === $entry_link, 'The account is missing its registration entry link.' );
	WP_CLI::success( 'Anar registration and MRN Forms SMS verification passed.' );
} finally {
	wp_set_current_user( 0 );
	if ( $created ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created );
	}
	foreach ( $ids as $fixture_id ) {
		$forms->delete( $fixture_id );
	}
	update_option( Settings::OPTION_KEY, $original );
}
