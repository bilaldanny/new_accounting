# CRUD matrix

What proves add -> edit -> view -> delete / restore, validation, duplicate, bulk, export and import for each main resource. Nothing here was built new: the round only checked that the screens still behave alike.

| Resource | Add / edit / view / delete / restore / bulk / name check | Validation + duplicate | Import | Other tests |
|---|---|---|---|---|
| Brands, Categories, Warranties, Item types, Departments, Units, Customer groups | `tests/Feature/CrudMatrixTest.php` (same steps for each) | same file | `*ImportTest.php` per resource | `*ApiTest.php` per resource |
| Customers, Suppliers | `CustomersApiTest`, `SuppliersApiTest` | same | `CustomersImportTest` | `ContactActivityTabsTest`, `CustomerPortalTest` |
| Products | `ProductsApiTest`, `ProductVariantsApiTest` | same | `ProductsImportTest` | `ProductCatalogCloseoutTest` |
| Sales, sales returns | `SellApiTest` family, `SellReturn*Test` | same | - | `SellApproval*Test`, `PosOffline*Test` |
| Purchases, returns, requisitions | `PurchasePagesTest`, `PurchaseReturnApiTest`, `PurchaseRequisitionApiTest` | same | - | `PurchaseApprovalApiTest` |
| Journal entries, payments, expenses, deposits, fund transfers | `JournalEntriesApiTest`, `PaymentsApiTest`, `ExpensesApiTest`, `DepositsApiTest`, `FundTransfersApiTest` | same | - | `VoucherApproval*Test`, `PeriodLock*Test` |

Export is the table's own (CSV / Excel of the rows on screen) and is covered by the Dusk table tests; a document's CSV / Excel export is `WaveFiveDocumentsTest`.
No breakage was found by the matrix in this round.
