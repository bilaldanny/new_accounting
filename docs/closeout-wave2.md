# Closeout wave 2: Approval Center (7 items)

Evidence: `tests/Feature/WaveTwoApprovalCenterTest.php` (8 tests), the page-load Dusk test of `/approvalcenter`.

Migrations to run live, in order (none has been run):

1. `2026_11_13_100000_create_approval_center_tables` (workflows, levels, requests, actions, delegations, settings; `discounts.approval_status`)
2. `2026_11_13_100100_add_approval_center_menus` (Approval Center + Manage permission)

## How it fits the approvals that already exist

A workflow does not approve anything itself. `ApprovalWorkflowGate` (api middleware) sits in front of the existing approve / reject
buttons of the covered documents (journal, payment, expense, deposit, fund transfer, credit / debit note, purchase, sale, stock
adjustment, stock transfer, cash collection, credit limit request, sales order). When a document matches an active workflow, the
click records the user's action at the current level; only the **last** level lets the call through to the real approve, which
still checks its own permission. A document that matches no workflow, a company with no workflow and a database without the
tables behave exactly as before (test: "with no workflow the single approval step is exactly what it was").

## The 7 items

1. **Workflow builder (levels, sequential and parallel approvers): Built.** Ordered levels; a level is satisfied by any one
   approver (`any`) or by every approver listed (`all`, the parallel case; a role entry is met by any user holding the role).
   Approvers are users or roles of the company (a foreign user is refused).
2. **Trigger rules: Built.** Document type, amount range, branch, creator role, priority (lowest first); a small document keeps
   the single step (test). Department is stored on the rule but not yet evaluated (users carry a department; documents do not).
3. **Delegation (out of office): Built.** A user covers themselves for a date range; the delegate may act for them at their level,
   sees the document in their inbox, and the history records "for whom". Ending a delegation is immediate.
4. **Inbox / outbox: Built.** Inbox = waiting documents whose current level the user may act on; outbox = the user's own documents
   with their progress. Both company scoped.
5. **Approval history and notifications: Built.** Every action (level, who, on behalf of, comment); in-app and email notices to
   the approvers of a level (hourly `approvals:notify-pending`, and when the previous level completes) and to the creator on the
   decision (`AppNotifier`). Email is not proven against a live SMTP server.
6. **Discount code approval: Built.** A company switch; new discount codes are then `pending` and cannot be used on a sale
   (the existing rejection list gains "waiting for approval"); approved or rejected on the Discounts tab. Off by default.
7. **Existing single-step approvals unchanged by default: Built** (the guarantee test above, plus the existing approval test files
   in the full regression).

## Known limitations

* Not covered by workflows yet: price list, asset, purchase return and purchase requisition approvals (they have their own
  approval controllers and multi-level rules; the registry in `ApprovalRegistry` is where they are added).
* A workflow that matches is evaluated on the document as it is when an approver acts; editing the amount afterwards can change
  which workflow matches (the open request keeps the one it started with).
* The workflow editor takes approvers as `user:12 role:3` text, not a picker.
