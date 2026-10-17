# Offline mode: design

Written before the build. It generalises the offline POS terminal (service worker, IndexedDB queue, `pos_offline_sales`, idempotent
sync, conflict queue; see `offline-pos-investigation.md` for why it is shaped that way) into one engine other documents use too. The POS
terminal keeps its screen, its queue and its tests; it now shares the engine's runner, status chip and conflict list.

## What works offline (create only, queued)

| Kind | Screen | Replayed through | Needs the permission |
|---|---|---|---|
| POS sale (cash / card slip) | the existing `/sell/pos/offline` terminal | `PosOfflineSync` (unchanged) | `/sell/pos` |
| Sales invoice, cash or credit | `/offline` | `POST /api/sells` (+ `POST /api/sell-payments` for the cash part) | `/sell/add` |
| Purchase | `/offline` | `POST /api/purchases` (+ `POST /api/purchase-payments`) | `/purchase/add` |
| Payment / receipt of a customer or supplier invoice | `/offline` | `POST /api/sell-payments`, `POST /api/purchase-payments` | `/sell/payment/add`, `/purchase/payment/add` |
| Expense | `/offline` | `POST /api/expenses` | `/expense/add` |
| New customer / supplier | `/offline` | `POST /api/customers`, `POST /api/suppliers` | `/customer/add`, `/supplier/add` |
| Stock adjustment (pending only) | `/offline` | `POST /api/stockadjustments` as *pending*; posting stays with the approver | `/stockadjustment/add` |

**Everything else is online only**: editing or deleting a posted document, returns, approvals, journals and accounting vouchers, payroll and
HR, the period lock, users / roles / settings, reports, imports and exports, subscriptions. Opening one of those screens offline shows one
friendly page (what works offline, how many entries wait) instead of a broken page.

## Reference data (read only, on the device)

`GET /api/offline/reference` returns, for the signed-in user's company and branch only: products (variation, unit, SKU, barcodes, sell and
purchase price), contacts with the time their balance was read, units, taxes, payment methods, cash / bank and expense accounts,
branches and warehouses, the open invoices of the company (for receipts and payments), and which of the offline kinds the user may do.
It is stored in IndexedDB under `userId:companyId:branchId` and shown with a "last synced" time, "stale" after a day.
**No authenticated page is cached** (a shared device): the service worker keeps only the two offline screens (`/offline`,
`/sell/pos/offline`) and the build files; they work as client-side forms.

## Entries, numbers, order

Every entry has a client UUID (idempotency key), the device id, `created_at` and a provisional reference `OFF-<device>-<seq>`, printed
as "provisional". The real number is the server's, assigned at sync, and written back to the local record (`real_ref`); numbers never
gap or repeat because they are made by the normal document code. Entries of one device are sent in the order they were made.

## Sync

One endpoint, `POST /api/offline/sync` (one entry per call, 256 KB, 60 calls a minute), writes a row in `offline_sync_entries`
(company, user, device, uuid, kind, status, error, conflict code, result, processed_at) with a unique key on (company, uuid): a second
call with the same UUID returns the first answer and posts nothing. The entry is replayed **through the real controllers** as the user
who made it (their permissions are checked again, tenant scope, period lock, credit limit, stock rules, tax and withholding are the
ones of an online save); a sale and its payment are one unit (one database transaction).

A refusal is never dropped. It becomes a **conflict** with a code and the server's words:
`insufficient_stock`, `period_locked`, `credit_limit`, `product_missing`, `price_changed` (POS), `contact_blocked`, `no_permission`,
`rejected`. The user then chooses **Edit and retry** (the entry's form opens filled in; saving replaces the queued entry and sends it
again) or **Discard** (asks to confirm, and writes the audit log with who, what, when and why). The supervisor screen
(`/posoffline`) lists conflicts of both the POS terminal and the engine.

## Detecting the network

`navigator.onLine` is not trusted: a heartbeat to `GET /api/ping` (4 second timeout, every 15 seconds, and on the browser's online /
offline events) decides. The header chip shows Online, Offline, Syncing n or Conflicts n, and a Sync now button. On reconnect the queue
is sent automatically with a growing delay after a failure; Background Sync is used when the browser has it, otherwise the page
open does it.

## Safety

* The local queue is keyed by user and company; another user of the same browser sees none of it and never sends it.
* Logging out with entries waiting asks first ("n entries have not been sent"); even a forced logout leaves them on the device, under
  the user's id, and only that user's next sign-in sends them. An expired session stops the sync with "sign in to send" and loses nothing.
* The queue holds no password or token. It is limited to 500 entries and the storage quota is handled (a clear message when the
  device is full; nothing is overwritten).
* Server: tenant isolation on every row, rate limit and payload limit on the sync, an audit-log line for every discard.

## Not in this round

Editing a queued entry's lines in a spreadsheet-style grid (the form is reopened instead), offline returns, offline approvals, and
anything that needs the server's figures live (customer balance after the sale is shown as of the last read).
