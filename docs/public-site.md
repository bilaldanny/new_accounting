# Public site: logo, pricing plans and blog

The landing page (`/`), `/register` and the blog (`/blog`) are public. They use their own small front-end bundle (`resources/js/landing.ts`)
and a dark navy / teal theme in `resources/css/landing.css`.

## Where the logo and the name come from

- Page: `/software/setting` (Software Settings, superadmin). The **System logo** and the **name** there are what the landing page, the register
  page and the blog show. The settings row is read on every visit, so a change shows at once.
- Two more logos there are for the public website: **Website Header Logo** (nav bar) and **Website Footer Logo** (footer). Each falls back when
  empty: footer to header, header to the software logo. A logo uploaded for the website is drawn as it is (make it for the dark navy
  background, for example a white wordmark); the software logo, made for light screens, is drawn on a light chip.
- The logo is picked with the file manager like the other logos there; it is stored as a path under `/storage/...` and served from the public
  disk (`public/storage` must be linked: `php artisan storage:link`).
- If no logo is set, the file is missing, or the field still holds the placeholder of a fresh install (`assets/images/logo-placeholder.svg`, a
  text-only "Your logo" file), the pages show the name only, never a broken image. A website header or footer logo is shown on its own (the name
  is not repeated beside it); the software logo is shown with the name.
- A light chip sits behind the software logo so a dark logo stays readable on the navy background. The uploaded file is never changed.
- A signed-out visitor receives only the name and the logo URLs from the settings (and the login logo on the sign-in screens), nothing else.
- Company logos (Company page) are not used on public pages. There is no favicon setting; the favicon is a set of static files in `public/`
  (`favicon.ico`, `favicon.svg`, `apple-touch-icon.png`, `assets/images/favicon-32x32.png`).

## Pricing plans

- Page: `/subscriptionplans` (Billing > Subscription Plans, superadmin). Create a plan with its price, billing cycle (monthly, quarterly,
  annual), trial days and the six limits (users, branches, warehouses, products, invoices per month, storage).
- Every **active** plan appears under Pricing on `/`, grouped by billing cycle with a toggle. A plan with trial days above 0 shows
  "Start free trial" (when sign-up is open); any other shows "Contact us". Inactive and deleted plans never appear.
- A plan has no currency of its own. Set `LANDING_CURRENCY_LABEL` (for example `PKR`) in `.env` to print it next to the prices.
- Payment is manual (bank transfer); the page says so. Contact details come from `LANDING_CONTACT_EMAIL`, `LANDING_CONTACT_PHONE` and
  `LANDING_CONTACT_ADDRESS`.

## Blog

- Page: `/blogposts` (Website > Blog Posts). **Superadmin only**: the menu is granted to no other role and the pages and API also check the
  role, so a company admin gets 403 even if the menu is ticked.
- Add a post: title, optional slug (made from the title, unique, when empty), excerpt, body in **markdown**, status, publish time, optional cover
  image (JPG, PNG or WebP, up to 2 MB) and optional meta title / description.
- To publish: set **Status** to Published (the publish time defaults to now). A later publish time keeps the post hidden until then. A draft, a
  scheduled post and a deleted post answer 404 on `/blog/{slug}`.
- Deleted posts go to the trash and can be restored; deleting from the trash removes the cover file too.
- Markdown is rendered with raw HTML stripped and unsafe links removed, so a script or an inline handler pasted into a post never runs.
- `/blog` lists published posts nine to a page. The landing page shows the latest three, and hides the section completely when nothing is
  published. There are no seeded or sample posts.
- Each post carries its own title, description and Open Graph tags (and its cover as `og:image`).
- After deploying, run `php artisan migrate` once: it creates `blog_posts` and the Website > Blog Posts menu.

## Screenshots on the landing page

- The pictures in the screenshot gallery and the hero are in `public/assets/landing/` (`dashboard.jpg`, `sales.jpg`, `products.jpg`,
  `sellreport.jpg`). They were taken on a sample company with fictional customers and products, and the page says so.
- To retake them after the interface changes, run `tests/Browser/LandingScreenshotsTest.php` on purpose with `CAPTURE_LANDING_SCREENSHOTS=1`
  (inline sqlite Dusk environment, like every Dusk run), then convert the `tests/Browser/screenshots/landing-*.png` files to 1280 px wide JPEGs
  in `public/assets/landing/`. It never runs in the normal suite.

## Default subscription packages

- `php artisan plans:seed-defaults` creates Starter, Growth and Business, each monthly and annual (the annual price is ten months), with a 14-day
  trial and growing limits. Growth is flagged as recommended. A plan whose code already exists is left exactly as it is, so editing a price and
  running the command again is safe; `--dry-run` only lists what it would create.
- The prices are starting points, not a decision: change them, the limits or the trial under `/subscriptionplans`. **Recommended on website**
  there is what puts the badge on a plan; with no plan flagged nothing is highlighted.
- Set `LANDING_CURRENCY_LABEL` (for example `PKR`) in `.env` so the prices carry a currency.
