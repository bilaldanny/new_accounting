# Security sweep of the wave 1-8 code (integration checkpoint, part 3)

Method: the booted router was listed (`php artisan route:list --json`, 2,232 route/method rows after leaving out vendor routes), every
controller behind an API route was scanned for a menu-permission or role check and for tenant scoping, and the new
controllers, models and upload paths were read. Everything marked **fixed** has a test that passed in this run. The complete table
is in `docs/route-table.md` (route | middleware | permission | tenant scope).

## Result table

| # | Check | Finding | Status |
|---|---|---|---|
| 1 | Controller takes `company_id` / `branch_id` from the request body or query | None in the wave 1-8 controllers; scope comes from the signed-in user or the route model. `ContactController@fetchCustomers` accepts `company_id` / `branch_id` in the query but only as a narrowing filter on top of `visibleToCurrentUser()`, so it cannot widen access. | Not an issue |
| 2 | Routes without auth | 25 routes have no sign-in: the reference-data lookups, lead capture, branding, Stripe webhook, device punch, storefront (catalog, order, shop page), receipt share, blog, registration, landing, `/up`. All are intended to be public. | Not an issue |
| 3 | Public routes without a rate limit | `fetchcountries`, `fetchstates`, `fetchcities`, `fetchcurrencies`, `fetchtimezones`, `/api/branding`, `/blog`, `/blog/{slug}` and `/capture/{code}` had no throttle. | **Found, fixed** (throttle added; `PublicRouteHardeningTest`) |
| 4 | Public routes: signature / token / tenant isolation | Stripe webhook is verified with the stored signing secret per company, device punch needs the device token and a company, storefront resolves the tenant from the shop code only, receipt share uses an unguessable token. Isolation tests exist in WaveSeven, WaveEight and the integrations tests. | Not an issue |
| 5 | API keys (`/api/v1/*`) read products, customers and invoices without checking the owner's menu permissions | A key acts as its owner, but the owner only needed the "API Keys" menu: someone with no Sales or Customer permission could create a key and read invoices and customers. | **Found, fixed** (`/product`, `/customer`, `/sell` required; `PublicApiTest`) |
| 6 | `GET /api/fetchledger` and `POST /api/contact-ledger-watches` had no menu-permission check | A signed-in user without any customer, supplier or ledger menu could read a party ledger of their own company. | **Found, fixed** (needs `/customer`, `/supplier`, `/report/ledger` or `/report/account-ledger`; `ContactLedgerWatchApiTest`) |
| 7 | Legacy image uploads saved into `public/images/*` (product, product variation, company logo, purchase attachment, bilty) | Extension came from the client file name and the stored name from the product / company name: a script sent as an "image" would be stored in a public directory, and a name containing `../` could write outside the folder. The bilty base64 branch took the extension from the data-URL prefix. | **Found, fixed** (`Base64Upload::publicExtensionFor` + `safeName`; `LegacyUploadHardeningTest`, 6 tests) |
| 8 | Uploads on the new screens (attachments, HR documents, expense receipts, portal bill, field signature) | Content type detected on the server, size capped, generated file names, private disk, `.php` / `.exe` rejection tests. | Not an issue |
| 9 | Mass assignment | No wave 1-8 model is unguarded (the new tables are written through `DB::table` with explicit columns). `$guarded = []` exists only on older models (asset, budget, POS, bank statement, cost center, price list tier, warehouse location, audit log); no controller passes `$request->all()` / `input()` to `create` / `update` / `fill`. | Not an issue (older models: worth tightening later, no path found) |
| 10 | Superadmin screens (SaaS admin, System Center) | Every method calls the superadmin guard (`superadmin()` / `guard()`). | Not an issue |
| 11 | Per-user data (my notifications, saved report filters) and portal | Filtered by `user_id`; portal users are bound to their contact (`users.contact_id`, `RestrictPortalUsers`), portal routes are throttled. | Not an issue |
| 12 | Designer / template / custom CSS injection | Allowlist of blocks and properties plus escaping; W5 tests. | Not an issue |
| 13 | Secrets in logs or responses | Provider keys, SMTP password, VAPID private key, Stripe keys, courier and bank-feed tokens are encrypted columns and masked in responses; API key plain text is shown once only (ApiKeysTest). | Not an issue |

## Encrypted columns (readable only with APP_KEY / `APP_PREVIOUS_KEYS`)

Integration provider credentials, outgoing SMTP password, WhatsApp / SMS tokens, Stripe keys and webhook secret, courier keys, bank
feed token, VAPID private key, attendance device tokens. Rotating `APP_KEY` without `APP_PREVIOUS_KEYS` makes these unreadable.

## Limits of this sweep

* The permission / scope columns of `docs/route-table.md` are a static source check, not a proof; they point to controllers that
  need a human look, they do not replace tests. 32 API routes have no check in the controller by design (portal, per-user data,
  lookups) and were read one by one (table above).
* The existing uploads of the older modules (before wave 1) were read where they save into `public/`; the private-disk modules were
  not re-audited line by line.
* Test helper order dependency noticed on the way: `ContactLedgerWatchApiTest` uses `createLinkedLedgerContact()` that is defined
  in `ContactLedgerApiTest`, so the file fails when run alone. It passes in the full suite; fixing it belongs to part 5.
