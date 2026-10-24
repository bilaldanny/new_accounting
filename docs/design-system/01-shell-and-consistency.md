# Part 1: shell and consistency

## 1. The layout shell

### Layout files (8) and who uses them

| File | Used? | By |
|---|---|---|
| `layouts/AppLayout.vue` | yes | every page except the ones below (`app.ts` `layout:` switch) |
| `layouts/app/AppSidebarLayout.vue` | yes | wrapped by `AppLayout`; it is the shell |
| `layouts/app/AppHeaderLayout.vue` | **no importer found** | dead file |
| `layouts/AuthLayout.vue` -> `auth/AuthSimpleLayout.vue` | yes | the 6 `auth/*` pages |
| `layouts/auth/AuthCardLayout.vue`, `AuthSplitLayout.vue` | **no importer found** | dead files |
| `layouts/settings/Layout.vue` | yes | nested as `[AppLayout, SettingsLayout]` for the 3 `settings/*` pages (Profile, Security, Appearance) |
| (no layout, `null`) | yes | 6 pages: `sell/addpos` (POS), `sell/posdisplay`, `sell/receipt`, `portal/index`, `public/leadcapture`, `public/storefront` |

So the signed-in app has **one real shell** plus 3 deliberate exceptions (POS/receipt/display, customer portal, public capture/storefront). The public website (`landing/*`, 4 pages) is a separate front-end bundle (`resources/js/landing.ts`, `resources/css/landing.css`, 1,362 lines) with its own tokens (`--lp-*`).

Title and breadcrumbs reach the shell in two ways: `defineOptions({ layout: { title, subtitle, breadcrumbs } })` (331 page files) and `setLayoutProps(...)` (17 files, e.g. `report/index.vue`). 82 add/edit/view/import pages set neither because their form chrome draws its own title (see 3. below).

### Shell parts (from `AppLayout.vue`, `AppSidebarLayout.vue`, `AppSidebar.vue`, `AppSidebarHeader.vue`, `HeaderTools.vue`)

| Part | What exists | Where |
|---|---|---|
| Sidebar | 250 px wide (computed; SCSS variable says 240), white. MetisMenu tree up to 4 levels, group labels (menu type 3), active branch highlighted, role-filtered. Collapse button (`toggle-icon`) shrinks to 70 px; a collapsed bar expands on hover. On phones it opens from the header burger. **No search inside the sidebar** (search is in the top bar). | `AppSidebar.vue` (328 lines), `useSidebarToggle.ts` |
| Header (top bar) | 60 px, dark (`#161922`). Left: burger (mobile), global search (results dropdown; `/` or Ctrl+K). Right: "ERP Synced" badge, Online/Offline chip (links to `/offline`), then `HeaderTools`: today's date (company time zone), **Today Profit**, **POS**, **Calculator**, **Tools** menu (calendar, to-do list, application tour), **sound toggle**; then notification bell (counts by type, mark read) and the user menu (initials avatar, name, email, Profile, Settings, Dashboard, Logout). | `AppSidebarHeader.vue` (688 lines), `HeaderTools.vue` (658 lines) |
| Breadcrumb + page title bar | `TheBreadcurm`: `<h1>` title on the left, `Home > ... > Page` on the right (stacks on phones). Hidden on the dashboard. **No actions live here**; page actions sit inside the page card (`topButtons`). | `theBreadcurm.vue` |
| Page actions | In the first row of the list card: Filter, Refresh, Import, Add New, Trash, bulk icons (`topButtons.vue`, 309 lines, used by 113 pages). Form pages have their own sticky action bar (see FormChrome below). | `topButtons.vue`, `*FormChrome.vue` |
| Footer | One line: "Copyright (c) year name". | `AppFooter.vue` |
| Floating / global | Back-to-top, Offline-blocked screen, keyboard shortcuts overlay (`?`), inactivity timer, internet detector, two toast systems (see Part 2), purple **Theme Customizer** cog on the right edge (`AppSwitcher.vue`). | `AppSidebarLayout.vue` |

### Pages outside the shell, or with their own page-level style

- No shell by design: POS (`sell/addpos.vue`, 3,041 lines, 1,026-line scoped style, 166 hex colours), customer display, receipt, portal, public storefront / lead capture.
- Inside the shell but styled on their own: `chart-of-account/index.vue` (841 lines), `opening-balance/index.vue` (764 lines), `project/index.vue`, `printlabel/index.vue`, `sell/invoice.vue` (print), `report/ledger.vue`.
- Not an app page at all: the 6 auth pages (own split layout), the 4 landing pages (own bundle).

## 2. "Menu index looks different from company index" and the same problem elsewhere

### What the code says about those two

`pages/menu/index.vue` (343 lines) and `pages/company/index.vue` (334 lines) are the **same template**: same `admin-list-page`, same `theTable` + `topButtons` + `theFilter`, same history-state code, same modals. A line diff shows the visible differences come from configuration only:

| Difference | Menu | Company |
|---|---|---|
| Columns | 3 (Name, Route, Sort order stepper) | 8 (Name with link, Email, Phone, Owner name / email / phone, Branches used, Users used) |
| Toolbar | filter shown, bulk icons **hidden** (`:show-bulk-icons="false"`) | filter **hidden** (`:show-filter="false"`), bulk icons shown |
| Special row control | sort-order stepper (`updateSortOrder`) and "select all across pages" | none |
| Add / edit modal | menu fields | company fields plus logo upload (`logo-url`, `record-id`) |
| Data loading | also loads the menu tree for the parent picker | generates a company code |

The company table is wide, so it scrolls sideways; the menu table is narrow and does not. That is what makes them look unlike each other. The fix is a **column and toolbar standard**, not a new template (see the brief: "List page, chunk 4").

### Ranked list: same kind of page, looks different (most different first)

Measured against the list standard (root `admin-list-page`, `theTable` / `ClientTable`, `topButtons`, no own `<h1>`, no raw table, almost no page CSS).

| # | Page | Is the same kind as | How it differs | Size of gap |
|---|---|---|---|---|
| 1 | `opening-balance/index.vue` | master lists (Chart of accounts, Banks) | root is `product-form-view` (form chrome), own `<h1>`, a raw `<table>`, 764 lines of its own markup | very large |
| 2 | `chart-of-account/index.vue` | other master lists | root `product-form-view`, own `<h1>`, 95-line scoped style, 16 hex colours, `ClientTable` instead of `theTable`, 841 lines | very large |
| 3 | `project/index.vue` | other master lists | 234-line scoped style, 31 hex colours, own cards | large |
| 4 | `printlabel/index.vue` | other lists | root `product-form-page`, 415 lines, own sheet layout | large |
| 5 | `posoffline/index.vue` | other lists | raw `<table>`, no `theTable` | medium |
| 6 | `customer/view.vue` vs `supplier/view.vue` | each other | two 2,156 / 2,198-line copies; the ledger, activity and documents tabs are re-implemented in each | large (duplication) |
| 7 | `sell/*` forms vs `purchase/*` forms vs the 26 other FormChromes | each other | `PurchaseFormChrome` (169 lines, used by 10 pages) has a reference chip and 3 buttons; 26 other chromes are 105-114 lines; 8 pages use `SimpleFormChrome`; **50 add/edit pages have no chrome** (they open as modals from the list) | large |
| 8 | `businesssettings`, `documentsetting`, `messagingsetting`, `setup`, `systemcenter`, `help`, `mynotifications` | settings pages | wrapped in `admin-list-page` (list shell) although they are forms or consoles | medium |
| 9 | `documentcenter/index.vue` (640 lines), `manufacturing/index.vue`, `crmanalytics/index.vue`, `chequeprint/index.vue` | workspace consoles | inline styles (3-9 each), own headings, mixed cards | medium |
| 10 | `portal/index.vue` | app lists | root `container` (no shell by design) | by design |
| 11 | `report/ledger.vue` | the report page | raw `<table>`, client-side running balance, own filter row; `report/index.vue` is the standard | medium |
| 12 | The 16 `approval/*/index.vue` | each other | these are 26-28-line wrappers around two shared lists (`SimpleApprovalList`, `VoucherApprovalList`): **consistent**, listed here only because a naive scan flags them | none |

Other cross-cutting inconsistencies (full evidence in Part 2): `.btn-dark` is teal with 9 px radius inside list pages but black with 4 px radius on the dashboard; the same date is "11 Oct 2026" in Sales and "2026-10-01" in Expenses; 39 pages draw their own `<h1>`; 55 pages contain a raw `<table>`.

## 3. Page-type catalog

570 page files. The scan finds **16 raw types**; the brief merges them into **12** (more than 12 raw types is the signal to merge).

| Raw type | Files | Example files | Shared components used | Not following the type's standard |
|---|---|---|---|---|
| List / Index (index, trash, draft, shipment) | 165 | `menu/index.vue`, `contact/customer/index.vue`, `sell/index.vue` | `theTable` 98, `ClientTable` 67, `topButtons` 98, `theFilter` 54, `CompanyPicker` 39, `ModalComponent` | 2 by the strict test (own `<h1>`, hex colours, page CSS); plus the outliers named above that were typed as workspaces |
| Form (create / edit) | 159 (60 add + 59 edit + 40 Fields / chromes) | `contact/customer/add.vue`, `product/Fields.vue`, `warehouse/WarehouseFormChrome.vue` | `TheForm` (Vueform) 93, `Loader` 93, `ModalComponent` 50, `SimpleFormChrome` 8 | **67**: 50 add/edit with no chrome, 17 with an own `<h1>` |
| Voucher / document entry (multi-line) | 59 | `journalentry/Fields.vue`, `sell/LineItemsEditor.vue`, `purchase/Fields.vue` | `TheForm`, `Loader`, `WarehousePicker`, `ProjectPicker`; 10 different line editors | **15**: 10 own `<h1>`, 5 hex / page CSS |
| Detail / view (incl. print) | 39 | `journalentry/view.vue`, `contact/customer/view.vue`, `sell/invoice.vue` | `Loader` 29, `VoucherApprovalActions` 6, `ActivityTimeline` 4 | 3 |
| Settings / config | 42 | `company/setting.vue` (11 tab files), `software/setting.vue` (4), `businesssettings/index.vue`, `settings/Profile.vue` | `Loader` 12, `theTable` 10 | 5 (note: 10 `software/*` city / country / state lists are typed here by folder but are really lists) |
| Approval inbox | 23 | `approval/sell/index.vue`, `approval/simple/SimpleApprovalList.vue` | `theTable`, `theFilter`, `topButtons` | 0 |
| Workspace (tabbed console) | 23 | `hrm/index.vue`, `manufacturing/index.vue`, `budget/index.vue`, `dispatch/index.vue` | `ClientTable` 17, `CompanyPicker` 16 | 1 by the test; many by the ranked list above |
| Import (modal page) | 14 | `branch/import.vue`, `company/import.vue` | `ImportModalLayout` 14 | 0 |
| Dashboard | 6 | `Dashboard.vue`, `dashboardinsights/index.vue`, `crmanalytics/index.vue` | `ClientTable`, `CompanyPicker`, `dashboard/*` | 2 |
| POS | 6 | `sell/addpos.vue`, `posshift/index.vue`, `sell/receipt.vue` | `TrackingInput` | 3 (page CSS, hex) |
| Public site | 7 | `landing/Index.vue`, `public/storefront.vue` | `landing/*` | 6 (own style on purpose) |
| Auth | 6 | `auth/Login.vue` | `PasswordInput` | 0 |
| Report (served by one file, 79 reports) | 1 file | `report/index.vue` | `theTable`, `theFilter`, `topButtons`, `FiscalYearDateRange` | 0 |
| Ledger / statement | 1 file | `report/ledger.vue` (plus the ledger tab in `contact/*/view.vue`) | `FiscalYearDateRange`, `LedgerStatementActions` | 1 |
| Wizard / setup | 1 | `setup/index.vue` | `CompanyPicker` | 0 |
| Partials (hints, pickers, editors) | 18 | `deposit/FieldHint.vue`, `product/VariationPicker.vue` | n/a | n/a |
| Error / empty pages | **0 files** | `routes/web.php` line 1664 renders `errors/403`, but `resources/js/pages/errors/` does not exist | n/a | not found |

### Recommended 12 templates (merge map)

| # | Template | Merges |
|---|---|---|
| 1 | **List** | List / Index, Import (modal), trash lists |
| 2 | **Form** (create / edit, page or modal) | Form, partial hints |
| 3 | **Voucher / document entry** | Voucher / document entry |
| 4 | **Detail / print document** | Detail / view, receipt, invoice, label sheet |
| 5 | **Ledger / statement** | Ledger, party statement, account ledger |
| 6 | **Report** | the 79 reports, financial statements and aging as variants |
| 7 | **Dashboard** | Dashboard, insights, analytics |
| 8 | **Settings** | Settings / config, theme and profile |
| 9 | **Workspace** | Workspace consoles, Wizard / setup, kanban / calendar boards |
| 10 | **POS / till** | POS, shift, customer display |
| 11 | **Approval inbox** | Approval inbox |
| 12 | **Public / auth / error** | Public site, Auth, error pages (new) |

Kanban and calendar boards: only a pipeline page (`opportunity/pipeline.vue`), the header calendar popup (`HeaderTools.vue`) and `hrm` attendance exist; there is no shared board or calendar component.
