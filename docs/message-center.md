# Message center implementation

The 1.2.0 work introduces an administrator-only message index and one editor per SMS purpose. `MessageCatalog` defines 34 existing purposes, including a separate requested cart-recovery code. The editor links from login settings, commerce templates and marketing stages. Business timing, eligibility, rewards and activation remain in their modules; shared campaign restrictions now have a dedicated page.

`MessageSettings` reads legacy login, commerce and marketing settings until an override is saved. Each override is a separate non-autoloaded option named `anar_toolkit_messages_<purpose>`; a gift variant uses `_gift`. This avoids rewriting other messages and requires no destructive migration. Writes use an expected configuration fingerprint and a conditional SQL update or atomic first insert to reject stale editors. Failed validation preserves the user's draft, while a stale editor reloads current settings.

Security SMS can inherit the sign-in binding or select its own provider, pattern and variables. The SMS provider interface remains unchanged. Existing integrations with no central override keep their original send path. OTP and second-factor dispatch now pass distinct reporting contexts; code storage, verification and session creation retain their existing behavior. Email settings remain separate.

For commerce/marketing, binding resolution happens before required-variable validation and transmission. Gift/no-gift variants follow the current rule or recovery stage. Existing queued jobs continue to resolve current configuration, consistent with the previous implementation; changing content affects pending messages. Disabled rules are still cancelled by the existing worker checks.

Plain-text mode is explicit and currently supported by custom HTTP and Meli Payamak's dedicated-number endpoint. Other built-in providers retain their approved-template transport. A rejection never falls back to another mode. Custom verification gateways can continue to transport `{code}`, while commerce custom gateways must transport `{message}`. Provider credentials remain in connection settings, not in message records.

A saved binding is pinned to a keyed fingerprint of the connection configuration, excluding legacy OTP fields and normalized for field order. A changed account requires reviewing and saving the binding again. Test metadata is tied to the effective message and connection fingerprint and does not claim handset delivery or panel-template approval. Sample sends create no challenge, order, campaign or coupon.

The test action requires the same administrator capability and nonce as saving, validates the current revision, and requires saved values. It uses the real message transport with sample data. Real test sends are only initiated by the administrator and may consume SMS credit. The preview is based on locally entered reference text; the plugin does not claim to retrieve the panel's approved wording.

Verification:

- Unit checks in `MessageSettingsTest` cover the catalog, legacy fallback, inheritance, independent security bindings, required variables, delivery modes and gift variants.
- `tests/Integration/messages.php` exercises actual WordPress options/CAS writes and mocked HTTP requests, including stale saves, context reporting, account changes, hidden legacy values and gift selection. Run only in a disposable local WordPress installation.
- The existing authentication, WooCommerce suite, release package and localization checks remain applicable.

The games work in this request is a reviewed execution plan in `games-execution-plan-fa.md`. Game quotas, prize inventory, budgets and the visual redesign of all ten games are not implemented by the message-center change.
