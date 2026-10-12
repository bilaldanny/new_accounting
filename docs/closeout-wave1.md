# Closeout wave 1: CRM, Customer Subscription, User Management, Dashboard (17 items)

Everything is additive and inert until a company configures it; the guarantee test of each item says so. Evidence:
`tests/Feature/WaveOneTest.php` (11 tests) and the page-load Dusk tests of the five new pages.

Migrations to run live, in order (none has been run):

1. `2026_11_12_100000_create_wave1_core_tables` (notifications, allocation rules, reminders, IP rules, login events, tracked sessions,
   security settings, password history, privacy requests, dashboard preferences; `activities.reminded_at`, `users.password_changed_at`)
2. `2026_11_12_100100_add_wave1_menus` (Security Center + Manage + Personal Data, Lead Allocation, Subscription Billing)

## CRM (2)

1. **Lead allocation rules: Built.** Round robin over a user pool, by lead source, by branch (the branch of the user entering the
   lead); active rules in priority order; hooked on the Lead `creating` event so the form, the public capture form and imports are
   all covered. A lead with an owner, a company with no rule and an empty pool are untouched (tests). "Allocate unassigned now".
2. **Follow-up reminders: Built.** An open call / meeting / task / follow-up due within the next day or overdue notifies its assignee
   in the app (`app_notifications`, page My Notifications) and by email, once (`reminded_at`); `crm:send-reminders` hourly.
   Email is `Mail::raw` through the app's mail settings (not proven against a live SMTP server).

## Customer Subscription (3)

3. **Auto-invoice and reminders (manual-payment equivalent of auto-charge): Built.** `customersubscriptions:run` daily:
   renews periods that ended (the existing renew call), flags overdue, a reminder three days before the due date.
4. **Dunning: Built.** An overdue invoice gets the company's invoice-dunning level it has reached (same three levels and templates as
   `Dunning`), once per level (`subscription_reminders`, unique per invoice and kind).
5. **Pro-rata refund: Built.** Quote (paid invoice covering today, unused days / period days) and cancel with or without the refund,
   recorded on the invoice; no money moves (manual model, as the module already was).

## User Management (6)

6. **IP allow / deny: Built.** Company list of addresses and CIDR blocks; any deny match refuses, allow rules (when present) are
   exclusive; a rule that would lock its own author out is refused; blocked requests are logged. No rules = no change.
7. **Login history and device list: Built.** `login`, `failed`, `logout`, `lockout`, `ip_blocked` events with IP and device; devices
   per user.
8. **Session management: Built.** Each browser session is tracked by a hash of its id (any session driver); an administrator ends
   one or all of a user's sessions and the next request is signed out (`session_revoked`).
9. **Password policy: Built.** Per company, off by default: minimum length, number, mixed case, symbol, expiry in days, last N
   passwords refused; enforced on user create / update, profile change and reset; an expired password closes everything except
   the password page and logout.
10. **Security logs with export: Built.** Filterable list and CSV.
11. **Maker-checker: Built.** Company switch; nobody approves a document they created, across the voucher families, purchase /
    sell / return / stock approvals, cash collection, asset, price list, credit limit, requisition and sales order approvals
    (`EnforceSecurity::MAKER_COLUMNS`). Off by default (test: the creator still may).
12. **Personal data export and deletion: Built.** Export of a contact or a user as one JSON; deletion is anonymisation (name,
    contact details, address replaced, the record id and the books stay), confirmed, audited in `privacy_requests`.

(That is 7 items for the 6 asked: login history and device list were listed together.)

## Dashboard (6, defined from the cards the dashboard already had)

The audit that named the six was not in the repository, so the gaps were taken from what the cards lacked:

13. **Top customers: Built.** 14. **Top products: Built.** 15. **Expenses of the period by account: Built.**
16. **Cash and bank in / out: Built.** 17. **Personal layout (cards shown, order): Built.** 18. **CSV export of the insights: Built.**
All on `/dashboard/insights`, each figure behind the permission of the report it summarises (a user without the sales menu gets
no sales figures, tested).

## Known limitations

* Notification delivery is in the app and plain email; the top-bar bell still shows its own counts (My Notifications is a page).
* The layout preference is saved and shown on the insights page; the main Dashboard page does not yet read it.
* Session tracking applies to browser sessions (cookie auth), not to API tokens.
* Reminders and dunning email are not proven against a live SMTP server.
