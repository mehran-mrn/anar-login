# Anar Toolkit 1.2.0

Optional WooCommerce order notifications, automatic cart recovery, customer campaigns and promotional tools, alongside SMS/email/Google login and SMS password recovery. See [commerce documentation](docs/commerce.md), [provider support messages](docs/support-messages-fa.md), and [review reply draft](docs/reviewer-response.md).

Build the public release with `python tools/package.py --output dist/anar-toolkit.zip`. All seven built-in providers, including IPPanel, Panelchi and MaxSMS, are included. Development dependencies and editable translation sources are excluded. Compiled Persian MO/PHP catalogs are included so installation does not require a separate language pack.

Stable GitHub releases publish automatically to [WordPress.org](https://wordpress.org/plugins/anar-toolkit/) after the release checks pass. Configure repository Actions secrets `SVN_USERNAME=mrnwp` and `SVN_PASSWORD` with the WordPress.org SVN password, then publish a release tagged with the plugin version (for example `0.8.0`). Ordinary commits run checks. See [publishing instructions](docs/publishing.md).

# Anar Toolkit

Fast, secure passwordless authentication for WordPress with Iranian SMS providers, email OTP, and Google OAuth.

![Anar Login](assets/images/anar-logo.png)

Anar Toolkit includes an English LTR source interface and complete Persian (`fa_IR`) RTL runtime catalogs. The installed plugin follows the site or user language without requiring a separate pack. Official or custom WordPress language packs retain priority over bundled translations.

**100% free, forever — all features included.** No Pro edition, subscriptions, license keys or feature paywalls. SMS providers charge separately for their own accounts and message credits.

**۱۰۰٪ رایگان، برای همیشه — با تمام امکانات.** هزینهٔ پنل یا ارسال پیامک را شرکت پیامکی دریافت می‌کند.

Product website: https://mrnwp.ir/anar/
English product page: https://mrnwp.ir/anar/en/

## Features

- Passwordless sign-in and registration with an Iranian mobile number or email address
- Google OAuth 2.0 sign-in
- Built-in Kavenegar, Meli Payamak, IPPanel, Panelchi, MaxSMS, Faraz SMS, and SMS.ir providers
- Extensible SMS provider contract and WordPress hooks
- Hashed OTP storage, atomic verification attempts and request limits, single use, expiration, and scheduled cleanup
- Per-identity and per-IP rate limits, resend cooldowns, and verification attempt limits
- Account-enumeration-resistant OTP responses
- Automatic user creation restricted to Subscriber or Customer with basic read capabilities; roles are revalidated after filters at registration time
- Existing-account lookup through Anar Login metadata or `digits_phone`
- Responsive login, account, and admin interfaces in both LTR and RTL
- Email delivery through `wp_mail`, compatible with SMTP plugins
- Optional data cleanup on uninstall

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later
- HTTPS in production, especially when Google sign-in is enabled
- An active service template from your SMS provider with a `code` variable

## Installation

1. Place the `anar-toolkit` directory in `wp-content/plugins` and activate the plugin.
2. Open **Anar Login** in WordPress admin and save the general settings.
3. Select an SMS provider and enter its service-template credentials.
4. Add `[anar_login]` to a login page.
5. Add `[anar_account]` to an account page and select that page in General settings.

## SMS providers

| Provider | API mode | Required settings |
| --- | --- | --- |
| Kavenegar | Verify Lookup | API key, template name |
| Meli Payamak | BaseServiceNumber | Username, password, body ID |
| IPPanel | Edge Pattern API | Access token, sender, pattern code, variable name |
| Panelchi | Pattern API | API token, approved pattern slug, code variable; sender defaults to 10001 |
| MaxSMS | Panelchi-compatible Pattern API | Separate MaxSMS API token, approved pattern slug and code variable; sender defaults to 10001 |
| Faraz SMS | IPPanel-compatible legacy pattern API | Username, password, sender, pattern code, variable name |
| SMS.ir | Verify API v1 | API key, template ID, parameter name |
| Custom gateway | Any HTTP API | Endpoint URL, parameters, optional headers and success text |

### Custom gateway

Use it for any panel without a built-in provider. Placeholders `{to}`, `{code}`, `{message}`, and `{sender}` work in the URL, parameters, and headers.

- **Parameters** — one `name=value` per line, sent as form fields, JSON, or the GET query string; or paste a complete JSON object for nested bodies or numeric values, e.g. `{"mobile":"{to}","templateId":123456,"parameters":[{"name":"CODE","value":"{code}"}]}`.
- **Recipient format** — `09121234567`, `+989121234567`, `989121234567`, or `9121234567`.
- **Success text** — set a fragment that only appears in successful responses (such as `"status":200`), so rejected messages are not reported as delivered. Then use **Send a test SMS** on the same tab.

## WooCommerce

When WooCommerce is active, a **WooCommerce** tab appears in the settings:

- **My Account** — replace the login/register forms with the passwordless form (a "sign in with a password" link remains), show it above them, or leave WooCommerce untouched
- **Checkout** — "Returning customer?" opens the code form and returns the customer to checkout
- **Billing phone** — the verified mobile fills an empty billing phone
- **Customer role** — new users are registered as WooCommerce customers

## Login popup

Enable **Login popup** in General settings, then link to `#anar-login` anywhere — for example a custom menu link — or use:

```text
[anar_login_button label="Sign in / Register"]
```

Signed-in visitors are sent to their account instead. Each page only carries a ~1.7 KB (gzip) inline loader; the form is fetched on first click and preloaded on hover.

## Replacing theme and WordPress logins

**Replace the theme's login** (General, on by default) makes the passwordless form the only login visitors see:

- Every WooCommerce login form — My Account, checkout, and `woocommerce_login_form()` wherever a theme or plugin prints it, including theme overrides
- Theme login links for signed-out visitors (header account icon, mobile toolbar, side panels) open the login popup and return to the page that was asked for
- WoodMart's own header dropdown and side-panel password forms are removed

**Also replace wp-login.php** is off by default. When on, the WordPress login page shows the passwordless form with a "Sign in with a password" link, so staff always keep password access.

Login targets are excluded from WordPress speculative loading, and a successful sign-in sends `Clear-Site-Data: "prefetchCache"`, so a page prefetched while signed out is never shown after signing in.

The form shows the site logo (WoodMart's header logo, the Customizer logo, or the site icon), a custom image, a lock icon, or nothing — never the plugin's logo.

## Two-step verification

In **Security**, require a one-time code after a correct password for administrators, editors and shop managers, or for everyone. The code goes to the account's mobile, or its email when no mobile is linked. Sign-ins with a code or Google skip the second step, and XML-RPC password sign-in is refused for affected accounts. Filter `anar_login_two_factor_required` to decide per user.

## Performance

Anar Login is built to stay out of the way of your page speed:

- Nothing is loaded on pages without `[anar_login]` or `[anar_account]`: no CSS, no JavaScript, no inline data
- On form pages: about 4.5 KB CSS and 3.6 KB JavaScript (gzip), deferred, with no jQuery and no external CDN
- With the popup or theme login replacement on, other pages carry only a ~1.7 KB (gzip) inline loader and make no extra requests until it is opened
- No database queries on ordinary page views; SMS providers are only built when a code is sent

SSL verification remains enabled for every provider. Fix the server CA chain rather than disabling TLS verification.

## Google sign-in

Create an OAuth 2.0 **Web application** in Google Cloud Console, add the callback URL shown in the Google sign-in settings tab to the authorized redirect URIs, then enter the client ID and secret. The callback has this form:

```text
https://example.com/wp-json/anar-login/v1/google/callback
```

The flow uses Authorization Code, validates `state` with a secure cookie and transient, and accepts only verified Google email addresses.

## Shortcodes

```text
[anar_login]
[anar_account]
```

`[anar_account]` automatically shows the login form to signed-out visitors.

## Localization

English is the source language. The bundled `fa_IR` translation is available in both WordPress PHP and MO formats, supporting WordPress 6.2 and later. All interface containers use `is_rtl()` and CSS logical properties so layout direction follows the active WordPress locale.

## Extending SMS providers

Implement `Anar\Login\Messaging\SmsProviderInterface`, then register the provider:

```php
add_filter(
	'anar_login_sms_providers',
	function ( $providers, $settings ) {
		$providers['my-provider'] = new MyProvider( $settings );
		return $providers;
	},
	10,
	2
);
```

See [the custom SMS provider guide](docs/custom-sms-provider.md) and [architecture notes](docs/architecture.md).

## Development

```bash
composer install
composer test
composer phpcs
```

## Author

Designed and developed by [Mehran Marandi](https://mehranmarandi.ir) — [@mehran_mrn](https://github.com/mehran-mrn/).

## License

GPL-2.0-or-later

## Customer marketing suite (0.8.0)

See [the setup and behavior guide](docs/marketing-suite.md) for automatic cart capture, SMS password recovery, discounts, order tracking and the independently switchable campaign modules. New marketing modules start disabled. The guide documents shortcode types, real-data reports, privacy controls, provider templates and practical limits.
