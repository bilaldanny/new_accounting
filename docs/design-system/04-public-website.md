# Part 4: the public website

Everything under "public" in the code: `resources/js/pages/landing/*` (4 pages), `public/*` (2 pages), `auth/*` (6 pages), plus the portal. Sources: `routes/web.php`, `resources/js/pages/landing/Index.vue` (604 lines), `resources/css/landing.css` (1,362 lines), `docs/public-site.md`.

## Page list

| Route | File | What it is | Content sections today | Missing |
|---|---|---|---|---|
| `/` | `landing/Index.vue` | landing page (when sign-up is enabled the nav shows Register) | **12 blocks**: hero with dashboard picture and two buttons; "areas covered" strip; **screenshots gallery** (4 tabs: dashboard, sales, products, reports, JPEGs in `public/assets/landing`); "four areas of financial control" (pillars); "from sign-up to first invoice" (steps); "everything else in the box" (feature groups); **interactive demo** ("a voucher only counts when it balances", plus a "Coming soon" roadmap box); **pricing** (live from the Subscription Plans table, monthly / annual toggle, "Start free trial" or "Contact us"); latest 3 blog posts (hidden when none); **FAQ** (6 questions); final call-to-action band; footer (links, email, phone, address from `.env`) | industry pages, feature pages, comparison, customer stories / testimonials, demo-request or contact form, security page, changelog, cookie banner, language switch |
| `/register` | `landing/Register.vue` (210) | sign-up (throttled), company + admin details | one form with a company fieldset | Terms / Privacy consent text, email verification explanation, plan chooser |
| `/blog` | `landing/BlogIndex.vue` (65) | published posts, 9 per page | cards | categories, tags, search, RSS |
| `/blog/{slug}` | `landing/BlogShow.vue` (65) | one post, markdown rendered with raw HTML stripped | title, cover, body, Open Graph tags | related posts, author, share links, table of contents |
| `/login`, `/forgot-password`, `/reset-password`, `/two-factor-challenge`, `/email/verify`, `/confirm-password` | `auth/*.vue` (6) via `AuthSimpleLayout` | sign-in family, in the **app** bundle (not the landing bundle) | brand block, form, "Create an account" link | nothing missing in function |
| `/shop/{code}` | `public/storefront.vue` (96) | public shop of one company | product grid, order form | cart page, order confirmation page |
| `/capture/{code}` | `public/leadcapture.vue` (228) | public lead capture form of one company | form | thank-you page |
| `/portal` | `portal/index.vue` | signed-in customer / supplier portal (own layout `container`) | statements and requests | n/a |
| Legal (terms, privacy, cookies, refund, acceptable use) | **not found** | | | all of them |
| About, contact page, pricing page on its own, features page, security page, FAQ page | **not found** (they are sections or anchors of `/`: `#features`, `#screens`, `#pricing`, `#faq`, `#contact`) | | | separate pages for SEO |
| 404 / 403 / 419 / 500 | **not found**: `/403` renders `errors/403` which does not exist; no `resources/views/errors/*` | | | all error pages |
| `robots.txt` | `public/robots.txt`: `User-agent: *`, `Disallow:` (allow all), **no `Sitemap:` line** | | | sitemap |
| Sitemap | **not found** (no route, command or config mentions it) | | | `sitemap.xml` |

## SEO and metadata

| Item | State |
|---|---|
| `<title>` | per page (landing: "brand \| Sales, inventory, accounting and tax in one place") |
| meta description | landing, blog index, blog post (own meta title / description fields) |
| Open Graph | `og:title`, `og:description`, `og:type` on landing and blog; **`og:image` only on blog posts with a cover**, not on the landing page or the blog index |
| Twitter card, canonical link, JSON-LD (Organization, Product, FAQPage, Article), hreflang | **not found** |
| Favicons / manifest | `favicon.ico`, `favicon.svg`, `apple-touch-icon.png`, `manifest.webmanifest` present; icons for the offline field app (`icons-field-*.png`) |
| Cookie banner / consent | **not found** (only the offline and POS service workers and the session cookie) |

## How different is it from the app theme

| | App (signed in) | Public site (`landing.css`) |
|---|---|---|
| Mode | light content on white sidebar, dark top bar | **dark only** (`--lp-bg #050b1a`, card `#0d1a31`) |
| Brand colour | teal `#0d9488` / `#199683` / `#0f766e` (three values) | mint `#2dd4bf` (`--lp-brand`), strong `#5eead4`, on-brand text `#04201c` |
| Font | Roboto body, Inter for the design-system parts | no remote fonts (the blade file says the public pages load none) |
| Radius / shadow | 7 / 9 / 14 / 18 px tokens | `--lp-radius: 14px`, own shadow |
| Components | Bootstrap + Tailwind + shadcn | own classes (`lp-*`), no shared component with the app |
| Logo | software logo from `/software/setting` | same setting, with header / footer logo fallbacks and a light chip behind a dark logo (`docs/public-site.md`) |
| Login / register | login family in the app bundle (light page, centred card with a brand block, `AuthSimpleLayout`); register in the landing bundle (dark) | **two different looks one click apart** |

What the landing screenshots show: the real app in the **light** theme on a dark page, which is honest but visually a hard contrast.

## What to change for one brand

1. **Pick one brand teal** (recommend `#0d9488` as the single primary, `#14b8a6` as the accent on dark) and one neutral ramp (slate). Retire `#199683`, `#0f766e` as separate brand values (keep them as hover / pressed shades derived from the primary).
2. **One font family** (Inter) for app and site, with a tabular-figures setting for numbers.
3. **Support both themes with the same tokens** (`--bg`, `--surface`, `--text`, `--primary`...): the site uses the dark values by default, the app light by default, and a visitor / user preference switches either.
4. Move login, forgot password and register to **one auth look** (brand panel plus form) in one bundle.
5. Share small components: Button, Input, Card, Badge, Logo lockup, Footer.
6. Add the missing pages in priority order: legal (terms, privacy), error pages, contact / demo request, features (4 pages: Sales and POS, Accounting, Inventory, Tax and compliance), industry pages (retail, distribution, manufacturing, services), comparison, security, changelog; then SEO (canonical, JSON-LD, og:image, sitemap, `Sitemap:` line in robots).
7. Tone: see decisions file (recommended: plain, accountant-friendly, Pakistan-aware, no hype; the existing copy already reads this way: "A voucher only counts when it balances").
