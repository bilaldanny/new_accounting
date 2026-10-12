# Tracker sync: waves 1-6 (modules 1, 8, 11-18)

Source of the item names and the old status: `full-reaudit-2026-10.csv` (repository root), every row of these modules whose old
status was Partial or Missing: **94 rows**. Status here = what exists in the code after waves 1-6 (nothing is migrated live).
Test column: `W1` = `tests/Feature/WaveOneTest.php`, `W2` = `WaveTwoApprovalCenterTest.php`, `W3` = `WaveThreeSaasTest.php`,
`W4` = `WaveFourReportsTest.php`, `W5` = `WaveFiveDocumentsTest.php`, `W6` = `WaveSixSystemTest.php` (all in `tests/Feature/`);
`Dusk` = a page-load or smoke test in `tests/Browser/`.

Tally: **Built 62, Partial 28, Not built 4**.

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
| 1. Dashboard & BI | Role-Based Dashboards (Owner, Accountant, Sales Manager, Warehouse Incharge, Cashier) | Not built | Figures still differ only by menu permission; no role-tailored layouts were written (wave 1 did not take this row). | - |
| 1. Dashboard & BI | Date / Branch / Warehouse Filters | Not built | No warehouse filter and no date range on the dashboard were added. | - |
| 1. Dashboard & BI | Custom Widget Builder & Dashboard Layout Customization | Partial | Personal layout (cards shown, order) is saved and used on `/dashboard/insights`; the main Dashboard page does not read it and there is no widget builder. | W1 "the dashboard insights show the top customers and products ... keep a personal layout" |
| 1. Dashboard & BI | Notification Center (in-app alerts) (New) | Partial | Stored notifications with read / unread and the My Notifications page exist; the header bell still shows its own live counts. | W1 "a follow-up due soon or overdue reminds its assignee once ..." |
| 1. Dashboard & BI | Daily Business Summary (auto Email/WhatsApp) (New) | Not built | Not written (wave 1 did not take this row); reuse `ReportScheduler` + `MessageDispatcher` when built. | - |
| 1. Dashboard & BI | Universal Search Bar | Not built | Search box is still unbound; no backend search was written. | - |
| 8. CRM | Lead Assignment & Sales Rep Allocation Rules | Built | | W1 "a lead with no owner is allocated in turn ..." and "allocation rules by source ... company scoped" |
| 8. CRM | Follow-ups, Reminders, Tasks, Calls & Meetings | Built | Email not proven against live SMTP. | W1 "a follow-up due soon or overdue reminds its assignee once ..." |
| 11. Approval Center | Discount & Price List | Partial | Discount-code approval built; price-list approval keeps its own controller and is not in the workflow registry. | W2 "discount codes need approval once the company asks for it ..." |
| 11. Approval Center | Workflow Builder | Built | Approvers typed as `user:12 role:3` text, no picker. | W2 "a two level workflow takes the approvers in order ..." |
| 11. Approval Center | Centralized Approval Inbox & Outbox | Built | | W2 "the inbox shows a user only what waits for them ..." |
| 11. Approval Center | Multi-Level Approval Workflows | Built | | W2 "a two level workflow ..." and "a parallel level needs every approver ..." |
| 11. Approval Center | Trigger Rules (Amount, Role, Branch, Department) | Partial | Amount, role, branch and priority are evaluated; department is stored but not evaluated (documents carry no department). | W2 "trigger rules choose the workflow by amount ..." |
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
| 13. Documents | Drag & Drop Invoice/Receipt Builder | Partial | Blocks are added, ordered and edited with fields; there is no drag-and-drop canvas. | W5 "block lists are checked against the whitelist ..."; Dusk `DocumentCenterSmokeTest` |
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
| 15. Settings | Import History with Undo/Rollback (New) | Partial | Customers and suppliers only; other importers are not hooked. | W6 "an import is recorded and can be undone while its records are untouched" |
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

## Follow-up the table exposes

Open rows of waves 1-6 that are code work, not live checks: the six Dashboard rows (role dashboards, filters, widget builder,
notification center, daily business summary, universal search), approver picker / department trigger in the Approval Center, price-list
approval in the workflow registry, drag-and-drop canvas, Multi-language coverage, cash drawer read by the POS. They are scheduled
as "wave 1b" after wave 8 unless the owner says otherwise.
