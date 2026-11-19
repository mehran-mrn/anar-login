# Shared administration workspace (1.12.0)

Settings, the feature catalog, store operations, marketing modules and SMS credit use the same header and grouped sidebar. Five navigation groups separate features, sign-in/accounts, store/marketing, messages/reports and shared settings. Enabled store modules keep their own registered routes; their extra WordPress submenu entries are hidden while direct links and bookmarks continue to work. Without WooCommerce the store group is omitted.

Each specialized page retains its own forms, nonces, save handlers, operations and reports. Store settings keep their partial-save behavior and are grouped into full-width cards with paired fields. Invoice settings omit campaign reports, customer profiles and campaign-rule guidance; their save button describes feature settings. Instructional help uses the existing accessible popovers beside headings and field labels. Meli Payamak has a translated recommendation badge attached to its provider card.

Validation: 139 unit tests (523 assertions), 232 actual-package checks, PHP coding standards, browser regressions for connection/OTP/feature saves, and desktop/mobile navigation and form checks. Browser QA uses an isolated local WordPress/SQLite instance with mocked provider responses and sends no SMS.

Feedback 006 remains cancelled. Feedback 007 (credit card in the header) and 008 (feature-switch feedback/concurrency) remain queued in user-feedback-fa.md and are not part of this layout release.
