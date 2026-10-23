# Blocked / Partial dossier (Part B)

Audit date 2026-10-18. Source of the Partial list: `items.csv` (53 Partial rows). Effort: S = under 2 days, M = 3-8 days, L = over 8 days
(one developer, with the outside item in hand). "Verified" below means I read it in code or at a source link; "unverified" means I could not.

Nothing here claims compliance with any tax authority. **The system is not FBR compliant** (`app/Services/Fbr/StubFbrGateway.php` sends nothing).

## 1. FBR (Pakistan sales-tax e-invoicing)

| Item (rows) | Built | Not built / unproven |
|---|---|---|
| Pakistan Fiscal Compliance (#303), FBR Audit Trail (#251) | Settings page, submission log, `FbrInvoiceBuilder`, `FbrSubmitter`, `FbrInvoiceGateway` interface, `StubFbrGateway`, QR on the invoice (checked against the QR standard, never against FBR), STRN/NTN fields (`TaxRegistrationPrintTest`), `FbrStubTest` ("a number FBR really issued is the only one the invoice prints") | The real gateway class; the invoice JSON payload against the official schema; item HS codes / sale-type / rate scenario codes; token handling; a sandbox run; the validate-then-post sequence |

**What I could verify on the web (sources):**
* PRAL/FBR publish a "Technical Specification for DI API", version 1.12 (2025) with two web methods, *Validate* and *Post*; the sandbox and production URLs are said to be the same and routing is by security token - [FBR download, DI API technical documentation v1.12](https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf). A sandbox posting endpoint `.../di_data/v1/di/postinvoicedata_sb` is quoted in a search excerpt of that document; **I did not open the PDF end to end, so field names, scenario IDs and error codes are unverified**.
* An earlier document "Technical Specification Digital Invoicing 1.2.3" also exists - [FBR download](https://download1.fbr.gov.pk/Docs/202441821416329TechnicalSpecificationDigitalInvoicing1.2.3.pdf). Which version applies to a given taxpayer is **unverified**.
* Third-party sources (ERP forums, a Frappe app, a Laravel package) list different endpoint names (`postinvoicedata_sbtest`, `postinvoicedatalive`, `validateinvoicedata`). **Treat as unverified**; use only the official PDF.
* Sandbox access needs a PRAL registration and, per one integrator, scenario-based test invoices before production access (unverified, secondary source).
* POS integration for Tier-1 retailers began 2018-19 and became mandatory by Finance Act 2019 (history only, 2020 news) - [Business Recorder](https://www.brecorder.com/news/566742), [FBR press release](https://www.fbr.gov.pk/pr/5783-point-of-sales-integrated-with-fbr/142245/2020). **Which taxpayers must use DI/POS today, and the deadlines, are unverified.** Ask the client's tax adviser or FBR.

**Exact outside things needed:** (1) the client's PRAL/IRIS registration and a sandbox security token; (2) the current official spec PDF and the list of scenario IDs for the client's sector; (3) a decision whether the client falls under DI, POS, or neither; (4) a production token held by the client (we must not hold it).
**Who provides:** the client (registration, token, scope); owner supplies the spec after the client's registration is confirmed.
**Work after unblock:** M-L (real gateway + payload mapping + error mapping + 10-20 scenario runs, retries, idempotency). 
**Live-verification checklist (sandbox only first):**
1. Enter sandbox token in Settings > Tax Registration (encrypted column). Seller NTN/STRN set.
2. Create customer "FBR Test Buyer" (registered, NTN 1234567-8 test value from the sandbox scenario sheet), product with HS code from scenario sheet, 17% tax.
3. Post a sales invoice of 10 x 1000. Press Validate; expect a validation response without errors; save the raw response.
4. Press Post; expect an FBR invoice number; confirm the invoice prints exactly that number and QR (see `FbrStubTest`).
5. Repeat for every scenario in the sector's list (e.g. unregistered buyer, exempt, 3rd schedule, return/credit note).
6. Send a deliberately wrong item code; confirm the error is shown to the user and the invoice stays unsent, retryable once.
7. Kill the network mid-post; confirm no duplicate FBR number on retry.
8. Only after all pass, repeat 2-4 with the production token for one invoice, with the client present.

## 2. Payments

Rows #325 Payment Gateways (Partial), #346 Failed Payment Retry, #349 Auto-Charge, #368 Storefront.
Built: `StripeGateway` (raw HTTP, no SDK), Checkout session, signed webhook verification per company, tests on recorded fixtures (`WaveThreeSaasTest:359,382`).
Not built: any other gateway; stored card / off-session retry; online payment on the portal or the storefront; refunds through the gateway; Pakistani wallets (JazzCash, Easypaisa, PayFast) - **specs unverified, not looked up**.
Needs: Stripe test-mode account and keys (client or owner) - or the chosen local gateway's merchant sandbox and its current API docs. **Who:** owner/client. **Work:** Stripe live check S; a second gateway M each; card-on-file + retry L (and PCI scoping decision).
Checklist: set test keys; create a subscription invoice of 1000; open checkout; pay with Stripe test card `4242 4242 4242 4242`; expect the invoice marked paid once; replay the webhook, expect no second payment; pay with `4000 0000 0000 0002` (declined), expect a clear failure; confirm no card data in `api_logs` or DB.

## 3. Email

Rows #12, #181, #260, #275, #107, plus every "email not proven" note. Built: Laravel mailer, templates, reminders, scheduled reports, payslip mail, tests with `Mail::fake()`.
Needs: working SMTP (or an API mailer) credentials, a sending domain with SPF/DKIM/DMARC; a test inbox. **Who:** client IT. **Work:** S.
Checklist: set `MAIL_*`; Settings > send test mail; check inbox and spam; send an invoice (attachment opens), a payslip, a scheduled CSV; check the Communication log row; unsubscribe/reply-to as agreed.

## 4. WhatsApp / SMS

Rows #107, #275, #311, #329, #12. Built: webhook, WhatsApp Cloud and Twilio adapters on fixtures (`LedgerStatementDeliveryTest`, `WaveFiveDocumentsTest:396`).
Needs: a Meta WhatsApp Business account with approved message templates (WhatsApp rules on template approval and 24-hour windows are **unverified here**), or a Twilio account and a verified sender; local SMS vendor if preferred. **Who:** client. **Work:** S per provider after approval (template approval takes the client days).
Checklist: connect provider; send a template message to the tester's own number; send the statement link; verify the link opens only that statement and expires; send with a wrong number, see the failure logged; opt-out wording agreed.

## 5. Bank feeds / statements

Rows #326, #123, #183. Built: CSV/OFX/MT940 parsers (`AccountsCloseoutTest:155,177`), reconciliation, `HttpJsonBankFeed` (generic HTTPS JSON). Not built: any real bank API; Pakistani bank file formats; Plaid/Open Banking.
Needs: the bank's file specification or API access (bank-specific, client asks the bank). **Work:** M per bank. Salary file: the bank's required layout, M.
Checklist: export a real statement; import; auto-match; unmatched lines listed; charge booked; repeat import adds no duplicates.

## 6. Couriers / e-commerce

Rows #328, #327, #108, #368. Built: `PostExAdapter`, `LeopardsAdapter` (recorded fixtures), `ShopifyAdapter`, `WooCommerceAdapter`, `MockEcommerceAdapter`, order import (`ChannelAdaptersTest`).
Needs: courier merchant accounts and API keys; a Shopify/Woo store with API credentials (a dev store is enough). **Work:** S-M each. Checklist: book one parcel, print the airway bill, track to delivered; place a store order of an unknown SKU and a known SKU, expect a draft sale and a kept failure; cancel in store, expect no sale.

## 7. Push notifications

Row #312. Built: RFC 8291/8292 encryption verified against the RFC example (`WaveSixSystemTest:222`). Needs: a real browser subscription on https and a VAPID key pair; **Work:** S. Checklist: subscribe on Chrome desktop and Android; send a test; expect delivery; unsubscribe; revoked subscription is removed after a 410.

## 8. Backup

Row #315. Built: local backups, download, remote-disk copy by a configured Laravel disk (`WaveSixSystemTest:418`). Not built: S3/Drive adapter packages not installed (`league/flysystem-aws-s3-v3` absent from `composer.json`) - needs approval to add a dependency; restore with real `mysql` never run in this audit or the tests (sqlite). **Work:** S (S3) + M (restore drill with encryption). Checklist: take a backup; copy to bucket; download and decrypt on another machine; restore into an empty database; run login + trial balance and compare totals.

## 9. Biometric devices

Row #176. Built: token-authenticated push endpoint + CSV import (`WaveSevenHrmTest:231`). Needs: the physical device model(s) and their push protocol/SDK; **Work:** M per vendor. Checklist: enrol 3 test staff; punch in/out; replay a punch (ignored); offline device sends a backlog; late/overtime figures match the shift rules.

## 10. Domain / white label

Row #340. Built: DNS TXT verification and branding by host. Needs: the client's domain, DNS access, vhost + TLS certificate on the server (owner ops). **Work:** S. Checklist: add domain; create TXT; verify; open the domain; login page shows client branding; certificate valid; other tenants unaffected.

## 11. PDF

Rows #258, #266, #270, #181. Only the contact **statement** has a server-made PDF (`app/Services/Documents/PdfWriter.php`, a small hand-written writer used by `ContactStatement::pdf`, attached to the statement email, `LedgerStatementDeliveryTest:64`); invoices, payslips and reports have none (no PDF library in `composer.json`; client-side jsPDF in the tables only). The Urdu/Arabic shaping capability of `PdfWriter` is unproven. Needs: **decision** (add a PDF dependency such as dompdf/Browsershot vs. keep "print to PDF") and, for Urdu/Arabic, a font that shapes correctly. **Work:** M (L with RTL fonts). Checklist: render a 3-page invoice, a payslip, a statement; compare totals with screen; Urdu text shapes; attach to email.

## 12. Internationalisation (language)

Rows #297, #269, #296. Built: dictionary + `t()` framework with an Urdu word list (26 keys in `resources/js/lang/index.ts`). **`t(` is called from zero `.vue` files in `resources/js`**; menu names come from the database. Needs: a decision on target languages and a translator, plus the extraction work. **Work:** L (see international-readiness.md, dimension 4).

## 13. Other Partial rows without an outside dependency (code or decision only)

| Row | Missing | Needs | Size |
|---|---|---|---|
| #45 Consignment | settlement posting a payable | owner decision on accounting | S |
| #64 Import purchases | LC/customs/clearing | spec of the client's import flow | M |
| #86/#90 POS touch, parked sales | tablet test, server-side parked sale | a tablet; decision | S-M |
| #110/#117 Multi-currency vouchers, inter-company | document-level FX, separate-company consolidation | decision (see Part C) | L |
| #158/#159 LIFO, per-sale COGS | perpetual inventory posting | accountant decision, `docs/inventory-valuation-investigation.md` | L |
| #175/#179/#182 HR endpoints | tests for shifts, roster, components, rates, tax slabs | none | S |
| #183/#186 Salary bank file, gratuity | bank layout; legal rules | accountant + bank | M |
| #263 Canvas builder | free-position layout | design decision | L |
| #293 Branch hierarchy, #296 formats, #306/#308 settings, #309 cash drawer, #316 retention, #318 form builder, #338 export attachments, #343 add-ons | listed in `items.csv` Why | none external | S-M each |
| #314/#322 Import undo, migration | master-data undo; real Tally/QuickBooks exports | sample exports from a client | M |
