# What the system does, what it does not, and what is still manual

This page is written for customers. It says plainly what is finished, what you must do yourself, and what we have not been able
to prove against the real outside service yet.

## Things you should know first

* **FBR e-invoicing is not working and the system is not FBR compliant.** The FBR page keeps your settings and a log, but nothing
  is sent to FBR and the numbers it shows are placeholders, not FBR invoice numbers. Do not rely on it for tax compliance.
* **Payroll tax and contribution rates are entered by your accountant.** The system ships with no EOBI, provident fund, social
  security or income tax rates. Enter them (with the date they start) on the HR page, and have them checked, before your first
  payroll. If you enter none, none is deducted.
* **There is no online payment.** Customers cannot pay an invoice or a shop order by card or wallet through the system. Payments
  are recorded by your staff. The card gateway (Stripe) adapter exists but has not been tested with real money.
* **Nothing posts to your books by surprise.** Payroll, manufacturing and rental can post accounting entries, but only after you
  switch it on and choose the accounts. Until then they only keep their own figures.

## Works and tested in the system

Sales, purchases, returns, payments, stock, accounts, ledgers and reports; customer and supplier statements; approvals with
levels and delegation; period locks; HR (employees, attendance, leave, payroll runs, payslips); manufacturing (bills of
materials, work orders, costs, scrap); repair and field jobs with customer signature; rental bookings with deposits; project
milestones; expense claims; the printed invoice designer; reports you can save and schedule; user security (IP rules, password
policy, sessions); multi-branch and multi-company.

## Manual or only partly automatic

| Area | What you do yourself |
|---|---|
| PDF | The system makes a print page; use the browser's Print, then Save as PDF. There is no server-made PDF file. |
| Bank salary file | A simple CSV is produced. Check the layout your bank wants before uploading; no bank's own format is built. |
| Gratuity | A monthly amount is set aside by the days you configure; the legal end-of-service calculation is not done. |
| Late and damage charges on rentals | They are worked out and can be posted to the customer's account; no separate invoice document is printed. |
| Cash drawer | The setting is saved, but the till does not open the drawer from it yet. |
| Language | English, with a small set of Urdu words and Urdu printing of invoices. Menus and most screens are English. |
| Import undo | Customer, supplier and product imports can be undone while unused. Other imports cannot. |
| Backup | Database backups are made and downloaded from the Backup page. Copying to cloud storage and restoring need your administrator. |
| Forms | You can add extra fields; there is no screen to rearrange whole forms. |
| Invoice layout | You reorder blocks by dragging; you cannot place items at any position on the page. |
| Machine and shift planning | Manufacturing plans quantities and dates, not machines or shifts. |

## A new company's starting set-up

* A new company starts with a ready chart of accounts, posting mappings, a financial year and the first item lists. Your accountant
  should still review the chart and rename or add accounts to suit your business, and enter opening balances.
* The balance sheet and the profit and loss statement can show different profit while you hold stock: the balance sheet uses the
  ledger (purchases are posted as an expense when bought), the profit and loss statement deducts the value of the stock you still hold.
  Both are internally balanced; ask us if you want the balance sheet to carry a stock line.
* Journal entries wait for approval by default; switch this off in company settings if one person does the books.

## Connections we could not test against the real service

The following are built and tested against recorded sample answers only. Before you depend on one, test it once with a real
account and tell us what you see.

* Email sending (reminders, payslips, daily summary, scheduled reports): needs your mail server settings.
* WhatsApp and SMS sending (WhatsApp Cloud, Twilio, or your own webhook).
* Stripe card checkout and its payment confirmation.
* Courier booking and tracking (PostEx, Leopards).
* Bank statement feed (an HTTPS JSON feed).
* Shopify and WooCommerce order sync.
* Exchange-rate feed.
* Push notifications to browsers.
* Copy of backups to Amazon S3 or Google Drive (the connection software is not installed).
* Fingerprint / attendance devices (the system accepts punches from a device or a file; no device was available to test).
* A customer's own web address for their company (the proving step is built; the web server and certificate are yours to set up).
* Installing the field-service page on a phone and using it with no network (the page works online; offline entry is not built).
* QR codes on invoices (made to the standard and checked by software; scan the first printed one with a phone).
* Online shop: customers send an order request; there is no online payment, no stock display and no delivery charge rules.

## Good practice

* Keep the period lock on once a month is closed.
* Read the payslip and the first manufacturing cost by hand once, against your own calculation.
* Switch each new feature on for one branch first.
