# Changelog

## 1.2.0 — 2026-11-08

* Centralize SMS configuration in Messages and templates while retaining module timing, audiences and rewards.
* Support per-purpose authentication templates, explicit text/template delivery, ordered variable mapping, sample previews and exact-message tests.
* Add separate gift/no-gift bindings and shared campaign settings.
* Retain legacy configuration without destructive migration; isolate message writes and invalidate reviewed bindings after account changes.
* Redesign all ten games with themed SVG/CSS surfaces, a multi-segment rotating wheel, real scratch coverage, opening boxes, flipping cards, sequential reels and a server-driven memory board.
* Add shared/per-game/combined quotas, named weighted prize tiers, shared or dedicated inventory, winner/percentage-point caps and campaign dates.
* Preserve earlier issuance totals; serialize reservations and recover interrupted requests without spending another attempt or issuing another coupon.

* Add monetary prize budgets, gross coupon ceilings, payment settlement and safe expiry reconciliation for classic and block checkout.
* Show the feature catalog once in the admin menu; retain alternate bookmarked routes without another visible entry.

## 1.1.1 — 2026-11-08

* Add in-page SMS phone verification for existing signed-in accounts without creating or switching accounts.
* Bind verification codes to the current account and session, enforce expiry and limits, and preserve existing phone ownership.
* Separate shared reward settings from per-game text and quiz-only question fields.
* Add editable gift names and result messages, label wheel outcomes and align the pointer with the issued reward.
* Show contextual info on hover, keyboard focus or touch, with Escape dismissal.

## 1.1.0 — 2026-11-08

* Add optional account credit monitoring with native provider units, cached status and administrator-only balance pages.
* Add per-account periodic checks, optional low-credit warnings and numeric thresholds; inactive features stay in the secondary catalog.
* Keep provider credentials outside cached records, preserve successful values on connection failures and reject stale or changed-unit warnings.
* Stop all scheduled checks when the feature or plugin is disabled.

## 1.0.0 — 2026-11-07

* Remove the separate signup consent checkbox and lengthy explanation; keep clear form submission and service/privacy links.
* Present MPSMVDR as a copyable account top-up discount code, separately from registration invoices.

## 0.8.4 — 2026-11-07

* Add the Meli Payamak Anar-user discount badge, copyable MPSMVDR code and disclosed referral signup.
* Add an opt-in registration dialog backed by the private MRNWP service; an unavailable service falls back to the referral website.
* Preserve the selected SMS provider and isolate registration from delivery credentials and checkout.

## 0.8.3 — 2026-11-07

* Publish the dedicated Persian and English Anar product pages at mrnwp.ir/anar/.
* Link the product website from the plugin header, administration credits and public listing.

## 0.8.2 — 2026-11-07

* Add MaxSMS to login, password recovery, store and campaign provider selections.
* Reuse the Panelchi-compatible pattern transport with separate MaxSMS credentials and templates.
* Add Persian guidance and regressions for OTP, multi-variable commerce delivery, rejected responses and credential isolation.

## 0.8.1 — 2026-11-06

* Bundle and register complete Persian runtime catalogs so WordPress.org installs follow the site/user language without a separate download.
* Preserve official/custom language pack priority and English LTR operation.
* Rewrite the public listing, setup and FAQ for Persian-speaking store owners and the expanded feature set.
* Verify fresh-install localization, locale switching and both PHP/MO catalog formats.

## 0.8.0 — 2026-11-06

* Add SMS password reset, automatic cart capture without a separate checkbox, single-use recovery gifts and HPOS order/shipment events.
* Add lifecycle, purchase prediction, smart offers, segmented scheduled bulk SMS, stock subscriptions, honest surveys, next-purchase and birthday gifts, printable invoices, promotional games and customer referrals.
* Add real-data recovery and campaign dashboards, masked message reports, optional first-party traffic insights and expanded privacy/retention controls.
* Keep new campaign modules disabled until configured; add regression checks and Persian translations.
* Load runtime services only for enabled capabilities, query only the selected admin report, and keep inactive capabilities in a lower-priority catalog with concise contextual guides.


## 0.7.0 — 2026-11-06

* Enable the direct IPPanel adapter in the public package for login and commerce messages.
* Add Panelchi approved-pattern delivery for OTP and multi-variable WooCommerce messages.
* Validate queued request status and identifiers; preserve separate login and recovery templates.
* Add automatic WordPress.org publishing for stable GitHub releases.

* Restrict public OTP and Google registration to Subscriber or Customer roles with basic read capabilities, including after role filters. Unsafe saved settings fall back to a checked Subscriber role; registration stops if that fallback is unsafe or missing.
* Run the WordPress account and authentication filters before issuing a passwordless session, honor denials, and reject a substituted user identity.
* Require a verified Google email at the account service boundary as well as the OAuth callback.

## 0.6.0 — 2026-10-29

* Rename the product to Anar Toolkit and use the anar-toolkit text domain/release folder.
* Add optional payment confirmations and three-stage consent-based cart/unpaid-order recovery.
* Add multi-variable commerce templates for the existing SMS adapters, independent from OTP templates.
* Add a local atomic queue, per-phone caps, sending hours, single-use recovery codes, opt-out confirmation and masked reports.
* Add privacy exports, erasure, retention and explicit commerce service disclosures.
* Exclude bundled translation catalogs from the WordPress.org ZIP; development catalogs and a separate Persian language pack remain available.

## 0.5.0 — 2026-10-23

### Replacing every login

- New **Replace the theme's login** setting (on by default): all WooCommerce login forms — including `woocommerce_login_form()` and theme overrides of it — show the passwordless form, and theme login links for signed-out visitors open the login popup
- WoodMart: the header dropdown and side-panel password forms are removed; the account icon, mobile toolbar and side-panel opener open the popup
- New **Also replace wp-login.php** setting (off by default) with a "Sign in with a password" link that always keeps the original form reachable
- Popup and shared forms include a "Sign in with a password instead" link; after sign-in the visitor lands on the page the link pointed to

### Fixes

- The first page after signing in could show the signed-out version: WordPress speculative loading prefetched it on pointer-down. Login targets are now excluded from prefetching for visitors, and the sign-in response sends `Clear-Site-Data: "prefetchCache", "prerenderCache"`
- The plugin's own logo is no longer shown on the frontend

### Branding

- New **Form logo** setting: site logo (WoodMart header logo, Customizer logo, or site icon), custom image with media picker, lock icon, or none; wide logos keep their proportions, and an image logo replaces the site-name text
- The inline loader is about 1.7 KB gzip, up from 1.1 KB, as it now recognises theme login links

## 0.4.1 — 2026-10-23

- **Test SMS uses the form as it is now:** the selected provider and entered credentials are tested before saving, nothing is stored, and blank secret fields fall back to the saved values. Previously the saved provider was tested, so choosing Meli Payamak without saving reported a Kavenegar error
- **Meli Payamak sender number:** besides the shared service line with a template (BaseServiceNumber, recommended), plain-text sending from a dedicated number (SendSMS) is now supported

## 0.4.0 — 2026-10-23

### WooCommerce

- My Account: the passwordless form replaces WooCommerce's login and register forms (or sits above them), with a "Sign in with a password instead" fallback
- Checkout: "Returning customer?" opens the code form and returns the customer straight to checkout; follows WooCommerce's login reminder setting
- The verified mobile fills an empty billing phone; new users get the `customer` role; My Account is the default destination after sign-in
- `woocommerce_login_redirect` is applied, and the form's assets load in `<head>` on these pages
- Everything is loaded only when WooCommerce is active

### Login popup

- Opens from any `#anar-login` link (for example a menu item), elements with `anar-login-trigger`, or the new `[anar_login_button]` shortcode
- Pages carry only an inline loader of about 1.1 KB gzip; the form, CSS and JS are fetched on first click and preloaded on hover or focus
- Native `<dialog>` with focus handling, Esc and backdrop close; after sign-in the visitor stays on the same page
- `[anar_login redirect="..."]` attribute for any form

### Two-step verification

- Optional second step for password sign-ins (wp-login.php, WooCommerce, anything using `wp_signon`) for privileged users or everyone
- The code goes to the linked mobile, or the account email when no mobile is linked; the password session is revoked until the code is verified
- Code and Google sign-ins skip it, as they already prove ownership; XML-RPC password sign-in is refused for affected accounts
- Hooks: `anar_login_two_factor_required`, `anar_login_two_factor_passed`, `anar_login_verified_session`

### Other

- Settings are autoloaded, so ordinary page views still run no Anar Login queries with every feature enabled
- New hooks: `anar_login_enqueue_assets`, `anar_login_default_redirect`

## 0.3.1 — 2026-10-23

### Added

- **Custom gateway** for any SMS panel with a web API: endpoint URL, GET/POST, form or JSON body, a raw JSON template for nested APIs, headers, four recipient formats, and a success-text check. TLS verification stays on and only 2xx responses count as delivered
- Provider fields can now be text, URL, textarea, or select, with placeholders and help text; plain label strings keep working

### Performance

- Assets are registered globally but enqueued only where `[anar_login]` or `[anar_account]` is used; in post content the stylesheet now prints in `<head>`, removing the flash of unstyled content
- The frontend script loads with the `defer` strategy
- SMS providers are built lazily on first use instead of on every request
- The schema version option is autoloaded, removing one database query from every page
- Settings defaults are memoized per request

### Fixes

- Admin notices from WordPress and other plugins were squeezed into the settings header; they now stack above the settings card
- The "Core is active" badge no longer wraps into several lines in Persian
- Themes can now register providers from `functions.php`, which loads after `plugins_loaded`

## 0.3.0 — 2026-10-23

### Security

- Verification attempts are now reserved with one conditional `UPDATE`, so parallel requests can no longer exceed the per-code attempt limit
- OTPs are stored as a keyed HMAC bound to the recipient and compared with `hash_equals` (codes from 0.2.0 still verify during the upgrade)
- Roles that can manage the site, users, plugins, or unfiltered HTML can no longer be chosen as the registration role
- Google sign-in uses PKCE; the state stays bound to an HttpOnly cookie but no longer to the IP address, which failed on mobile networks
- New phone accounts get a random username instead of the phone number, so numbers no longer leak through usernames or author URLs (`anar_login_new_user_login` restores custom schemes)
- New `anar_login_can_authenticate` filter and multisite spam check before a session is created

### Fixes

- Persian and Arabic digits typed into the code field were stripped, making the code look incomplete
- Meli Payamak error responses were reported as successful deliveries
- Settings of providers added with `anar_login_sms_providers` were dropped on save
- Bare 10-digit numbers such as `989xxxxxxxx` were truncated during normalization
- A failed SMS or email delivery no longer locks the user out for the whole resend cooldown
- Sign-in requests no longer send a REST nonce, so a stale nonce from a cached page cannot block a login
- Translations and upgrades now load on `init`, avoiding the WordPress 6.7 early-translation notice

### Experience

- The code is submitted automatically once complete, and pasted text such as "Code: 12345" is reduced to its digits
- Android Chrome can fill the code from the SMS (WebOTP); iOS one-time-code suggestions keep working
- New sign-in mode setting: mobile or email, mobile only (numeric keypad), or email only
- `redirect_to` is honored and core's `login_redirect` filter is applied, for both OTP and Google sign-in
- Google sign-in failures return to the login form with a readable message instead of a JSON error page
- Loading spinners, step transitions, an error shake, a localized resend countdown, focus rings, and LTR-mirrored arrows
- Brand colors derive their dark and soft tones automatically; fields use 16px text so iOS does not zoom
- Larger, more readable text in the login form, account panel, and admin
- Admin: test SMS tool showing the provider's exact response, and a guide for Android code filling

## 0.2.0 — 2026-08-01

- Added English as the source language and bundled a complete Persian (`fa_IR`) translation
- Added automatic LTR/RTL direction across the login, account, and admin interfaces
- Reworked directional styling with CSS logical properties
- Localized JavaScript validation and copy-feedback messages
- Updated plugin metadata and admin credits for Mehran Marandi

## 0.1.0 — 2026-07-31

- معماری مستقل و namespaced برای ورود بدون رمز
- OTP موبایل و ایمیل با hash، expiry، مصرف اتمیک و محدودسازی
- providerهای کاوه‌نگار، ملی پیامک، IPPanel، فراز SMS و SMS.ir
- Google OAuth 2.0 Authorization Code flow
- REST API ورود و به‌روزرسانی پروفایل
- فرم ورود و پنل کاربری RTL و واکنش‌گرا
- پنل تنظیمات حرفه‌ای، مستندات توسعه و CI
