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
| Invoice layout | You reorder blocks by dragging, or write your own layout as HTML and CSS with placeholders (Document Center > Code editor). Script, forms, external files and external images are removed on purpose; the page prints inside a sandbox. |
| Machine and shift planning | Manufacturing plans quantities and dates, not machines or shifts. |

## A new company's starting set-up

* A new company starts with a ready chart of accounts, posting mappings, a financial year and the first item lists. Your accountant
  should still review the chart and rename or add accounts to suit your business, and enter opening balances.
* The balance sheet shows your closing stock as an asset (valued with your costing method on that day, the same figure as the Stock
  Report) and its profit is the profit and loss statement's net profit, so the two agree. This is the periodic method: cost of goods
  sold = opening stock + purchases - closing stock, worked out when you run a report. **A cost of goods sold entry is not posted to the
  ledger for each sale** (no perpetual inventory): the ledger itself still shows purchases as an expense, so the trial balance and
  the account ledger do not show a stock account. If you journal your stock to your own inventory account, map it under Account
  Mapping > Inventory; the balance sheet then shows that account and no stock line of its own.
* Journal entries wait for approval by default; switch this off in company settings if one person does the books.

## Calendar, holidays and the scanner

* The header calendar shows follow-ups, approved leaves, invoices falling due, your to-dos and the company holidays. The holidays
  are the list on the HR page (HR Settings > Holidays). One click imports the public holidays of Pakistan for a year: the national
  days are fixed, but Eid, Ashura and Eid Milad un Nabi follow the moon sighting, so the imported dates are marked "approximate"
  (listed for 2026 and 2027 only) and must be confirmed by you each year.
* A holiday does not change attendance or payroll by itself; it is for planning and for the calendar.
* At the till, a product tracked by serial number or batch asks for its serial numbers (typed or scanned) or batch when it is added;
  a batch left empty takes the earliest expiry. The camera scan uses the browser's own barcode detection and works only where the
  browser offers it (recent Chrome and Edge on a phone or laptop with a camera); a scanner that types the code is the reliable way.
  The scanner settings (shortest code, prefix, ending key, speed, beep) are kept in the browser of that till.
* The platform-wide subscription analytics page of the superadmin runs five queries over every company and can take more than 15 seconds on a large database; company users see only their own company and are not affected.
* The dark theme covers the dashboard and the main lists; some older settings pages still show light panels in it.

## Keyboard use and printed designs

* The keyboard works across the application for moving around and for the till: `/` or Ctrl+K searches, `g` then a letter opens a
  page, `n` opens the add form, Ctrl+S saves, `?` lists the keys, and the point of sale has function keys and a barcode scanner. Forms
  are filled with Tab and Shift+Tab; tables move row to row with the arrow keys, Enter opens the row and Space ticks it; the product
  search on sale, purchase, transfer and adjustment forms takes the arrow keys and Enter. A sale at the till, a purchase and a journal
  entry are proved end to end with the keyboard alone in an automated browser test. Some older screens (date pickers, a few
  drop-down lists from a component library) still need a few clicks; tell us which ones you use most and we will cover them.
* A barcode scanner must behave as a keyboard (almost all do: they type the code and press Enter). A scanner that needs its own driver
  is not covered.
* In a printed design, type codes such as `{invoice}`, `{customer}` and `{total}` into a Text block, the title, the terms or the footer
  (the designer lists all of them). The line items table is chosen from the columns offered; free HTML is not accepted on purpose.

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
