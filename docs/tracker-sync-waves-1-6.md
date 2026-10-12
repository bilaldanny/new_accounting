# Tracker sync: waves 1-6 (modules 1, 8, 11-18)

Source of the item names and the old status: `full-reaudit-2026-10.csv` (repository root), every row of these modules whose old
status was Partial or Missing: **94 rows**. Status here = what exists in the code after waves 1-6 (nothing is migrated live).
Test column: `W1` = `tests/Feature/WaveOneTest.php`, `W2` = `WaveTwoApprovalCenterTest.php`, `W3` = `WaveThreeSaasTest.php`,
`W4` = `WaveFourReportsTest.php`, `W5` = `WaveFiveDocumentsTest.php`, `W6` = `WaveSixSystemTest.php` (all in `tests/Feature/`);
`Dusk` = a page-load or smoke test in `tests/Browser/`.

Tally: see `tracker-sync-final.md` (this file is regenerated into it).

## Things to know before reading the table

1. **Wave 1's six Dashboard items were not the six rows of the audit.** Wave 1 was written when the audit CSV was not looked for
   and it built six other dashboard gaps (top customers, top products, expenses, cash in / out, personal layout, CSV export).
   The six audit rows (role dashboards, filters, widget builder, notification center, daily summary, universal search) are
   therefore Not built or Partial below. They are the honest open items of waves 1-6.
2. Items marked **Partial** have the same reason every time: the part that needs an outside account, device or provider could not be
   proven here (see the live checklist of the wave doc), or a part of the row was left out and is named.
3. Rows that were fixed before wave 1 by an earlier round (period lock, session timeout, API log page) are marked with the
   earlier test.

| Module | Item name (as in the tracker) | Status | Why (Partial / Not built) | Test |
|---|---|---|---|---|
| 1. Dashboard & BI | Role-Based Dashboards (Owner, Accountant, Sales Manager, Warehouse Incharge, Cashier) | Built | Wave 1b: own layout, else the company layout of the role, else a built-in layout by kind of role; a card still needs its permission. | W1B "a user gets their own layout, else the company layout of their role, else the built-in layout of that kind of role" |
| 1. Dashboard & BI | Date / Branch / Warehouse Filters | Built | Wave 1b: date range and warehouse on the sales and purchases cards (branch and as-of date existed); ledger-based cards have no warehouse. | W1B "the sales card takes a date range and a warehouse, and without them answers exactly as before" |
| 1. Dashboard & BI | Custom Widget Builder & Dashboard Layout Customization | Built | Wave 1b: KPI widgets from the figures of reports the user may open; the layout now drives the main dashboard (hide, order inside each row). | W1B "a user builds KPI widgets from the figures of reports they may open ..."; Dusk DashboardToolsSmokeTest |
| 1. Dashboard & BI | Notification Center (in-app alerts) (New) | Built | Wave 1b: the bell lists stored unread notifications with mark read / mark all read, beside the live approval counts. | W1 "a follow-up due soon or overdue reminds its assignee once ..."; Dusk DashboardToolsSmokeTest |
| 1. Dashboard & BI | Daily Business Summary (auto Email/WhatsApp) (New) | Partial | Wave 1b: figures, settings, hourly command, once-a-day per recipient and the run log are built and tested; delivery through a real SMTP server / WhatsApp provider is not proven. | W1B "the daily summary works out the day ...", "the daily summary goes to a phone through the company messaging provider ..." |
| 1. Dashboard & BI | Universal Search Bar | Built | Wave 1b: `/api/search` over pages, customers, suppliers, products, sales, purchases and vouchers, permission and company scoped. | W1B "the search bar finds pages, contacts, products and documents the user may open, inside their company only"; Dusk DashboardToolsSmokeTest |
| 8. CRM | Lead Assignment & Sales Rep Allocation Rules | Built | | W1 "a lead with no owner is allocated in turn ..." and "allocation rules by source ... company scoped" |
| 8. CRM | Follow-ups, Reminders, Tasks, Calls & Meetings | Built | Email not proven against live SMTP. | W1 "a follow-up due soon or overdue reminds its assignee once ..." |
| 11. Approval Center | Discount & Price List | Built | Wave 1b: price lists are now in the workflow registry (their approve route is gated like the other documents). | W1B-Approval "a price list can be put behind an approval workflow"; W2 "discount codes need approval once the company asks for it ..." |
| 11. Approval Center | Workflow Builder | Built | Wave 1b: approvers are picked from the users and roles of the company (the text entry is gone). | W2 "a two level workflow takes the approvers in order ..."; W1B-Approval "the directory lists the people, roles and departments of the company only" |
| 11. Approval Center | Centralized Approval Inbox & Outbox | Built | | W2 "the inbox shows a user only what waits for them ..." |
| 11. Approval Center | Multi-Level Approval Workflows | Built | | W2 "a two level workflow ..." and "a parallel level needs every approver ..." |
| 11. Approval Center | Trigger Rules (Amount, Role, Branch, Department) | Built | Wave 1b: the department rule is evaluated against the department of the person who made the document. | W1B-Approval "a workflow with a department trigger applies only to documents made by people of that department" |
| 11. Approval Center | Approval Delegation (during leave) | Built | | W2 "a delegate acts for an approver who is away ..." |
| 11. Approval Center | Approval History & Instant Notifications | Built | In-app and email notices; email not proven against live SMTP. | W2 "the inbox shows a user only what waits for them, ... approvers are notified once" |
| 12. Reports | Profit & Loss (Comparative, Multi-Period) | Built | | W4 "the comparative profit and loss lines up two periods ..." |
| 12. Reports | Balance Sheet (Comparative, Multi-Period) | Built | Inventory at cost is a memo line outside the totals (no inventory account in the ledger). | W4 "the comparative balance sheet shows two days, stays balanced ..." |
| 12. Reports | Cash Flow Statement (Direct & Indirect) | Built | | W4 "the indirect cash flow reconciles to the direct method" |
| 12. Reports | Sales by Customer/Product/Category/Salesperson/Branch/Warehouse/Payment Method | Built | Payment method is by payment date (a cash view). | W4 "sales by salesperson, warehouse and payment method net the returns ..." |
| 12. Reports | Commission & Sales Target Reports | Built | | W4 "the commission and target reports are the KPI statement of the month" |
| 12. Reports | Sales Forecasting | Built | An estimate from history, labelled so. | W4 "the sales forecast report is the dashboard forecast engine" |
| 12. Reports | Stock Adjustment, Transfer & Stock Take Reports | Built | Adjustment and transfer reports existed; stock take variance report added. | W4 "stock take variance values the difference at the unit cost of the count date" |
| 12. Reports | Advance Report | Built | | W4 "the advance report lists customer advances with what is left" |
| 12. Reports | Withholding Tax Report | Built | Not netted for returns. | W4 "withholding, exemptions and tax liability read the documents" |
| 12. Reports | Tax Exemption Report | Built | Uses the tax's stored percentage. | W4 "withholding, exemptions and tax liability read the documents" |
| 12. Reports | Tax Liability & Input/Output Reconciliation | Built | Ledger side reads 0 until the output / input tax accounts are mapped (flag shown). | W4 "withholding, exemptions and tax liability read the documents" |
| 12. Reports | FBR Audit Trail & Sales Ledger Reports | Partial | The FBR gateway is a stub: the trail logs attempts and says so; nothing was ever sent to FBR. | W4 "the FBR audit trail lists submissions and says the gateway is a stub" |
| 12. Reports | Profit by Product, Customer, Branch, Salesperson, Warehouse | Built | | W4 "profit by customer, branch, salesperson and warehouse agrees with item profit and loss" |
| 12. Reports | Dashboard Analytics | Built | | W4 "the analytics overview is read only and company scoped"; Dusk `ReportToolsSmokeTest` |
| 12. Reports | Custom Report Builder (New) | Built | Six datasets, no joins or calculated columns, 5000-row cap. | W4 "the report builder runs whitelisted datasets ..." and "a saved custom report is private or shared ..." |
| 12. Reports | Scheduled Reports (New) | Partial | Schedule, due logic and CSV are tested with a faked mailer; real SMTP delivery not proven. | W4 "a scheduled report is mailed as a CSV when due ..." |
| 12. Reports | Saved Filters & Favorite Reports (New) | Built | | W4 "saved filters are the user own, can be favourites ..." |
| 13. Documents | Invoice Designer (custom design) | Built | Block list with property fields. | W5 "block lists are checked against the whitelist ..." |
| 13. Documents | Drag & Drop Invoice/Receipt Builder | Built | Wave 1b: blocks are dragged by their handle to reorder (up / down buttons stay); layout is a block list, not a free-position canvas. | W5 "block lists are checked against the whitelist ..."; Dusk DocumentCenterSmokeTest "a block of the designer is moved by dragging its handle" |
| 13. Documents | Multiple Templates (Branch/Business-Type) | Built | Business types = document types (invoice, quotation, receipt, return, PO). | W5 "templates are the company own, one default per branch and type ..." |
| 13. Documents | Branding (Logo, Fonts, Colors, Custom CSS) | Built | "Custom CSS" is an allowlist of structured properties; free CSS is refused on purpose. | W5 "the renderer escapes everything, refuses unsafe logos ..." |
| 13. Documents | Header/Footer, T&Cs, Signature Block, QR Code | Partial | QR encoder verified against the published reference and a spec decoder but never read by a phone scanner. | W5 "the QR encoder matches the published error correction vector and decodes back to its text" |
| 13. Documents | Custom Fields on Invoice | Built | Filled from the sale view page, not on the sale form. | W5 "custom fields are defined per company, validated by type ..." |
| 13. Documents | Default Template Setting | Built | | W5 "templates are the company own, one default per branch and type ..." and "the public receipt uses the default receipt template ..." |
| 13. Documents | Multi-Language Invoice Printing | Built | English and Urdu word sets only. | W5 "the renderer escapes everything, ... lays Urdu out right to left" |
| 13. Documents | Export & Send (PDF, Thermal, Excel, Web Link, Email, WhatsApp) | Partial | No server-side PDF (no library installed; browser Save as PDF from the @page HTML); email / WhatsApp not proven against live providers. | W5 "a sale exports to CSV and Excel ..." and "sending by link, whatsapp without a provider and with a webhook provider ..." |
| 13. Documents | Email, SMS & WhatsApp Templates | Built | | W5 "message templates fill their placeholders ..." |
| 13. Documents | Automated Reminders (Payment Due, Overdue, Invoice) | Built | Live delivery rides on the providers above. | W5 "due and overdue reminders go once per rule ..." and "reminders of one company never touch another ..." |
| 13. Documents | Instant Document Delivery via WhatsApp/Email | Partial | The send-on-save mechanism is proven; actual delivery needs a live provider. | W5 "instant delivery sends a posted sale when switched on, and a failed delivery never fails the sale" |
| 13. Documents | Document Attachments | Built | Sales and purchases (contacts and products keep their existing attachments only). | W5 "attachments are checked by their real type ..." |
| 14. User Mgmt & Security | IP Restrictions & Whitelisting | Built | | W1 "an IP allow list refuses other addresses ..." |
| 14. User Mgmt & Security | Session Timeout, Device Management & Login History | Built | API-token sessions are not tracked (browser sessions only). | W1 "sign-ins and failures are logged, exported and company scoped, and sessions are listed and ended" |
| 14. User Mgmt & Security | Password Policy | Built | | W1 "the password policy enforces length ..." and "an expired password is sent to change it ..." |
| 14. User Mgmt & Security | Security Logs & Export/Download Logs | Built | | W1 "sign-ins and failures are logged, exported ..." |
| 14. User Mgmt & Security | Back-Dated/Post-Dated Entry Restriction (New) | Built | Done by the earlier period-lock round (`max_backdate_days`, `allow_future_dates`), not in waves 1-6. | `PeriodLockTest.php`; Dusk `PeriodLockTest` |
| 14. User Mgmt & Security | Maker-Checker/Segregation of Duties (New) | Built | Off by default (company switch). | W1 "an expired password is sent to change it, and maker-checker stops a creator approving their own voucher" |
| 14. User Mgmt & Security | Personal Data Export & Deletion (Privacy) (New) | Built | Deletion is anonymisation; the books keep the record. | W1 "personal data of a contact is exported and anonymised for good ..." |
| 15. Settings | Multi-Branch & Business Locations Hierarchy | Built | No report roll-up by subtree; branch form has no parent field (tree API / page only). | W6 "branches can be arranged in a tree without cycles ..." |
| 15. Settings | Financial Years (already built) & Period Lock | Built | Period lock and close done by the earlier period-lock round. | `PeriodLockTest.php`; Dusk `PeriodLockTest` |
| 15. Settings | Currency, Multi-Currency & Exchange Rates (Live Sync) | Partial | Vouchers in foreign currency are built (earlier round); the rate feed is tested against a recorded answer only. | W6 "the rate sync writes dated rates for known currencies ..."; `ForeignCurrencyTest.php` |
| 15. Settings | Regional Settings (Timezone, Date, Number Formats) | Built | Printed documents and pages with their own number code still use the standard format. | W6 "business settings default to the format the application always used ..." |
| 15. Settings | Multi-Language Support | Partial | Framework + English / Urdu small word list; menus and almost every page remain English. | W6 "business settings default ..." (language is a business setting); no test of full coverage |
| 15. Settings | Pakistan Fiscal Compliance (FBR e-Invoicing/POS) | Partial | Unchanged stub: nothing is sent to FBR; needs the verified FBR specification and credentials. | `FbrStubTest.php`; W4 "the FBR audit trail ..." |
| 15. Settings | POS Settings | Built | Consolidated as one page with the existing settings linked. | W6 "business settings default ..." and "another company keeps its own format ..." |
| 15. Settings | Cash Drawer | Partial | The setting is stored and validated; the POS does not read it and no hardware pulse was tested. | W6 "business settings default ... validate what they save" |
| 15. Settings | Notification Templates | Built | | W6 "a notification template wraps the application wording for its company only" |
| 15. Settings | Email, SMS & WhatsApp Gateways | Partial | Webhook, WhatsApp Cloud and Twilio adapters from earlier rounds tested on fixtures; no live provider run. | `ChannelAdaptersTest.php`; W5 "sending by link, whatsapp ..." |
| 15. Settings | Push Notifications | Partial | RFC 8291 / 8292 verified by the RFC worked example; never run against a real browser vendor push service. | W6 "web push encryption follows the worked example of RFC 8291" (+ 2 more push tests) |
| 15. Settings | Import/Export Center (centralized) | Built | | W6 "exports are the company own, templates match the importer ..." |
| 15. Settings | Import History with Undo/Rollback (New) | Partial | Customers, suppliers and (wave 1b) products are recorded and undone while unused; master-data importers (brands, categories, units, ...) and updates of existing records are not tracked. | W6 "an import is recorded and can be undone while its records are untouched" |
| 15. Settings | Backup & Restore (Google Drive, AWS S3, Local) | Partial | S3 / Google Drive adapter packages not installed (dependency change needs approval); real `mysql` restore run not tried. | W6 "a backup is copied to a configured remote disk and restored only with the typed confirmation ..." |
| 15. Settings | Data Retention & Archiving | Built | Log tables only, by design. | W6 "retention removes only old log rows of its own company ..." |
| 15. Settings | System Logs | Built | | W6 "the log viewer lists entries newest first, filters, masks secrets ..." |
| 15. Settings | Custom Fields & Forms Builder (New) | Partial | Custom fields built; no form layout builder. | W6 "custom field values of a contact or product need that record page permission ..."; W5 "custom fields are defined per company ..." |
| 15. Settings | Franchise/Multi-Owner Management | Built | | W6 "a franchise owner sees only the member companies ..." |
| 15. Settings | Consolidated Business Settings | Built | Single page of the company's settings with links; multi-company rollup is the franchise page (owner decision in the audit). | W6 "a franchise owner sees only the member companies ..."; Dusk `/businesssettings` |
| 15. Settings | Setup Wizard (New) | Built | | W6 "the setup wizard follows what exists ..." |
| 15. Settings | Data Migration Tool (Excel, Tally, QuickBooks) (New) | Partial | Template import built; Tally and QuickBooks mappers verified on sample fixtures only (real export layouts vary); contacts only. | W6 "contacts migrate from a template, a Tally export and a QuickBooks list ..." |
| 15. Settings | Demo/Sandbox Data (New) | Built | | W6 "a demo company is separate, shows its password once ..." |
| 15. Settings | In-App Help Center & Support Tickets (New) | Built | | W6 "the help center shows published articles ..."; W3 "support tickets are the tenant own ..." |
| 16. Integrations | Payment Gateways & Online Payment APIs | Partial | Stripe Checkout adapter and signed webhook verified on fixtures; no live test-mode run. | W3 "the Stripe adapter creates a checkout ..." and "a paid checkout webhook marks the subscription invoice paid once ..." |
| 16. Integrations | Bank Integrations | Partial | HTTPS JSON feed adapter on a fixture; no real bank endpoint. | W3 "the bank feed returns statement lines in the reconciliation shape ..." |
| 16. Integrations | E-commerce (Shopify, WooCommerce) | Partial | Adapter and mock built in the earlier module round; live platforms unverified. | `ChannelAdaptersTest.php` |
| 16. Integrations | Courier & Shipping APIs (Airway Bill, Tracking) | Partial | PostEx / Leopards adapters on recorded fixtures only. | W3 "the courier adapters send the documented requests ..." and "a courier error is reported ..." |
| 16. Integrations | Messaging Gateways (WhatsApp Business API, SMS, SMTP) | Partial | Adapters tested on fixtures; no live provider. | `ChannelAdaptersTest.php` |
| 16. Integrations | API Logs & Interactive Documentation (Swagger/Postman) | Built | OpenAPI 3.0.3 and Postman v2.1 generated from routes; request bodies are free-form. | W3 "the OpenAPI document and the Postman collection list the API routes and need permission" |
| 17. SaaS Billing | Feature, User, Branch, Warehouse, Product, Invoice & Storage Limits | Built | | W3 "a plan with a feature list switches the other features off ..." |
| 17. SaaS Billing | Package / Subscription Settings | Built | | W3 "a plan with a feature list ..." (plan `settings` JSON read by `PlanFeatures::setting`) |
| 17. SaaS Billing | Tenant Usage Analytics & Storage Monitor | Built | | W3 "usage is snapshotted once a day and the trend shows the change" |
| 17. SaaS Billing | Tenant Data Management (Export / Delete on Cancellation) | Built | Attachments (files) are not in the export ZIP. | W3 "a tenant exports its own data as a zip ..." and "deletion needs a cancelled subscription ..." |
| 17. SaaS Billing | Tenant Onboarding & Support Ticketing (New) | Built | | W3 "the onboarding checklist follows what the company has set up" and "support tickets are the tenant own ..." |
| 17. SaaS Billing | White-Label Branding & Custom Domain (New) | Partial | Branding and DNS-TXT verification built; serving on the customer's domain needs DNS, vhost and SSL, not provable here. | W3 "a custom domain is verified by its DNS record ..." |
| 17. SaaS Billing | Reseller / Partner Management (New) | Built | | W3 "a partner earns its commission on the invoices its companies paid in the period" |
| 17. SaaS Billing | Upgrade, Downgrade, Plan Change & Add-ons | Built | | W3 "active add-ons are billed with the plan ..." |
| 17. SaaS Billing | Renewal & Cancellation | Built | Command scheduled daily 05:00 (`tenants:run-billing`). | W3 "a subscription set to end at the period end is cancelled when the period ends, and can be renewed before" |
| 17. SaaS Billing | Failed Payment Retry & Auto-Suspension | Partial | Reminders, suspension after grace days and auto-reactivation are built; an automatic charge retry needs a stored-card gateway and is not written. | W3 "overdue invoices get reminders once, an account unpaid past the grace days is suspended and paying reactivates it" |
| 18. Customer Subscription | Automated Recurring Invoicing & Auto-Charge | Partial | Auto-invoice, renewal and reminders built; auto-charge of a card is not possible without a gateway (payment stays manual). | W1 "billing generates the next invoice when a period ends, reminds before the due date ..." |
| 18. Customer Subscription | Dunning & Failed Payment Recovery | Built | Reminder levels, not a card retry. | W1 "billing generates the next invoice ... applies the dunning levels once" |
| 18. Customer Subscription | Pro-Rated Refunds & Payment History | Built | | W1 "cancelling refunds the unused part of the paid period pro rata, and only when asked" |

## Follow-up the table exposed, and wave 1b

The first pass of wave 1 did not take the six dashboard rows of the audit, and waves 2-6 left a few rows Partial for want of code.
Wave 1b (see `closeout-wave1b.md`) closed: the six dashboard rows (role dashboards, filters, widget builder, notification centre,
daily business summary [delivery Partial], universal search), the department trigger, price-list approval, the approver picker and the
drag-and-drop designer. Still open as code work: language coverage beyond a small word list, the cash drawer read by the POS (hardware),
import history for the master-data importers, a form layout builder for custom fields, and a free-position canvas.
