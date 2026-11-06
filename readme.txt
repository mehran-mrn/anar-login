=== Anar Toolkit – SMS Login & Recovery for WooCommerce ===
Contributors: mrnwp
Tags: sms, woocommerce, abandoned-cart, otp, notifications
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SMS login, confirmed-payment notifications, and consent-based cart and unpaid-order reminders for WooCommerce. All plugin features are free.

== Description ==

Anar Toolkit connects passwordless sign-in with opt-in purchase recovery and payment communication for WooCommerce stores using Iranian SMS providers. Each commerce module is optional and disabled by default. It also adds passwordless login and registration to WordPress. Visitors can sign in with an Iranian mobile number and an SMS one-time password (OTP), an email code, or their Google account. The plugin includes English/LTR and Persian/RTL interfaces.

Public registration is limited to Subscriber or WooCommerce Customer roles with basic read capabilities. Elevated or custom roles cannot be assigned by these registration paths. Existing accounts keep their roles, and administrators can disable registration while retaining verified sign-in for existing users.

**100% free, forever — all features included.** There is no Pro edition, license key, paid add-on, trial period or feature paywall. Security controls limit abusive authentication requests; they do not require payment to unlock features. Your SMS provider may charge for its account, approved templates and message credits. Those are external service fees, not plugin fees. Email login uses your site's existing WordPress mail configuration.

= ورود پیامکی و ثبت‌نام با شماره موبایل در وردپرس =

انار یک افزونهٔ ورود با پیامک و ثبت‌نام با شماره موبایل ایرانی است. کاربران با دریافت کد یک‌بارمصرف یا OTP وارد حساب می‌شوند؛ ورود با ایمیل و حساب گوگل نیز در دسترس است. فرم ورود پاپ‌آپ، ورود پیامکی ووکامرس در حساب کاربری و تسویه‌حساب، پنل فارسی راست‌به‌چپ و تأیید دومرحله‌ای اختیاری از امکانات افزونه هستند.

**۱۰۰٪ رایگان، برای همیشه — با تمام امکانات.** تمام قابلیت‌های افزونه رایگان هستند؛ نسخهٔ پولی، اشتراک افزونه، لایسنس یا قفل امکانات وجود ندارد. برای پیامک از حساب خودتان در پنل پیامکی استفاده می‌کنید و هزینهٔ احتمالی پنل و ارسال پیامک را همان شرکت دریافت می‌کند.

اتصال‌های آماده برای پنل‌های پیامکی ایران شامل کاوه‌نگار (Kavenegar)، ملی‌پیامک (Meli Payamak)، آی‌پی‌پنل (IPPanel)، پنل‌چی (Panelchi)، فراز اس‌ام‌اس (Faraz SMS) و SMS.ir هستند. درگاه HTTP سفارشی نیز برای سرویس‌های دیگر وجود دارد. Anar Toolkit سرویس ارسال پیامک یا فروشندهٔ اعتبار پیامک نیست.

[MRNWP](https://mrnwp.ir/) | [Source and issues](https://github.com/mehran-mrn/anar-login)

= Commerce toolkit =

* Separate customer and administrator SMS notifications after WooCommerce confirms payment.
* Up to five administrator recipients and independent approved message templates.
* Three configurable recovery stages, measured in minutes after the last saved cart change. Set a stage to zero to disable it.
* Separate SMS-provider selection for commerce, reusing credentials from SMS settings without reusing OTP templates.
* Explicit shopper consent form for classic cart and checkout, plus [anar_cart_reminders] and [anar_recover_cart] beside Cart and Checkout blocks.
* Cart snapshots are saved only after the shopper saves consent; OTP verification alone never subscribes a shopper.
* Guest and registered-customer carts with a saved Iranian mobile number; standard simple and variable products.
* Six-digit, phone-bound recovery codes restore saved products or open WooCommerce order payment. Codes are HMAC-protected, single-use and expire after a configurable 5–1440 minutes (30 by default). They never authenticate the shopper or bypass WooCommerce order access checks.
* SMS contains a code and the URL of the page that accepts it. That URL carries no code or account token. Customers enter their phone and latest code in [anar_recover_cart]. Classic cart/checkout includes this form automatically, including empty carts.
* Each code permits five guesses. Atomic per-phone and per-IP recovery limits also apply across browser sessions. Customers can request a fresh code for an eligible, consented purchase; unknown and opted-out numbers receive the same public response.
* The unsubscribe link opens a confirmation page; GET/link-preview requests never stop messages.
* Stop cart and unpaid-order reminders for a phone using the unsubscribe link. Payment notifications are separate transactional messages.
* Recheck consent, order eligibility and expiry before processing. Automatic reminders respect sending hours and daily caps. Explicit new-code requests instead use resend/hourly security limits and can be fulfilled outside reminder quiet hours.
* Local queue with atomic claims and duplicate protection, masked recipient reports and manual retry for confirmed failures (three attempts maximum).
* Ambiguous network/server results are marked uncertain and are not retried automatically. Accepted is API acceptance, not confirmed handset delivery.
* WordPress privacy export/erasure by saved email, phone-based administrator erasure, and 7–90 day configurable local retention.
* Action Scheduler when available, with WP-Cron fallback; scheduler traffic or server cron is needed. Scheduled times are targets and may be delayed.
* WooCommerce order CRUD for HPOS. Login integration remains classic-checkout based; commerce works with Checkout Blocks when both shortcodes are placed beside the block.

Payment notifications use the WooCommerce payment-complete event. Opening the thank-you page, creating an order, or selecting cash on delivery does not send a payment confirmation. Manual status changes alone do not trigger this event. Gateways must report payment completion correctly.

The saved cart contains product IDs, variation attributes and quantities. Product add-on metadata, bundles, custom-priced items, subscriptions and third-party checkout extensions are not recreated. Test your catalog before enabling recovery. No bulk marketing campaigns, back-in-stock alerts or repurchase campaigns are included in this release.

= Login features =

* SMS OTP login and registration with Iranian mobile numbers, including Persian and Arabic digits.
* Email one-time passwords through wp_mail; compatible with SMTP plugins.
* Optional Google OAuth authorization-code sign-in with PKCE and a browser-bound state cookie.
* Six built-in Iranian SMS adapters and a configurable HTTPS gateway.
* WooCommerce My Account and classic checkout login integration; WooCommerce is optional.
* A login popup loaded on demand, shortcode buttons and optional wp-login.php replacement.
* Optional two-step verification after password sign-in for privileged roles or all accounts.
* Expiring, HMAC-protected one-time codes; atomic attempt limits and single use.
* Atomic per-identity and per-IP request limits and resend cooldowns.
* Existing phone identity lookup through Anar metadata or Digits phone metadata.
* Configurable registration, safe default roles, redirects and site-logo appearance.
* Editable account names; email addresses and phone identities cannot be changed through the profile endpoint.
* Local CSS and readable JavaScript, without a runtime Composer dependency or external CDN.
* Optional data removal on uninstall, disabled by default.

The optional theme adapter supports WoodMart login components. Compatibility can vary by theme version and customizations. The plugin does not require WoodMart and does not bundle its code. Keep a password sign-in route available while setting up OTP, and test mail or SMS delivery before enabling two-step verification.

== Installation ==

1. Upload the anar-toolkit folder or install its ZIP through Plugins > Add New. If replacing a pre-release Anar Login installation in another folder, deactivate the old copy first; do not activate both copies.
2. Activate Anar Toolkit and open its administration menu.
3. Select your SMS provider and enter your own credentials and approved template. Alternatively, use email codes with your existing WordPress mail setup.
4. Create a page containing [anar_account] and select it as the account page; use [anar_login] for a separate login form if needed.
5. Add [anar_login_button] or a link to #anar-login when the popup is enabled.
6. Configure WooCommerce and optional two-step verification only after testing delivery.
7. Open Anar Toolkit > Commerce toolkit. Choose the commerce provider, recipient numbers and approved payment/recovery templates. Built-in providers send their approved template; the local text is the reference or custom-gateway message.
8. Map exact provider variables to {site}, {name}, {order_id}, {total}, {currency}, {code}, {minutes}, {recovery_page} and {unsubscribe_url}. Recovery mappings must include the code, entry-page URL and opt-out URL. Custom HTTP transport must use {message}. For Kavenegar, token={code}, token2={recovery_page} and token3={unsubscribe_url} is a starting point; each value must fit the provider's 100-character/whitespace limits.
9. Choose the page for entering recovery codes (the WooCommerce cart by default). Add [anar_recover_cart] to a custom page and exclude it from full-page caching. Set stage delays, site-local sending hours, code validity and retention. Review the site's privacy notice and the SMS provider's permitted use before enabling each module.
10. Classic cart/checkout displays consent and recovery forms automatically. Place [anar_cart_reminders] and [anar_recover_cart] beside Cart/Checkout blocks. The customer must click Save reminder preference; filling the billing phone alone does not save consent. A recovery code can be entered on a different device without logging in.

HTTPS is required for SMS gateway endpoints and recommended for the entire site. Google sign-in requires your own Google Cloud OAuth web application. No SMS or Google credentials are bundled.

== Frequently Asked Questions ==

= Is every feature really free forever? =

Yes. Anar Toolkit is 100% free, forever, with all features included. There are no paid plugin upgrades, license keys or feature gates. SMS account and message charges are set by your chosen provider. Ordinary security throttling is included to protect login requests.

= آیا افزونهٔ ورود پیامکی انار با تمام امکانات همیشه رایگان است؟ =

بله؛ انار ۱۰۰٪ رایگان، برای همیشه و با تمام امکانات است. بابت امکانات افزونه، به‌روزرسانی یا بازکردن قابلیت‌ها هزینه‌ای دریافت نمی‌شود. هزینهٔ احتمالی پنل و ارسال کد تأیید پیامکی مربوط به شرکت پیامکی منتخب شماست.

= Does it support Iranian SMS panels and WooCommerce? =

Yes. It includes Kavenegar, Meli Payamak, IPPanel, Panelchi, Faraz SMS and SMS.ir, plus a custom HTTPS gateway. WooCommerce integration provides My Account login, classic checkout login and verified billing-phone synchronization. It does not add a native Checkout Block component.

= Can I use email login without an SMS subscription? =

Yes. Set the identity mode to email. Codes use wp_mail and your existing mail transport. Google sign-in is another optional method.

= Are raw codes stored? =

No. Active codes are stored as keyed HMAC values bound to a recipient hash. Verification attempts, request limits and code consumption use atomic database operations. Expired records are removed by scheduled cleanup.

= Does two-step verification replace authenticator apps? =

It supplies a code by SMS or email after password sign-in. It does not implement TOTP authenticator apps or recovery codes. Verified OTP and Google sessions do not receive an additional code step. XML-RPC password authentication is refused for affected accounts.

= Will my site's public login form advertise Anar or MRNWP? =

No. The login form uses your site logo, a configured image or a neutral lock symbol. Developer links are confined to the plugin administration screen and this directory description.

== External services ==

All integrations use accounts configured by the site administrator. The plugin makes no telemetry, licensing or analytics requests to MRNWP or its author. Provider service fees and contracts are independent of the free plugin.

SMS services are contacted when a visitor requests/resends a code, a password sign-in requires a second step, an administrator explicitly sends a test SMS, or an enabled commerce module processes a queued payment notification or consented purchase reminder. Commerce requests can run in the background. No delivery-status polling occurs. The selected provider receives the destination mobile number, the code or message, sender/template identifiers and the provider credentials needed for the request. For commerce, the provider receives configured template values which can include first name, order number, amount, currency, site name, recovery code, code validity, recovery entry-page URL and unsubscribe URL. An administrator notification contains the order variables and the administrator recipient number. The provider also sees the server IP address and standard HTTP metadata. The plugin User-Agent contains its name/version, not the site URL. A configured message may contain the site name or domain.

= Kavenegar =

Endpoint: https://api.kavenegar.com/v1/{your-api-key}/verify/lookup.json
Data: API key in the URL path; recipient, OTP and template name in the query string. Purpose and timing are described above.
Official service: https://kavenegar.com/وب-سرویس-پیامک.html
Official API documentation: https://kavenegar.com/rest.html
Official terms and limited account-data-use notice: https://console.kavenegar.com/membership/signup (open the public terms popover beside the consent checkbox; no account or acceptance is required to read it). The notice addresses account-verification documents. A separate privacy policy covering all SMS recipient/message processing has not been verified. This remains an external-documentation review dependency; the service homepage is not represented as a privacy policy.

= Meli Payamak =

Endpoints: https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber and https://rest.payamak-panel.com/api/SendSMS/SendSMS
Data: account username/password, recipient and template body ID plus OTP, or sender and configured message for dedicated-number delivery.
Official terms: https://www.melipayamak.com/rules/
A separate public privacy-policy link could not be verified during release preparation. Request the applicable privacy notice from Meli Payamak before using it.

= IPPanel =

Endpoint: https://edge.ippanel.com/v1/api/send
Data: API token in the Authorization header; sender number, recipient, pattern code and OTP parameter in a JSON body.
Official service and integration lab: https://ippanel.co/lab/
API infrastructure: https://ippanel.com/
Official API documentation: https://ippanelcom.github.io/Edge-Document/docs/send/pattern
Official terms: https://ippanel.com/login (open the public rules modal using the button beside the login statement; logging in is not needed). The terms describe message monitoring and use of business identifiers. A separate public privacy-policy URL has not been verified. Obtain and review the applicable privacy notice from IPPanel before enabling this integration; the login/rules page is not represented as a standalone privacy policy.

= Panelchi =

Endpoint: https://api.panelchi.com/sms/pattern (HTTPS POST).
Data: API token in the Authorization: Bearer header; recipient mobile number, approved pattern slug, sourceNumber (10001 by default) and pattern variables in a JSON body. OTP requests send the code variable. Enabled commerce modules send their separately configured template variables, including recovery code, recovery entry page and unsubscribe link when applicable. Requests occur only at the SMS delivery times described above. The provider also receives the server IP and standard HTTP metadata. A request UID and an accepted/queued status indicate API acceptance, not handset delivery.
Official service/account portal: https://panelchi.com/
Official API documentation: https://docs.panelchi.com/redoc/
Account activation, identity verification and an active approved pattern may be required by the provider. Access from servers outside Iran may be restricted. The plugin does not create accounts or patterns, complete identity verification, or poll delivery status automatically.
Separate publicly accessible terms and privacy-policy URLs could not be verified. Obtain and review the applicable terms and data-processing/privacy notice from Panelchi before enabling this integration; the account portal and API documentation are not represented as privacy policies.

= Faraz SMS =

Endpoint: https://ippanel.com/patterns/pattern
Data: Faraz account username/password, sender, recipient, pattern code and OTP parameter as form fields. Delivery uses IPPanel infrastructure; consult your Faraz service contract for processing by that infrastructure.
Official terms and privacy commitment (panel rules, clause 12): https://farazsms.com/terms-conditions/
Official API documentation: https://docs.farazsms.com/

= SMS.ir =

Endpoint: https://api.sms.ir/v1/send/verify
Data: API key in the X-API-KEY header; recipient, numeric template ID and OTP parameter in JSON.
Official terms, including its security/privacy commitment: https://sms.ir/terms-and-conditions/

= Custom gateway =

The administrator supplies the HTTPS endpoint, authentication headers, parameters and response-success text. Only this configured service receives the recipient/code/message/sender and any credentials placed in the templates. The plugin cannot know the operator of an arbitrary URL. Review that operator's terms and privacy notice before saving the endpoint. GET requests may expose codes or credentials in provider/proxy URL logs; prefer POST when the service supports it. Redirects are not followed and WordPress safe HTTP validation remains enabled.

= Google sign-in =

Google is contacted only when a visitor chooses Google sign-in and completes the OAuth callback. The browser visits https://accounts.google.com/o/oauth2/v2/auth with the client ID, redirect URI, openid/email/profile scopes, state and PKCE challenge. The server exchanges the authorization code and PKCE verifier at https://oauth2.googleapis.com/token using the configured client secret, then requests https://openidconnect.googleapis.com/v1/userinfo with the temporary access token. Google returns its verified account identifier, email, name and optional profile image. These identify or create the local WordPress account; the plugin does not retain Google access or refresh tokens. A Google-hosted profile image can be loaded in the account panel.
Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

= Email and profile images =

Email codes use WordPress wp_mail; delivery depends on the site's hosting, SMTP plugin or configured mail service, which receives the address and code message. This plugin does not bundle an external email transport. WordPress avatar functions can use Gravatar for profile images, depending on site configuration; its image requests include the visitor IP address and an email-derived avatar identifier.
Gravatar terms: https://wordpress.com/tos/
Gravatar privacy: https://automattic.com/privacy/

== Privacy ==

User names and email addresses are stored in the usual WordPress user records. Verified mobile numbers, Google account identifiers and optional Google avatar URLs are stored in user metadata. SMS and OAuth credentials are stored in the site's WordPress options database; protect administrator and backup access. The plugin does not claim to encrypt those stored credentials. Commerce consent records store mobile number, optional email, consent text and UTC consent timestamp. Cart snapshots store standard product/variation IDs, selected variation attributes, quantities, amount, currency, a keyed session identifier, link seed, state and related order IDs. Queue records store recipient number, event, related object ID, state, safe error code, attempts and timestamps; full message text and API credentials are not written to queue logs. Recovery challenges use a separate recovery channel in the OTP table, with a keyed code hash, expiry, attempt count and consumption timestamp. Contact records reference the current recovery challenge and cart. Plaintext recovery codes are not stored in the local queue or database. The opt-out URL uses an action-specific HMAC and contains no phone/email. Only a verified recovery code can restore a cart or navigate to order payment; it does not grant account access. WooCommerce session cookies are used to associate an opt-in cart; no separate reminder tracking cookie or reminder IP history is created.

OTP records contain recipient HMACs, code HMACs, delivery channel, attempt counts and timestamps. Request-limit records contain HMAC-derived identity/IP keys, counts and expiry times. Cleanup runs daily through WP-Cron: code records older than one day and expired request limits are removed when that job executes. Traffic or a server cron is needed for scheduled cleanup. Optional diagnostic logging records only channel and error identifiers, without provider response bodies, credentials, phone numbers or codes. The administrator-only test tool can show a provider's response in its own browser session.

Google state and pending two-step sign-ins use ten-minute transients. Google state is bound to an HttpOnly SameSite=Lax browser cookie. Successful logins set normal WordPress authentication cookies. The browser may retain a non-sensitive post-login redirect marker for cache handling. See services.txt for the endpoint inventory and the Persian guide for setup.

Commerce data is removed by the daily cleanup after the configured 7–90 day retention window, subject to scheduler execution. Recovery eligibility expires after 1–30 days since saved activity, no longer than the retention period. Codes independently expire after 5–1440 minutes and newer codes replace older ones. Recovery request/guess limits use keyed identity/IP hashes in the existing security-limit table. The optional saved email allows WordPress personal-data export and erasure; administrators can erase phone-only local reminder data after verifying a request. Deletion does not recall SMS or delete data already held by a provider. Uninstall keeps settings and identity metadata unless the administrator first enables data deletion. With that option enabled, uninstall removes the plugin's tables, options and identity metadata; it does not delete WordPress users or WooCommerce orders. Minimal order-linked cart/idempotency metadata remains with the WooCommerce order. Network-wide activation and uninstall across multiple sites are not implemented; configure each site individually.

== Screenshots ==

1. Persian login form with SMS and email OTP support.
2. Persian administration settings and the permanent free-feature commitment.
3. Persian SMS provider settings with administrator-only delivery testing.
4. English login form with automatic LTR layout.

== Changelog ==

= 0.7.0 =

* Enable the direct IPPanel adapter in the public package for login and commerce messages.
* Add Panelchi approved-pattern delivery for OTP and multi-variable WooCommerce messages.
* Validate queued request status and identifiers; preserve separate login and recovery templates.
* Add automatic WordPress.org publishing for stable GitHub releases.

= 0.7.0 =

* Restrict public OTP and Google registration to Subscriber or Customer roles with basic read capabilities, including after role filters. Unsafe saved settings fall back to a checked Subscriber role; registration stops if that fallback is unsafe or missing.
* Run the WordPress account and authentication filters before issuing a passwordless session, honor denials, and reject a substituted user identity.
* Require a verified Google email at the account service boundary as well as the OAuth callback.

= 0.6.0 =

* Rename the product to Anar Toolkit and use the anar-toolkit text domain/release folder.
* Add optional payment confirmations and three-stage consent-based cart/unpaid-order recovery.
* Add multi-variable commerce templates for the existing SMS adapters, independent from OTP templates.
* Add a local atomic queue, per-phone caps, sending hours, single-use recovery codes, opt-out confirmation and masked reports.
* Add privacy exports, erasure, retention and explicit commerce service disclosures.
* Exclude bundled translation catalogs from the WordPress.org ZIP; development catalogs and a separate Persian language pack remain available.


= 0.5.1 =

* Prepare the public WordPress.org release with full service disclosures, GPL license, Persian setup guide and release packaging.
* Reserve SMS, email and Google request limits atomically to handle concurrent requests.
* Require HTTPS for SMS gateways and prevent credential forwarding through redirects.
* Keep provider response bodies out of optional diagnostic logs and honor authentication-blocking filters.
* Add the permanent 100%-free, all-features-included notice in the administration screen.

= 0.5.0 =

* Replaces every WooCommerce login form and the theme's login links (including WoodMart) with the passwordless form; wp-login.php optionally.
* Fixed the first page after sign-in occasionally showing the signed-out version.
* The form now shows the site's logo instead of the plugin's.

= 0.4.1 =

* The test SMS now uses the provider selected in the form, even before saving.
* Meli Payamak can also send from a dedicated sender number.

= 0.4.0 =

* Added WooCommerce integration for My Account and checkout.
* Added a login popup that loads on first click.
* Added optional two-step verification for password sign-ins.

= 0.3.1 =

* Added a custom gateway for any SMS panel with a web API.
* Assets now load in the head and only on pages with the form; the script is deferred and one query per page was removed.
* Fixed admin notices being squeezed into the settings header.

= 0.3.0 =

* Fixed a race that let parallel requests exceed the verification attempt limit.
* Fixed Persian digits in the code field, Meli Payamak failure detection, and custom provider settings not saving.
* Added auto-submit, Android SMS code filling, a test SMS tool, a phone-only mode, and friendlier Google sign-in errors.

= 0.2.0 =

* Added an English source interface and complete Persian translation.
* Added automatic LTR and RTL layouts with CSS logical properties.
* Added designer identity and author links.

= 0.1.0 =

* Initial OTP and REST API foundation.
* Added five Iranian SMS providers, email, and Google OAuth.
* Added the admin, login, and account interfaces.
