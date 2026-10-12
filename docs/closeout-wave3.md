# Closeout wave 3: SaaS billing and integrations (16 items)

Evidence: `tests/Feature/WaveThreeSaasTest.php` (17 tests), recorded fixtures in `tests/Fixtures/integrations/`, the page-load
Dusk tests of `/tenant-billing`, `/integrations`, `/support` and `/saasadmin`.

Migrations to run live, in order (none has been run):

1. `2026_11_14_100000_create_saas_wave3_tables` (plan `features` / `settings`, usage snapshots, billing notices, data exports,
   deletion requests, onboarding, support tickets + messages, custom domains, partners + `companies.partner_id`, add-ons +
   company add-ons, `subscription_invoices.addons_amount`, integration accounts, courier shipments, gateway payments)
2. `2026_11_14_100100_add_saas_wave3_menus` (Subscription & Billing, Integrations, Support Tickets, SaaS Admin + Manage rows)

Scheduler: `tenants:run-billing` daily at 05:00 (usage snapshot, cancel at period end, reminders, suspension, due purges).
Grace days: `config('saas.grace_days')`, default 7. Deletion grace: 14 days.

## What changes for existing tenants

`EnforceTenantPlan` (api middleware) is the only behavioural change: a company whose `tenant_status` is `suspended` or `cancelled`
now gets 402 everywhere except sign-out, password, its billing page, support and branding. A plan with no feature list includes
everything, a company with no plan passes untouched. Tests: the existing subscription, plan, directory, limits and scoping
suites are green.

## The items

1. **Per-feature plan switches: Built.** Catalog of 14 features (`PlanFeatures::CATALOG`); a feature not in the plan's list answers
   403 `plan_feature_off`. Superadmin sets the list; a tenant admin cannot.
2. **Generic package settings: Built.** Free-form JSON per plan read with `PlanFeatures::setting($company, 'dot.key', $default)`.
3. **Usage history and trend: Built.** One snapshot a day per company (users, branches, invoices, products, storage), trend with
   change over a window, on the tenant page and for the superadmin.
4. **Tenant data export: Built.** ZIP of CSVs of the company's own rows only; downloadable by that company only.
5. **Delete on cancellation: Built.** Only for a cancelled company: request, confirm by typing the company code, 14-day grace,
   cancel allowed inside it, then purge of that company's rows (other companies untouched; the company row is deactivated).
6. **Onboarding checklist: Built.** Ten steps derived from what exists (accounts, sale, products, users ...).
7. **Support tickets: Built.** Tenant sees its own, staff see and answer all, reply notifies the opener (in-app), close on reply.
8. **White-label branding: Built.** Name, colour, logo, hide "powered by"; resolved by verified host.
9. **Custom domain mapping: Partial.** Mapping, DNS TXT verification (`_erp-verify.<domain>` holds the token, DNS reader
   injectable, tested) and host-to-branding lookup are built. Serving the application on the customer's domain needs DNS, a
   reverse proxy / vhost and an SSL certificate, none of which can be proven here.
10. **Reseller / partner: Built.** Partner record with commission percent, companies assigned to a partner.
11. **Commission statement: Built.** Paid invoices of the partner's companies in a period, commission = base x percent.
12. **Add-ons: Built.** Add-on catalogue, per-company quantity and dates; billed on the plan invoice (`addons_amount`), ended ones
    are not; no add-ons means the plan price exactly as before.
13. **Renewal and cancellation completion: Built.** Cancel at period end, renew before it ends, cancelled when it ends.
14. **Failed-payment reminders and auto-suspension: Built.** Reminders on day 1, day 5 and a final warning once each (idempotent),
    suspended after the grace days, reactivated automatically when the invoice is paid.
15. **Swagger / OpenAPI and Postman: Built.** OpenAPI 3.0.3 and Postman v2.1 generated from the routes (paths, methods, path
    parameters, controller action; request bodies are free-form), behind the integrations permission.
16. **Courier APIs (PostEx, Leopards): Partial.** Adapters written to the vendors' published request shapes and tested against
    recorded fixtures only. **Payment gateway (Stripe Checkout)** and **bank statement feed (HTTPS JSON adapter)**: same status,
    Partial. Credentials are stored encrypted and never returned.

## Live test checklist (before any of the Partial items is called done)

* PostEx / Leopards: real merchant credentials; book one test parcel, confirm tracking number, track it; compare the city names
  the vendor accepts (the adapters send the city text as entered).
* Stripe: test-mode keys, create a checkout for a subscription invoice, pay with a test card, confirm the webhook
  (`/api/gateway/stripe/webhook/{company}`) marks the invoice paid once and a replayed event does nothing; set the endpoint's signing
  secret in the integration account.
* Bank feed: point at the bank's real endpoint, confirm field names match (`id`, `date`, `amount` or `debit` / `credit`) and that
  lines import into bank reconciliation.
* Custom domain: add the TXT record, verify, then configure the vhost and certificate for the domain.
* Email / in-app reminders against a live SMTP server.

## Known limitations

* Payment gateway beyond Stripe, other couriers and other bank feed formats are not written.
* The OpenAPI document does not infer request schemas.
* Tenant export covers the tables the exporter lists; attachments (files) are not in the ZIP.
