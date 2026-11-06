# Customer campaigns (0.8.0)

Open **Anar Toolkit → Features** to enable modules. Active features appear first; inactive features remain available under **Other features** with their guides and activation switches. Each enabled marketing module has its own settings and report menu. Overview, carts and messages are separate tabs and query only the selected report. New campaign modules start disabled; an update never launches a bulk campaign. Login remains usable without WooCommerce.

Disabled runtime components are not instantiated and do not enqueue storefront files or run their feature queries. A small autoloaded feature snapshot selects the required services; full connection and campaign settings are loaded on demand. Already-issued personal coupons keep their restrictions, historical opt-out links remain usable, and scheduled retention cleanup continues. Turning a feature off preserves its configuration and records, cancels pending messages for disabled events, and stops its scheduling.

1. Configure credentials under SMS settings. Select a commerce provider, then optionally a different marketing provider key.
2. Create approved provider templates for the purposes you need. Each campaign has its own template ID and `parameter={placeholder}` mapping. Include `{unsubscribe_url}` in marketing messages; surveys also require `{action_url}`. Discounts sent in a message require `{coupon_code}`. Survey discounts are issued after feedback instead.
3. Set sending hours, per-phone caps, minimum message gap, customer/product filters and retention before enabling modules. Actual execution depends on Action Scheduler or WordPress cron.
4. Start the optional WooCommerce history import. It records existing paid orders in batches of 25 and does not replay welcome messages. Subsequent discovery runs can apply enabled inactivity, prediction and offer rules to imported history.
5. Place the desired customer shortcodes on pages and test the flows with your own account before enabling customer campaigns.

## Available modules

| Module | Behavior |
| --- | --- |
| Lifecycle | First-purchase thank-you, three configurable inactivity stages, and a VIP spend threshold. The highest currently due inactivity stage is chosen for old history. |
| Prediction | Product-specific repeat intervals from customer history, product history, or a hybrid. Uses median intervals and deviation-based confidence. Insufficient history produces no prediction. Before/on/after stages are independently configurable. This is a statistical estimate, not an AI service. |
| Smart offers | Recommends a recently published, available product outside the customer's recent product history. Included/excluded product IDs and included categories apply. No external recommendation service is called. |
| Bulk SMS | Paste phone numbers/first-column CSV rows (1,000 per import), or use recorded customers. Preview an audience of at most 10,000 contacts; explicitly confirm a campaign, optionally schedule it, then pause/resume/cancel remaining work. Segments: all, VIP, no purchase in 30 days, new. Eligibility is checked again at send time. |
| Stock | Single-product/variation requests, with phone verification before subscribing. One notification per request/restock, with current availability checked again before sending. |
| Surveys | Scoped order links, one response per paid order, ratings 1–5, optional product reviews held for moderation. Any configured gift is independent of the rating, including negative feedback. |
| Next-purchase gift | A configurable personal discount after an eligible paid order. |
| Birthday | Optional month/day only in the account form or `[anar_customer_preferences]`. Gregorian calendar everywhere; Persian calendar requires PHP Intl. No birth year is retained. |
| Invoice | My Account print action and scoped expiring invoice links; browser print/PDF, current order totals, addresses and merchant identity. Owner/manager or a valid invoice signature is required. A link does not sign anyone in. |
| Games | `[anar_game type="wheel"]`, `scratch`, `boxes`, `cards`, `slots`, `treasure`, `daily`, `quiz`, `memory`, `milestone`. Free promotional coupons, server-decided outcomes, shared daily quota and one reward per play. Milestones require the configured number of paid orders. No cash prize or paid entry. Configure quiz questions/options before using the quiz. |
| Referrals | `[anar_referrals]` for verified customers; first qualifying purchase, self-referral checks, duplicate protection, completion and refund waiting period. Refunds revoke unused rewards. |
| Insights | Local paid-order/product facts, campaign outcomes and coupon-attributed purchases. Optional first-party traffic counting starts disabled and excludes account/checkout/admin pages. Visitor days count browser-days, not unique people. |

## Authentication, carts and order events

SMS password recovery uses an already linked Anar/Digits phone, a separate six-digit five-minute challenge, five guesses, rate limits and normal WordPress reset hooks. A billing number alone cannot reset an account. Successful resets revoke old sessions. Use `[anar_reset_password]` or the reset section in the login form/popup.

Automatic cart capture is the default capture mode when reminders are enabled. Known account numbers and numbers entered at checkout are saved with a visible notice, without a separate checkbox. The optional explicit-preference mode remains available. Neither mode overrides an existing unsubscribe. Cart recovery restores standard products at current prices/stock after phone-code verification; bundles, subscriptions and custom item metadata are not reconstructed.

Recovery coupons can be percent/fixed, minimum-spend restricted, exclusive and exclude sale items. They are single-use, expire and are bound to the recipient billing phone. Personal marketing gifts use the same phone binding; the optional **existing coupon** choice uses the merchant's existing WooCommerce restrictions instead. A verified recovery can apply the coupon to a cart or eligible unpaid order.

Enable customer processing/completion/on-hold/cancellation/refund events separately. Administrator new-order/failure/payment notices have separate switches. Save tracking number, carrier and HTTPS URL in the order's Anar box, use standard WooCommerce Shipment Tracking metadata, or configure an extra tracking meta key/filter. WooCommerce CRUD supports HPOS. Stale status/tracking messages are cancelled at send time.

## Reports and data

Reports use actual local records and keep currencies separate. **Accepted** means provider API acceptance, not handset delivery. Ambiguous results are not automatically retried. Coupon attribution is not proof of incremental revenue. The recovery revenue timestamp/amount fields were introduced in 0.8.0; older rows without those fields are omitted from revenue cards.

Campaign retention is 7–365 days, cart/message retention 7–90 days. Daily cleanup removes expired activity and inactive customer profiles in bounded batches; active profiles retain their purchase facts for interval calculations. A minimal keyed suppression record remembers unsubscribes after identity erasure. Account email privacy exports link full phone history only to an account with a verified matching phone; phone-only guest requests use administrator phone erasure after identity verification. WooCommerce orders and provider-side records are separate.

Traffic uses a random first-party localStorage identifier lasting up to 30 days, daily keyed hashes and only the referring hostname. No external analytics endpoint, raw IP, full referrer URL, Maps extraction, management application, or competitor assets are included. Add your actual modules, retention, provider and purposes to the suggested privacy-policy content.

Run `tests/Integration/commerce.php` and `tests/Integration/suite.php` only in a disposable `local` WordPress/WooCommerce installation. Both intercept provider HTTP and mail. Never run these fixtures on a store. The WordPress.org ZIP excludes tests, tooling, dependencies and editable translation sources. Compiled Persian MO/PHP catalogs are bundled and follow the site/user locale; no external pack is required.


## Runtime isolation and navigation

Only enabled runtime components are wired. Login, reset, login SMS reports and two-step verification have independent switches; existing two-step protection can remain active while the Anar login form is stopped. Google integration is instantiated only when enabled. Stock-only operation does not record unrelated purchase histories; print-only invoices need no message worker. Worker schedule checks run on administration/cron requests and feature changes, not ordinary storefront views. Stopping a sending module cancels its pending work and removes the message schedule when no active module needs it. History import is an explicit, bounded task with its own temporary scheduling.

Disabled features retain their settings and records. Existing personal coupons keep their restrictions; shared daily cleanup still enforces retention. Administrator report pages query only the selected module. Saving one module's settings preserves all others. Disabled capabilities remain visible in a lower-priority catalog rather than being removed. Contextual help uses native keyboard-accessible disclosure controls, with no external help SDK or frontend bundle.

For fresh-process isolation QA, copy `tests/fixtures/feature-modes.php` into the disposable local site's `wp-content/mu-plugins`. Set `ANAR_QA_FEATURE_MODE` to each of `off`, `login`, `two_factor`, `invoice`, `games`, `traffic`, `stock`, `recovery`, `orders`, then run `wp eval-file tests/Integration/features.php`. With `stock`, also run `tests/Integration/admin.php`. Remove the fixture and environment variable after testing; neither belongs on a store.
