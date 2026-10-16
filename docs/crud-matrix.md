# CRUD matrix

What proves add -> edit -> view -> delete / restore, validation, duplicate, bulk, export and import for each main resource. Nothing here was built new: the round only checked that the screens still behave alike.

| Resource | Add / edit / view / delete (or cancel) + validation | File |
|---|---|---|
| Brands, categories, warranties, item types, departments, units, customer groups | name check, duplicate, restore, bulk delete, company isolation | `CrudMatrixTest.php` |
| Journal entries, payments, expenses, deposits, fund transfers | unbalanced and missing-date refused, edit, delete, another company gets 404 | `CrudMatrixVouchersTest.php` |
| Sales, purchases, sales returns, purchase returns | missing contact / lines refused, edit, delete, another company gets 404 | `CrudMatrixTradeTest.php` |
| HR employees, loans, leave requests | duplicate number, loan amount, reversed dates refused; edit, close loan, cancel leave, delete | `WaveSevenHrmTest.php` ("crud matrix") |
| Projects | blank name, duplicate code refused; edit; delete | `ProjectCostingTest.php` ("crud matrix") |
| Manufacturing bills and work orders | empty bill, zero quantity refused; edit and delete a bill; cancel a work order | `WaveEightManufacturingTest.php` ("crud matrix") |
| Service jobs | blank title refused; edit; cancel (a job is never deleted) | `WaveEightServiceTest.php` ("crud matrix") |
| Rental items and bookings | zero units, reversed dates refused; edit; cancel (a booking is never deleted) | `WaveEightRentalTest.php` ("crud matrix") |
| Company holidays | add, edit, delete, import, company isolation | `HolidaysTest.php` |
| Customers, suppliers, products | their own `*ApiTest.php` files | existing |

Import and export: each master has its `*ImportTest.php`; the table export (CSV / Excel of the rows on screen) is the table component's and
a document's export is `WaveFiveDocumentsTest`.

**Found and fixed by the matrix:** editing a voucher (and a number of other forms) that belongs to another company answered 500 with an
empty message instead of 404, because the controller's catch-all swallowed the not-found. 57 controllers now let a not-found or an HTTP
error through. Nothing was ever changed: the answer was only the wrong status.
