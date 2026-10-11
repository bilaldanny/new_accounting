# Module closeout, wave 2: Inventory & Warehouse (6 of 8 items)

Six of the eight Inventory rows are built. The other two, **Valuation Methods** and **COGS Calculation & Recalculation
Engine**, are deliberately **not built**: they touch stock valuation and the ledger, so only an investigation was written
([inventory-valuation-investigation.md](inventory-valuation-investigation.md)) and they wait for your confirmation.

Live migrations to run, in this order (nothing has been run on the live database):

1. `2026_11_05_100000_create_inventory_closeout_tables`
2. `2026_11_05_110000_add_inventory_closeout_menus` (companyadmin is granted every page and report row)

The code tolerates the new tables / columns being absent, so deploying before migrating does not break the app.
Tests: `tests/Feature/InventoryCloseoutTest.php` (19 tests), `RolePresetAccessTest` (Warehouse Keeper preset gets reservations,
pick / pack lists, write-offs, and the two reports; Inventory Close stays administrator-only).

## 1. Stock Reservation, Allocation & Release: Built
`stock_reservations`, `StockReservationController` (`/api/stock-reservations`, availability, close as released / fulfilled),
page `/stockreservation`. A reservation never changes the stock figure. Company setting `reservation_mode` (default `off`):
with `enforce`, `SaleStockCheck` takes what others hold off the available quantity; a customer's own hold never blocks that
customer; expired and closed holds count for nothing.
Tests: "a reservation is a record that changes no stock and blocks nothing until the company enforces it" (guarantee),
"with enforcement a sale cannot take what others hold, but the customer own hold is theirs", "a released or expired
reservation holds nothing and reservations are company scoped".

## 2. Negative Stock Control Rules: Built
`company_settings.negative_stock_mode` (allow / warn / block; empty = as before) and `products.negative_stock` (allow /
block, wins over the company). `NegativeStockRules` is called from `Transaction::guardSaleStock`. With nothing set it is the
old rule exactly: the POS refuses, the sale form saves and warns. Settings on Company Settings > Sell; the product form has the
override.
Tests: "with no rule the POS refuses ... as before" (guarantee), "a company that allows negative stock lets the POS sell, and
one that blocks refuses the sale form", "a product rule wins over the company rule", settings round trip.

## 3. Pick / Pack / Put-away: Built
`warehouse_tasks` + `warehouse_task_lines`, `WarehouseTaskController`, page `/warehousetask`: a pick or pack list from a posted
sale, a put-away list from a purchase or receiving note; work off quantities (and a location for put-away), complete (or
complete short), cancel. The lists are a record of the work: stock moves through issue and receiving notes as before, so
completing a list changes no stock figure (tested).

## 4. Damage, Expired, Near-Expiry & Wastage: Built
`stock_writeoffs`, `StockWriteoffController`, page `/stockwriteoff`: saving creates a completed `abnormal` stock adjustment
through `Transaction::createAdjustment` (stock guard, period lock, inventory close, adjustment report all apply) plus a row with
the reason and the value (average cost on the day); the page totals per reason. Near-expiry candidates are in the existing Batch
Expiry report.
Tests: "a write-off is a completed abnormal adjustment with a reason and a value, and cannot go below stock", isolation.

## 5. ABC Analysis & Stock Ageing: Built
`AbcAnalysisReport` (`/report/abc-analysis`: ranked by net sales value over the period, A to 80%, B to 95%, C the rest) and
`StockAgeingReport` (`/report/stock-ageing`: stock on a day laid over the newest receipts first, bands 0-30, 31-60, 61-90,
91-180, 181+, unknown, optional stock value). Both are read-only views over `StockMovements` and posted sales; both are in the
analytics report framework (menu, permission, export, search).
Tests: "ABC analysis ranks products by net sales value and classes them A, B and C", "stock ageing lays the stock over the newest
receipts first".

## 6. Period-End Inventory Closing Lock: Built
`inventory_closes` (history of closes and reopens), `InventoryClose` service, `InventoryCloseController`, page `/inventoryclose`.
Stock documents (purchases, receiving and issue notes, returns, posted sales, transfers, adjustments) dated on or before the close
cannot be created, changed, deleted or restored: model events on `Transaction` and the period lock's bulk path
(`Transaction::periodLockRows`) both ask it, so bulk delete is covered. Separate from the books lock; the same override
(`/period-lock/override` + header reason) sends one save through and is written to the audit log as
`inventory_close_override`. Drafts and quotations are not stock movements. No close recorded = nothing checked.
Tests: "with no close recorded every stock document saves exactly as before" (guarantee), the close / refuse / bulk delete /
free-after test, override audit, forward-only close, reopen with reason, company isolation.

## Not built (waiting for your decision)
- Valuation Methods (FIFO, LIFO, Weighted Average, Standard Cost)
- COGS Calculation & Recalculation Engine

The report lists what the code does today (periodic weighted average, no COGS posting, `accounting_method` unused), what each
method would change, the reports affected, the backfill risks, a stepwise plan with guarantee tests, and four questions.
