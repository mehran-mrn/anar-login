# One active SMS provider (1.10.0)

Choose the active provider by selecting its connection card and saving settings. The same account is used for verification, store notifications and campaigns. Email and Bale remain independent sign-in channels; Bale remains OTP-only.

Retired login, store, campaign and per-message provider overrides no longer select the sending transport. Existing credentials and message template content remain stored. If a saved message belongs to a different provider or account, its account-change check blocks sending until an administrator reviews and saves it for the active provider. Template identifiers are not automatically copied into a different account and sent without review.

The message editor has a hidden active-provider value for its mapping preview; the server resolves the active provider independently of submitted values. NotificationSender also rejects attempts to send through a retired provider before making an HTTP request. Unit tests exercise both boundaries.

The connection panel exposes quick message-editor links for active features. Save a different provider before editing its messages. Contextual help renders outside animated/clipped cards and uses the browser top layer when available so it remains visible over dialogs.
