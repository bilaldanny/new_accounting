# Closeout wave 1b: the dashboard rows wave 1 left open, and the Partial rows that were code work

The tracker table of waves 1-6 (`tracker-sync-waves-1-6.md`) showed that wave 1 had not taken the six Dashboard rows of the audit
(it built six other dashboard figures), and that a few rows of waves 2-6 were Partial only because code had not been written. This
wave closes them. Evidence: `tests/Feature/WaveOneBDashboardTest.php` (8), `WaveOneBApprovalTest.php` (3), `WaveOneBImportTest.php` (2),
and the real-browser tests `DashboardToolsSmokeTest` (search bar, insights tools, approver pickers) and
`DocumentCenterSmokeTest` "a block of the designer is moved by dragging its handle". Screenshots in `tests/Browser/screenshots`
(`wave1b-*.png`).

Migrations to run live (none has been run), after the wave 8 pairs:

1. `2026_11_21_100000_create_dashboard_tools_tables` (daily_summary_settings, daily_summary_runs, dashboard_role_layouts, dashboard_kpis)
2. `2026_11_21_100100_add_dashboard_tools_menus` (`/dashboard/layouts`, `/dashboard/summary`, granted to companyadmin)

Scheduler: `dashboard:daily-summary` hourly (sends only companies that switched it on, once the send hour has come, each recipient and
channel once a day). The server's cron must call `schedule:run`; the command can be run by hand (`--force` ignores the switch).

## What changed for existing screens

* The top bar search box, which was drawn but not bound, now answers (below). The bell lists the stored unread notifications (with
  mark read, mark all read, and a link to My Notifications) beside the approval counts it always showed.
* The Dashboard page reads the user's layout (hide, and order inside each row), shows KPI widgets after the sales cards and has a date
  range and a warehouse for the sales and purchases cards. A company with no layout setting and a user with no personal layout see the
  cards they saw before unless the role name looks like an owner, accountant, sales manager, warehouse incharge or cashier (then the
  built-in layout of that kind applies; changing it per role is on the insights page).
* `/api/dashboard/sales` answers exactly as before without the new parameters (test).
* Approval workflows: the department rule is now evaluated; price lists are in the registry; the editor picks approvers.

## The rows

1. **Role-Based Dashboards: Built.** Layout resolution: the user's own, else the company's for their role, else the built-in one for
   the kind of role, else every card. A layout only chooses cards; every card still needs its menu permission.
2. **Date / Branch / Warehouse Filters: Built, with a stated limit.** Date range and warehouse on the sales and purchases cards (the
   branch and as-of date existed); a warehouse of another company is refused. The ledger-based cards (receivables, cash, profit) have
   no warehouse, so the warehouse filter does not apply to them.
3. **Custom Widget Builder & Dashboard Layout Customization: Built.** KPI widgets: a numeric figure of the summary of any report
   the user may open (the analytics, tax, payroll and production reports), for today / this week / this month / last 30 days; at most
   12 per user; computed under the report's own permission every time (losing the permission blanks the value).
4. **Notification Center: Built.** See above.
5. **Daily Business Summary: Partial.** Figures (net sales and purchases, invoices, money received and paid, expense vouchers, top
   three products), settings (recipients, channels, send hour), the hourly command, the once-a-day-per-recipient rule, the run log and
   "send now" are built and tested with a faked messaging provider. Delivery to a real mailbox / WhatsApp number is not proven
   (same as the other message deliveries).
6. **Universal Search Bar: Built.** Pages (by menu name, permitted ones only), customers, suppliers, products, sales, purchases and
   journal vouchers; each group only with its list permission; company and (for a branch user) branch scoped; typed `%` and `_` are
   literal; at least two characters.
7. **Trigger Rules (department): Built.** Evaluated against the department of the person who made the document.
8. **Discount & Price List: Built.** Price list approvals go through the workflow gate like the other documents.
9. **Workflow Builder: Built.** Approvers are picked from the users and roles of the company; departments from the company's list.
10. **Drag & Drop Invoice/Receipt Builder: Built.** Blocks are dragged by their handle (up / down buttons stay). The layout is an
    ordered block list; there is no free-position canvas.
11. **Import History with Undo (extended): still Partial.** A product import of a company user is now recorded and undone while its
    products are on no sale, purchase, adjustment, opening stock line or component list. Master-data importers and updates of
    existing records are not tracked.

## Known limitations

* Order on the Dashboard is applied inside each row of cards (CSS order), not across rows.
* The warehouse filter covers sales and purchases only (see row 2).
* The search is a substring match on names and numbers; it is not a ranked full-text search.
* Language coverage, the cash drawer in the POS (hardware), a form layout builder for custom fields and the other tracker rows marked
  Partial in `tracker-sync-final.md` are unchanged by this wave.
