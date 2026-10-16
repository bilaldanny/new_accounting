# Design reference: the Menus screen

Every list page follows `resources/js/pages/menu/index.vue` (the Menus screen). Copy its structure, not its behaviour.

## Page shell

```
<div class="admin-list-page admin-list-page--simple">
  <div class="admin-list-card"><div class="admin-list-card__body admin-list-table">
    <CompanyPicker v-if="isSuperadmin" .../>   <!-- superadmin only: a named drop-down, never a bare company id box -->
    ... tabs (pill tabs: .nav .nav-tabs) ...
    ... one card per section, no nested cards ...
  </div></div>
</div>
```

* One card with padding from `admin-design-system.scss`; the `--simple` modifier flattens a direct child `.card`.
* Title / subtitle / breadcrumbs come from `defineOptions({ layout: { title, subtitle, breadcrumbs } })`, never an `<h1>` in the page.
* Buttons: teal primary (`btn-dark` is restyled by the shell), outline for secondary, `btn-sm` inside tables. Forms use `form-control-sm` / `form-select-sm` with a visible `<label>` or an `aria-label`.
* Empty states say what to do next; skeletons while loading (`SkeletonTableRows` inside the table, `dash-extras__skeleton` for cards).

## Tables

* Server lists: `theTable` with `columns`, `state`, `getData`, `changeOrder` (see the Menus page).
* Lists the page already holds in memory: `ClientTable` (`:columns`, `:rows`, `export-name`, optional `empty-text`), cells with their own markup through `#cell-<key>="{ row }"` slots, totals through a `#footer` slot.
* theTable gives: search, page size, column chooser, export, header sort, sticky header, footer slot, row keyboard keys (Up / Down / Home / End, Enter opens the first action, Space ticks the checkbox), focus ring and `aria-rowindex`.
* A raw `<table>` in a list page fails `tests/Feature/NoRawTableInListPagesTest.php` unless it is on the allow-list there with its reason.

## Keyboard and focus

* Every control is reachable with Tab and shows the teal focus ring (`:focus-visible`).
* Esc closes the open dialog / menu, Enter submits, `/` or Ctrl+K searches, `?` lists the keys, `g` then a letter opens a page, `n` opens the add form, Ctrl+S saves.
* The POS keys are F2 search / scan, F3 customer, F4 payment, F8 hold, F9 new sale, F10 cash sale, + / - quantity, Delete removes the line, Esc cancels.

## Themes

Light is the default; the template's dark theme is the `dark-theme` class on `<html>`. A card sets its own surface and text colour in both (see the `html.dark-theme` rules at the end of `admin-design-system.scss`).

## Not copied on purpose

Forms with line editors (`LineItemsEditor`, `Fields`), the document view / print pages and the public / auth pages keep their own markup.
