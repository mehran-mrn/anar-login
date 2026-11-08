=== Anar Toolkit – SMS Login, Recovery & Marketing for WooCommerce ===
Contributors: mrnwp
Tags: sms, woocommerce, abandoned-cart, otp, notifications
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SMS login, password reset, cart recovery, order updates and customer campaigns for WooCommerce. Includes Persian RTL and English LTR.

== Description ==

Anar Toolkit brings sign-in, purchase recovery and customer communication into one modular toolkit for WordPress and WooCommerce. Choose the capabilities your store needs and configure each one separately.

= Sign-in and account access =
* Iranian mobile-number registration and SMS login, email verification codes and Google sign-in.
* SMS password reset for a previously linked phone and optional two-step verification.
* Optional authenticator apps, recovery codes and passkeys; one shared form with three visual presets.
* Four-step setup guidance and a saved-form preview in the existing Settings page.
* On-demand login popup, My Account and classic checkout integration.

= Recover purchases and follow up with customers =
* Automatic abandoned-cart capture, three reminder stages and personal single-use discounts.
* Order status, shipment tracking, payment notices and administrator purchase/failure alerts.
* First-purchase, inactivity, VIP, birthday and next-purchase gift campaigns.
* Purchase-interval estimates, product offers and segmented scheduled bulk SMS.
* Verified stock alerts, post-purchase surveys, moderated reviews and customer referrals.
* Printable invoices, ten promotional games and actual-data cart, message and campaign reports.

= Persian included; enable only what you need =
The complete Persian (fa_IR) translation is bundled with the plugin. Set your WordPress site or user language to Persian for RTL forms, settings and contextual guides. No separate language ZIP is required. English LTR remains available.

Active features appear first. Inactive capabilities stay below with their guides and activation switches; their storefront services and files are not loaded. Administrator reports query only the selected section. New store and campaign modules start disabled.

Built-in SMS providers: Kavenegar, Meli Payamak, IPPanel, Panelchi, MaxSMS, Faraz SMS and SMS.ir, plus a custom HTTPS gateway. Use your own account and approved templates. Provider acceptance does not confirm handset delivery.

**100% free, forever, with all features included.** No Pro edition, plugin subscription, license key or feature paywall. SMS providers charge separately for their accounts and message credits.

[Persian feature and setup guide](https://github.com/mehran-mrn/anar-login/blob/main/docs/plugin-page-fa.md) | [Source and support](https://github.com/mehran-mrn/anar-login) | [Product website and Persian guide](https://mrnwp.ir/anar/) | [English product page](https://mrnwp.ir/anar/en/)

== Installation ==

1. Install and activate Anar Toolkit. If replacing a pre-release copy in an anar-login folder, deactivate that copy first.
2. For Persian, choose Persian in Settings > General or the user's Profile language. The translation is included in the plugin.
3. Open Anar Toolkit > Features. Select the capabilities you need, then use each active feature's Configure link. New store and campaign modules start disabled.
4. In Connection settings, enter your SMS provider credentials and approved template. Email uses your site's mail transport; Google sign-in needs your own OAuth web application.
5. Use [anar_account] for the account page, [anar_login] for a standalone form and [anar_login_button] for a popup button.
6. Configure each event's schedule, audience, approved template and exact provider variable names. Keep login and campaign templates separate. Recovery mappings require {code}, {recovery_page} and {unsubscribe_url}; marketing messages require {unsubscribe_url}, surveys require {action_url}, and discount messages require {coupon_code}.
7. Place [anar_recover_cart] beside Cart/Checkout blocks. Automatic capture uses a linked account or checkout phone with a visible notice and no separate checkbox. Explicit preference mode is optional; existing unsubscribes always remain effective.
8. Test delivery before replacing login or enabling two-step verification. Background reminders depend on Action Scheduler or WP-Cron. HTTPS is required for gateway endpoints.

[Read the Persian setup guide](https://github.com/mehran-mrn/anar-login/blob/main/guide-fa.txt).

== Frequently Asked Questions ==

= Is Persian supported without a separate download? =

Yes. Compiled Persian translations are included. Forms, settings, interface messages and contextual guides follow the WordPress site or user language and support RTL. Provider names, shortcodes and technical identifiers stay unchanged. English is also available. Official/custom language packs in WordPress's global languages folder keep their normal priority.

The directory's language badge reports approved translations on translate.wordpress.org. It is separate from the bundled translation. The directory requires an English source readme; the linked Persian guide covers setup and features in Persian.

= Is every feature free? =

Yes. No Pro edition, plugin subscription, license key or feature paywall exists. Your provider sets any account, template and SMS-credit charges. Email uses your existing mail service.

= Can I use only SMS login? =

Yes, including without WooCommerce. Enable only the required capabilities. Inactive features remain in the lower-priority catalog and do not run their storefront services or enqueue files. Turning a feature off keeps its configuration and records. Already-issued personal coupons retain their restrictions, historic opt-out links remain usable and scheduled retention cleanup continues.

= How do abandoned carts and discounts work? =

A linked account or checkout number identifies the saved standard cart. Up to three reminders follow configured inactivity delays. The customer enters a phone-bound recovery code to restore products or open an eligible unpaid order. Generated discounts are single-use, expiring and restricted to the billing phone. Codes never authenticate an account or bypass order access checks.

Standard simple/variable products use current prices and stock. Add-on metadata, bundles, subscriptions and custom-priced items are not recreated. Test your catalog. Recovery URLs carry no recovery code or account token. Provider variable length limits also apply to mapped URLs.

= Which order events can notify customers and administrators? =

Payment, processing/completion and other selected statuses, shipment tracking, administrator new-order and payment-failure notices have separate switches and templates. Enter the tracking number, carrier and HTTPS tracking URL in the order's Anar box. WooCommerce CRUD supports HPOS. Payment confirmation requires WooCommerce's payment-complete event; visiting the thank-you page or manually changing a status is not payment proof.

= How are bulk messages and surveys controlled? =

Bulk sending requires an audience preview and an explicit launch. Importing numbers alone sends nothing. Configure daily caps, message gaps and sending hours; pause or cancel remaining work. Marketing messages contain an unsubscribe link. Survey gifts do not require a positive rating; product reviews require moderation. Accepted means API acceptance, not handset delivery. Ambiguous requests are not automatically resent.

= Are purchase predictions and attributed sales guaranteed results? =

No. Predictions estimate purchase intervals from recorded history and are omitted when data is insufficient. Reports use actual local records. Coupon-attributed purchases do not prove incremental sales. Optional traffic statistics stay on your server and start disabled.

= Can a billing phone reset an account password? =

No. SMS reset requires a previously linked Anar or compatible verified identity number. Its five-minute code is separate from login/cart recovery codes. A successful reset revokes previous sessions. Public registrations can assign only Subscriber or WooCommerce Customer roles with basic read capabilities.

= Does it work with every checkout and theme? =

WooCommerce login integration uses My Account and classic checkout; it does not add a native Checkout Block login component. Place the recovery/preference shortcodes beside blocks. The optional WoodMart adapter still needs testing with your actual theme version and customizations. Keep a password login route available during setup.

= How do invoices, birthdays, referrals and games work? =

Customers print/save invoices as PDF from My Account or an expiring scoped link. Use [anar_customer_preferences] for birthday month/day, [anar_referrals] for referrals and [anar_game type="wheel"] for a game. Other types: scratch, boxes, cards, slots, treasure, daily, quiz, memory and milestone. Server-side rules enforce coupon rewards and daily limits; there is no paid entry or cash prize. Persian birthday dates require PHP Intl.

== External services ==

All integrations use accounts configured by the site administrator. The plugin makes no telemetry, licensing or analytics requests to MRNWP or its author. Provider service fees and contracts are independent of the free plugin.

SMS services are contacted when a visitor requests/resends a code, a password sign-in requires a second step, an administrator explicitly sends a test SMS, or an enabled module processes a queued order notification, purchase reminder or customer campaign. Commerce requests can run in the background. No delivery-status polling occurs. The selected provider receives the destination mobile number, the code or message, sender/template identifiers and the provider credentials needed for the request. For commerce, the provider receives configured template values which can include first name, order number, amount, currency, site name, recovery code, code validity, recovery entry-page URL and unsubscribe URL. An administrator notification contains the order variables and the administrator recipient number. The provider also sees the server IP address and standard HTTP metadata. The plugin User-Agent contains its name/version, not the site URL. A configured message may contain the site name or domain.

= Kavenegar =

Endpoint: https://api.kavenegar.com/v1/{your-api-key}/verify/lookup.json
Data: API key in the URL path; recipient, OTP and template name in the query string. Purpose and timing are described above.
Official service: https://kavenegar.com/وب-سرویس-پیامک.html
Official API documentation: https://kavenegar.com/rest.html
Official terms and limited account-data-use notice: https://console.kavenegar.com/membership/signup (open the public terms popover beside the consent checkbox; no account or acceptance is required to read it). The notice addresses account-verification documents. A separate privacy policy covering all SMS recipient/message processing has not been verified. This remains an external-documentation review dependency; the service homepage is not represented as a privacy policy.

= Optional SMS account balance monitoring =

The SMS credit feature is off by default. Enable it from the feature catalog or SMS credit page. An administrator can explicitly check a configured account, opt it into periodic monitoring and independently enable or disable low-credit warnings and set the threshold. Enabling periodic monitoring authorizes authenticated, server-side balance requests to that provider about every 30 minutes through WordPress scheduled tasks; actual timing depends on scheduled-task execution. No balance requests run on normal storefront requests or when rendering administrator pages.

The supported read-only endpoints are https://rest.payamak-panel.com/api/SendSMS/GetCredit (Meli Payamak sending username/password), https://api.kavenegar.com/v1/{API-KEY}/account/info.json, https://edge.ippanel.com/v1/api/payment/credit/mine, https://api.panelchi.com/account/info (Panelchi and MaxSMS), https://api.sms.ir/v1/credit and https://api.iranpayamak.com/ws/v1/account/balance (Faraz). The corresponding account API key/token authenticates each request. Faraz requires its new Api-Key separately from the legacy sending password. Meli Payamak affiliate credentials are not used. No recipient numbers, OTPs, order details or message bodies are sent for balance checks.

Only the validated amount, native unit, timestamps, a keyed account fingerprint and a generic error code are saved locally. Credentials remain in the existing private sending settings and are never included in balance-page scripts, notices or cached records. A failed request retains the last successful amount and marks it outdated/failed; it is not interpreted as zero credit. Account credential changes invalidate the old displayed balance. Thresholds use the displayed provider unit; currencies and SMS units are not silently converted. Only administrators with manage_options can configure or view balance information. No warning SMS or email is sent by this feature.

Provider terms and privacy references are listed in the corresponding service sections below. Additional balance documentation: https://github.com/Melipayamak/melipayamak-php | https://kavenegar.com/rest.html | https://ippanelcom.github.io/Edge-Document/docs/payment/my-credit/ | https://docs.panelchi.com/redoc/ | https://github.com/IPeCompany/SmsPanelV2.TypeScript | https://docs.farazsms.com/service/get-ws-v1-account-balance

= Meli Payamak =

Endpoints: https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber and https://rest.payamak-panel.com/api/SendSMS/SendSMS
Data: account username/password, recipient and template body ID plus OTP, or sender and configured message for dedicated-number delivery.
Official terms: https://www.melipayamak.com/rules/
A separate public privacy-policy link could not be verified during release preparation. Request the applicable privacy notice from Meli Payamak before using it.

= Optional Meli Payamak partner registration =

The Meli Payamak settings show the copyable account top-up discount code MPSMVDR and the direct affiliate link https://melipayamak.com/?aff=SMVDR. Anar receives commission from referred purchases and usage. Registration is optional; existing accounts and all other providers remain available. Listing Meli Payamak first does not change the saved provider.

Only when an administrator chooses registration, the plugin contacts https://mrnwp.ir/wp-json/mrnwp-partners/v1/melipayamak/products to check availability and plans. There are no automatic signup-service requests on page loads or provider selection. Without a configured service, valid plans or JavaScript support, the direct affiliate registration link is used.

After deliberately submitting the clearly labelled registration form, first name, last name, mobile number, plan code and a short-lived request ticket are sent to https://mrnwp.ir/wp-json/mrnwp-partners/v1/melipayamak/register. MRNWP forwards the four registration fields to https://affiliate.melipayamak.com/api/v1/affiliate/fast-register. It retrieves plans from https://affiliate.melipayamak.com/api/v1/affiliate/products using a private affiliate token and matching affiliate mobile held only on the MRNWP server. No affiliate token is shipped in this plugin, localized to JavaScript or requested from merchant sites.

MRNWP does not retain submitted names or raw phone numbers in its application records. It temporarily retains keyed abuse-prevention identifiers, request status and successful invoice links, with daily bounded cleanup of records older than one hour. Hosting access/security logs may separately retain connection metadata according to host policy. Payment happens at https://sign.melipayamak.com/invoice/ and is not confirmed by Anar. Account activation, service credentials and approved patterns remain separate. The discount code is for account top-ups and is copied manually; it is not sent to the registration API or applied to the registration invoice. Prices are displayed only if their currency has been confirmed by the service operator.

MRNWP privacy: https://mrnwp.ir/privacy/
Meli Payamak terms: https://www.melipayamak.com/rules/

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

= MaxSMS =

Endpoint: https://api.panelchi.com/sms/pattern (HTTPS POST).
MaxSMS uses the Panelchi-compatible pattern API documented on its official webservice page. The provider receives the recipient, approved pattern slug, pattern variables, source number and Bearer API token at the delivery times described above. MaxSMS settings are stored separately from Panelchi settings; selecting MaxSMS does not reuse a Panelchi account token or pattern automatically. Login and commerce patterns remain separate.
Official service: https://maxsms.co/
Official integration documentation: https://maxsms.co/webservice/
Shared API documentation: https://docs.panelchi.com/redoc/
Account activation and approved patterns are managed by the provider. Review the applicable MaxSMS service terms and privacy notice, including processing by the shared Panelchi infrastructure, before enabling delivery. A separate public privacy-policy URL has not been verified; the service and API documentation are not represented as privacy policies.

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

User account details use normal WordPress records. Verified phones, Google identifiers and optional avatar URLs use user metadata. Provider credentials are stored in the site's options database; the plugin does not claim to encrypt them. Protect administrator and backup access.

Cart snapshots contain standard product/variation IDs, quantities, totals, currency and keyed session/order references. Local tables store contact preferences and the customer facts needed by enabled campaigns. Authentication codes use keyed hashes, expiration and atomic guess limits. Queue logs exclude plaintext codes, full messages and credentials; the optional login log records outcome metadata.

Cart/message retention is 7–90 days and campaign activity retention is 7–365 days. Scheduled cleanup needs a working cron. WordPress privacy export/erasure and administrator phone erasure are available after verifying the requester. A minimal keyed suppression record preserves an unsubscribe after identity erasure. WooCommerce orders and provider-side data are separate.

Optional first-party traffic uses a random browser identifier for up to 30 days, daily hashes and the referring hostname. No raw IP or full referrer URL is stored and no external analytics service is used. Birthdays store month/day without year. WordPress and WooCommerce handle account/session cookies.

Uninstall preserves data unless deletion was explicitly enabled beforehand. It does not remove WordPress users or WooCommerce orders. Network-wide activation/uninstall is not implemented; configure each site independently. See services.txt and the Persian guide for details.

== Screenshots ==

1. Persian administration and service connection settings.
2. Persian RTL login form using the site's identity.
3. SMS provider configuration and administrator-only sending test.
4. English login form with LTR layout.

= Can game discounts have a monetary budget? =
Yes. Set a budget per pool and a maximum money discount for each prize. Amounts use the current store currency and include the tax reduction. The maximum cost is reserved at issuance; a paid order consumes its actual saving and releases the remainder. Unused expired gifts release their reservation. Saved unpaid orders retain it to cover late payments. Refunds do not reactivate consumed gifts or replenish spent money. Earlier uncapped coupons remain unchanged and outside this budget. Capped calculations require WooCommerce 8.8 or later.

== Changelog ==

= 1.3.0 =
* Add optional authenticator enrollment with a local QR code, recovery codes and account security management.
* Add optional passkey registration, sign-in and management; load its verifier and browser adapter only when used.
* Share the sign-in design across verification and account security, with three form styles.
* Add a four-step setup guide and an inert preview of the saved form within Settings.
* Preserve ordinary OTP/Google sign-in and consolidate password second-step handling.


= 1.2.4 =
* Place order message editors next to their notification switches and recovery editors next to each reminder time.
* Remove the redundant store template tab while preserving its bookmarked route.
* Add verification-message controls to games and stock settings; retain the shared editor and central message catalog.

= 1.2.3 =
* Move Messages and templates into shared Settings and retain old bookmarked links.
* Edit messages in place with one accessible dialog across login, commerce and marketing settings.
* Preserve parent forms, search and scroll; show validation and unsaved changes inside the editor.
* Unify admin controls and add a full-screen mobile editor with a responsive message list.

= 1.2.2 =
* Keep monetary gift coupons safe when Anar is deactivated, removed or skipped.
* Upgrade earlier capped coupons to persistent protected storage while retaining their ordinary active display.

= 1.2.1 =
* Add a central message catalog and editor with contextual module links and independent security-message templates.
* Add explicit delivery modes, named/ordered variable mapping, sample previews, per-message tests and gift/no-gift bindings.
* Preserve legacy settings, protect concurrent message edits and require review after a provider account changes.
* Redesign all ten games with three palettes, actual wheel/reel/card/box animations and scratch coverage.
* Add independent game quotas, weighted prize tiers, shared inventory, winner/percentage-point caps and safe request recovery.
* Add real monetary budgets and per-coupon ceilings, including tax savings, for classic and block checkout; settle payments and unused expiry safely.
* Keep one visible Features menu entry while retaining older bookmarked catalog URLs.

= 1.1.1 =
* Let signed-in existing customers verify and link their phone directly from a game.
* Clarify shared daily limits and separate quiz settings from each game’s display text.
* Add editable game titles, prize labels and result messages, a labeled wheel and hover/focus info popups.

= 1.1.0 =
* Add optional cached SMS credit monitoring for the supported providers.
* Let administrators independently control periodic checks, warning visibility and thresholds per account.
* Preserve the last successful balance on errors, separate locked credit, and invalidate changed-account results.

= 1.0.0 =
* Simplify Meli Payamak signup by replacing the separate checkbox with deliberate form submission.
* Show the copyable MPSMVDR code for account top-ups, separately from registration payment.

= 0.8.4 =
* Add a copyable Meli Payamak discount code and a disclosed affiliate signup link.
* Offer optional administrator-only quick registration when the MRNWP signup service is configured; otherwise open the referral link.
* Keep affiliate credentials outside the distributed plugin and validate payment links against the official invoice host.

= 0.8.3 =
* Link the dedicated bilingual Anar product pages from plugin metadata, administration and the WordPress.org listing.

= 0.8.2 =
* Add MaxSMS as a separate provider for login, password recovery, store notifications and customer campaigns.
* Share the Panelchi-compatible transport while keeping MaxSMS credentials and patterns independent.
* Include Persian guidance and transport, credential-isolation and actual-package regression checks.

= 0.8.1 =
* Include complete Persian runtime catalogs so Persian installations work without a separate language ZIP.
* Refresh the listing, setup and FAQ for the expanded feature set and link the full Persian guide.
* Preserve normal official/custom language-pack priority and test locale switching and PHP/MO formats.

= 0.8.0 =
* Add SMS password reset, automatic cart capture, personal discounts and order/shipment events.
* Add lifecycle, prediction, offers, bulk, stock, surveys, gifts, invoices, games, referrals and local insights.
* Add active-first feature management, real-data reports and Persian contextual guides.

Full history: https://github.com/mehran-mrn/anar-login/blob/main/CHANGELOG.md

== Upgrade Notice ==

= 1.3.0 =
Existing sign-in settings are retained. Passkeys are off by default. Advanced account factors currently support single-site WordPress over HTTPS; passkeys additionally require PHP 8.0+. Customers without an enrolled authenticator keep their usual sign-in flow.


= 1.1.1 =
Existing accounts can verify a phone without signing out. Games share the daily attempt limit; each game now has separate display text settings.

= 1.1.0 =
SMS credit monitoring is optional and off by default. Enable it, check an account, then choose its warning threshold. Faraz balance checks need the new account API key.

= 1.0.0 =
The signup form is shorter. The Anar discount code is for account top-ups and can be copied from the Meli Payamak settings or signup dialog.

= 0.8.4 =
Meli Payamak settings now include the Anar discount code and optional partner signup. Existing delivery settings remain unchanged.

= 0.8.3 =
The dedicated Anar product pages now include features, setup guidance and the stable latest-version download.

= 0.8.2 =
MaxSMS is now available in the provider lists. Configure your MaxSMS account token and approved patterns separately.

= 0.8.1 =
Persian translations are now included. Choose Persian for the site or user; a separate language ZIP is no longer required.
