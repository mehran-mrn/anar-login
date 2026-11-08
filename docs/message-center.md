# Message center implementation

Version 1.2.3 places the administrator-only message index inside the existing Settings layout and reuses one on-demand dialog for each SMS purpose. `MessageCatalog` defines 34 existing purposes, including a separate requested cart-recovery code. Login settings, commerce templates and marketing stages open that same editor in place. Canonical links use `anar-login-settings&section=messages&edit_message=<purpose>`; old `anar-messages` bookmarks redirect before output, preserving variants and filters. The editor uses `edit_message` because WordPress removes its reserved `message` notice argument from browser URLs. Business timing, eligibility, rewards and activation remain in their modules; shared marketing restrictions use the Campaign sending rules section of Settings, independently of WooCommerce activation.

`MessageSettings` reads legacy login, commerce and marketing settings until an override is saved. Each override is a separate non-autoloaded option named `anar_toolkit_messages_<purpose>`; a gift variant uses `_gift`. This avoids rewriting other messages and requires no destructive migration. Writes use an expected configuration fingerprint and a conditional SQL update or atomic first insert to reject stale editors. AJAX validation errors preserve the editor fields, and stale revisions offer an explicit reload action. Loading and saving both require the administrator capability and their own WordPress nonces. Non-AJAX submissions retain the legacy notice/draft fallback.

Security SMS can inherit the sign-in binding or select its own provider, pattern and variables. The SMS provider interface remains unchanged. Existing integrations with no central override keep their original send path. OTP and second-factor dispatch now pass distinct reporting contexts; code storage, verification and session creation retain their existing behavior. Email settings remain separate.

For commerce/marketing, binding resolution happens before required-variable validation and transmission. Gift/no-gift variants follow the current rule or recovery stage. Existing queued jobs continue to resolve current configuration, consistent with the previous implementation; changing content affects pending messages. Disabled rules are still cancelled by the existing worker checks.

Plain-text mode is explicit and currently supported by custom HTTP and Meli Payamak's dedicated-number endpoint. Other built-in providers retain their approved-template transport. A rejection never falls back to another mode. Custom verification gateways can continue to transport `{code}`, while commerce custom gateways must transport `{message}`. Provider credentials remain in connection settings, not in message records.

A saved binding is pinned to a keyed fingerprint of the connection configuration, excluding legacy OTP fields and normalized for field order. A changed account requires reviewing and saving the binding again. Test metadata is tied to the effective message and connection fingerprint and does not claim handset delivery or panel-template approval. Sample sends create no challenge, order, campaign or coupon.

The test action requires the same administrator capability and nonce as saving, validates the current revision, and requires saved values. It uses the real message transport with sample data. Real test sends are only initiated by the administrator and may consume SMS credit. The preview is based on locally entered reference text; the plugin does not claim to retrieve the panel's approved wording.

Verification:

- Unit checks in `MessageSettingsTest` cover the catalog, legacy fallback, inheritance, independent security bindings, required variables, delivery modes and gift variants.
- `tests/Integration/messages.php` exercises actual WordPress options/CAS writes and mocked HTTP requests, including stale saves, context reporting, account changes, hidden legacy values and gift selection. Run only in a disposable local WordPress installation.
- The existing authentication, WooCommerce suite, release package and localization checks remain applicable.

The dialog has one keyboard focus scope, a visible title/close action, an inline unsaved-change prompt and full-screen mobile layout. Gift variants replace the editor inside the same dialog. Save-and-close restores the original page, filters, scroll and opener focus; parent forms are never submitted by a message save. Browser Back/Forward and direct links resolve the selected editor. During writes the editor is inert to prevent capturing an unsent edit as saved. Responses return the canonical sanitized values and a fresh revision.

Shared `admin-controls.css` styles apply only to Anar admin surfaces. Message CSS is structural, inheriting the settings palette and typography. Info help uses delegated events so dynamically loaded editors behave like existing pages. Storefront assets and message dispatch rules are unchanged by this UI release.

`tests/Integration/message-editor.php` covers real WordPress AJAX responses, capabilities, nonces, independent saves, stale edits, required variables, inheritance, gift variants and shared settings with mocked SMS. Run it only in disposable local WordPress alongside `messages.php`.

Version 1.2.4 removes the commerce template tab. Order notification switches contain compact message summaries; recovery timing and summaries share one settings card. Games and stock settings expose their own verification messages. `tab=templates` resolves to automation settings. The full catalog remains in Settings and sending history is labeled Sending report. No binding or dispatch behavior changes.
