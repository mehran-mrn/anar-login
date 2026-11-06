# Draft reply for review T2 — send after uploading 0.6.1

Hi,

Thank you for updating the slug and for identifying this issue. I have uploaded version 0.6.1.

Public OTP and Google registrations are now restricted to Subscriber or WooCommerce Customer, with a runtime allowlist of basic read capabilities. This is checked after role filters in both registration paths. Unsafe saved or filtered roles fall back to a validated Subscriber; if that fallback is missing or elevated, no user is created.

Account creation and login are necessary for the plugin's optional, site-owner-enabled passwordless authentication feature. OTP ownership is verified before registration; Google requires a verified email and a valid, single-use OAuth state. The plugin uses WordPress user and session APIs, runs `wp_authenticate_user` and `authenticate` before issuing a session, honors denials, and rejects changes to the verified user identity.

I tested the submitted ZIP on a fresh WordPress installation with WP_DEBUG enabled, including unsafe roles, altered Subscriber/Customer capabilities, both registration paths, and authentication-filter denials. No live SMS was sent during these tests.

Thank you for your continued review.

## Sending notes

- Upload `anar-toolkit-0.6.1.zip` through the existing WordPress.org submission before sending this reply in the same review email thread.
- The review team confirmed the `anar-toolkit` slug on 30 October 2026; no further slug change is requested.
- This draft is prepared locally. No upload or email has been sent by the development agent.
- Passwordless hooks receive an already verified WP_User and an empty password. Security plugins that require a password-specific challenge or proprietary 2FA flow still need individual compatibility testing; do not claim universal compatibility.
