# ACCUNIVO design system audit (read-only)

Written from the code as it stands on `main` (working tree of 2026-10-25). Nothing in the app, the database, migrations or config was changed; the only things written are the files in this folder.

## Files

| File | What it is |
|---|---|
| `01-shell-and-consistency.md` | Part 1: layout shell, layout files, ranked "same kind, different look" list, page-type catalog (counts, examples, non-standard pages) |
| `02-tokens-and-components.md` | Part 2: colours, type, spacing, radii, shadows, icons, dark theme, RTL, component inventory and duplicates, missing components, money/date rules, page states, keyboard / scanner / print |
| `03-ledger-and-financial-screens.md` | Part 3: ten ledger and financial screens, current vs recommended |
| `04-public-website.md` | Part 4: every public page, what is missing, how far it is from the app theme |
| `05-build-once.md` | Part 5, item 11: UI pieces repeated 10+ times and the single spec for each |
| `06-decisions-for-owner.md` | Part 7: decisions needed, 2-3 options each, with a recommendation |
| `screens.csv` | Part 5, item 10: 506 screens (273 visible menu entries, 190 hidden sub-screens of a menu entry, 43 routes with no menu row) |
| `DESIGN-BRIEF.md` | Part 6: the compact brief for Google AI Studio, in 5 self-contained chunks |
| `shots/` | Screenshots made earlier in this project with Dusk on the sqlite demo company (see `shots/README.md`) |

## How the numbers were made (so you can judge them)

- **Page files** (570): every `.vue` under `resources/js/pages`, scanned with a script for: components used, root wrapper class, raw `<table>`, own `<h1>`, hex colours, `<style>` size, inline styles, Tailwind vs Bootstrap class use, hand-written number/date formatting, and keyword flags for loading / empty / error / confirm / toast / permission / offline. Keyword flags are **approximate** (a word in a comment counts); counts of files, components, tokens and lines are exact.
- **Page type** is assigned by directory and file name plus those signals. Partials (`Fields.vue`, `FieldHint.vue`, pickers, editors) are counted in the catalog but are not screens.
- **Menus**: `tests/Fixtures/live-menus.json` (872 rows: 292 not hidden = 14 group headers + 278 visible links; 580 hidden rows = sub-screens and permission-only actions). The "371 menu items" figure could **not be reproduced from code**: the menu table lives in the database and its content is built by migrations; the fixture is the nearest thing in the repo. If your live list has 371 visible entries, the difference is in `screens.csv` rows marked `hidden_in_menu = yes` plus the 43 routes with no menu row.
- **Routes to pages**: parsed from `routes/web.php` (`Route::get(...)` followed by `Inertia::render(...)`), the report loops (`/report/<key>` renders `report/index.vue`), and a few controller routes added by hand (`/dashboard`, `/sell/pos`, `/<x>/approval`).
- **Runtime facts** (computed fonts, button colours, radii) were read in a real browser on the sqlite demo company (DEMO-HISTORY), signed in as a company admin, on `127.0.0.1:8099`. No MySQL, no live company 1 or 2.
- Anything the code does not show is written as **"not found"**; no guesses.

## One-paragraph verdict

The app has a real shell and one good list standard (`theTable` + `topButtons` + `theFilter` inside `admin-list-page`, 165 list files, almost all conforming), but everything around that standard has grown in parallel: two styling systems (Bootstrap SCSS and Tailwind utilities), three-plus font stacks, 27 copy-pasted form chromes, 10 separate line-item grids, three party ledgers, no shared date or money cell, a half-finished dark theme, no error pages, and financial statements drawn as ordinary flat tables. The design brief in this folder fixes those by naming each piece once.
