# Closeout wave 8: Manufacturing (10 items) and Advanced modules (15 items)

Evidence: `tests/Feature/WaveEightManufacturingTest.php` (17 tests), `WaveEightServiceTest.php` (7), `WaveEightRentalTest.php` (6),
`WaveEightBusinessTest.php` (8: project work, expense claims, storefront); real-browser tests `ManufacturingSmokeTest`,
`ServiceSmokeTest` (a job is made, assigned, opened on the technician page and signed on the pad; the manifest link is present) and
`ShopSmokeTest`; the page-load Dusk tests of the nine new pages and four production reports. Screenshots are in `tests/Browser/screenshots`.

Migrations to run live, in order (none has been run; the wave 7 pair `2026_11_18_100000` / `_100100` comes first):

1. `2026_11_19_100000_create_manufacturing_tables` (boms, bom_items, bom_outputs, bom_operations, production_plans, work_orders,
   work_order_materials / issues / receipts / scraps / labor / checks, manufacturing_settings)
2. `2026_11_19_100100_add_manufacturing_menus`
3. `2026_11_20_100000_create_service_field_tables` (service_jobs, service_job_parts, service_job_events)
4. `2026_11_20_100100_add_service_menus`
5. `2026_11_20_200000_create_rental_tables` (rental_items, rental_bookings, rental_settings)
6. `2026_11_20_200100_add_rental_menus`
7. `2026_11_20_300000_create_project_work_claims_storefront_tables` (project_milestones, project_tasks, expense_claims + lines +
   receipts, storefront_settings / products / orders)
8. `2026_11_20_300100_add_project_work_claims_storefront_menus`

New public files: `public/manifest.webmanifest`, `public/field-sw.js`, `public/icons-field-192.png`, `public/icons-field-512.png`.
No new scheduled command in this wave.

## Rules that were kept

* **Nothing reaches the ledger by default.** Manufacturing, rental and (wave 7) payroll post only when the company switches posting
  on *and* maps the accounts; every posting goes through `AccountsVoucher` (journal approval setting, period lock, numbering) inside
  the same database transaction as the stock movement or return it belongs to, so a locked month refuses the whole step and nothing
  is left behind (tests). Expense claims post only when a user with the pay permission pays an approved claim.
* **Stock moves through completed stock adjustments**, the kitting path (`Transaction::createAdjustment`: stock guards, branch,
  warehouse, period lock). A finished-goods receipt line carries its production unit cost as `opening_cost`, which the average cost,
  FIFO layers and the stock report already read: production cost follows the company's valuation method (tested under FIFO and
  standard cost) without a second costing engine.
* **Separate approval permissions**: activate a BOM version / release a work order (`/manufacturing/approve`), approve an expense
  claim (`/expenseclaim/approve`), approve leave and payroll (wave 7). All are `/approve` routes, so maker-checker and the Approval
  Center workflows cover them (registry entries `bom`, `workorder`, `expenseclaim`, `leave`, `payroll`) and the role presets keep
  them with the Manager.
* Company and branch always come from the signed-in user; every id from a request is looked up inside the company; one tenant
  isolation test per family; a technician with only the work permission reaches only the jobs assigned to them.
* The public shop is tenant scoped by its code, off until switched on, rate limited, honeypotted, prices computed on the server, and
  its orders are kept apart from the books until staff convert one.

## Manufacturing (10)

1. **Bill of Materials with Multi-Level Versioning: Built.** Versions (draft, active, archived; only a draft is edited, a new
   version copies the last; activating archives the active one), components with scrap %, by-products and co-products with a cost
   share, operations. A component that has an active BOM of its own is a sub-assembly: the explosion goes down to raw materials
   (depth 8, loops refused at activation). A work order keeps the version it was made from (test).
2. **Production Planning, Scheduling & Master Production Schedule: Built.** Plan rows per product and week, compared with the work
   orders planned in the week and what they produced; material requirements of what is still to cover (multi-level) against stock on
   hand with the shortage. It does not schedule capacity (machines, shifts); the schedule is the planned start and end of each work
   order.
3. **Manufacturing Orders & Work Orders: Built.** Draft, release (approver), in progress, completed, cancelled; numbering
   `WO-00001`; planned quantity, dates, warehouse, BOM version; partial receipts.
4. **Raw Material Consumption & Issue Rules: Built.** Issue against the work order's required lines (one completed adjustment,
   cost at the valuation method on the day), a company switch for over-issue, backflush on receipt (issues what the received share
   needs and was not issued).
5. **Finished Goods Receipt, By-Products & Co-Products: Built.** Receipt of finished goods plus the BOM outputs in proportion, each
   at its share of the cost; optional quality gate.
6. **Work-in-Progress (WIP) Accounting: Built, guarded.** WIP value per work order (materials + labour + overhead - absorbed -
   loss), WIP report, and with posting on the six-account entries (issue, labour, receipt, abnormal scrap, closing variance); the WIP
   account balance equals the report total (test).
7. **Manufacturing Overhead & Direct Labor Costing: Built.** Labour hours at a labour rate and an overhead rate per hour, entered
   or applied from the BOM operations; absorbed by receipts with the materials.
8. **Scrap & Wastage: Built.** Normal scrap stays in the cost of the good units; abnormal scrap is taken out as a loss at the
   average cost per planned unit; closing variance for cost left after the last receipt; scrap report.
9. **Production Quality Checks (New): Built.** Checks with inspected and failed quantities, optional scrap of the failed quantity,
   optional company switch that holds receipts back until enough units passed.
10. **Manufacturing Reports: Built.** Production Summary, Work in Progress, Material Variance, Production Scrap (insight reports:
    saved filters, scheduling and export work for them).

## Advanced (15)

11. **Repair & Service: Job Cards, Service Orders & Technician Assignment: Built.** Job cards of three types, status flow with a
    history, technician assignment and notification, priority, site address, item and serial.
12. **Parts Consumption & Service Payments: Built, with a stated limit.** Parts are listed on the job (with or without a price) and
    become the lines of the draft invoice; they leave stock when that sale is finalised the normal way. Payments are taken on the
    sale with the normal sale payment screen; the job shows the invoice, what was paid and what is due (no payment entry inside the job).
13. **Warranty Service & Repair Invoicing: Built.** A job linked to an in-warranty claim (or marked warranty) is invoiced at zero so
    its parts still go out of stock; otherwise parts at their price (price list when none) and one service line.
14. **Warranty Claim Tracking (New): Built.** Claims, their statuses and the in-warranty judgement exist in the Warranty Claims
    module of the product catalogue round; a job card links to a claim and shows its status.
15. **Rental: Rental Products, Booking Calendar & Rental Period: Built.** Rental items (units, daily rate, deposit and late fee per
    unit), bookings that cannot overbook an item on any day, calendar with free units and overdue marks.
16. **Security Deposits, Returns & Inspection: Built.** Deposit taken at pickup (posted when posting is on), inspection with
    condition and notes at return, deposit applied to the charges and the rest refunded.
17. **Late Charges & Damage Billing: Partial.** Late days, late charge and damage charge are worked out and kept, and with rental
    posting on the return posts the charges to the customer's account and the income accounts. No printed invoice document is made
    for them (the customer statement shows the voucher); a sale invoice for rentals was not built because a rental is not a stock sale.
18. **Project & Job Management: Projects, Milestones & Tasks: Built.** Milestones (due date, amount, pending / done / billed) and
    tasks (assignee, due date, hours, status) on the existing projects, with progress.
19. **Project Budget, Expenses & Revenue: Built.** Budget revenue / cost on the project (final closeout), revenue and cost read
    from what is tagged to it, shown beside the milestones' planned billing.
20. **Job Costing & Project Profitability: Built.** `ProjectProfitability` (margin against budget) from the final closeout, shown
    on the milestones and tasks page.
21. **Field Service: Field Jobs, Scheduling & Mobile Dispatch: Built.** Jobs of type field / installation with a schedule, a board
    filtered by technician, type and day, a notification to the technician when assigned, and the technician's own mobile page.
22. **Customer Digital Signature & Service Reports: Built.** Signature drawn on a pad, content-checked PNG kept privately, printed
    on the escaped, scriptless service report (A4 `@page`; the browser saves it as PDF).
23. **Mobile App / PWA (Field Sales, Inventory, Approvals): Partial.** The technician page `/service/work` is mobile first, has a
    web app manifest with icons and a service worker (shell and static files only, API calls never cached; the last job list of the
    signed-in user is kept on the device for reading offline). Not proven: installing on a real phone, HTTPS, and offline writes
    (nothing is queued offline). Field sales, inventory and approvals screens were not made into app pages.
24. **E-commerce Storefront: Partial.** Public shop by code with published products and server-side prices, orders kept apart from
    stock and books, staff convert an order to a draft sale or reject it. No online payment (cash on delivery or invoice), no stock
    availability shown to shoppers, no shipping or tax rules in the shop.
25. **Expense Claims (Employee Reimbursement): Built.** Claims with lines on expense accounts and receipts, submit, approval by
    another user, payment through one expense voucher from a cash or bank account.

## Tracker sync (modules 10 and 19)

| Module | Item name (tracker) | Status | Why (Partial / Not built) | Test |
|---|---|---|---|---|
| 10. Manufacturing | Bill of Materials with Multi-Level Versioning | Built | | WaveEightManufacturing "a BOM is versioned ...", "a BOM refuses its own product ...", "a BOM is exploded through its sub-assemblies ..." |
| 10. Manufacturing | Production Planning, Scheduling & Master Production Schedule | Built | No machine or shift capacity planning | "the production schedule compares plans with work orders ..." |
| 10. Manufacturing | Manufacturing Orders & Work Orders | Built | | "a work order issues materials, takes labour, receives the finished good ...", "a partial receipt takes its share ..." |
| 10. Manufacturing | Raw Material Consumption & Issue Rules | Built | | "backflush issues what the received quantity needs ...", "over-issue can be switched off ..." |
| 10. Manufacturing | Finished Goods Receipt, By-Products & Co-Products | Built | | "a work order issues materials ... and its by-product at the production cost" |
| 10. Manufacturing | Work-in-Progress (WIP) Accounting | Built | Posting off by default, needs six mapped accounts | "with posting on every step posts a balanced entry, the WIP account follows the WIP report ...", "with posting off the whole production cycle leaves ... exactly as they were" |
| 10. Manufacturing | Manufacturing Overhead & Direct Labor Costing | Built | | "the finished good is valued by the valuation method of the company, FIFO included" |
| 10. Manufacturing | Scrap & Wastage | Built | | "abnormal scrap is a loss out of the work order ...", "over-issue can be switched off, ... a closed one holds nothing" |
| 10. Manufacturing | Production Quality Checks (New) | Built | | "quality checks can hold a receipt back until enough has passed" |
| 10. Manufacturing | Manufacturing Reports | Built | | "the production reports are company scoped and add up from the work orders" |
| 19. Advanced | Repair & Service: Job Cards, Service Orders & Technician Assignment | Built | | WaveEightService "a job card goes from the call to the invoice ...", "assigning a job tells the technician once ..." |
| 19. Advanced | Parts Consumption & Service Payments | Built | Parts leave stock when the draft invoice is finalised; payments are recorded on the sale | "a job card goes from the call to the invoice ..." |
| 19. Advanced | Warranty Service & Repair Invoicing | Built | | "a warranty job is invoiced at zero so its parts still leave stock ..." |
| 19. Advanced | Warranty Claim Tracking (New) | Built | Existing claims module, linked from the job card | ProductCatalogCloseoutTest "a warranty claim is judged against the sale date and the warranty rules"; "a warranty job is invoiced at zero ..." |
| 19. Advanced | Rental: Rental Products, Booking Calendar & Rental Period | Built | | WaveEightRental "a booking is priced from the item and cannot overbook it ...", "an item that is still out stays taken ..." |
| 19. Advanced | Security Deposits, Returns & Inspection | Built | Ledger part guarded, off by default | "a return works out late days ...", "with posting on the deposit and the return post balanced entries ..." |
| 19. Advanced | Late Charges & Damage Billing | Partial | Charges and ledger entries yes; no printed invoice document for them | "a return works out late days, late and damage charges ..." |
| 19. Advanced | Project & Job Management: Projects, Milestones & Tasks | Built | | WaveEightBusiness "a project gets milestones and tasks with progress and billing figures ...", "project work of another company is not reachable ..." |
| 19. Advanced | Project Budget, Expenses & Revenue | Built | Budget and tagging from the final closeout | `ProjectCostingTest`; "a project gets milestones and tasks ..." |
| 19. Advanced | Job Costing & Project Profitability | Built | `ProjectProfitability` from the final closeout | `ProjectCostingTest` "revenue and direct cost tagged to a project give its margin against budget ..." |
| 19. Advanced | Field Service: Field Jobs, Scheduling & Mobile Dispatch | Built | | "the board filters jobs by technician, type and day ...", Dusk ServiceSmokeTest |
| 19. Advanced | Customer Digital Signature & Service Reports | Built | | "the customer signature must be a real PNG ...", Dusk ServiceSmokeTest |
| 19. Advanced | Mobile App / PWA (Field Sales, Inventory, Approvals) | Partial | Manifest + service worker built; real-phone install, HTTPS and offline writes not proven; only the technician page is an app page | "the field app manifest names its start page and icons ...", Dusk ServiceSmokeTest |
| 19. Advanced | E-commerce Storefront | Partial | No online payment, stock availability or shipping rules | WaveEightBusiness "the public shop lists only published products ...", "the shop is off until enabled ...", "staff turn a web order into a draft sale ..." |
| 19. Advanced | Expense Claims (Employee Reimbursement) | Built | | "a claim is filed, submitted, approved by someone else and paid ...", "a rejected claim cannot be paid, a locked month refuses the payment ...", "an approval route of the claims is covered by maker-checker ..." |

## Live checks

* Manufacturing: make one BOM, one work order for a handful of units, issue, apply the standard labour, receive; compare the unit
  cost with a hand calculation and with the Stock Valuation report. Only then map the six accounts and switch posting on for a test month.
* Service: make a job, assign it to a real user, open My Jobs on a phone, draw a signature, print the report, make the draft invoice.
* PWA: open `/service/work` on the phone over HTTPS, "Add to home screen", then turn the network off and open the app.
* Rental: take one booking through pickup and return with posting off, then on in a test month.
* Shop: open the shop, publish one product, place an order from a phone, convert it to a draft sale.

## Known limitations

* No capacity planning, routing or machine scheduling; no lot / batch genealogy of produced goods; by-product value is a fixed cost
  share percent, not a market price.
* Manufacturing and rental ledger entries use the accounts the company maps; a company that keeps no inventory account in its ledger
  keeps posting off and uses the work-order cost figures.
* The job board, the rental and shop pages take customer, product and account ids as numbers (no pickers).
* A service job can be invoiced once; later corrections are made on the draft or posted sale.
