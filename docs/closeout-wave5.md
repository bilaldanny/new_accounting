# Closeout wave 5: Documents and communication (13 items)

Evidence: `tests/Feature/WaveFiveDocumentsTest.php` (17 tests), `tests/Browser/DocumentCenterSmokeTest.php` (designer preview in a real
browser, with a screenshot) and the page-load Dusk test of `/documentcenter`.

Migrations to run live, in order (none has been run):

1. `2026_11_16_100000_create_documents_communication_tables` (`document_templates`, `message_templates`, `document_reminder_rules`,
   `document_reminder_log`, `document_attachments`, `custom_field_definitions`, `custom_field_values`, `document_delivery_settings`)
2. `2026_11_16_100100_add_documents_communication_menus` (Document Center + Manage permission)

Scheduler: `documents:send-reminders` daily at 08:00 (the server's cron must call `schedule:run`; it can be run by hand).

## What changes for existing screens

* A company with no template prints exactly as before. The new print page is `/api/documents/{id}/render` (built-in design when no
  template exists); the old invoice and receipt pages are untouched.
* The public receipt link (`/r/{token}`) uses the company's **default receipt template** when there is one; without it the old page is
  served (tested both ways).
* `SellController::store` calls `DocumentDelivery::afterSale()` after the commit; it returns at once unless the company switched
  instant delivery on, and it never throws (a failed delivery never fails the sale; tested).
* The sell view page gained a collapsed "Documents, export and attachments" panel (`DocumentTools.vue`).
* `Company::storageUsedBytes()` also counts document attachments.
* Printing, exporting, sending and previewing change nothing in the books or stock (test "changes nothing").

## The items

1. **Invoice designer, block based: Built.** Fourteen block types (header, title, parties, lines, totals, text, terms, notes, custom
   fields, QR, signature, footer, divider, spacer) stored as JSON. Anything not in `DocumentBlocks::SCHEMA` is refused (type) or
   dropped (property); texts are stripped of markup and cut; numbers are clamped; at most 40 blocks.
2. **Templates by branch and type with a default: Built.** Types: invoice, quotation, receipt, sales return, purchase order.
   Choice order: template asked for, branch default, company default, built-in. One default per company / branch / type / language.
3. **Branding with a CSS allowlist: Built.** Structured properties only (three colours as `#rrggbb`, a font from four families, font
   size, logo height, border and table style). No free CSS and no URL is accepted (tests for injection attempts).
4. **Header, footer, terms, signature: Built.** Terms and footer default to the company's Invoice Settings; signature labels are
   editable; the NTN / STRN follow the Invoice Settings switch; an FBR number prints only when FBR really issued it.
5. **QR code: Partial.** Own encoder (byte mode, level M, versions 1-9, up to 180 bytes), rendered as an inline SVG of the share
   link or the document number. Verified against the published Reed-Solomon reference codewords and by a decoder written from the
   specification. It has not been read by a phone scanner here: scan the first printed code once.
6. **Custom fields on the invoice: Built.** Definitions per company (text, number, date, select, checkbox; required; print flag) and
   values per sale or purchase, validated by type, written only for a record of the company and printed by the `custom_fields`
   block. The same engine serves contacts and products for the settings wave.
7. **Multi-language printing: Built.** English and Urdu word sets, right-to-left layout for Urdu, Western digits in both.
8. **PDF: Partial.** No PDF library is installed and adding a dependency needs approval. The same HTML carries `@page` sizes, so the
   browser's Print / Save as PDF gives a PDF on A4, A5 or a thermal roll; there is no server-side PDF file.
9. **Thermal printing: Built.** 80 mm and 58 mm papers with compact lines (item, quantity, amount).
10. **Excel / CSV export: Built.** CSV (with BOM) and a real `.xlsx` (own minimal writer, no formula is ever written; text starting
    with `= + - @` is prefixed). Open one in Excel once (checklist).
11. **Send by link, email, WhatsApp, SMS: Partial (delivery needs live providers).** Link is built and tested. Email goes through the
    app's mailer and WhatsApp / SMS through `MessageDispatcher` (webhook, WhatsApp Cloud, Twilio adapters); with no provider
    configured the share is recorded `not_configured` and nothing is claimed as sent. Tested with the array mailer and a faked
    webhook; not run against a live SMTP server or provider.
12. **Email / SMS / WhatsApp message templates: Built.** By event (invoice created, receipt, due soon, due today, overdue, payment
    received, custom), channel and language; placeholders replaced as plain text (markup stripped); company default else built-in.
13. **Automated due and overdue reminders: Built.** Rules (before due / on due / after due, once or every N days up to a maximum),
    daily command, once per rule and invoice per day (unique log row), not beyond the maximum, skipped for settled invoices, disputed
    / escalated / closed collection cases and customers with no contact detail; every attempt is logged. Separate from Dunning (a
    reviewed manual selection).
14. **Instant delivery: Built (mechanism).** A company setting (channel, language) that sends a posted sale as soon as it is saved;
    the delivery itself is item 11.
15. **Attachments on sales and purchases: Built.** PDF, images, Word, Excel, CSV, text up to 5 MB, real type checked, storage limit
    enforced, found only through their document (company scoped), listed / downloaded with view and uploaded / deleted with edit.

## Live checks

* Scan the first printed QR code with a phone and open the Excel export.
* Send one invoice by email to a real mailbox, and by WhatsApp / SMS once a provider is configured in Messaging Settings.
* Switch instant delivery on for a test company, save one sale, confirm one email and one `receipt_shares` row.
* Run `php artisan documents:send-reminders` against a company with one overdue invoice and a rule; confirm the log.

## Known limitations

* Instant delivery covers the sell form and POS save (`SellController::store`), not imports, recurring invoices or the offline POS sync.
* Custom fields are filled from the sale view page; they are not on the sale form itself yet.
* The designer is a block list with property fields, not drag and drop. Preview uses sample data or a chosen document.
* A receipt template must exist for the public link to use a design; invoice templates apply to the print page and exports only.
