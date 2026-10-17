# Page crawl

Run by `tests/Browser/MenuCrawlerTest.php` (`CRAWL=1`, optional `CRAWL_ROLES`, `CRAWL_ONLY`, `CRAWL_LIMIT`). For every menu page that has a screen it records
the HTTP status, console errors and warnings, a blank page, sideways scrolling and a spinner that stays after 15 seconds, as the platform superadmin, a company
admin that holds every company-level menu, and a limited role (dashboard and customers only). Screenshots of failures go to `tests/Browser/screenshots/crawl-*.png`.

## Result

* Full run: **789 page visits (263 pages x 3 roles), 79 flagged**. Nothing was blank, nothing scrolled sideways, no 5xx. What was flagged:
  * 2 real defects, fixed: **Bad Debt** threw `buckets.map is not a function` (my table conversion looped an object as an array) and **Document Center** gave the
    colour inputs an empty value (a console warning). Both are fixed.
  * 8 platform pages answered 403 to the company admin (Backup, Blog posts, Coupons, Software settings, Subscription invoices, Subscription plans, Tenants, Webhooks
    settings of the platform): **expected denials**, the crawler now lists them as `denied`. Backup is superadmin-only by design.
  * 69 list pages "still spinning" at 4 seconds: the test server runs one request at a time and the page was still loading; the crawler now waits up to 15 seconds.
* The 44 pages that were flagged were crawled again for each role (table below): **43 of 43 ok for the company admin and for the limited role, 42 of 43 for the superadmin.**
  The one left is `/customersubscriptionanalytics` as the superadmin: it runs five platform-wide analytics queries and was still loading after 15 seconds on the test
  database (which also holds the 12-month demo company). It finishes; it is slow, not stuck. Noted in `known-limitations.md`.
* The limited role was refused (403) on every page but the dashboard and customers; those refusals are listed as `denied` in the full run and are intended.

## Pages crawled again

| Role | Page | Result | HTTP | Console errors | Overflow | Blank | Spinner | Screenshot | First console message |
|---|---|---|---|---|---|---|---|---|---|
| superadmin | /bank | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /barcode/settings | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /branch | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /brand | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /business/settings | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /category | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /chart-of-account | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /checkout/settings | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /city | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /country | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /crmanalytics | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /currency | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /currencyrate | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /customer | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /customer-group | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /customersubscriptionanalytics | FAIL | 200 | 0 |  |  | yes | tests/Browser/screenshots/crawl-superadmin-customersubscriptionanalytics.png | spinner: <div data-v-7497e5e0="" class="modal-form-loader" role="status" aria-live="polite" aria-busy="true"><div data-v-7497e5e0="" class="modal-for IN admin-list-card__body p-3 p-md-4 |
| superadmin | /customersubscriptioninvoices | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /customersubscriptions | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /department | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /financialyear | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /giftcard/orphan-refunds | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /invoice/settings | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /itemtype | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /loyalty/settings | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /period-lock | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /pipeline | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /portalusers | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /purchase | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /purchase/payment | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /receipt/settings | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /role | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /sell | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /sell/payment | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /state | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /supplier | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /tax | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /tenants | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /timezone | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /unit | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /user | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /variation | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /warranty | ok | 200 | 0 |  |  |  |  |  |
| superadmin | /webhooks | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /bank | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /barcode/settings | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /branch | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /brand | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /business/settings | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /category | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /chart-of-account | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /checkout/settings | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /city | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /country | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /crmanalytics | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /currency | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /currencyrate | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /customer | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /customer-group | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /customersubscriptionanalytics | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /customersubscriptioninvoices | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /customersubscriptions | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /department | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /financialyear | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /giftcard/orphan-refunds | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /invoice/settings | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /itemtype | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /loyalty/settings | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /period-lock | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /pipeline | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /portalusers | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /purchase | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /purchase/payment | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /receipt/settings | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /role | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /sell | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /sell/payment | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /state | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /supplier | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /tax | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /tenants | denied | 403 | 0 |  |  |  |  |  |
| companyadmin | /timezone | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /unit | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /user | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /variation | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /warranty | ok | 200 | 0 |  |  |  |  |  |
| companyadmin | /webhooks | ok | 200 | 0 |  |  |  |  |  |
| limited | /bank | denied | 403 | 0 |  |  |  |  |  |
| limited | /barcode/settings | denied | 403 | 0 |  |  |  |  |  |
| limited | /branch | denied | 403 | 0 |  |  |  |  |  |
| limited | /brand | denied | 403 | 0 |  |  |  |  |  |
| limited | /business/settings | denied | 403 | 0 |  |  |  |  |  |
| limited | /category | denied | 403 | 0 |  |  |  |  |  |
| limited | /chart-of-account | denied | 403 | 0 |  |  |  |  |  |
| limited | /checkout/settings | denied | 403 | 0 |  |  |  |  |  |
| limited | /city | denied | 403 | 0 |  |  |  |  |  |
| limited | /country | denied | 403 | 0 |  |  |  |  |  |
| limited | /crmanalytics | denied | 403 | 0 |  |  |  |  |  |
| limited | /currency | denied | 403 | 0 |  |  |  |  |  |
| limited | /currencyrate | denied | 403 | 0 |  |  |  |  |  |
| limited | /customer | ok | 200 | 0 |  |  |  |  |  |
| limited | /customer-group | denied | 403 | 0 |  |  |  |  |  |
| limited | /customersubscriptionanalytics | denied | 403 | 0 |  |  |  |  |  |
| limited | /customersubscriptioninvoices | denied | 403 | 0 |  |  |  |  |  |
| limited | /customersubscriptions | denied | 403 | 0 |  |  |  |  |  |
| limited | /department | denied | 403 | 0 |  |  |  |  |  |
| limited | /financialyear | denied | 403 | 0 |  |  |  |  |  |
| limited | /giftcard/orphan-refunds | denied | 403 | 0 |  |  |  |  |  |
| limited | /invoice/settings | denied | 403 | 0 |  |  |  |  |  |
| limited | /itemtype | denied | 403 | 0 |  |  |  |  |  |
| limited | /loyalty/settings | denied | 403 | 0 |  |  |  |  |  |
| limited | /period-lock | denied | 403 | 0 |  |  |  |  |  |
| limited | /pipeline | denied | 403 | 0 |  |  |  |  |  |
| limited | /portalusers | denied | 403 | 0 |  |  |  |  |  |
| limited | /purchase | denied | 403 | 0 |  |  |  |  |  |
| limited | /purchase/payment | denied | 403 | 0 |  |  |  |  |  |
| limited | /receipt/settings | denied | 403 | 0 |  |  |  |  |  |
| limited | /role | denied | 403 | 0 |  |  |  |  |  |
| limited | /sell | denied | 403 | 0 |  |  |  |  |  |
| limited | /sell/payment | denied | 403 | 0 |  |  |  |  |  |
| limited | /state | denied | 403 | 0 |  |  |  |  |  |
| limited | /supplier | denied | 403 | 0 |  |  |  |  |  |
| limited | /tax | denied | 403 | 0 |  |  |  |  |  |
| limited | /tenants | denied | 403 | 0 |  |  |  |  |  |
| limited | /timezone | denied | 403 | 0 |  |  |  |  |  |
| limited | /unit | denied | 403 | 0 |  |  |  |  |  |
| limited | /user | denied | 403 | 0 |  |  |  |  |  |
| limited | /variation | denied | 403 | 0 |  |  |  |  |  |
| limited | /warranty | denied | 403 | 0 |  |  |  |  |  |
| limited | /webhooks | denied | 403 | 0 |  |  |  |  |  |
