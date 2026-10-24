# ACCUNIVO design brief (for Google AI Studio)

Five chunks. Each chunk is **self-contained**: paste one per prompt, in order. Every chunk starts with the same 6-line context. Names in `Code Style` are component names: **build once, reuse by name** (never re-draw a component that is named in chunk 3). Choices follow the recommended option of every decision in `06-decisions-for-owner.md`.

---

# CHUNK 1 of 5: foundations and tokens

**Context.** ACCUNIVO is a multi-company accounting, inventory, POS and tax web application for Pakistani small and medium businesses (PKR, FBR tax, Urdu documents). Users are accountants, shop owners, cashiers and warehouse staff, many on cheap laptops and phones. The signed-in app is dense and data-heavy; the public website sells it. Design for long working days: calm, compact, numbers first. Light and dark themes share one token set. English now, Urdu / RTL-ready later.

**Task for this chunk.** Produce the design foundations as a token sheet plus a one-page style guide, and nothing else.

### Principles
1. Numbers are the hero: tabular figures, right aligned, never truncated.
2. One primary action per screen. Everything else is secondary.
3. Same thing, same look: one component per job (see chunk 3).
4. Keyboard first: every action reachable without a mouse, visible focus.
5. Quiet colour: colour carries meaning (status, sign, selection), not decoration.

### Colour (semantic tokens; hex for light / dark)
| Token | Light | Dark |
|---|---|---|
| `bg` page | `#F8FAFC` | `#0B1220` |
| `surface` card | `#FFFFFF` | `#111A2C` |
| `surface-2` header rows, hover | `#F1F5F9` | `#172238` |
| `border` | `#E2E8F0` | `#25324A` |
| `border-subtle` | `#F1F5F9` | `#1B2740` |
| `text` | `#0F172A` | `#E6EDF7` |
| `text-2` | `#475569` | `#A5B3C9` |
| `text-muted` | `#94A3B8` | `#6B7C96` |
| `primary` | `#0D9488` | `#14B8A6` |
| `primary-hover` / pressed | `#0F766E` | `#2DD4BF` |
| `primary-soft` background | `#CCFBF1` at 40% | `#0D9488` at 18% |
| `on-primary` | `#FFFFFF` | `#04201C` |
| `success` / soft / border | `#15803D` / `#F0FDF4` / `#BBF7D0` | `#4ADE80` / 14% / 30% |
| `warning` | `#B45309` / `#FFFBEB` / `#FDE68A` | `#FBBF24` / 14% / 30% |
| `danger` | `#B91C1C` / `#FEF2F2` / `#FECDD3` | `#F87171` / 14% / 30% |
| `info` | `#0369A1` / `#EFF6FF` / `#BFDBFE` | `#38BDF8` / 14% / 30% |
| `focus-ring` | 2 px `primary` + 2 px offset | same |
Top bar is **dark in both themes** (`#161922`) with white text. Amounts: positive `text`, negative `danger`, zero `text-muted`. Charts use `primary`, `#3B82F6`, `#A78BFA`, `#F59E0B`, `#EF4444` in that order; never red / green alone (add a sign or icon).

### Typography
Family **Inter** (system fallback), numbers in tabular-nums. Urdu slot reserved (Noto Nastaliq Urdu) but unused now.
| Style | Size / line | Weight | Use |
|---|---|---|---|
| `display` | 28 / 36 | 700 | dashboard totals |
| `h1` | 20 / 28 | 700 | page title |
| `h2` | 16 / 24 | 600 | card title |
| `h3` | 14 / 20 | 600 | section label |
| `body` | 14 / 20 | 400 | default |
| `table` | 13 / 20 | 400 | cells (comfortable 14) |
| `caption` | 12 / 16 | 500 | table header (uppercase, +0.04em), helper text |
| `mono` | 13 / 20 | 500 | voucher and invoice numbers, codes |

### Spacing, radius, elevation, motion
- Spacing scale (px): 4, 8, 12, 16, 20, 24, 32, 40, 56. Page padding 24 (phone 12). Card padding 20 (phone 14). Field gap 16.
- Radius: control 8, card 12, modal 16, pill 999, chip 6. Inputs and buttons both 8 (today they differ: 4 and 9).
- Shadow: `sm` (1px, 5%), `card` (1px + 12px blur, 7%), `popover` (16px blur, 14%). Dark theme: borders instead of shadows.
- Motion: 150 ms ease for hover and focus, 200 ms for drawers and modals; respect reduced motion.

### Density
Two settings: **compact** (default: table rows 32 px, control height 32, 13 px text) and **comfortable** (rows 40, control 40, 14 px). The user switches it per device.

### Icons
**lucide**, 18 px, stroke 1.75, `text-2` colour; 16 px inside buttons and table actions; 20 px in the sidebar. No filled icons except status dots.

### Breakpoints
`xs` under 480, `sm` 480-767 (phone), `md` 768-1023 (tablet), `lg` 1024-1279 (laptop), `xl` 1280-1535, `2xl` 1536 and up. Phone layouts exist for every screen.

### Numbers, money, dates (global rules)
- Amounts: 2 decimals, thousand separator comma (company can choose lakh / crore grouping, 0-4 decimals, brackets for negatives); right aligned; currency symbol in column headers or once per page, not in every cell; mixed currencies show a 3-letter code.
- Balances carry **Dr** or **Cr** (`12,500.00 Dr`). Quantities use the product unit and its own decimals.
- Dates `dd-MMM-yyyy` (`25-Oct-2026`), times 12-hour with AM / PM, ranges `01-Jul-2026 to 30-Jun-2027`; the fiscal year is named `FY 2026-27`.
- Language: UI text sentence case, no exclamation marks, verbs on buttons ("Save invoice").

### Themes
Light (default) and Dark from the token table above. No other themes, no per-header colours. Theme control lives in the user menu, remembered per user.

### Accessibility
WCAG AA contrast, 44 px touch targets on phones, visible focus, labels on all inputs, status never by colour alone.

**Deliver:** token table (light + dark), type specimen, spacing / radius / shadow chips, 12 sample amounts showing every money rule, light and dark side by side.

---

# CHUNK 2 of 5: application shell

**Context.** ACCUNIVO is a multi-company accounting, inventory, POS and tax web application for Pakistani SMEs (PKR, FBR). Dense, number-first, keyboard friendly, light and dark themes on one token set (`primary #0D9488`, Inter, radius 8 / 12, compact density, lucide icons, dates `dd-MMM-yyyy`). Use only tokens from chunk 1; do not invent colours. Build once, reuse by name.

**Task.** Design the shell that wraps every signed-in screen, desktop and phone.

### Desktop (>= 1024)
- **Sidebar**, 256 px, collapsible to 72 px (icons; hover expands). Top: company switcher (name, branch). Search field "Search menu" (new). Section labels (small caps) for groups; tree up to 3 levels, one branch open at a time, active page with a primary left bar and tint; chips for counts (approvals pending). Pinned favourites (up to 6) and Recent (last 5) above the tree. Bottom: user avatar, name, role.
- **Top bar**, 56 px, dark. Left: sidebar toggle, **global search** (`/` or Ctrl+K; results grouped Pages, Customers, Products, Invoices). Right, in order: sync / offline chip (`Online`, `Offline`, `3 waiting`), **Today Profit** button, **POS** button, **Calculator**, **Tools** menu (Calendar, To-do, Application tour, Shortcuts), notification bell (count badge, grouped by type, "Mark all read"), user menu (Profile, Theme, Density, Language, Logout).
- **Page header** (component `PageHeader`): title (h1), optional subtitle, breadcrumb on the right, primary action at the far right; a row of tabs below when the page has tabs.
- **Content**: max width 1600, centred on very wide screens (tables use full width).
- **Footer**: one quiet line (copyright, version) shown at the end of content, never fixed.
- **Floating**: back-to-top (after 600 px), keyboard help (`?`), toast stack top right.

### Tablet (768-1023)
Sidebar is an overlay drawer opened by the burger; top bar loses date and calculator (moved into Tools); content padding 20.

### Phone (< 768)
- Top bar 52 px: burger, page title (truncated), search icon, bell.
- Drawer sidebar (full height, 85% width) with search at the top.
- **Bottom action bar** only on forms and vouchers (Cancel, Save & new, Save), fixed, 56 px, with safe-area padding.
- Lists become cards (chunk 3 `DataTable` phone mode). Filters open in a bottom sheet.
- POS keeps its own tablet-first layout (chunk 4).

### Special shell states
- **Offline / blocked**: a full-width banner under the top bar ("You are offline. 3 entries wait to be sent") and a blocking screen when a page cannot work offline.
- **Period locked**: a thin warning strip on any voucher or report dated in a locked period.
- **No permission** (403), **not found** (404), **session expired** (419), **server error** (500): the same card in the shell with an icon, one sentence, and a "Go to dashboard" button.
- **Inactivity**: modal "Still there?" with 60 s countdown.
- **Loading the shell**: skeleton sidebar and header (no spinner).

### Keyboard
`/` or Ctrl+K search; `?` help; `n` new; Ctrl+S save; Esc close; `g` then `d` dashboard, `s` sales, `o` POS, `p` purchases, `c` customers, `u` suppliers, `i` products, `j` journals, `e` expenses, `a` chart of accounts, `r` trial balance, `h` HR.

**Deliver:** desktop shell (expanded and collapsed), tablet drawer, phone with drawer open, offline banner, locked-period strip, the four error pages; light and dark.

---

# CHUNK 3 of 5: component library

**Context.** ACCUNIVO is a multi-company accounting, inventory, POS and tax web application for Pakistani SMEs (PKR, FBR). Tokens: `primary #0D9488`, Inter 14, radius 8 (controls) / 12 (cards), compact density (32 px rows), lucide icons, `dd-MMM-yyyy`, amounts right aligned and tabular, balances with Dr / Cr, light and dark. **Every component below must show all its states: default, hover, focus, active / pressed, disabled, loading, error, empty.** Phone variants are listed where they differ.

**Task.** Design these 26 components as a library sheet. Names are final.

1. **Button**: primary, secondary (outline), ghost, danger; sizes 28 / 32 / 40; icon-left, icon-only; split button (main action + menu); loading spinner keeps width.
2. **Input / MoneyInput / DateInput / DateRange / Select / Combobox / Textarea / Checkbox / Radio / Switch**: label above, helper text, error text with icon, required asterisk, prefix and suffix slots. `MoneyInput` right aligned with thousand separators while typing and a currency prefix. `DateRange` presets: Today, This month, Last month, This quarter, Fiscal year, Custom; shows the locked-period boundary.
3. **PartyPicker** (customer or supplier): search by name, code, phone; each result shows balance (Dr / Cr) and credit limit; "+ New" row at the bottom. **ProductPicker**: search by name, SKU, barcode; shows stock and price; scanner-ready.
4. **BranchWarehouseSelect**: a pair of selects remembering the last choice.
5. **StatusBadge**: dot + label, tones neutral, info, success, warning, danger, brand. Vocabulary: Draft, Pending, Approved, Posted, Paid, Partial, Overdue, Rejected, Cancelled, Active, Inactive.
6. **AmountCell**: right aligned, tabular, negative in danger, optional Dr / Cr suffix, zero muted; variants plain, bold (totals), with currency code.
7. **DataTable**: toolbar (search, density, column chooser, export), sticky header, sortable columns with arrow, row selection with "select all across pages", row actions (primary action + overflow menu), footer totals row, pagination (25 / 50 / 100), column types (text, primary text with link, code, badge, amount, date, avatar + name). **Phone mode: each row becomes a card** (label / value pairs, actions in a strip at the top of the card, selection hidden).
8. **FilterBar**: search + date range + up to 3 pinned filters + "More filters" popover + saved filters + Clear; active filters appear as removable chips beneath.
9. **PageHeader** and **Tabs** (underline, count chip, scrolls on phones).
10. **FormChrome**: sticky bar with crumb, title, optional reference chip and status chip, Cancel / Save & Add New / Save & Close; phone: bottom bar.
11. **SectionCard**: header (title + description), body, optional footer; never nested.
12. **LineGrid**: editable grid for voucher and document lines. Columns defined by a list. Keyboard: Enter or Tab next cell, Shift+Tab previous, Ctrl+Enter save, Insert add line, Delete remove line, Up / Down change row, paste rows from a spreadsheet. Row states: valid, error (red border + message), needs serial / batch. Phone: each line a card with "Edit" opening a full-height sheet.
13. **TotalsPanel**: right aligned: sub-total, discount, tax, shipping, round-off, **Total** (display size), paid, **Due**. Journal variant: Debit total, Credit total, **Difference** (turns success at zero, danger otherwise).
14. **AttachmentBox**: drop zone, file list (name, size, who, when), image preview, size limit hint.
15. **ApprovalTimeline** and **ApprovalActions**: status chip, vertical timeline (created, submitted, approved by, rejected with reason), Approve / Reject with reason field.
16. **PrintHeader / PrintFooter**: company logo, name, address, NTN / STRN, document title, number, date, page x of y; A4 and 80 mm receipt variants.
17. **ConfirmDialog**: title, one-sentence consequence, destructive button right; "type DELETE" variant for irreversible actions.
18. **EmptyState**: icon, one line, one reason, primary action. **Skeleton**: table rows, cards, form fields. **ErrorState**: icon, message, Retry.
19. **KpiTile**: label, value (display), delta chip (up / down with colour and arrow), optional sparkline; accent variant.
20. **Toast** (success, error, info; Undo action; top right, bottom on phones) and **Alert** (inline page message).
21. **Modal** (sm 400, md 560, lg 800; phone: bottom sheet) and **Drawer** (right, 420).
22. **Stepper / Wizard** (numbered, with progress and Back / Next).
23. **StatementTable**: for ledgers, statements and aging (spec in chunk 4): opening and closing rows, indentation levels, subtotal rows, sticky totals, link cells.
24. **Chart** styles: line, bar, donut, sparkline with the palette from chunk 1, tooltip, legend, empty and loading.
25. **Avatar and Logo lockup** (company logo, fallback initials), **Chip / Tag**.
26. **Tooltip and Popover**, **Dropdown menu**, **Command palette** (global search results).

**Deliver:** one sheet per component group with all states in light, then a dark pass of the whole library, and the phone variants of DataTable, LineGrid, Modal and FormChrome.

---

# CHUNK 4 of 5: page-type templates and ledger specs

**Context.** ACCUNIVO is a multi-company accounting, inventory, POS and tax web application for Pakistani SMEs (PKR, FBR). Use the shell (chunk 2) and the named components (chunk 3) only; tokens from chunk 1. **Compose every template from named components; do not redraw them.** Phone layouts are required for each template.

**Task.** Design these 12 page templates. Each is a wireframe with real PKR sample data.

1. **List.** `PageHeader` (title, **Add** primary) / `FilterBar` / `DataTable` (default 25 rows) / bulk bar when rows are selected. Standard columns: Code or Number, Name (link), 2-4 data columns, `StatusBadge`, `AmountCell`, row actions. Master lists with 6 or fewer fields add and edit in a `Modal`; all others open the Form template. Trash and Import are menu items of the split Add button, not extra pages.
2. **Form (create / edit).** `FormChrome`, then `SectionCard`s in a single column up to 720 px (two columns of fields above 1024), Cancel / Save. Validation errors inline and a summary at the top. Unsaved-changes dialog on leave.
3. **Voucher / document entry** (journal, payment, receipt, expense, fund transfer, sales invoice, purchase bill, quotation, stock transfer). `FormChrome` with status chip and number; header card (date, number, party or account, branch, reference, currency); `LineGrid`; `TotalsPanel` to the right under the grid; `AttachmentBox`; notes; `ApprovalTimeline` in a right panel when approval is on; period-lock warning strip. Footer: Save draft, Save & Add New, Save & Post. Journals show the Debit / Credit **Difference** and block posting until it is zero.
4. **Detail / print document.** `PageHeader` with status chip and actions (Edit, Approve, Print, Email, WhatsApp, More); document preview centred (A4 look) with `PrintHeader`; side panel: `ApprovalTimeline`, attachments, linked documents. Receipt variant 80 mm.
5. **Ledger / statement.** See "Ledger standard" below.
6. **Report.** `PageHeader`, `FilterBar` (company, branch, date range always; extras per report), summary `KpiTile` row (3-6 tiles), `DataTable` or `StatementTable`, export split button (CSV, Excel, PDF, Print). 79 reports use this one template.
7. **Dashboard.** Greeting row with date and company; filter strip (branch, as-of, period); KPI row (Sales today, Sales this month, Purchases, Receivable, Payable, Cash); "Needs your attention" list (approvals, low stock, due cheques); Quick actions grid (New sale, POS, New purchase, New customer, Expense, Journal, Reports); charts (sales vs purchases by month, profit trend, top customers, top products); recent documents. Customisable per role.
8. **Settings.** Left tab list (sections) + right content as `SectionCard`s; sticky Save bar appears when something changed; each setting has a label, control, one-line help.
9. **Workspace** (HR, manufacturing, projects, rentals, service, document centre, setup wizard). Tabs along the top, each tab is a List or Form template; summary strip above; `Stepper` for setup flows. Board variant: columns with cards (pipeline, dispatch); calendar variant: month grid with event chips (attendance, leave, due dates).
10. **POS.** Full screen, no sidebar. Left: search / scan box ("Scan a barcode at any time") and product tiles by category; right: cart (lines, qty stepper, line discount), customer chip, totals, **Pay** (F4), Hold (F8), New (F9), Cash sale (F10); shift bar (cashier, till, offline chip). Keys shown as small labels on the buttons. Payment sheet: cash, card, wallet, split; change due in display size. Large touch targets (48 px). Customer display variant: items list and total only.
11. **Approval inbox.** List template with tabs (Pending, Approved, Rejected), row = document number, party, amount, requested by, age; row opens a side panel preview with Approve / Reject; bulk approve.
12. **Public / auth / error.** Auth card (brand panel + form), email verify, two-factor challenge; the four error pages; offline page.

### Ledger standard (templates 5 and 6)
**Ledger** (account ledger, party ledger and statement, day book, cash book, bank book, item ledger):
- Header: title, entity (account or party with code and balance chip `Dr` / `Cr`), `DateRange`, branch, currency chip, **period-lock chip** ("Locked up to 31-Jul-2026"), actions split button (Export, Print) and, for parties, **Email** and **WhatsApp**.
- Columns: Date, Document (link), Reference, Narration, Debit, Credit, Balance **Dr/Cr**. Optional: Branch, Status chip, Method / Cheque on a second line.
- Rows: **Opening balance** first (bold, muted fill), entries, **Period totals** (debit total, credit total), **Closing balance** last. Totals row sticky at the bottom of the viewport.
- Behaviour: every document, account and party is a link; running balance computed on the server; 50 rows per page; the opening row stays when the range is empty ("No entries from 01-Jul-2026 to 31-Jul-2026").
- Variants: **Day book** = all vouchers grouped by date, no running balance, daily totals; **Cash book / Bank book** = account ledger fixed to cash or bank, optional two-column receipts / payments print, bank book adds cheque and a "reconciled" tick; **Item ledger** = In, Out, Balance qty, Rate, Value with opening and closing quantity.
- Phone: card per entry (date + document on top, narration, Dr / Cr amount, balance); sticky closing balance bar.

**Statement** (trial balance, profit and loss, balance sheet, cash flow): sections with indented accounts (3 levels), subtotal rows (bold, top border), grand total (double rule), optional "Compare with" columns (Previous, Change, Change %) and "% of revenue" column, a **check banner** (Trial balance: "Balanced" or "Difference 1,250.00"; Balance sheet: "Assets equal Liabilities + Equity"; Cash flow: "Closing cash agrees with bank and cash accounts"), drill-down from every account to its ledger, formal print layout. Structure:
- Profit and loss: Revenue / Cost of sales (opening stock + purchases - closing stock) / **Gross profit** / Operating expenses / **Operating profit** / Other income and expense / **Net profit before tax** / Tax / **Net profit**.
- Balance sheet: Assets (current, non-current) / Liabilities (current, non-current) / Equity (capital, reserves, **current-year profit** line); closing stock as an asset.
- Cash flow: Operating / Investing / Financing, each with a subtotal, then Net change, Opening cash, Closing cash; Direct and Indirect toggle.
- Trial balance: grouped by class (Assets, Liabilities, Equity, Revenue, Expenses); Opening Dr / Cr, Period Dr / Cr, Closing Dr / Cr; "Hide zero accounts".

**Matrix** (receivable and payable aging, payment age, stock age): party rows by bucket (Not due, 1-30, 31-60, 61-90, 90+, Total, % overdue), bucket headers shaded green to red, sticky totals row, row expands to open documents, party links to the ledger, **Send reminder** action.

**Voucher rules:** Dr = Cr checked live; dates in a locked period are blocked with the reason; every voucher has Draft, Pending, Approved, Posted, Rejected states shown by `StatusBadge`; attachments on every voucher; print preview inside the view.

**Deliver:** each template on desktop and phone with sample data; the ledger, statement and matrix with 12-row examples; light first, dark for List, Voucher and Statement.

---

# CHUNK 5 of 5: module screens and the public website

**Context.** ACCUNIVO is a multi-company accounting, inventory, POS and tax web application for Pakistani SMEs (PKR, FBR). Use the shell (chunk 2), components by name (chunk 3) and the 12 templates (chunk 4). **A module screen is a template plus a column list and filters; do not design new layouts.** The full screen list with columns, fields and priorities is `screens.csv` (506 screens: 273 menu entries, 190 sub-screens, 43 routes without a menu row; **110 are "high" priority**).

**Task A: design the high-priority screens in this order, each as its template with the listed columns.**

| Module | Screens (template) and the data to show |
|---|---|
| Dashboard | Dashboard: sales today / month, purchases, receivable, payable, attention list, quick actions, charts |
| Sell | **Sales list** (List: Date, Invoice no, Customer, Status, Payment, Total, Due); **New sale / Edit sale** (Voucher entry with serial / batch lines); Sale return, Draft, Quotation, Shipment, Sales order, Sell payment (List + Voucher); **POS** (template 10); Invoice and receipt (Detail / print) |
| Purchase | Purchase list (Ref no, Supplier, Payment, Status, Total); New purchase (Voucher entry, approval timeline); Purchase return; Purchase payment; Receiving note |
| Contacts | **Customer list** (Code, Business name, City, Opening balance, Total due; tabs All / Active / Inactive); Customer detail (profile, **ledger tab = PartyLedger**, activity, documents, credit limit); Supplier list and detail |
| Products | **Product list** (Name, SKU, Category, Item type, Brand, Status, price); Product form (sections: basics, units, price, stock, variants, tracking); Low stock; Stock transfer; Stock adjustment (Voucher entry) |
| Accounts | Chart of accounts (tree table: code, name, class, balance; add child inline); **Journal entry**, **Payment**, **Receipt (deposit)**, **Expense**, **Fund transfer** (Voucher entry); Opening balance; **Bank reconciliation** (two panes: statement lines left, book entries right, match lines, Reconcile); Period lock; Year-end close |
| Reports | The 11 high reports as in chunk 4: **Account ledger, Customer / Supplier ledger, Trial balance, Profit and loss, Balance sheet, Cash flow, Stock report, Sell report, Purchase report, Customer outstanding, Supplier outstanding**; then the rest (68) as the Report template with the columns in `screens.csv` |
| Approval | Approval inbox for sales, purchases, journals, expenses, deposits, fund transfers, stock adjustments and transfers |
| HR, manufacturing, CRM, billing, settings, integrations, user management | Workspace and Settings templates; priorities medium and low, designed after the above |

**Task B: the public website (dark by default, same tokens, mint accent `#14B8A6`, Inter).**
Sitemap (new pages marked +):
- `/` Home: hero (promise, two buttons: Start free trial, Book a demo+), logo strip, screenshots gallery (dashboard, sales, products, reports), four pillars, "from sign-up to first invoice" steps, feature groups, interactive "a voucher must balance" demo, **pricing** (monthly / annual toggle, plans from the database), testimonials+, blog (latest 3), FAQ (6+), final band, footer.
- `/features` + with four sub-pages: Sales and POS+, Accounting+, Inventory+, Tax and compliance (FBR)+.
- `/industries` + : Retail+, Distribution+, Manufacturing+, Services+.
- `/pricing`+ (own page), `/compare`+ (vs spreadsheets and vs other accounting software), `/security`+, `/changelog`+, `/demo`+ (request form), `/contact`+ (form, map, phone, email).
- `/blog` and `/blog/{slug}` (categories+, related posts+, share+), `/register` and `/login` (**one auth look** with the app), `/terms`+, `/privacy`+, `/cookies`+ (with cookie banner+), 404+ and 500+ pages.
- SEO: unique title and description, `og:image` on every page, canonical, JSON-LD (Organization, FAQPage, Article), `sitemap.xml`.
- Tone: plain, accountant-friendly, Pakistan-aware (PKR, FBR e-invoicing, lakh / crore), no hype, concrete numbers ("a voucher only counts when it balances").
- Components shared with the app: Button, Input, Card, Badge, Logo lockup, Footer.

**Deliver:** the high-priority screens grouped by module on desktop (phone for Sales list, New sale, Customer list, Dashboard, POS), then the website pages on desktop and phone.
