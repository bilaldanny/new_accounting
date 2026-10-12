# Closeout wave 7: HR and payroll (15 items of module 9)

Evidence: `tests/Feature/WaveSevenHrmTest.php` (22 tests), the page-load Dusk tests of `/hrm` and the four payroll reports and
`tests/Browser/HrmSmokeTest.php` (an employee is added in a real browser; the rates tab shows the "verify the rates" reminder;
screenshots `wave7-hr-employees.png`, `wave7-hr-rates.png`).

Migrations to run live, in order (none has been run):

1. `2026_11_18_100000_create_hrm_payroll_tables` (employees, employee_documents, hr_shifts, hr_rosters, hr_attendance,
   hr_device_tokens, hr_device_punches, leave_types, leave_requests, salary_components, employee_salaries, statutory_rates,
   tax_slabs, employee_bonuses, employee_loans, employee_loan_repayments, payroll_runs, payroll_lines, hr_settings)
2. `2026_11_18_100100_add_hrm_payroll_menus` (HR and Payroll + manage / attendance / leave approve / payroll / payroll approve,
   and the Payroll Register, Statutory Liability, Attendance Summary and Employee Loan Balances reports; granted to companyadmin)

Scheduler: `hrm:document-alerts` daily at 08:30 (the server's cron must call `schedule:run`; it can be run by hand).

## Rules that were kept

* **No statutory rate and no tax slab is hard-coded.** The application ships none. EOBI, provident fund, social security and
  income tax are rows the company's accountant enters with an effective date (`statutory_rates`, `tax_slabs`). The payroll uses the
  row in force on the last day of the month. With no rows nothing of the kind is deducted (test), and the UI says
  "rates accountant se verify karein" on the rates tab and in the statutory report.
* **Payroll posting to the ledger is off by default** (`hr_settings.posting_enabled = false`). Preparing, approving, payslips, the
  bank file and every report change nothing in the books (guarantee test compares vouchers, voucher lines, trial balance and
  transactions before and after).
* When switched on and the seven accounts are mapped, an approved run posts **one** balanced journal voucher through
  `AccountsVoucher` (the same path as a hand-entered journal): the journal approval setting, the period lock and voucher
  numbering apply. A locked month refuses the posting, the run stays `approved` and nothing is left behind (test). A run posts once.
* Approval is its own permission (`/hrm/payroll/approve`, `/hrm/leave/approve`): the Accountant preset prepares, the Manager
  preset approves. Maker-checker (wave 1) covers `payroll-runs` and `leave-requests`; the Approval Center workflows cover them too
  (registry entries `leave` and `payroll`); an employee's own login cannot decide their own leave.
* Company comes from the signed-in user (superadmin names one), never from the body; a branch user sees only their branch's
  employees; every id taken from the request is looked up inside the company (tenant tests for each family).

## Items

1. **Employees / Employee Directory & Status: Built.** Employee master separate from users (optional link), statuses, employment
   type, bank details, CNIC, manager, department, shift.
2. **Departments, Designations & Org Structure: Built, with a stated limit.** Existing departments, designation as a text field
   (no designation master table), manager hierarchy shown as an organisation tree per department.
3. **Employee Document Vault & Expiry Tracking: Built.** PDF / image / Word / Excel up to 5 MB, real type checked, storage limit
   enforced, private disk, found only through the employee; expiring list and a daily alert to the company admins, once per document.
4. **Attendance, Shifts & Roster Scheduling: Built.** Shifts (start, end, grace, weekly off, overnight), daily roster overrides,
   manual / CSV / device attendance in one table; a manual row is never overwritten by a file or a device.
5. **Biometric / Attendance Device Sync: Partial.** Built: CSV import and a token-authenticated push endpoint
   (`POST /api/hrm/device/punch`, `X-Device-Token`, rate limited, replay-safe, company from the token). No vendor SDK, pull from a
   device, or real device was available, so a physical device (ZKTeco and similar) has not been tested.
6. **Overtime, Late / Early Exit Rules: Built.** Grace minutes, minimum overtime, overtime multiplier, optional late and early
   deductions (factor), absent and unpaid-leave days.
7. **Leave Types, Requests, Approval & Balance Tracking: Built.** Types with a yearly entitlement, paid / unpaid, weekly offs not
   counted, balance with pending shown, overlap refused, approval writes `leave` / `unpaid_leave` attendance, rejection and
   cancellation remove it.
8. **Salary Components Builder: Built.** Earnings and deductions, fixed or percent of basic, taxable flag, effective-dated per
   employee structure; pro-rata for joiners and leavers.
9. **Bonuses & Sales Commission Auto-Sync: Built.** Bonuses per month (taxable or not, taken once by the approved run); an employee
   linked to a commission agent receives the month's commission from the same statement the KPI page and commission report use.
10. **Payslip Generation & Email Delivery: Partial.** The payslip is one standalone HTML page (A4 `@page`; the browser saves it as
    PDF, escaped, English / Urdu labels) and is emailed through the app's mailer. No server-side PDF library, and email is not
    proven against a live SMTP server.
11. **Statutory Deductions & Payroll Tax Rules: Built.** Admin-entered, effective-dated, employee and employer side, on basic or
    gross, optional cap and fixed amount; annual slabs with fixed amount and rate above the lower bound. Correctness of the
    rates themselves is the accountant's responsibility (stated on screen).
12. **Bank Salary File / WPS Export: Partial.** Two CSV layouts (generic, and a beneficiary / account / bank / amount / reference
    layout), only for an approved or posted run, formulas neutralised, employees without an account or with nothing to pay counted in
    `X-Skipped`. No bank's own upload format and no WPS / SIF file was written or verified: confirm the layout with your bank.
13. **Payroll Reports: Built.** Payroll Register, Statutory Liability, Attendance Summary, Employee Loan Balances (menu rows,
    permissions, saved filters and scheduled reports work for them like the other insight reports).
14. **Employee Loans & Salary Advances (New): Built.** Instalment recovered at approval (never more than the pay left), balance and
    status kept, given back when the run is cancelled; ledger entry for the recovery rides the payroll voucher (item 15 account
    `loan_receivable`). The loan payout itself is not a voucher here: record it as a normal payment.
15. **Gratuity / EOBI / Provident Fund (New): Partial.** EOBI and provident fund are statutory rate rows (employee and employer
    side, item 11). Gratuity is a monthly accrual of `days per year x basic / 30 / 12` into the mapped accrual accounts. Eligibility
    years, the legal formula and end-of-service settlement are not computed.

## Tracker sync (module 9)

| Module | Item name (tracker) | Status | Why (Partial / Not built) | Test |
|---|---|---|---|---|
| 9. HRM & Payroll | Employees / Employee Directory & Status | Built | | "employees are the company own, separate from users ..." |
| 9. HRM & Payroll | Departments, Designations & Org Structure | Built | Designation is free text (no master table) | "the organisation tree groups employees by department under their managers" |
| 9. HRM & Payroll | Employee Document Vault & Expiry Tracking | Built | | "documents are stored per employee with an expiry ...", "the expiry command alerts the company admins once ..." |
| 9. HRM & Payroll | Attendance, Shifts & Roster Scheduling | Built | | "a manual entry is never overwritten by a file or a device ..." |
| 9. HRM & Payroll | Biometric / Attendance Device Sync | Partial | Push endpoint and CSV proven; no physical device or vendor SDK available | "a device punches with its own token ..." |
| 9. HRM & Payroll | Overtime, Late / Early Exit Rules | Built | | "late, early and overtime minutes come from the shift ..." |
| 9. HRM & Payroll | Leave Types, Requests, Approval & Balance Tracking | Built | | "leave skips the weekly off ...", "a rejected leave writes nothing and the leave approval can be put behind an approval workflow" |
| 9. HRM & Payroll | Salary Components Builder | Built | | "statutory rates and tax slabs are the admin rows in force ..." (structure) |
| 9. HRM & Payroll | Bonuses & Sales Commission Auto-Sync | Built | | "sales commission of the linked agent is added to the month ...", "a payroll run is one per month ..." |
| 9. HRM & Payroll | Payslip Generation & Email Delivery | Partial | No server PDF; email not proven on live SMTP | "the payslip escapes what it prints ..." |
| 9. HRM & Payroll | Statutory Deductions & Payroll Tax Rules | Built | Rates entered and verified by the accountant | "with no statutory rate and no tax slab nothing of the kind is deducted ...", "statutory rates and tax slabs are the admin rows in force ..." |
| 9. HRM & Payroll | Bank Salary File / WPS Export | Partial | Generic and IBFT-style CSV only; no bank / WPS format verified | "the payslip escapes ... and the bank file needs an approved run ..." |
| 9. HRM & Payroll | Payroll Reports | Built | | "the payroll reports read approved payrolls, loans and attendance of the signed-in company only" |
| 9. HRM & Payroll | Employee Loans & Salary Advances (New) | Built | | "a payroll run is one per month, approving applies loans and bonuses once and cancelling gives them back" |
| 9. HRM & Payroll | Gratuity / EOBI / Provident Fund (New) | Partial | Accrual by a configured number of days; legal eligibility and settlement not computed | "sales commission ... and the gratuity accrual follows the setting" |

Guarantee and control tests: "with posting off, preparing, approving and everything else leaves the books exactly as they were ...",
"posting needs the switch and the mapped accounts, makes one balanced voucher once, and a locked month refuses it", "every hrm
endpoint needs its own menu permission", "a branch user sees and writes only the employees of their branch", "payroll runs, lines
and payslips of another company are not found ...", "the person who prepared a payroll cannot approve it while maker-checker is on".

## Live checks

* Enter your real statutory rates and tax slabs on the Rates tab, prepare one month, and compare two payslips with a manual
  calculation before approving.
* Map the seven accounts, switch posting on for a test month, post, and check the voucher in the ledger and the trial balance.
* Point one attendance device (or a script) at `/api/hrm/device/punch` with its token and check the day's attendance.
* Send one payslip to a real mailbox; upload one bank file to the bank's test channel (or ask the bank for the layout).
* Run `php artisan hrm:document-alerts` with an expiring document.

## Known limitations

* Payroll is monthly only (no weekly / fortnightly runs, no arrears or back-pay runs, no final-settlement run).
* Attendance rules are one set per company (no per-shift overtime rules); public holidays are entered as `holiday` attendance rows.
* Department-level and designation master data are minimal (see item 2); the HR page edits salary as basic only (components are
  attached through the API).
* The seven posting accounts are entered as chart-of-account ids on the HR settings card (no account picker).
