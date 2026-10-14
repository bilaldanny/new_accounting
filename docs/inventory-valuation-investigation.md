# Inventory valuation and COGS: investigation (wave 2, nothing built)

Scope: the two tracker items "Valuation Methods (FIFO, LIFO, Weighted Average, Standard Cost)" and "COGS Calculation &
Recalculation Engine". They touch stock and the ledger, so this is a report and a plan only. **Nothing for these two is
built until you confirm** (decisions at the end).

## 1. How cost and COGS work today

| Question | Answer in the code |
|---|---|
| Is a cost stored per sale line? | No. `sell_lines` has no cost column; the FIFO link table `purchase_sell_lines` is never written (`AverageCost` docblock). |
| Where is a unit cost computed? | `App\Services\Reports\AverageCost`: weighted average of every non-draft purchase line up to the day (rate x qty x packing, less returns to supplier, plus landed cost, plus Opening Stock screen entries that carry `opening_cost`). Computed on demand per variation and date, never stored. |
| Is COGS posted to the ledger? | **No.** `SellJournal` posts customer / sales / tax only; `PurchaseJournal` posts purchase account / tax / supplier. There is no inventory asset and no per-sale COGS entry. |
| Where does COGS appear? | Only in reports: `ProfitLossReport` (COGS = opening stock + purchases - closing stock, periodic method, stock valued by `StockValuation`), `ItemProfitReport` (per sold line, average cost on the sale date), `StockReport` / `StockValuation` (stock x average cost), `DashboardMetrics` (reuses P&L), `LandedCost`. |
| What does `company_settings.accounting_method` (fifo / lifo, default lifo) do? | Nothing. It is stored and shown in Company Settings, and no code reads it for cost. |
| Standard cost? | No field exists (`product_details` has default purchase / sell price only). |

So the system already runs a periodic weighted-average costing; "FIFO / LIFO / standard" are not alternatives to a posted
perpetual COGS, they would be alternatives to `AverageCost`.

## 2. What each method would change

- **Weighted average** (today): unchanged; remains the default.
- **FIFO / LIFO**: need layers: for each variation and branch, the purchase (and opening / landed cost) layers in date order
  consumed by the sale lines and returns in date order. A sale then has a cost from the layers it consumed. Layers are
  derivable from existing rows (purchase lines, returns, adjustments, transfers), so no new source of truth is needed, but the
  consumption sequence must be deterministic and recomputed when any earlier document is edited, deleted, restored or back-dated.
  Branch / warehouse transfers move layers with the quantity.
- **Standard cost**: a `standard_cost` per variation (with effective dates); valuation = stock x standard; the difference to
  actual purchase cost becomes a purchase price variance that has no account today.
- **COGS recalculation engine**: because no cost is stored per sale, "recalculation" means producing the per-line cost under
  the chosen method for a date range, comparing it to what reports show now, and (optional, later) posting it.

## 3. Reports affected if the method becomes selectable

`StockValuation` (and through it Profit & Loss, Dashboard gross profit, balance sheet stock figure if added), `StockReport`
value columns, `ItemProfitReport` (cost, profit, `without_cost`), `ProfitLossReport` COGS, `LandedCost` (spreads onto purchase
layers), the Opening Stock screen (opening cost becomes the first layer), and anything reading `AverageCost::correlated()`
in SQL (it is a correlated subquery, so FIFO / LIFO would need a different shape or a cache table).

## 4. Risks

1. **Silent change of historic profit.** Switching a company's method changes every past P&L if costs are computed on demand.
   Mitigation: the method applies from an effective date, and the previous method stays for earlier dates (needs a
   `valuation_method_changes` history).
2. **Performance.** Average cost is one grouped query; layer consumption is per variation and per sale, needing a materialised
   cost per sale line (a `sell_line_costs` table filled by a job and kept in step by model events).
3. **Backfill.** A materialised cost table must be backfilled from history for every company; period-locked months must not be
   rewritten (read-only backfill into a new table avoids touching locked documents).
4. **Negative stock.** Sales before purchases have no layer; a rule is needed (use the next purchase's cost, or the last
   known cost, or flag as `uncosted` as the reports do today).
5. **Ledger.** If a perpetual inventory / COGS posting is wanted (Dr COGS, Cr Inventory per sale) it adds two mapped accounts,
   changes the books, the trial balance, balance sheet and every period already closed. This must not be switched on by default.

## 5. Plan (after confirmation), in order, each step with a guarantee test

1. `valuation_method` per company with an effective date (default `average`, NULL = average) and a history table. Guarantee:
   a company without the setting produces byte-identical Stock Report, Item Profit and P&L figures.
2. `sell_line_costs` (read-only materialised cost per sale line per method) plus the recalculation command and an
   Inventory > Cost Recalculation page that shows the difference per line before anything is used. Nothing posted.
3. FIFO and LIFO layer service feeding `StockValuation`, `StockReport`, `ItemProfitReport` only when the company's method says so.
4. Standard cost: `product_details.standard_cost`, valuation at standard, variance report. No ledger posting.
5. Optional, separate decision: perpetual COGS posting (Dr COGS / Cr Inventory) behind its own company switch, with an
   opening migration entry, period-lock respected, and a reversal path.

## 6. Decisions needed from you

1. Is a **reporting-only** method choice (steps 1-4: reports and valuation change, the ledger does not) what you want, or do you
   also want **perpetual COGS posted to the ledger** (step 5)?
2. Effective-date model: method applies **from a date forward** (recommended), or recomputes all history?
3. Negative-stock costing rule: next purchase cost, last known cost, or leave `uncosted`?
4. Standard cost: per variation company-wide, or per branch?

Until you answer, these two items stay **Not built** in the tracker.

## Update: balance sheet stock line

Built as the periodic presentation only (steps 1-4 never needed a ledger entry): the balance sheet adds the closing stock from `StockValuation`
as an asset, the equity profit is the P&L net profit, and the stock the year opened with is shown in equity. The perpetual COGS posting
(step 5) is still **not built** and still needs its own decision.
