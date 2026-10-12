# Closeout wave 4: Reports and analytics (17 items)

Evidence: `tests/Feature/WaveFourReportsTest.php` (21 tests), the page-load Dusk tests of the new report pages and
`tests/Browser/ReportToolsSmokeTest.php` (report builder preview, saved-filter bar, analytics blocks, with screenshots).

Migrations to run live, in order (none has been run):

1. `2026_11_15_100000_create_report_tools_tables` (`report_filters`, `custom_reports`, `report_schedules`, `report_schedule_runs`)
2. `2026_11_15_100100_add_report_tools_menus` (19 reports, Analytics, Report Builder + Manage permission)

Scheduler: `reports:send-scheduled` daily at 06:00 (needs the server's cron to call `schedule:run`; it can be run by hand).

## What changes for existing screens

* `SalesKpiController::statement` now calls `App\Services\SalesKpiStatement` (the same code moved out); the KPI page and the
  commission / target reports cannot disagree (test: "the commission and target reports are the KPI statement of the month").
* `ItemProfitReport::rowsWithTransaction()` (new method) and `CashFlowReport::section()` (made public). No existing output changed.
* The report page (`report/index.vue`) gained the saved-filter bar and, for the new reports, comparison / month fields.
* Nothing here posts anything: test "running every wave 4 report changes nothing in the books or stock".

## The items

1. **Comparative Profit & Loss: Built.** Two periods side by side (default: the period of the same length right before), change and
   change %. The current column is `ProfitLossReport` itself (tested equal). Stock is valued by the costing method in force.
2. **Comparative Balance Sheet with the inventory line: Built, with a stated limit.** Two days side by side (default: one year
   earlier). The ledger has no inventory account, so inventory at cost (method of each day, `CostSource`) is a memo line **outside
   the totals**; the sheet keeps balancing exactly as the single-day sheet does.
3. **Indirect cash flow: Built.** Ledger profit plus the cash effect of each non-cash balance sheet account, grouped as the direct
   method groups them; the report states the difference to the direct method and it is 0.00 (tested, incl. an equipment purchase
   and a loan). Change in stock value is a memo line.
4. **Sales by salesperson / warehouse / payment method: Built.** Net of returns, drafts left out; payment method is by payment date
   (a cash view, not expected to equal net sales).
5. **Commission and target reports: Built.** Same statement as the KPI page (agent base rate or tier, targets, shortfall).
6. **Sales forecasting: Built.** The dashboard forecast engine as a report (history, run rate, estimate with low / high).
   It is an estimate from history, labelled so.
7. **Stock take variance: Built.** Counted against frozen system quantity per line, valued at the unit cost on the count date.
8. **Advance report: Built.** Customer advances kept from cash collections and supplier advances from supplier payments with used /
   remaining (same `advanceRemaining()` the screens use).
9. **Withholding tax report: Built.** Receivable (sales) and payable (purchases) from the documents' `withholding_amount`.
10. **Tax exemption report: Built.** Lines taxed at zero under an exemption rule with certificate and the tax not charged
    (line amount x the rate of the line's tax; a group tax uses its stored percentage).
11. **Tax liability reconciliation: Built.** Output / input tax from the documents, tax payments, the same tax read from the
    mapped `outputtax` / `inputtax` accounts, and the differences. A company that has not mapped those accounts sees the
    ledger side as 0 with the "mapped" flag at 0.
12. **FBR audit trail: Partial (the gateway is a STUB).** It logs every submission attempt with status, number and error, and says
    on screen that stub numbers are not FBR invoice numbers. Nothing was ever sent to FBR; this is not evidence of compliance.
13. **Profit by customer / branch / salesperson / warehouse: Built.** On the lines of Item Profit & Loss, so the totals equal it
    (tested for all four); a line without a known cost is counted apart.
14. **Dashboard analytics page: Built.** `/analytics`: six months of net sales, forecast, sales by salesperson, receipts by
    method, profit by branch and by customer; each block is the report of the same name.
15. **Custom report builder: Built.** Six whitelisted datasets (sales, purchases, sale lines, payments, contacts, products), chosen
    columns, filters, one group-by with sums, sort, date range; saved private or shared; CSV export. Columns, operators and
    datasets are looked up in a whitelist, values are bound, the company is always the signed-in user's, rows are capped at 5000.
16. **Scheduled / emailed reports: Partial (delivery not proven).** Schedules (daily / weekly / monthly, up to 10 recipients) are
    produced as their creator with their company, branch and menu permission re-checked on every run and mailed as CSV.
    Selection, due logic, once-a-day, skip when the creator is inactive or lost the permission, and the CSV are tested with a
    faked mailer; actual delivery needs a live SMTP check (below).
17. **Saved filters and favourites: Built.** Personal to the user, stored by whitelisted field name (never a company id),
    favourites listed first.

## Live checks

* Run `php artisan reports:send-scheduled` after creating a schedule to one real mailbox; confirm the CSV attachment opens.
* Open each new report once on live data of company 1 and compare the comparative P&L current column to Profit & Loss.
* Map the output / input tax accounts before reading the tax liability reconciliation.

## Known limitations

* Standard reports available to schedules are the new reports and the analytics group; the transaction / party / product lists are not.
* The builder has no joins beyond its datasets and no calculated columns; a result is capped at 5000 rows.
* Withholding is not netted for returns; the exemption report uses the tax's stored percentage.
* The comparative balance sheet cannot add stock to the totals until the ledger carries an inventory account.
