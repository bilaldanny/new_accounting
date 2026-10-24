# Part 2: design tokens and components (from the real CSS / Vue)

## 4. Tokens

### Sources of style (where a value can come from)

| Layer | Files | Size |
|---|---|---|
| Bootstrap 5 + the purchased admin template | `resources/scss/app.scss` imports `bootstrap`, `bootstrap-extended`, `custom_app` (5,779 lines), `dark_theme` (828), `header_colors` (326), `semi_dark` (102), `_variables` (1,831) | ~20,600 lines of SCSS in total |
| App design system (the "modern-*" and "admin-*" classes, `--app-*` tokens) | `admin-design-system.scss` (6,740 lines), `dashboard-design-system`, `sidebar-design-system`, `auth-design-system`, `modal-design-system` | |
| Phone polish (added in this session, uncommitted) | `_mobile-polish.scss` (319 lines) | |
| Tailwind v4 + shadcn tokens | `resources/css/app.css` (172 lines): `--background`, `--primary` ... as grey HSL | used by `components/ui/*` and by Tailwind utilities in 274 page files |
| Public site | `resources/css/landing.css` (1,362 lines), tokens `--lp-*` | separate bundle |
| Page `<style>` blocks | 32 page files, 7,397 lines | |

### Colours

The only named token set the app actually uses is `--app-*` (declared once in `admin-design-system.scss`, used 111 times for primary, 124 for border):

| Role | Token / value | Notes |
|---|---|---|
| Primary | `--app-primary`; teal. Three teals are in use: `#199683` (136 uses; the old topbar / `--vf-primary`), `#0d9488` (113; list-page buttons, the "modern" primary), `#0f766e` (78; hover, form chrome) | one brand, three values |
| Primary soft / border / hover | `--app-primary-soft`, `--app-primary-border`, `--app-primary-hover` | |
| Success / warning / danger / info | `--app-success` (+ `-soft`, `-border`), `--app-warning #d97706`, `--app-danger`, `--app-info #0369a1`; each has soft background and border | defined, but pages also use Bootstrap `bg-success` etc. (32 pages) |
| Text | `--app-text #111827`, `--app-text-secondary #64748b`, `--app-text-muted #94a3b8` | |
| Surfaces | `--app-background #f8fafc` (page), `--app-card #ffffff`, `--app-border #e2e8f0`, `--app-border-subtle #f1f5f9` | measured: page `rgb(248,250,252)` |
| Sidebar | white (`rgb(255,255,255)`), active item teal tint | |
| Top bar | `rgb(22,25,34)` = `#161922` (measured), not a token | dark bar over a light page |
| Stray accents | `#8833ff` (35 uses, the theme-customizer cog), `#6366f1` (24), `#fd3550` (21, template red), `#15ca20` (19, template green) | template leftovers |

Totals: **315 distinct 6-digit hex colours, 2,675 uses**: 1,641 in SCSS / CSS and **1,034 inside 41 Vue files** (888 in page files, the rest in components), 171 inline `style=""` attributes in pages. Slate greys dominate (`#f8fafc` 162, `#64748b` 159, `#94a3b8` 134, `#e2e8f0` 118, `#f1f5f9` 112). Template themes (`_theme-color.scss`, `[data-theme-mode="red"]` and others) are present but only the default is reachable in the UI.

### Typography

| Fact | Evidence |
|---|---|
| **Four font stacks are declared**: Bootstrap `$font-family-sans-serif: "Poppins"` (`_variables.scss:687`), `--app-font: Inter, ui-sans-serif, ...` (`admin-design-system.scss:52`), Tailwind `--font-sans: Instrument Sans` (`app.css`, loaded by the `@fonts` directive), and the template's own Roboto | |
| What the browser actually uses (measured) | `<body>`: **Roboto**, 14 px. Page title, table header and table cells: **Inter** (18 px / 700; 12 px; 13.5 px). So two fonts on one screen; Poppins and Instrument Sans are declared but did not show up on the pages measured |
| Monospace | `--app-font-mono` (10 uses) for codes and voucher numbers |
| Sizes | **58 distinct `font-size` values** in SCSS; the common ones are `0.75rem` (89), `0.8125rem` (77), `0.6875rem` (40), `0.875rem` (37), plus 14 / 12 / 18 / 13 / 16 px literals. No type scale is named |
| Weights | 600 (99 uses), 700 (91), 500 (52), 400 (10), odd 650 (5) and 800 (4) |

### Spacing, radii, shadows, borders

- **Spacing**: no app scale. Bootstrap `$spacer: 1rem` utilities (`p-3`, `mb-2`) on 407 pages, Tailwind utilities (`px-4`, `gap-2`) on 274 pages, and raw `rem` values in SCSS.
- **Radii**: tokens exist (`--app-radius-sm 7px`, `-md 9px`, `-lg 14px`, `-xl 18px`) but SCSS has **34 distinct `border-radius` values**. Measured: list buttons 9 px, inputs **4 px**, dashboard cards **6 px**, list cards 14 px, pills 999 px (29 uses), circles 50% (37).
- **Shadows**: `--app-shadow-card`, `--app-shadow-sm`, `--app-shadow-row` exist (used 5 / 9 / 1 times); SCSS has **208 `box-shadow` declarations with 126 distinct values**.
- **Borders**: `--app-border` (124 uses) and `--app-border-subtle` (62).
- **Control height**: `--form-control-height: 2.375rem` (38 px, measured on inputs).
- **Breakpoints**: 56 `@media` rules in app SCSS; the phone rules end at 767.98 px (new file) while older rules use 575.98 / 640 / 700 / 900 / 991.98 / 1100 / 1280 px; there is no single breakpoint list.

### Icons

- **Two icon libraries are in active use**: `@lucide/vue` in **147 files, 102 of them pages** (plus the shadcn kit and shell components) and `@boxicons/vue` in **42 files, 31 of them pages** (`topButtons.vue`, `AppSidebarHeader.vue`, report and list pages). Same screen can mix them (the header uses boxicons, the dashboard cards use lucide).
- Font icons also ship: `LineIcons` and `boxicons` fonts in `public/assets/fonts`, `icons.css` (144 KB) from the template, Material Design Icons partials under `resources/scss/custom/plugins/icons`. Only 2 Vue files use font-icon class names (`lni-` / `bx-` / `bi-`).
- `components/ui/*` is the shadcn-vue kit (**116 files**); list, form and report pages almost never import it (the shell and auth screens do).
- Sidebar icons come from the menu table, mapped in `utils/menuIcons.ts` (boxicons names).

### Dark theme

- **Two separate mechanisms, neither complete**:
  1. `.dark` class on `<html>`, toggled by `useAppearance.ts` / `initializeTheme()` from the OS preference. It only drives the Tailwind / shadcn tokens (the grey HSL set in `app.css`); the Bootstrap pages ignore it.
  2. `dark-theme` class (also `semi-dark`, `minimal-theme`, and 8 `headercolorN` classes) set by **jQuery in `public/assets/js/app.js`** from the "Theme Customizer" cog (`AppSwitcher.vue`). It is implemented by **243 `dark-theme` selectors** (232 in `dark_theme.scss`, 11 in `admin-design-system.scss`) and 38 Vue files that mention a dark class. The script replaces the whole `class` attribute of `<html>` and no persistence was found (no localStorage write), so a reload returns to light.
- Result seen in screenshots: dark top bar and sidebar, but list titles dark-on-dark and cards still white on several pages (the new design-system pages were written light-first).
- Public site is dark-only (`--lp-bg #050b1a`, brand `#2dd4bf`), a different palette from the light app.

### RTL / Urdu

- `resources/js/lang/index.ts` sets `<html dir="rtl">` when the language is `ur`; Business Settings and the document tools offer English / Urdu. The page itself says: "the language framework and an Urdu word list are in place; menu names and most pages are still English" (`businesssettings/index.vue:223`).
- RTL stylesheet rules: only inside template partials (`resources/scss/custom/**`, which `app.scss` does **not** import); `html/demo/rtl/*` is the vendor demo. No Urdu web font (no Noto Nastaliq / Naskh) found. No logical CSS properties convention (`margin-inline-start`): spacing uses `ms-` / `me-` and `ml-` / `mr-` mixed.
- Verdict: **RTL not usable today**; it would be a design decision (see decisions file).

## 5. Component inventory

Counts are page files importing the component (scripted import scan, `@/components/...`).

### Used by many pages

| Component | What it does (props / slots) | Pages using | Bypassed by |
|---|---|---|---|
| `Loader.vue` (151 lines) | full-area spinner / skeleton; `message`, `fields` | **181** | 1 page uses skeleton rows instead |
| `theForm.vue` (204) | Vueform wrapper: `url`, `endpoint`, `method`, `formData`, `onSubmit`, `success`, `error`, `formSize`, `floatPlaceholders`, `showRequired` | 122 | `report/*`, `businesssettings`, hand-built forms (not counted) |
| `theTable.vue` (**2,243 lines**) | server table: search, page size, column chooser, export (csv / excel / pdf / copy), header sort, sticky header, `footer` slot, `cell-<key>` slots, keyboard rows, bulk bar. **37 props, 20 of them domain actions** (`linkCoa`, `addPurchasePayment`, `addSellPayment`, `editShipping`, `downloadInvoice`, `certificateDownload`, `approve`, `reject`, `requeue`...) | 113 | 55 pages hold a raw `<table>` (9 in list pages) |
| `ClientTable.vue` (119) | in-memory table: `columns`, `rows`, `exportName`, `emptyText`, `cell-<key>` and `footer` slots | 97 | same |
| `topButtons.vue` (309) | list toolbar: filter, refresh, add, import, trash, bulk delete / active / inactive; 20 props | 113 | |
| `theFilter.vue` (113) | collapsible filter card: `title`, `collapseId`, `showFooter`, `loading`; emits `search`, `clear` | 64 | reports build their own filter row |
| `CompanyPicker.vue` (98) | superadmin company selector | 65 | `report/index.vue` and `report/ledger.vue` render their own company `<select>` |
| `ModalComponent.vue` (133) | Bootstrap modal: `id`, `title`, `subtitle`, `size`, `dialogClass`, `header`, `footer`, `onOpen`, `onClose`, `onSubmit`; `footer` slot | 54 | 3 pages use `data-bs-toggle` modals directly |
| `ImportModalLayout.vue` (269) | import dialog: file, sample download, result; slots `help`, `extra` | 15 | |
| `SimpleFormChrome.vue` (112) | page chrome for simple forms | 8 | the 27 `*FormChrome.vue` copies below |
| `LookupSelect`, `WarehousePicker`, `ProjectPicker` | remote select pickers | 6 / 4 / 3 | `LookupSelect` is rarely used: customer / supplier / product pickers are rebuilt per form |
| `FiscalYearDateRange.vue` (183) | start / end date inside the active fiscal year | 4 (+ both report pages) | most date ranges are two native `<input type="date">` (64 files) |
| `VoucherApprovalActions`, `ActivityTimeline`, `LedgerStatementActions`, `DocumentTools`, `TrackingInput` | document-level pieces | 6 / 4 / 2 / 1 / 4 | |

### Duplicates (same job, 2+ components)

| Job | Copies | Evidence |
|---|---|---|
| Form page chrome (breadcrumb, title, Cancel / Save & Add New / Save & Close) | **27 files** named `*FormChrome.vue`, 105-114 lines each, **differing only in 3 strings** (list title, crumb, page title); `PurchaseFormChrome` (169 lines) is a larger variant; plus `SimpleFormChrome` | `diff activity/ActivityFormChrome.vue discount/DiscountFormChrome.vue` shows only the 3 label lines |
| Line-item grid | **10 editors**: Deposit / Expense / FundTransfer (335-356 lines, near copies), Journal (433), PriceList (517), Purchase (1,015), Sell (1,033), StockAdjustment (902), StockTransfer (907), PurchaseRequisition (135) | file sizes |
| Party ledger | `report/ledger.vue` (589), customer `view.vue` (2,156), supplier `view.vue` (2,198) | Part 3 |
| Dead copies | `components/old/` (24 files: duplicate `PasswordInput`, `TextLink`, `AppearanceTabs`, `DeleteUser`, `OldAppSidebar`...), unused `skeleton/*` (6), unused `TablePreferencesDrawer`, unused `AppHeaderLayout`, `AuthCardLayout`, `AuthSplitLayout` | no importer found |
| Notifications | `handleSuccess` / `handleError` from `common.ts` (101 page files, vue-notification list in `AppLayout`), `sonner` `Toaster` (6 files, 1 page), flash toast (`flashToast.ts`), one `Swal` use (sweetalert loaded on demand) | three toast looks |
| Page title | `TheBreadcurm` `<h1>` from layout options vs an own `<h1>` in 39 pages | page scan |

### Components that should exist and do not (or exist only inline)

| Missing | Today |
|---|---|
| **PageHeader** (title, subtitle, crumbs, actions) | title and crumbs from `TheBreadcurm`; actions inside the card; form pages repeat the header in each chrome |
| **FilterBar** (search, date range, party, branch, status, saved filters, "more") | `theFilter` card for lists; a different hand-made row in `report/index.vue`; a third in `report/ledger.vue` |
| **StatusBadge** | `theTable` `type: 'badge'` (65 columns); `badge bg-*` classes in 32 pages; 21 pages with their own status-pill classes |
| **AmountCell / Money** (right aligned, tabular, negative rule, Dr/Cr, currency) | `theTable` `format: 'number'`; everywhere else hand-formatted (61 files) |
| **DatePicker / DateRange** | native `<input type="date">` in 64 files; `FiscalYearDateRange` in 4 |
| **MoneyInput** | `<input type="number">` in 129 pages, no thousand separator, no currency, no paste rule |
| **ConfirmDialog** | `ModalComponent` rebuilt per page; no shared "delete? / approve? / discard changes?" |
| **EmptyState** | inside `theTable` only (`modern-empty-*`); ad hoc text elsewhere (68 files mention empty text) |
| **Skeleton** | `SkeletonTableRows` in `theTable`; 6 other skeleton components unused; `Loader` everywhere |
| **StatCard / KpiTile** | `dash-kpi-cell` (dashboard), `report/index.vue` summary cards, `product-form` cards: three looks |
| **Tabs** | Bootstrap `nav-tabs` hand-written in 36 pages |
| **Drawer** | `TablePreferencesDrawer` exists but is unused; filters use a collapse, details use modals |
| **LineGrid** (the one voucher grid) | 10 editors above |
| **PartyPicker / ProductPicker** | rebuilt in each form (`Fields.vue`) |
| **AttachmentBox, ApprovalTimeline, PrintHeader** | attachments inside each voucher `Fields.vue` (journal, payment, deposit, expense, fundtransfer, purchase); `VoucherApprovalActions` + `ActivityTimeline` exist but are used by 6 / 4 pages; print header: `sell/invoice.vue`, `sell/receipt.vue` and the cheque / label pages each carry their own |
| **ErrorPage (403 / 404 / 419 / 500)** | not found: `routes/web.php` renders `errors/403` but `resources/js/pages/errors/` does not exist; no custom `resources/views/errors/*` |

## 6. Money, number and date display rules as they are

| Rule | Current behaviour | One helper? |
|---|---|---|
| Decimals | default 2; company can choose 0-4 in Business Settings (`number_format.decimals`); quantities pass their own decimals | `utils/numberFormat.ts` `formatNumber(value, decimals)` |
| Thousand / decimal separator | company setting: comma / dot / space / none; decimal dot / comma | same helper |
| Digit grouping | company setting: western `1,234,567` or Indian `12,34,567` | same helper |
| Negative numbers | company setting: `-1,234.50` or `(1,234.50)` | same helper |
| Currency symbol | **not part of the helper**. 5+ voucher editors read `auth.user.currency_symbol` and append it to the column label, e.g. `Debit (PKR)`; tables show no symbol | no |
| Dr / Cr | two columns `Debit` / `Credit` in vouchers, Trial Balance, ledger rows; **balance is one signed number with no Dr/Cr suffix** (`report/ledger.vue` and the account ledger) | no |
| Dates | server decides: PHP formats `Y-m-d` in 33 places and `d M Y` in 14 (`app/`); Sales list shows `11 Oct 2026`, Expenses `2026-10-01`; the browser has no date formatter (0 uses of `Intl.DateTimeFormat`, 5 of `toLocaleDateString`, 47 files use `toISOString`); no company date-format setting found | no |
| Who follows the number helper | `theTable` cells with `format: 'number'`, `report/index.vue`, `report/ledger.vue`, customer / supplier view, chart of accounts, opening balance (**10 files**) | |
| Who bypasses it | **61 files** format with `toFixed` (46 uses) / `toLocaleString` (64) / `Intl.NumberFormat` (3): `sell/Fields`, `sell/LineItemsEditor`, `purchase/*`, `journalentry/JournalLinesEditor`, every voucher view, `Dashboard.vue`, POS (`sell/addpos`, `posdisplay`, `receipt`), bank reconciliation, budget, fixed asset, depreciation, portal, offline, header profit | they ignore the company's decimals, grouping and negative setting |

## 7. States each page type should have, and how many pages have them now

Keyword scan (approximate), pages with the signal / pages of the type:

| Type | Loading | Empty | Error | Confirm | Success toast | No-permission | Offline |
|---|---|---|---|---|---|---|---|
| List (165) | 106 | 139 | 120 | 37 | via `handleSuccess` | 18 | 0 |
| Form (159) | 98 | 36 | 108 | 2 | via `handleSuccess` | 1 | 0 |
| Voucher entry (59) | 29 | 29 | 43 | 1 | via `handleSuccess` | 0 | 0 |
| Detail / view (39) | 29 | 11 | 21 | 3 | | 15 | 0 |
| Settings (42) | 14 | 13 | 26 | 5 | | 4 | 0 |
| POS (6) | 2 | 3 | 5 | 4 | | 2 | 2 |

- **Loading**: `Loader` full-area on 181 pages, skeleton rows only inside `theTable`.
- **Empty**: written by `theTable` (`modern-empty-*`, with "Clear search" when a search is active); everywhere else free text.
- **Error**: `catch` blocks call `handleError`; no page-level error state.
- **No permission**: pages are guarded by `EnforcePageMenuPermission` (server); the 403 target page is missing; in-page "permission" mentions are keyword hits.
- **Offline / blocked**: `OfflineBlocked.vue` screen, the header Online / Offline chip, `/offline` entries page and the offline POS (`lib/offline/*`). Only POS and the offline pages are designed for it.
- **Validation errors**: Vueform inline errors (`InputError.vue` used by 15 files).
- **Confirm delete**: `ModalComponent` or the table's own bulk bar; `sweetalert2` is loaded in one place.

## 8. Keyboard, focus, scanner, print patterns to show in the design

- **Global keys** (`KeyboardShortcuts.vue`, `docs/design-reference.md`): `?` help, `/` or Ctrl+K search, `n` new, Ctrl+S save, Esc close, `g` then a letter to go: `d` dashboard, `s` sales, `o` POS, `p` purchases, `c` customers, `u` suppliers, `i` products, `j` journals, `e` expenses, `a` chart of accounts, `r` trial balance, `h` HR.
- **Tables**: Up / Down / Home / End move the row, Enter opens the first action, Space ticks the checkbox; focus ring teal `:focus-visible`.
- **POS keys**: F2 search / scan, F3 customer, F4 payment, F8 hold, F9 new sale, F10 cash sale, + / - quantity, Delete removes the line, Esc cancels.
- **Scanner / barcode**: scanner-ready line on POS ("Scanner ready: scan a barcode at any time", `Keys (F1)`, `Scanner settings`), `TrackingInput.vue` for serial / batch capture, `printlabel/*` and `barcode/settings` for labels, scan beep with its own setting separate from the notification sound toggle.
- **Voucher grids**: line editors handle `keydown` (Enter / Tab to the next cell, add line at the end) in all 10 editors, each differently.
- **Print**: `window.print()` in 20 pages (all voucher `view.vue`, `chequeprint`, portal tools, invoice, receipt), 5 effective `@media print` blocks (2 in SCSS: `admin-design-system`, `custom_app`; 3 in Vue: `chequeprint`, `sell/invoice`, `sell/receipt`; a 6th in `scss/custom/components/_print.scss` is not imported). No shared print header / footer. Party statement PDF is server generated (`LedgerStatementActions`: download, email with PDF, WhatsApp link).
