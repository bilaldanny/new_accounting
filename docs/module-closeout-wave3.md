# Module closeout, wave 3: Procurement & Purchases (9 items)

All nine rows of module "4. Procurement & Purchases" that were Partial or Missing are built. Everything is additive: a company
that uses none of it sees the purchases, stock, payments and ledger it saw before, and the tests below pin that.

Live migrations to run, in this order (nothing has been run on the live database):

1. `2026_11_06_100000_create_procurement_closeout_tables`
2. `2026_11_06_110000_add_procurement_closeout_menus` (companyadmin is granted every new page and the report row)

The code tolerates the new tables / columns being absent, so deploying before migrating does not break the app.
Tests: `tests/Feature/ProcurementCloseoutTest.php` (18 tests), `RolePresetAccessTest` (Accountant gets Supplier Payments,
Warehouse Keeper gets Quality Inspection and Inbound Shipments, Manager gets Reorder Suggestions and Supplier Returns;
Purchase Approval Rules stays administrator-only).

## 1. Purchase Orders & Multi-Level PO Approval: Built
`purchase_approval_rules` (amount range, ordered levels each tied to a role, creator may not approve, priority) and
`purchase_approval_steps`; `App\Services\PurchaseApprovals`, called from `Transaction::approvePurchase`. The narrowest matching
range wins. A purchase moves to approved only when the last level approves; each approve call is one level, by a user of that
level's role, never the creator (when the rule says so) and never someone who already took a lower level. A purchase no rule
matches keeps the single step. Page `/purchaseapprovalrule`; the approval show endpoint now returns the progress.
Tests: "with no approval rule one approval approves a purchase, as before" (guarantee), "a purchase in a rule range stays
pending until every level approves ...", "approval rules are company scoped and need their permission".

## 2. Auto Reorder Suggestions: Built
`App\Services\ReorderSuggestions`: stock (StockMovements) at or below the reorder point = reorder level (or minimum stock) +
average daily sales of the last 30 days x lead time; suggested quantity brings stock to the maximum (or the reorder quantity),
supplier from consignment ownership or the last purchase. `POST /api/reorder-suggestions/purchase-orders` makes one draft purchase
per supplier through `Transaction::createPurchase` (status `draft`: no stock and no ledger effect, tested). Page `/reordersuggestion`.
Tests: the three reorder tests (suggestion and draft, lead time and sales, isolation / supplier checks).

## 3. Import Purchases (LC, customs clearing, multi-currency): Built, with a stated limit
`import_purchase_details` and `ImportPurchaseController` (`/api/import-purchases`, page `/importpurchase`): LC number, bank,
amount, open and expiry dates, bill of lading, customs reference and clearing date, clearing agent, currency, exchange rate and
foreign total, with the local equivalent and the difference from the purchase total shown. **Limit:** these are details kept
with the purchase; the purchase still posts in the local currency (entering the local amount you pay), so foreign-currency
revaluation and exchange gain / loss are not computed. Multi-currency vouchers are a wave 6 item.
Tests: "import details are kept beside the purchase ...", "import details of another company cannot be read or changed".

## 4. Quality Inspection on Receiving: Built
`quality_inspections` + lines, `purchase_lines.quantity_rejected`, `QualityInspectionController`, page `/qualityinspection`.
Accepted plus rejected may not exceed what was received; the rejected quantity is kept on the line and `StockMovements` counts
received less rejected, so **stock counts only what was accepted**. Deleting an inspection puts the line (and stock) right.
The rejected goods leave through an RMA / purchase return.
Tests: "stock counts only what an inspection accepted, and deleting the inspection puts the rejected quantity back" (guarantee:
stock before is the received quantity), isolation test.

## 5. Purchase Payments, Advance & Payment Allocation: Built
`supplier_payment_batches` + allocations, `SupplierPaymentController`, page `/supplierpayment`. One payment is allocated across
several bills, each through `Payment::createPurchasePayment` (the same voucher a payment on the bill posts); what no bill takes is
kept as a supplier advance (`Payment::createPurchaseAdvance`: supplier debited, cash / bank credited) and later settles bills
without moving cash (`createPurchasePaymentFromAdvance`, no voucher). A batch can be reversed: its payments and vouchers are
removed and the bills go back to due. The existing purchase payment endpoints are untouched.
Tests: allocation + advance with cash credited exactly once per payment, advance use and reverse, isolation.

## 6. Inbound Shipment & Receiving Tracking: Built
`inbound_shipments` + events, `InboundShipmentController`, page `/inboundshipment`: carrier, tracking, container, shipped / ETA /
arrived dates, a status history, late shipments (ETA passed, not arrived) flagged and filterable. Stock is still received through
the receiving note.
Test: "a shipment keeps a status history and a late one is flagged".

## 7. RMA (Authorization, Inspection, Return Receipt): Built
`rma_requests` + lines, `RmaController`, page `/rma`: requested > authorized (needs the supplier's number) > inspected > shipped >
received > credited > closed (or rejected), only along `RmaRequest::FLOW`; the purchase return document can be linked
(`purchase_return_id`). Only an unauthorised request can be deleted.
Test: "an RMA moves along its flow only, needs the supplier number to be authorised and is company scoped".

## 8. Supplier Performance (On-Time Delivery, Defect Rate): Built
`SupplierPerformanceReport` gains **On-time %** (tracked shipments that arrived on or before the ETA) and **Defect %** (rejected
over inspected quantity); each is a dash when there is nothing to judge by, never a false 0 or 100.
Test: "supplier performance shows on-time delivery and defect rate, and a dash when there is nothing to judge".

## 9. Supplier Price Comparison: Built
New analytics report `/report/supplier-price-comparison` (`SupplierPriceComparisonReport`): per product and supplier the latest,
quantity-weighted average and lowest rate per base unit, the number of purchases and how far above the cheapest each is.
Test: "price comparison lists each supplier rate per product and flags the cheapest".
