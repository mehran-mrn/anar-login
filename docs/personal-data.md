# Local personal-data erasure

Open **Anar → Personal data** and search a mobile number. The search normalizes the number and reads Anar reminder contacts, saved carts, message/queue entries, marketing profiles and associated campaign records, including disabled modules. It does not alter those records. An absent number produces a visible result without an erase control.

Search and erasure forms submit through the authenticated admin handler and redirect to an opaque, administrator-bound result URL. The result remains available for ten minutes, including after reload, without resubmitting the operation or putting the mobile number in the URL. Feedback uses a dedicated inline panel rather than generic WordPress notices; an empty result identifies the searched number and preserves it in the input field.

The preview lists counts and the affected number. After verifying the requester, explicitly confirm the displayed erasure. A private, administrator-bound preview expires after ten minutes and is consumed once. The server uses its stored number rather than a submitted replacement, verifies that the records have not changed, runs the existing local erasers and checks for remaining personal records. Changed, expired or failed requests show feedback and require a fresh search. Partially completed erasure is reported as incomplete.

This tool preserves WordPress accounts and login phone numbers, WooCommerce orders, provider-held records, minimal suppression identifiers and anonymous campaign budget totals. Personal gifts are revoked by the existing erasers. The older direct-erasure endpoint returns to the search screen instead of bypassing preview and confirmation.

Run `tests/Integration/personal-data.php` with WP-CLI only in a disposable local WordPress installation. Its synthetic fixtures cover read-only search, missing data, Persian digits, explicit confirmation, nonce validation, preview ownership, changed data, token replay, number tampering, erasure feedback and preservation of accounts and orders.
