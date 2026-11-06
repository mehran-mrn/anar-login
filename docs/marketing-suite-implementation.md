# Anar marketing suite implementation

Requested on 2026-11-06: independently implement the public XCart feature set, excluding Google Maps number extraction and the management application. The supplied installer and its public package were inspected statically; neither was executed. Competitor code, fonts and assets must not be copied into Anar.

Source references: https://xcart.ir/ , https://xcart.ir/feature/ , https://xcart.ir/next-purchase-prediction/ , https://xcart.ir/customer-lifecycle/ . Reference inspection files stay in `tmp/anar-080`, outside the plugin repository.

Required release scope:

- SMS/email/Google sign-in and SMS password reset, with separate challenge purposes.
- Automatic cart capture without a separate consent checkbox; opt-out must remain sticky.
- Three-stage cart/unpaid-order recovery and single-use, phone-bound percent/fixed discounts.
- Customer/admin order events, failure alerts and HPOS-compatible shipment tracking.
- Customer lifecycle: first purchase, configurable inactivity stages and VIP threshold.
- Product-specific next-purchase prediction: personal/product/hybrid intervals, confidence and stage controls.
- Smart offers with customer/product rules and optional discount.
- Scheduled segmented bulk messages with preview, pause, cancel, per-phone caps and deduplication.
- Back-in-stock subscriptions, variation support and one notification per subscription/restock.
- Post-purchase surveys/product reviews, moderation and rating-independent rewards.
- Next-purchase discounts after qualifying paid orders.
- Birthday greetings, date entry and optional gifts.
- Printable invoices protected by order ownership or expiring purpose-scoped links.
- Promotional games: wheel, scratch, gift boxes, cards, slots, treasure, daily/calendar rewards, quiz, memory and purchase milestones. Rewards are decided/limited on the server; no purchase stake or cash prize.
- Customer referral coupons with self-referral/duplicate-order/refund controls.
- Dashboard/CRM, separate campaign reports, message logs, sales/product analysis and optional first-party traffic reports.
- Guided module setup, bilingual RTL/LTR interface and per-module switches.

Release criteria: no fake dashboard statistics; API acceptance must not be labelled handset delivery. Keep credentials and plaintext authentication codes out of reports and archives. Test disabled modules, migration, revocation, duplicate/background requests, monetary rules, order ownership, privacy export/erasure and authenticated administration. Render the real admin/storefront views on desktop and mobile before publishing. New campaign modules start disabled; installing an update must not begin a bulk campaign.

Implemented for 0.8.0: a central feature catalog, conditional runtime services, lazy report pages, independent login/reset/logging/2FA switches, and formal Persian contextual guides. Disabled capabilities remain below the active group with their activation switches and help. Full configuration options load on demand through a compact autoloaded feature snapshot. Coupon restrictions, historic opt-outs and retention cleanup continue independently of feature switches.

Local validation on 2026-11-06:

- 38 PHPUnit tests / 126 assertions.
- 99 authentication security checks, 57 commerce checks and 83 expanded-suite checks; provider HTTP and mail mocked.
- Nine fresh-process feature modes: off, login, two-factor, invoice, games, traffic, stock, recovery and orders. No unrelated runtime classes, feature bootstrap queries or unused assets on an ordinary page.
- Admin isolation: one root catalog, all 18 capability switches, active-first ordering and no unrelated reports on the stock page.
- Verified compact snapshot autoloading; full login, commerce and marketing configurations excluded from alloptions.
- Coding standards, JavaScript syntax and 100 release/package checks passed.
- WordPress Plugin Check: no errors. Remaining warnings concern the custom-table SQL/atomic queue, schema installation, bounded metadata lookups and product exclusions; no unescaped-query or readme-parser warnings remain.
- Real Persian RTL administration inspected on desktop and at 390px; no horizontal overflow. Native help popovers checked and their RTL alignment corrected.

Stable publishing uses the existing GitHub-release workflow, followed by SVN and public ZIP verification. Live Panelchi delivery remains unverified: the authorized account test returned UserInactive; API acceptance and real handset delivery must not be conflated.
