=== Anar Toolkit – SMS Login, Recovery & Marketing for WooCommerce ===
Contributors: mrnwp
Tags: sms, woocommerce, abandoned-cart, otp, notifications
Requires at least: 6.2
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 1.15.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SMS login, password reset, cart recovery, order alerts and customer campaigns for WooCommerce.

== Description ==

Anar Toolkit adds modular sign-in, purchase recovery and customer communication tools to WordPress and WooCommerce. Enable only the capabilities your store needs.

**Questions, problems, or suggestions?** Visit the [Anar homepage and support discussion](https://mrnwp.ir/anar/#respond). I will do my best to help you and resolve your issue as quickly as possible. Email and phone are optional and are not displayed publicly. You can ask a question without leaving a rating.

= Sign-in and accounts =
* Iranian SMS codes, email codes, Google sign-in and optional Bale OTP.
* SMS password recovery, two-step verification, authenticator apps and passkeys.
* Verified mobile-number changes, adaptive bot checks and optional MRN Forms registration.
* On-demand popup, WooCommerce account/checkout integration and block-editor blocks.
* Elementor widgets for sign-in, the account button and cart recovery.

= Store and marketing tools =
* Abandoned-cart capture, three reminder stages and single-use recovery coupons with expiry and optional tax-inclusive discount ceilings.
* Order, payment, shipment and administrator notifications.
* Lifecycle, birthday, VIP and next-purchase campaigns; segmented bulk messages and product suggestions.
* Stock alerts, surveys, referrals, promotional games and local sales/traffic reports.
* Simple-print and branded invoices with display controls and protected sample/order previews.

= Setup and language =
Manage capabilities inside Settings. Configure SMS, email and messenger connections separately. Choose SMS, Email and/or Bale for sign-in. All SMS features use the single provider selected in Service providers. Customer-facing features load their files only when needed.

Persian RTL translations are bundled; English LTR is supported. Anar is 100% free, forever; SMS providers charge separately.

[Setup guide](https://github.com/mehran-mrn/anar-login/blob/main/guide-fa.txt) | [Source and support](https://github.com/mehran-mrn/anar-login) | [Product website](https://mrnwp.ir/anar/)

انار جعبه‌ابزار ورود و ثبت‌نام پیامکی، بازیابی سبد خرید رهاشده، بازاریابی پیامکی و بازی‌های آنلاین فروشگاهی برای وردپرس و ووکامرس است. پنل تنظیمات و فرم‌ها به فارسی راست‌چین و انگلیسی چپ‌چین در دسترس‌اند. هر قابلیت را می‌توان جداگانه فعال کرد.

سؤال، مشکل یا پیشنهاد خود را از [بخش پرسش‌وپاسخ صفحه اصلی انار](https://mrnwp.ir/anar/#respond) ارسال کنید. تمام تلاشم را می‌کنم تا در سریع‌ترین زمان ممکن به شما کمک کنم و مشکلتان را برطرف کنم. ایمیل و شماره تماس اختیاری و خصوصی‌اند؛ برای پرسیدن سؤال نیازی به ثبت امتیاز ندارید.

== Installation ==

1. Install and activate Anar Toolkit. If replacing a pre-release copy in an anar-login folder, deactivate that copy first.
2. For Persian, choose Persian in Settings > General or the user's Profile language. The translation is included in the plugin.
3. Open Anar Toolkit > Features. Select the capabilities you need, then use each active feature's Configure link. New store and campaign modules start disabled.
4. In Service providers, select one SMS provider and enter its connection credentials. In Sign-in settings, select SMS, Email and/or Bale and configure the SMS code message. Email uses your site's mail transport; Google sign-in needs your own OAuth web application.
5. Use [anar_account] for the account page, [anar_login] for a standalone form and [anar_login_button] for a popup button.
6. Configure each event's schedule, audience, approved template and exact provider variable names. Keep login and campaign templates separate. Recovery mappings require {code}, {recovery_page} and {unsubscribe_url}; marketing messages require {unsubscribe_url}, surveys require {action_url}, and discount messages require {coupon_code}.
7. Place [anar_recover_cart] beside Cart/Checkout blocks. Automatic capture uses a linked account or checkout phone with a visible notice and no separate checkbox. Explicit preference mode is optional; existing unsubscribes always remain effective.
8. Test delivery before replacing login or enabling two-step verification. Background reminders depend on Action Scheduler or WP-Cron. HTTPS is required for gateway endpoints.

[Read the Persian setup guide](https://github.com/mehran-mrn/anar-login/blob/main/guide-fa.txt).

== Frequently Asked Questions ==

= Where can I ask a question or share a suggestion? =

Open Anar Toolkit > Support and suggestions, or visit the [discussion section on the Anar homepage](https://mrnwp.ir/anar/#respond). Describe the problem and the plugin version you use. Replies appear under your question. Email and phone are optional and private; your name and message become public after approval.

= Is Persian supported without a separate download? =

Yes. Compiled Persian translations are included. Forms, settings, interface messages and contextual guides follow the WordPress site or user language and support RTL. Provider names, shortcodes and technical identifiers stay unchanged. English is also available. Official/custom language packs in WordPress's global languages folder keep their normal priority.

The directory's language badge reports approved translations on translate.wordpress.org. It is separate from the bundled translation. The Persian setup guide covers configuration and features in more detail.

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

= Elementor widgets and printable invoices =
Three optional Elementor widgets reuse Anar's sign-in form, account button and cart recovery form. Elementor is not required for other features. Enable the corresponding Anar capability before using its widget.
Choose a simple-print or branded invoice, adjust its accent color, logo URL and footer, and choose whether to show product SKUs, shipping addresses and customer notes. Save changes before opening the invoice preview. An empty preview order ID uses sample data; a real order ID is accessible only to an authorized store manager.
Recovery coupons support an optional maximum monetary discount including tax (0 means unlimited). Capped recovery coupons require WooCommerce 8.8 or later. Coupon expiry is measured from issuance and is not renewed by subsequent reminders.

== External services ==

= Bale Safir OTP (optional) =
Bale is used only for verification codes when selected by the administrator. SMS and Bale can send the same code for one mobile challenge; email goes only to the address entered. Bale is not used for store or marketing messages. For legacy v2 accounts, Anar sends the configured client ID and secret to https://safir.bale.ai/api/v2/auth/token and temporarily caches the access token. It sends the international phone and numeric OTP to https://safir.bale.ai/api/v2/send_otp with that token. Existing v3 accounts remain usable for OTP: the configured API key goes in the header, and the bot ID, phone, request ID and OTP go to https://safir.bale.ai/api/v3/send_message. A Bale account is needed to receive the code. A failure in one enabled mobile sender does not stop the other. Uncertain delivery keeps the challenge valid. [Bale documentation](https://docs.bale.ai/safir).


All integrations use accounts configured by the site administrator. The plugin makes no telemetry, licensing or analytics requests to MRNWP or its author. Provider service fees and contracts are independent of the free plugin.

SMS services are contacted when a visitor requests or resends a code for sign-in or a connected MRN Forms form, a password sign-in requires a second step, an administrator explicitly sends a test SMS, or an enabled module processes a queued order notification, purchase reminder or customer campaign. Commerce requests can run in the background. No delivery-status polling occurs. The selected provider receives the destination mobile number, the code or message, sender/template identifiers and the provider credentials needed for the request. For commerce, the provider receives configured template values which can include first name, order number, amount, currency, site name, recovery code, code validity, recovery entry-page URL and unsubscribe URL. An administrator notification contains the order variables and the administrator recipient number. The provider also sees the server IP address and standard HTTP metadata. The plugin User-Agent contains its name/version, not the site URL. A configured message may contain the site name or domain.

= Kavenegar =

Endpoint: https://api.kavenegar.com/v1/{your-api-key}/verify/lookup.json
Data: API key in the URL path; recipient, OTP and template name in the query string. Purpose and timing are described above.
Official service: https://kavenegar.com/وب-سرویس-پیامک.html
Official API documentation: https://kavenegar.com/rest.html
Official terms and limited account-data-use notice: https://console.kavenegar.com/membership/signup (open the public terms popover beside the consent checkbox; no account or acceptance is required to read it). The notice addresses account-verification documents. A separate privacy policy covering all SMS recipient/message processing has not been verified. This remains an external-documentation review dependency; the service homepage is not represented as a privacy policy.

= Optional SMS account balance monitoring =

An administrator can explicitly click Check connection in Service providers to validate the entered account credentials through its credit endpoint, even when credit monitoring is off. This check does not save settings or send an SMS. Unsupported providers can be tested through a configured message instead.

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

= 1.15.1 =
* Hide disabled capabilities' dedicated settings links while keeping settings shared by active capabilities available.
* Update the Anar workspace and WordPress submenus immediately after feature changes, including recovery from a lost save response.
* Keep bookmarked or remembered disabled settings sections from reopening; preserve saved settings and restore navigation when re-enabled.

= 1.15.0 =
* Add on-demand account mobile-number checks and previewed Digits migration.
* Keep existing accounts and source data; hold conflicts and changed records for review.
* Hide migration without unmatched Digits records or after completion; no frontend scans or background migration jobs.

= 1.14.1 =
* Add a dedicated support page and direct links to questions and suggestions on the Anar homepage.
* Explain optional private contact details and rating-free questions; include Persian support translations and a homepage link in the installed-plugin description.

= 1.14.0 =
* Add a dedicated SMS test workspace, free saved-configuration checks and a support report without credentials.
* Refresh the admin light header, colored shortcuts and mobile navigation. Preserve independent saves and existing account recovery.

= 1.13.1 =
* Refresh active navigation, enabled switches, provider cards and admin buttons with the shared rose palette.


= 1.13.0 =
* Add confirmed recovery codes independent of SMS, first-login recovery setup and a hosting recovery command.
* Keep verification usable during delivery outages and require recovery before new MFA activation.
* Queue feature changes, consolidate cancellation queries and improve internal navigation.
* Place reminder schedules, messages and discounts beside abandoned carts and refresh the admin and provider cards.


= 1.12.0 =
* Unify administration headers and contextual navigation across settings, store, reports, features and credit.
* Keep specialized workflows and independent saves; remove unrelated invoice reports and repeated field guidance.
* Refine store settings layout and mark Meli Payamak as recommended.

= 1.11.0 =
* Save feature switches immediately with inline status and conflict handling.
* Save provider connections and OTP generation independently so hidden logo fields cannot block them.
* Separate login messages from code generation and add connection checks and fixed message shortcuts.

= 1.10.0 =
* Use one active SMS provider throughout the toolkit. Review message templates after changing provider.
* Clean settings pages with anchored contextual help, simpler feature cards and registration setup.

= 1.9.1 =
* Fill missing Persian interface strings from the bundled catalog while preserving installed language-pack translations.
* Move saved-form preview beside core status and developer credits below settings.
* Compact the cached SMS credit summary and use Anar's primary color for settings navigation.

= 1.9.0 =
* Simplify sign-in to SMS, Email and Bale choices; SMS and Bale share one OTP without priorities or backups.
* Group sender connections into SMS, email and messengers; restrict Bale to verification codes.
* Add a shared SMS default, configured-first provider lists, incomplete-connection warnings and saved usage summaries.
* Preserve existing credentials and explicit message bindings; update the Persian setup guide.


= 1.8.0 =
* Separate provider connections from General sign-in delivery and OTP controls while retaining the original provider cards.
* Add linked email/mobile and Bale/SMS simultaneous delivery, with independent mobile fallback.
* Support optional Safir v3 OTP, plain text and template messages for store and club use. Existing v2 OTP accounts remain supported.


= 1.7.0 =
* Add optional Bale/SMS delivery priorities with one shared code, safe fallback and preserved resend limits. Existing installations keep single-service delivery.
* Simplify choosing the SMS fallback panel and explain simultaneous mobile/email sign-in and delivery priorities in the setup guide.
* Preserve independent verification, email and commerce message settings and include Persian translations.

= 1.6.2 =
* Match scrollbars to Anar dialogs in administration, Meli Payamak signup, sign-in popups and saved-form previews while retaining scrolling and leaving unrelated page scrollbars unchanged.


= 1.6.1 =
* Shorten the directory description so WordPress retains all service and privacy disclosures without truncation.


= 1.6.0 =

* Move feature switches into Settings and group store recovery, message and discount controls.
* Let store messages follow the sign-in provider by default while preserving explicit provider and message bindings.
* Add recovery discount ceilings including tax, retaining coupon expiry and single-use restrictions.
* Add optional Bale Safir OTP delivery with cached access tokens.
* Add Elementor sign-in, account-button and purchase-recovery widgets using existing forms.
* Add simple-print and branded invoice templates, display controls and administrator-only sample/order previews.


= 1.5.9 =

* Redesign the Features screen with a distinct color and icon for each capability and a layout that adapts to smaller screens.
* Explain what each capability does in Persian and English and show its current state on an accessible switch.

= 1.5.8 =

* Preselect Meli Payamak on fresh and unconfigured installations and highlight the selected SMS provider.
* Open the message editor with a visible notice when the SMS provider selection is unsaved.
* Use a compact editor with segmented delivery choices, an accessible variable list and a prominent confirmation before discarding edits.

= 1.5.7 =

* Open the SMS editor with the saved provider selected and preserve existing message-specific providers.
* Show only the delivery methods and fields supported by the selected provider.
* List all available variables for each message and mark required values.

= 1.5.6 =

* Refresh the WordPress.org banners and search icon, and use a transparent Anar mark in the WordPress admin.

= 1.5.5 =
* Add an administrator dashboard widget with feature status and recorded SMS outcomes for today and the last seven days.
* Show saved SMS balances in compact rows with low-credit warnings on the dashboard and relevant Anar screens.

= 1.5.4 =
* Add the Persian name, a bilingual summary, and a Persian feature overview to the WordPress.org listing.

= 1.5.3 =
* Add a labeled registration link for SMS.ir in its SMS provider settings.

= 1.5.2 =
* Add a labeled registration link for IPPanel in its SMS provider settings.

= 1.5.1 =
* Show the loading indicator inside the sign-in popup in the configured brand color.
* Fix the phone and email input styling, including icon spacing in RTL.
* Show password recovery when the popup offers password sign-in and SMS password recovery is enabled.

= 1.5.0 =
* Add registration with custom fields from MRN Forms and reuse Anar’s SMS verification service for regular forms.
* Show loading feedback on login popup clicks and download the popup assets alongside the form.

= 1.4.2 =
* Display Meli Payamak signup plans as selectable cards with the current price and rate per SMS when the currency is confirmed.
* Filter digits from first and last name fields and reject invalid names before forwarding a registration request.

= 1.4.1 =
* Keep the verified Anar number authoritative for sign-in and password reset after a mobile-number change. Digits metadata remains a migration fallback for accounts without a linked Anar number.
* Refresh the plugin description and the Persian setup guide.

= 1.4.0 =
* Send sign-in, order and campaign SMS through one transport per provider and every one-time code through one shared service.
* Add an invisible, self-hosted bot check for public code requests, adaptive by default, to stop SMS floods.
* Read the real visitor address behind ArvanCloud, Cloudflare or a load balancer, and share one hourly code allowance per mobile number.
* Let customers change their mobile number with verification and sign out other devices from account security.
* Add optional per-role sign-in destinations, an optional name step after sign-up, and block editor blocks for the sign-in form, button and SMS password reset.
* Load the popup loader as a deferred cached file and record traffic insights through a lightweight REST beacon.

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

= 1.15.1 =
Disabled capabilities now hide their dedicated settings links immediately. Saved settings are retained, and shared settings remain available to active capabilities.

= 1.15.0 =
Open User tools to check mobile numbers. Digits migration appears only while unmatched source records remain and no completed migration receipt exists. Check the preview before transferring; accounts and source data are preserved.

= 1.14.1 =
Adds the Anar support page, homepage discussion links and Persian guidance for questions and suggestions.

= 1.14.0 =
Existing settings are retained. Open Test and troubleshoot to check the saved SMS configuration before sending a test.

= 1.10.0 =
All SMS now uses the selected provider in Service providers. Retired per-feature choices no longer route messages. Review and save templates belonging to another provider before sending.

= 1.9.0 =
Review the SMS, Email and Bale choices in General after updating. Former mobile priority routes now send the same OTP through SMS and Bale together. Bale is OTP-only. Existing credentials and explicit SMS selections are retained.

= 1.5.1 =
Enable “Password sign-in in the popup” in Anar settings if you want to offer password sign-in alongside one-time codes.

= 1.5.0 =
To use custom registration or SMS verification for forms, update MRN Forms to 1.5.0 or later.

= 1.4.2 =
Meli Payamak quick signup displays plan cards and validates names before registration.

= 1.4.1 =
Update if you migrated from Digits and use mobile-number changes. Anar now uses the current linked number for sign-in and password reset.

= 1.4.0 =
Existing settings are retained. The invisible bot check starts in adaptive mode. Sites behind a CDN should choose the visitor address header in Settings, Security. Clear the page cache after updating.

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
