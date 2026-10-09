# Public site: logo, pricing plans and blog

The landing page (`/`), `/register` and the blog (`/blog`) are public. They use their own small front-end bundle (`resources/js/landing.ts`)
and a dark navy / teal theme in `resources/css/landing.css`.

## Where the logo and the name come from

- Page: `/software/setting` (Software Settings, superadmin). The **System logo** and the **name** there are what the landing page, the register
  page and the blog show. The settings row is read on every visit, so a change shows at once.
- The logo is picked with the file manager like the other logos there; it is stored as a path under `/storage/...` and served from the public
  disk (`public/storage` must be linked: `php artisan storage:link`).
- If no logo is set, the file is missing, or the field still holds the placeholder of a fresh install (`assets/images/logo-img.png`), the pages
  show the name only, never a broken image.
- A light chip sits behind the logo so a dark logo stays readable on the navy background. The uploaded file is never changed.
- A signed-out visitor receives only the name and the logo URL from the settings, nothing else.
- Company logos (Company page) are not used on public pages. There is no favicon setting; the favicon is a static file.

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
