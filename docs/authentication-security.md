# Authentication and public registration in 0.6.1

`UserService` is only called after OTP verification or the server-to-server Google OAuth callback. The site owner can disable creation of new accounts independently of existing-account sign-in. This feature creates accounts only on the WordPress site where the owner enabled Anar, using `wp_insert_user`; it does not create external service accounts.

## Public registration role policy

Only `subscriber` and `customer` are candidates. A role must exist, grant `read`, and have no truthy capability outside `read` and the legacy `level_0`. Custom roles are refused even if they currently have only read access. False capability entries do not grant permissions.

Settings sanitization and the admin role dropdown use the same policy. Both OTP (SMS/email) and Google resolve the role again immediately before `wp_insert_user`. The `anar_login_new_user_role` filter, including WooCommerce's customer preference, is applied before final validation. This filter can no longer opt into arbitrary custom or publishing roles.

Unsafe legacy settings and unsafe filter results fall back to `subscriber` only after checking that role's actual capabilities. If it is absent or elevated, registration returns `anar_registration_role` before insertion. WordPress's configurable `default_role` is never used as the fallback. Existing accounts retain their roles; this release does not demote or otherwise change existing users.

## Authentication checks

OTP codes are HMAC protected, expire, are single-use, and have atomic attempt limits. Google uses cookie-bound, single-use state, PKCE, HTTPS server-to-server token/profile requests, and requires the boolean verified-email claim. The account service also checks the verified-email claim.

After identity verification, the service checks multisite spam status, applies `wp_authenticate_user` and then `authenticate` to the verified WP_User with an empty password, validates that each result still represents that exact user, and applies `anar_login_can_authenticate`. Any denial stops session creation. Invalid/substituted authentication results fail closed, and denials from the core filters fire `wp_login_failed`.

On success it calls `wp_set_current_user`, `wp_set_auth_cookie` and the `wp_login` action. No fabricated password, application password, or permanent credential is used for passwordless authentication. The plugin's own optional password-login second factor recognizes an already verified OTP/Google session. Other security plugins may require their own integration, especially for password-specific challenges and 2FA; running standard filters is not a claim of universal compatibility.

## Local regression tests

Run `tests/Integration/authentication.php` with `wp eval-file` in a disposable local WordPress + WooCommerce installation with WP_DEBUG enabled and HTTP/mail intercepted. It creates and deletes temporary users, temporarily modifies test roles/settings, and must never be run against production. The public ZIP excludes this test and all development tools.

The test covers unsafe stored and filtered roles for SMS/email/Google; safe Subscriber/Customer paths; elevated or missing built-in roles; WordPress's unsafe default role; registration disabled; unverified Google claims; core-filter denials and account substitution; OTP failures, replay, expiry and attempt exhaustion; and a mocked full Google callback with state replay protection.
