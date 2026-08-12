# Anar Login

Fast, secure passwordless authentication for WordPress with Iranian SMS providers, email OTP, and Google OAuth.

![Anar Login](assets/images/anar-logo.png)

Anar Login ships with an English LTR interface and a complete Persian (`fa_IR`) RTL translation. WordPress selects the language and layout direction automatically.

## Features

- Passwordless sign-in and registration with an Iranian mobile number or email address
- Google OAuth 2.0 sign-in
- Built-in Kavenegar, Meli Payamak, IPPanel, Faraz SMS, and SMS.ir providers
- Extensible SMS provider contract and WordPress hooks
- Hashed OTP storage, atomic single use, expiration, and scheduled cleanup
- Per-identity and per-IP rate limits, resend cooldowns, and verification attempt limits
- Account-enumeration-resistant OTP responses
- Automatic user creation with a configurable least-privileged role
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

1. Place the `anar-login` directory in `wp-content/plugins` and activate the plugin.
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
| Faraz SMS | IPPanel-compatible legacy pattern API | Username, password, sender, pattern code, variable name |
| SMS.ir | Verify API v1 | API key, template ID, parameter name |

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
