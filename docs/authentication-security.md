# Authentication and public registration in 0.6.1

## Existing-account phone verification in 1.1.1

The games module lets a signed-in customer prove ownership of a phone and link it to their current account. The AJAX endpoint requires the current WordPress session and an `anar_game_phone` nonce. Billing details alone never establish verification. Each six-digit SMS challenge is stored through `OtpRepository` with a separate `game_phone` channel and an identity containing the normalized phone, user ID and hashed session token. It expires after five minutes, is consumed once and permits at most five attempts; shared phone/IP rate limits also apply. Login, password-reset, other-account and other-session challenges cannot authorize linking.

After successful proof, account links are serialized using an expiring database reservation and existing ownership is checked again. A number belonging to another account through `_anar_phone`, a legacy phone key or the configured identity-meta keys is refused. An already verified account cannot replace its phone through this endpoint. Linking does not create a user, switch the current account or create a new session. Disabling games also disables this verification path. The games form and play endpoint both require a normalized SMS phone identity.

`tests/Integration/games.php` exercises the old-account flow, billing-only identities, incorrect and expired codes, resend limits, delivery failures, account/session isolation, login-code isolation, conflicting linked ownership, replay and disabled-module behavior. Its SMS provider is simulated and it must only run in a disposable local WooCommerce installation.

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


## Optional account factors in 1.3.0

Version 1.3.0 adds a browser-bound 256-bit flow capability, purpose separation, five atomic flow attempts and compare-and-delete consumption. Flow state is not autoloaded. Credential enrollment requires a fresh contact challenge scoped to the current account, followed by an authenticator code. TOTP secrets use AES-256-GCM with a versioned associated-data label and a key derived from the WordPress authentication salt. Changing salts requires recovery/re-enrollment; retain a secure database-and-configuration backup.

Ten random 128-bit recovery codes are displayed only at enrollment and stored only as SHA-256 hashes. TOTP time-step acceptance and recovery-code removal use a conditional database update so concurrent stale records cannot both succeed. Account and IP attempt budgets persist across newly issued flows. Password, code and Google paths share the enrolled-factor gate. Interactive strong-factor verification preserves WordPress account denials and password changes invalidate pending login proofs. Application-password and XML-RPC access are blocked for enrolled accounts.

`Plugin::security_hooks` registers lightweight callbacks without loading the credential engine or querying credentials on unrelated requests. `tests/Integration/login-performance.php` asserts this in a fresh WordPress process. `tests/Integration/account-security.php` checks browser/purpose binding, attempts, stale-state rejection, recovery replay and independent login/management challenges. The broader roadmap remains separate from this release. Advanced factors currently support single-site WordPress; network-wide session policy is not claimed. Passkeys require PHP 8+ and HTTPS, and their verifier is isolated under src/Vendor/WebAuthn. Enrollment QR codes are generated locally by the bundled Project Nayuki library. Browser and device compatibility beyond the recorded tests is not claimed.


The second-step implementation is shared: `AccountSecurity` owns pending verification, session interception, the form and completion for both delivered codes and authenticator/recovery codes. `TwoFactor` resolves only the legacy policy and contact; it no longer maintains separate transient state, cookie hooks or a second login form. Session creation remains in `UserService`. Hook registration is idempotent and lazy.
