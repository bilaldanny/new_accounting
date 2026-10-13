# Page payload sizes

What a page sends to the browser: the Inertia response (the page's props as JSON; the first full page load embeds the same JSON in the
HTML). Measure it any time:

```bash
php artisan pages:payload-sizes --user=<id> --top=15
```

The Pest test `tests/Feature/PagePayloadBudgetTest.php` keeps it from growing back.

## What was found

On a 308 menu install, as a user holding every menu, **every page** was about 150 KB, and 150 KB of that was one shared prop:
`auth.user.permissions`, the sidebar tree. It carried every column of every menu row (timestamps, colours, audit ids) and also the
hidden add / edit / delete / approve permission rows, 291 of the 308, which the sidebar never draws. On the browser test database
(about 900 menu paths granted) it was 250 to 375 KB per page, which is where the cut off JSON of the Dusk server came from.

The pages themselves are small: list pages load their rows from an endpoint after they open, so the page's own props were under 1 KB on
all 338 measured pages, with the pilot demo company's data in place. There was no page with its own bulk prop to page or defer.

## Fix

`Menu::slimSidebarTree()` sends only what the sidebar reads (`id, parent_id, name, icon, route_name, my_route, status, type`) and drops
hidden rows and everything below them, exactly the rows `AppSidebar.vue` never renders. The cached full tree (used by the permission
checks and many tests) is unchanged.

| Shared prop | Before | After |
|---|---|---|
| `auth` (user + sidebar tree + permission paths) | 146.7 KB | 33.2 KB |
| everything shared with every page | 146.7 KB + about 0.3 KB | 33.2 KB + about 0.3 KB |

## Top 15 pages, before and after (308 menus, all of them granted)

| Page | Before | After |
|---|---|---|
| `/blog` | 147.9 KB | 34.5 KB |
| `/settings/profile` | 147.4 KB | 33.9 KB |
| `/report/supplier-price-comparison` | 147.4 KB | 33.9 KB |
| `/report/balance-sheet-comparative` | 147.4 KB | 33.9 KB |
| `/report/profit-loss-comparative` | 147.4 KB | 33.9 KB |
| `/report/sales-by-payment-method` | 147.4 KB | 33.9 KB |
| `/report/stock-movement-history` | 147.4 KB | 33.9 KB |
| `/report/profit-by-salesperson` | 147.4 KB | 33.9 KB |
| `/report/customer-outstanding` | 147.4 KB | 33.9 KB |
| `/report/supplier-outstanding` | 147.4 KB | 33.9 KB |
| `/report/product-sell-summary` | 147.4 KB | 33.9 KB |
| `/report/purchase-price-trend` | 147.4 KB | 33.9 KB |
| `/report/supplier-performance` | 147.4 KB | 33.9 KB |
| `/report/cost-center-analysis` | 147.4 KB | 33.9 KB |
| `/report/sales-by-salesperson` | 147.4 KB | 33.9 KB |

All other pages are within 0.2 KB of these. Largest page after the fix: 35.3 KB, against the 150 KB goal.

## Budget (enforced by the test, measured with 900 extra hidden action rows)

- everything shared with every page: under 60 KB
- any page's whole response: under 80 KB
- any page's own props: under 40 KB (load longer data from an endpoint with a limit)

## What is not covered

- 47 parameterless pages were skipped by the measurement because they redirect or refuse this user (public or other roles).
- Pages with a path parameter (`/x/{id}/edit`) are not measured; their own props are one record.
- The API responses a page loads after it opens are a separate question (they are paged or limited by their controllers).
