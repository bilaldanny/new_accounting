# Contacts module closeout (2026-11)

The seven items the October re-audit (`full-reaudit-2026-10.csv`, module "2. Contacts") listed as Partial are built.
This file is the evidence for each one: where it lives, what it does, and which test proves it. Nothing here
changes ledger or GL posting logic; the only posting involved (opening balances on import) goes through the
code the add form already uses.

Live migrations to run, in this order (nothing has been run on the live database):

1. `2026_11_03_100000_add_contact_import_menus` - hidden `/customer/import`, `/supplier/import` rows
2. `2026_11_03_110000_add_credit_limit_mode_to_company_settings` - `company_settings.credit_limit_mode`
3. `2026_11_03_120000_create_contact_attachments_table`
4. `2026_11_03_130000_add_contact_document_menus` - hidden `/customer/documents`, `/supplier/documents` rows (after 1)
5. `2026_11_03_140000_create_portal_requests_table` - `portal_requests` and `company_settings.portal_payment_info`
6. `2026_11_03_150000_add_portal_requests_menu` - Portal Requests page (Contacts group, next to Customer) and its approve row

The menu migrations grant their rows to the global `companyadmin` role. The code tolerates the credit-limit and
payment-info columns being absent (it behaves as "hard stop" and "no instructions"), so deploying before migrating
does not break the app.

## 1. Customer and Supplier import

- Routes: `POST /api/customers/import`, `POST /api/suppliers/import` (permission `/customer/import`,
  `/supplier/import`). Body `{rows, dry_run?, allow_duplicates?}`; at most 1000 rows.
- Code: `App\Services\ContactImporter`, `App\Http\Controllers\Concerns\ImportsContacts`,
  `resources/js/pages/contact/ContactImport.vue` (Import button on both lists).
- Template: downloaded from the modal. Columns: business_name, first_name, last_name, mobile, email, address,
  ntn_number, strn_number, code, opening_balance, credit_limit, pay_term, pay_type, type. Only business_name and
  mobile are required.
- Check run: the same code runs inside a transaction that is rolled back (`dry_run`), so the check reports exactly what
  the import would do, including account-mapping problems. The modal always checks first and writes only a clean file.
- Row errors name the row (`Row 3: ...`); any error rejects the whole file (nothing saved), as every other import does.
- Duplicates: a row sharing email, mobile or business name (case and spaces ignored) with a contact of the company,
  or with an earlier row of the same file, is skipped and listed; `allow_duplicates` imports them anyway.
- Scoping: a company user's rows always go to their own company (a company id in the file or request is ignored);
  a branch user's go to their branch; a superadmin names the company.
- Opening balance: `Contact::createCustomer()` / `createSupplier()` is called per row, which links the account and posts
  the balance through `ContactChartOfAccountLinker` exactly as the add form. A balance with no account mapping is a row
  error, not a silently dropped balance.
- Tests: `tests/Feature/ContactImportTest.php` (valid file, check run, bad rows, duplicates, tenant isolation,
  permission, opening balance posts to `account_balances`, missing mapping).

## 2. Credit controls: soft stop / warn mode

- Setting: `company_settings.credit_limit_mode` = `hard` (default; NULL reads as hard), `warn`, `off`. Company Settings >
  Sell tab ("Customer Credit Limit").
- Code: `App\Services\CustomerCreditLimit`, `CompanySetting::creditLimitMode()`,
  `CreditLimitExceededException` (`credit_limit_warning`), the axios interceptor in `resources/js/app.ts`.
- Warn: the save is refused with the figures and `code: credit_limit_warning, requires_override_reason: true`; the screen
  asks for a reason and repeats the request with `credit_override_reason` (min 3 characters). The override is written to
  `audit_logs` (`event: credit_limit_override`, on the customer, with reason, invoice, limit, balance, sale amount,
  projected balance). Applies to new sales, edits that grow a sale, and moving a draft to final.
- Hard (and no setting): the response is byte-for-byte what it was, no new key. A reason sent in hard mode does nothing.
- Tests: `tests/Feature/CreditLimitModeTest.php`, and the unchanged `tests/Feature/CustomerCreditLimitTest.php`.

## 3. Customer and supplier documents

- Routes: `GET|POST /api/{customers|suppliers}/{id}/attachments`, `GET .../{attachmentId}/download`,
  `DELETE .../{attachmentId}`. List and download need the contact's view permission (`/customer/:id/view`,
  `/supplier/:id/view`); upload and delete need `/customer/documents`, `/supplier/documents`.
- Code: `ContactAttachmentController`, `ContactAttachment`, `contact_attachments`,
  `resources/js/pages/contact/ContactDocuments.vue` (the Documents tab of both view pages).
- Limits: 5 MB per file; type judged from the file's content (PDF, JPG, PNG; Word, Excel, CSV, text only when the
  extension agrees with the detected type). Files sit on the private `local` disk and are served only by the
  authenticated download route. Counted in `Company::storageUsedBytes()` and refused at the plan's storage limit.
- Scoping: a document is only ever found through a contact the caller can see, inside that contact's company.
- Tests: `tests/Feature/ContactAttachmentTest.php`.

## 4. Portal upgrade (customer and supplier)

- Customer: `GET /api/portal/invoices`, `/portal/invoices/{id}` (detail with lines, totals, payments; printed or saved
  as PDF in the browser), `/portal/statement?from&to` (PDF and CSV in the browser), `/portal/products`,
  `GET|POST /portal/requests`, `POST /portal/requests/{id}/cancel`.
- Supplier: `GET /api/portal/purchase-orders`, `/portal/purchase-orders/{id}` (status and receipt progress),
  `GET|POST /portal/bills`, `GET /portal/bills/{id}/file`.
- Both: `GET /api/portal/payment-info` shows the company's "Portal payment instructions" (Company Settings > Sell).
  No online payment: the model stays manual.
- Requests: kept in `portal_requests`, never in the books. Staff review them in Contacts > Portal Requests
  (`/portalrequests`, approve/reject permission `/portalrequests/approve`; the Manager preset gets both):
  a quotation request is approved into a `quotation`, an order request into a `draft` sale (through
  `Transaction::createSell`, so numbering and tax are the sale form's; no ledger posting). A supplier bill is approved as
  "accepted for entry" or rejected with a reason; sending it never changes what is owed.
- Isolation: every portal query starts from the contact the signed-in user is tied to (`ResolvesPortalContact`); an id
  in the URL is only ever looked up under that contact and returns 404 otherwise. A customer is refused supplier
  endpoints and the reverse; staff users are refused portal endpoints; portal users stay refused by every staff
  endpoint (`RestrictPortalUsers` allow-list extended by name).
- Rate limits: `throttle:portal` 60/min and `throttle:portal-submit` 10/min, per account.
- Tests: `tests/Feature/PortalUpgradeTest.php`, `tests/Feature/CustomerPortalTest.php` (unchanged),
  `tests/Browser/ContactsCloseoutTest.php`.

## Not covered

- No server-side PDF (the app has none): invoice and statement PDFs are made in the browser (jsPDF), as table exports are.
- Portal products show the company's whole active catalogue with that customer's price; there is no per-customer
  catalogue restriction.
- An approved bill is a record that staff accepted it; entering the purchase bill is still done on the purchase screens.
- The importer creates only; it does not update existing contacts (unlike the products import, contacts have no
  natural key in a file).
