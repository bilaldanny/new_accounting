# Module closeout, wave 1: Products & Catalog (13 items)

Every item below was a Partial or Missing row of module "3. Products & Catalog" in the October re-audit. Each is additive:
a company that uses none of it sees the stock, costs, prices and ledger it saw before, and the tests below pin that.
Nothing posts to the general ledger; stock effects go through the existing stock-adjustment path and `StockMovements`.

Live migrations to run, in this order (nothing has been run on the live database):

1. `2026_11_04_100000_create_product_catalog_extension_tables`
2. `2026_11_04_110000_add_product_catalog_menus` (companyadmin is granted every new page; the legacy hidden `/openingstock`
   rows become the real Opening Stock page)

The code tolerates the tables / columns being absent (`ProductCatalog::supported()` and the per-feature memo checks), so
deploying before migrating does not break the app.

Tests: `tests/Feature/ProductCatalogCloseoutTest.php` (25 tests), `tests/Feature/RolePresetAccessTest.php`. Menu / preset
fixtures: `tests/Fixtures/live-menus.json`, `role-preset-menus.json` (Sales Representative gets `/warrantyclaim`, Warehouse
Keeper gets `/productbom`; no other preset changes).

## 1. Item Types: five behaviours
`item_types.behaviour` = inventory, non_inventory, service, kit, digital; NULL reads as inventory.
`App\Models\ItemType` (BEHAVIOURS, UNSTOCKED_BEHAVIOURS), `ItemTypeController` validation, `product/itemtype/Fields.vue`
(Behaviour select). `StockMovements` leaves non-inventory, service, digital and kit lines out of stock, and
`SaleStockCheck` never blocks on them.
Tests: "an item type keeps behaviour null by default and accepts only the five behaviours", "a service, non-inventory or
digital item never moves stock and is never blocked by it", guarantee "stock of a product with no behaviour is exactly what
it always was".

## 2. Custom Product Attributes & dynamic fields
`product_attribute_definitions` (text, number, date, choice, checkbox; required; active), `ProductAttributeController`
(`/api/product-attributes`), values in `products.custom_fields` validated by `ProductCatalog::cleanCustomFields`; the
Custom Attributes page (`/productattribute`) and the Catalog details section of the product form render them dynamically.
Test: "custom attributes are defined per company and validated when a product is saved", "attribute pages need their menu
permission".

## 3. Product Tags
`products.tags` (JSON, trimmed, de-duplicated, capped); tag element on the product form; bulk add / remove through the
bulk update. Test: "tags are cleaned, kept on the product and changed in bulk".

## 4. Warranties & after-sales rules
`warranties.require_invoice / max_claims / terms`, `warranty_claims` (claim_no, serial, in-warranty judgement against the
sale date and warranty period, open > in review > approved / rejected > resolved with repair / replace / refund / none),
`WarrantyClaimController` (`/api/warranty-claims`), page `/warrantyclaim`. A claim after the warranty ended or above
`max_claims` needs a reason to be approved. Company and branch scoped.
Tests: "a warranty claim is judged against the sale date and the warranty rules", "a claim after the warranty ended needs a
reason to be approved", "warranty claims are company and branch scoped".

## 5. Multiple barcodes per product & sale / POS scan lookup
`product_barcodes` (unique inside the company, optional variation), saved with the product (`barcodes`),
`ProductCatalog::resolveCode`, `GET /api/products/lookup?code=`, and the sale / POS product search matches them (the POS
scan-and-Enter path). Barcode generator / label printing is the existing Print Label page.
Tests: "a product answers to several barcodes, unique inside the company, and the sale search finds it", "a barcode of
another company is not found".

## 6. Consignment stock
`products.ownership` (own / consignment) and `consignment_supplier_id` (a supplier of the same company);
`ConsignmentController`: stock held per supplier with what was sold since the last settlement and what is owed,
settlements (draft > settled with a payment reference), page `/consignment`. Settling records the reference; it does not
post to the ledger (the supplier is paid through the normal payment vouchers).
Test: "consignment ownership needs a supplier of the company and the stock report counts what was sold".

## 7. Kits / bundles / combos
Item type behaviour `kit` + `product_components`. A kit sale takes its components out of stock (not itself), a return gives
them back, the stock check validates components. `StockMovements::kitComponents`, `SaleStockCheck`.
Tests: "a kit sale takes its components out of stock, not itself, and a return gives them back", "a component list cannot
loop, include a service or point to another company".

## 8. BOM and kitting / de-kitting
`ProductComponentController` (`/api/products/{id}/components`, `/assemble`), `App\Services\KitAssembly`: kitting and
de-kitting are completed stock adjustments through `Transaction::createAdjustment` (period lock, branch, warehouse and
stock rules as for any adjustment); no new costing engine. Page `/productbom`.
Test: "kitting and de-kitting are completed stock adjustments through the normal path".

## 9. Product discount rules
`product_discount_rules` (product / category / brand / all, percent or fixed, minimum quantity, dates, priority),
`ProductDiscountRules` (narrowest scope, then priority, then size), `/api/product-discount-rules`, page
`/productdiscountrule`. The sale and POS product search carries each product's rules and the line discount is prefilled
from the best one, following quantity breaks until the user types a discount (`resources/js/utils/discountRules.ts`,
`sell/Fields.vue`, `sell/addpos.vue`). `PriceRules` still decides whether the price may be sold.
Tests: "the narrowest matching discount rule wins and the product search carries the rules", "discount rules of another
company are neither listed nor applied".

## 10. Opening Stock screen
`OpeningStockController` (`/api/opening-stock`, page `/openingstock`): per branch, by SKU or barcode, valued with a unit
cost (`purchase_lines.opening_cost`, read by the average cost), written as a completed `opening` stock adjustment. A
variation already opened in the branch is refused (correct it with an adjustment). Nothing posts to the ledger.
Tests: "opening stock is entered once per variation and branch, valued, and does not post to the ledger", guarantee
"average cost is untouched by adjustments that carry no opening cost".

## 11. Import Products / Import Opening Stock
Products import is unchanged; opening stock imports from a spreadsheet on the same screen (sku, quantity, unit_cost): the
sheet is read in the browser, `dry_run` checks every row (row-numbered errors, nothing saved), then the same endpoint
saves. Template download on the page. Test: the opening stock tests above (dry run, duplicates in file, already opened).

## 12. Reorder level, reorder quantity, min / max, lead time
`products.reorder_level, reorder_qty, min_stock, max_stock, lead_time_days` (`max >= min` checked), on the product form,
bulk update and export; the existing Low Stock page and alert quantity are untouched.
Test: "reorder settings are saved with their limits checked".

## 13. Export products & bulk product update
`POST /api/products/bulk-update` (category, subcategory, brand, item type, warranty, active, alert / reorder / min / max /
lead time, ownership, tags add / remove, custom fields; company scoped; capped ids) with the panel above the product list;
`GET /api/products/export-all` (every field, company scoped CSV).
Tests: "bulk update changes the chosen fields of the selected products only inside the company", "the complete product
export has every field and only the company products".

## Not covered
- Barcode label design is still the existing Print Label page.
- Consignment settlement does not create a purchase voucher; it is a settled record with the supplier's payment reference.
- Bundle pricing is the kit product's own price; there is no automatic "sum of components" price.
