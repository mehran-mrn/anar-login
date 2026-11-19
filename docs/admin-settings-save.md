# Administration saves (1.11.0)

Connection credentials and the five OTP generation limits belong to independent forms. Their controls use an explicit `form` attribute so the forms remain valid siblings rather than nested forms. A hidden invalid logo URL in the general form cannot block either save. Inactive logo URL controls are disabled; validation in the remaining general form reveals the relevant tab and field instead of failing silently.

Scoped saves validate an allowlist, merge against the latest settings, sanitize provider fields and retain blank saved secrets. A scope cannot change unrelated settings. Incomplete credentials do not activate another provider. Success is reported only after reading the saved provider/credentials or OTP limits back. The main settings sanitizer preserves fields owned by the independent forms. Form results use live status regions; validation and network failures retain the draft.

Feature switches save only the selected capability. The client serializes changes, disables switches during the request and updates the card after confirmation. A stale expected state returns a conflict. An unconfirmed network result does not trigger an automatic retry; the administrator is instructed to check saved state. Disabling a capability retains its configuration and stored data.

## Authorization and trust boundaries

All write and account-check handlers require `manage_options` and a purpose-specific WordPress nonce. Capability keys, scope names, provider names and OTP ranges are validated server-side; submitted field names cannot select an arbitrary option or override unrelated settings. Responses do not contain credentials. The read-only account check calls the existing fixed provider credit endpoints, without changing monitoring settings or sending a message. Providers without a supported check endpoint use the explicit message-editor test path. A positive account check confirms account credentials, not SMS delivery or template approval.

## Validation

ScopedSettingsTest covers field isolation, range rejection, credential completeness, preserved secrets and main-form preservation. Browser QA reproduces the hidden invalid `form_logo_url` regression, verifies connection and OTP persistence after reload, mocks the account-check transport, tests message-editor/test controls and immediate feature saves, and exercises invalid nonce, unknown capability, stale state, anonymous requests and failed-save recovery. Desktop and mobile layouts are inspected separately. No real SMS is sent by these checks.
