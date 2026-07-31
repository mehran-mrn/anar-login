=== Anar Login ===
Contributors: mehran-mrn
Tags: otp, login, sms, iran, google, passwordless
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast passwordless WordPress authentication with Iranian SMS providers, email OTP, Google OAuth, and automatic LTR/RTL support.

== Description ==

Anar Login is a standalone, extensible passwordless authentication plugin designed and developed by Mehran Marandi.

* Iranian mobile OTP sign-in and registration
* Email OTP through wp_mail
* Google OAuth 2.0
* Kavenegar, Meli Payamak, IPPanel, Faraz SMS, and SMS.ir
* Rate limits, securely hashed OTPs, and atomic single use
* Responsive login and account interfaces
* English LTR interface and bundled Persian RTL translation
* Extensible API, provider contract, and WordPress hooks

Author website: https://mehranmarandi.ir
GitHub: https://github.com/mehran-mrn/

== Installation ==

1. Install and activate the plugin.
2. Open the Anar Login admin menu.
3. Save your SMS provider and security settings.
4. Add `[anar_login]` and `[anar_account]` to the desired pages.

== Frequently Asked Questions ==

= Is the raw OTP stored in the database? =

No. Each code is stored using WordPress password hashing and is invalidated after its first successful use.

= Is it compatible with SMTP plugins? =

Yes. Email is sent through wp_mail.

= Does it support Persian and RTL? =

Yes. Persian translations are bundled and the layout direction follows the active WordPress locale automatically.

= How can I add another SMS provider? =

Implement SmsProviderInterface and register it with the anar_login_sms_providers filter.

== Changelog ==

= 0.2.0 =

* Added an English source interface and complete Persian translation.
* Added automatic LTR and RTL layouts with CSS logical properties.
* Added designer identity and author links.

= 0.1.0 =

* Initial OTP and REST API foundation.
* Added five Iranian SMS providers, email, and Google OAuth.
* Added the admin, login, and account interfaces.
