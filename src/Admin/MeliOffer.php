<?php
/**
 * Contextual affiliate offer and optional registration dialog.
 *
 * @package AnarLogin
 */

namespace Anar\Login\Admin;

use Anar\Login\Partners\MeliSignup;

defined( 'ABSPATH' ) || exit;

final class MeliOffer {

	public static function card() {
		?>
		<div class="anar-meli-offer">
				<div><span class="anar-meli-badge"><?php esc_html_e( 'Anar user discount', 'anar-toolkit' ); ?></span><h3><?php esc_html_e( 'Need a Meli Payamak account?', 'anar-toolkit' ); ?></h3><p><?php esc_html_e( 'Copy the Anar discount code to use when topping up your Meli Payamak account.', 'anar-toolkit' ); ?></p></div>
			<div class="anar-meli-offer__actions"><div class="anar-meli-coupon"><code dir="ltr"><?php echo esc_html( MeliSignup::COUPON ); ?></code><button type="button" class="button" data-copy="<?php echo esc_attr( MeliSignup::COUPON ); ?>"><?php esc_html_e( 'Copy discount code', 'anar-toolkit' ); ?></button></div><a class="button button-primary" data-meli-signup href="<?php echo esc_url( MeliSignup::REFERRAL ); ?>" rel="sponsored noopener"><?php esc_html_e( 'Register with Meli Payamak', 'anar-toolkit' ); ?></a></div>
				<p class="anar-meli-disclosure"><?php esc_html_e( 'Anar receives a commission from purchases and usage of referred accounts.', 'anar-toolkit' ); ?></p>
			<p class="anar-meli-status" data-meli-status role="status" aria-live="polite" hidden></p>
		</div>
		<?php
	}

	public static function dialog() {
		?>
		<dialog class="anar-meli-dialog" aria-labelledby="anar-meli-title" aria-describedby="anar-meli-description">
			<div class="anar-meli-dialog__head"><div><span class="anar-meli-badge"><?php esc_html_e( 'Anar user discount', 'anar-toolkit' ); ?></span><h2 id="anar-meli-title"><?php esc_html_e( 'Meli Payamak quick signup', 'anar-toolkit' ); ?></h2></div><button type="button" class="anar-meli-close" data-meli-close aria-label="<?php esc_attr_e( 'Close signup', 'anar-toolkit' ); ?>">×</button></div>
				<p id="anar-meli-description"><?php esc_html_e( 'Choose a plan and enter the account holder details. Submitting this form sends these details through Anar to Meli Payamak to create your account.', 'anar-toolkit' ); ?></p>
			<form data-meli-form>
				<fieldset class="anar-meli-plans">
					<legend><?php esc_html_e( 'Account plan', 'anar-toolkit' ); ?></legend>
					<div class="anar-meli-plans__list" data-meli-plans></div>
				</fieldset>
				<div class="anar-meli-fields">
					<label><?php esc_html_e( 'First name (optional)', 'anar-toolkit' ); ?><input type="text" name="first_name" autocomplete="given-name" maxlength="80" pattern="[^\p{Nd}]*"></label>
					<label><?php esc_html_e( 'Last name', 'anar-toolkit' ); ?><input type="text" name="last_name" autocomplete="family-name" maxlength="80" pattern="[^\p{Nd}]*" required></label>
				</div>
				<label class="anar-meli-label"><?php esc_html_e( 'Mobile number', 'anar-toolkit' ); ?><input type="tel" name="mobile_number" dir="ltr" inputmode="tel" autocomplete="tel" placeholder="09121234567" maxlength="20" required></label>
				<div class="anar-meli-trap" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
					<div class="anar-meli-topup"><span><?php esc_html_e( 'Account top-up discount code', 'anar-toolkit' ); ?></span><div class="anar-meli-coupon"><code dir="ltr"><?php echo esc_html( MeliSignup::COUPON ); ?></code><button type="button" class="button" data-copy="<?php echo esc_attr( MeliSignup::COUPON ); ?>"><?php esc_html_e( 'Copy discount code', 'anar-toolkit' ); ?></button></div></div>
				<p class="anar-meli-links"><a href="https://www.melipayamak.com/rules/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Meli Payamak terms', 'anar-toolkit' ); ?></a><a href="https://mrnwp.ir/privacy/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MRNWP privacy', 'anar-toolkit' ); ?></a></p>
				<button type="submit" class="button button-primary" data-meli-submit><?php esc_html_e( 'Create account and get invoice', 'anar-toolkit' ); ?></button>
			</form>
			<div class="anar-meli-result" data-meli-result hidden><p data-meli-result-text role="status"></p><a class="button button-primary" data-meli-payment target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Review invoice and pay on Meli Payamak', 'anar-toolkit' ); ?></a><p><?php esc_html_e( 'Account activation, web service credentials and approved SMS patterns are completed separately. Payment is not confirmed inside Anar.', 'anar-toolkit' ); ?></p></div>
			<p class="anar-meli-status" data-meli-error role="alert" hidden></p>
		</dialog>
		<?php
	}
}
