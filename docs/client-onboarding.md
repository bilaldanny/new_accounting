# Client onboarding checklist

Steps to set up a new client. Everything here exists in the codebase today; each step names the page or command.
Page paths are the sidebar paths; run the commands from the project root.

## 0. Two ways to start: self-signup or manual

**Self-signup (the client does it).** The public landing page at `/` has Register and Login buttons and shows the active subscription plans.
A visitor registers at `/register` with company name, their name, email, phone and password, and in one database transaction
(`App\Services\SelfSignup`) gets:

- the company, its first branch (`headoffice`), the admin user and the shared `companyadmin` role, exactly as an administrator would create;
- a SaaS tenant record on a trial for the chosen plan (status `trial`, `trial_ends_at` = the plan's trial days, the plan's limits copied
  onto the company). Only an active plan with trial days can be chosen; with no such plan the company starts as a trial on the default limits;
- the starter staff roles of step 2 (`RolePresets::sync`) and the withholding tax accounts of step 3 (created and mapped for the branch);
- an automatic login and a redirect to the dashboard. If anything fails, nothing is created.

Controls: `PUBLIC_REGISTRATION_ENABLED=false` closes it (the button becomes "Contact us" and `/register` answers 403); 5 attempts per minute per
IP; a hidden honeypot field; an already-registered email gets the same generic error as any other failure. The landing page's contact
details come from `LANDING_CONTACT_EMAIL`, `LANDING_CONTACT_PHONE`, `LANDING_CONTACT_ADDRESS`, the product name from `LANDING_BRAND` and a
currency label for plan prices from `LANDING_CURRENCY_LABEL` (see `config/landing.php`). Payment is manual (bank transfer): there is no
payment gateway, and an operator marks invoices paid in Billing.

After a self-signup, steps 4 to 8 below are still the client's to do (financial year, tax rates, FBR note, staff roles, smoke test); steps 1 to 3
are already done, so only check them.

**Manual onboarding (an operator does it)** is still possible and is what steps 1 to 3 describe.

## 1. Create the company and its first branch

- Page: `/company` (Add New). Needs a superadmin.
- Saving a company also creates, in one go: its settings row, its first branch (`headoffice`), its hidden company-admin role and the admin user
  (`CompanyController::store`).
- Caution: the admin made here is given a new company role named `companyadmin` that has **no menus ticked**, while every existing admin (and every
  self-signup) uses the one shared `companyadmin` role (the row with an empty company, which the menu migrations keep granting menus to). If a
  manually created admin sees an empty menu, point that user at the shared role. Self-signup does this for you.
- More branches: `/branch` (Add New). A new branch gets its chart-of-account roots and its account mapping automatically, including the
  **Withholding Tax Receivable** and **Withholding Tax Payable** accounts (created and mapped for you, see step 3).
- Note the company id and branch id for step 2:

```bash
php artisan tinker --execute 'echo App\Models\Branch::query()->get(["id", "company_id", "name"])->toJson();'
```

## 2. Sync the role presets

The app ships no ready-made staff roles. This command creates Accountant, Cashier, Sales Representative, Manager and Warehouse Keeper for
one branch and ticks their menus (`App\Support\RolePresets`). Run it once per branch.

```bash
php artisan roles:sync-presets {company_id} {branch_id} --dry-run   # preview, changes nothing
php artisan roles:sync-presets {company_id} {branch_id}             # create and grant
php artisan roles:sync-presets {company_id} {branch_id} --only=Cashier --only=Manager   # a subset
```

- Running it again is safe: an existing role is only topped up with the menus it is missing, nothing is switched off, so the client's own
  tweaks stay.
- Only the Manager preset gets the approval pages. Accountant, Cashier, Sales Representative and Warehouse Keeper do not, and no preset gets
  Billing (SaaS), Tenants, Integrations, Company Settings, Roles or Users.
- Fine-tune a role later on `/role` (Role Permission screen).

## 3. Check the Chart of Accounts and Link Accounts

- Page: `/chart-of-account`. A new branch starts with six root accounts (Equity, Assets, Liabilities, Expenses, Revenue, Cost of Goods Sold)
  plus the two withholding accounts. Build the rest of the client's chart here (or `/opening-balance` for opening balances).
- Page: `/company/setting` > **Link Accounts** (pick the branch). Check that each of these is mapped, because documents cannot post without them:
  - **Input Tax** and **Output Tax**
  - **Customer**, **Supplier**, **Bank**, **Cash**, **Customer Advance**
  - **Sales** / **Local Sales** / **Export Sales** and **Purchase** / **Local Purchase** / **Import Purchase**
  - **Withholding Tax Receivable** and **Withholding Tax Payable**: mapped automatically for a new branch. If a sale or purchase with a
    withholding tax is refused with "Withholding tax accounts are not set up for this branch", map them here.
- Branches created before the automatic setup were fixed by migration `2026_10_27_100200_map_withholding_tax_accounts`; a mapping the
  client filled in themselves is never overwritten.

## 4. Set the financial year

- Page: `/company/setting` > **Financial Year** tab, or `/financialyear` (Add Financial Year).

## 5. Tax rates and exemptions

- Page: `/company/setting` > **Tax** tab, or `/tax`. Add the sales tax rates ("Sales / purchase tax") and the withholding taxes ("Withholding tax") the client uses.
- Page: `/taxexemption` for customer tax exemptions.
- The Accountant preset can open `/tax`, `/financialyear` and `/taxexemption`.

## 6. FBR: read this before enabling anything

**The FBR e-invoicing integration is a stub. It is not live and not compliant.** Nothing is sent to FBR, and the `FBR-STUB-...` numbers it
shows are not FBR invoice numbers (`App\Services\Fbr\StubFbrGateway`).

- Page: `/company/setting` > **Tax** tab > "FBR e-invoicing". The same warning is shown there, in Roman Urdu:
  "Ye FBR integration structure hai, lekin LIVE/COMPLIANT nahi hai."
- Tell the client plainly: **they must verify their own FBR registration status, and whether they fall under the FBR POS Fiscalization /
  Tier-1 Retailer scheme, with their own tax consultant**, and confirm separately what a live integration needs. Do not enable FBR for a
  client as a compliance measure.

## 7. Give the staff their preset roles

- Page: `/user` (Add New). Create each staff member and choose the matching preset role (Accountant, Cashier, Sales Representative,
  Manager, Warehouse Keeper). Keep the admin user for the client's owner only.
- A Cashier ringing up POS sales needs the POS menu only; they are not given Add Sell. Cashiers cannot reverse or cancel a cash collection.

## 8. Day-one smoke test

Do it as the client's admin first, then repeat the sale as a Cashier and a report as an Accountant.

1. Log in at `/login`; the dashboard opens.
2. A sale: `/sell/add` (or POS at `/sell/pos/add` as a Cashier). Save it and open its invoice.
3. A purchase: `/purchase/add`. Save it.
4. A report: `/report/trial-balance` (it should balance) and `/report/sell`.
5. As each preset user, open one page they must not have (for example a Cashier on `/fixedasset`): it must answer 403.

## Server notes

- Fixed asset depreciation is scheduled for the 1st of each month (`assets:run-depreciation`); it needs the server's cron to call
  `php artisan schedule:run` every minute, and can always be run by hand.
- Database backup page: `/backup`.
