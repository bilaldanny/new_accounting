<?php

namespace App\Services\Approvals;

use App\Models\TAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The documents the Approval Center can put behind a workflow: where each lives, how to find the ones waiting, and the menu
 * permission its own approve button already needs (an approver must hold it: the workflow adds levels, never rights).
 */
final class ApprovalRegistry
{
    /**
     * @return array<string, array{label: string, prefix: string, permission: string, table: string, number: string, amount: string|null, branch: string|null, creator: string, pending: callable(Builder): Builder}>
     */
    public static function all(): array
    {
        $voucher = fn (array $types) => fn (Builder $q): Builder => $q->where('status', 'pending')->where(function (Builder $w) use ($types): void {
            foreach ($types as $type) {
                $w->orWhere('voucher_no', 'like', $type.'-%');
            }
        });
        $transaction = fn (string $type, string $status) => fn (Builder $q): Builder => $q->where('type', $type)->where('status', $status)->whereNull('deleted_at');
        $base = ['amount' => 'total_amount', 'branch' => 'branch_id', 'creator' => 'created_by', 'number' => 'voucher_no', 'table' => 't_accounts'];

        return [
            'journal' => $base + ['label' => 'Journal entry', 'prefix' => 'journal-entry-approvals', 'permission' => '/journalentry/:id/approve', 'pending' => $voucher(TAccount::VOUCHER_TYPES)],
            'payment' => $base + ['label' => 'Payment voucher', 'prefix' => 'payment-approvals', 'permission' => '/acpayment/:id/approve', 'pending' => $voucher(TAccount::PAYMENT_VOUCHER_TYPES)],
            'expense' => $base + ['label' => 'Expense', 'prefix' => 'expense-approvals', 'permission' => '/expense/:id/approve', 'pending' => $voucher(TAccount::EXPENSE_VOUCHER_TYPES)],
            'deposit' => $base + ['label' => 'Deposit', 'prefix' => 'deposit-approvals', 'permission' => '/deposit/:id/approve', 'pending' => $voucher(TAccount::DEPOSIT_VOUCHER_TYPES)],
            'fundtransfer' => $base + ['label' => 'Fund transfer', 'prefix' => 'fund-transfer-approvals', 'permission' => '/fundtransfer/:id/approve', 'pending' => $voucher(TAccount::FUND_TRANSFER_VOUCHER_TYPES)],
            'creditdebitnote' => $base + ['label' => 'Credit / debit note', 'prefix' => 'credit-debit-note-approvals', 'permission' => '/creditdebitnote/:id/approve', 'pending' => $voucher(TAccount::CREDIT_DEBIT_NOTE_VOUCHER_TYPES)],
            'purchase' => ['label' => 'Purchase', 'prefix' => 'purchase-approvals', 'permission' => '/purchase/approval', 'table' => 'transactions', 'number' => 'invoice_no', 'amount' => 'final_amount', 'branch' => 'branch_id', 'creator' => 'created_by', 'pending' => $transaction('purchaseorder', 'pending')],
            'sell' => ['label' => 'Sale', 'prefix' => 'sell-approvals', 'permission' => '/sell/approval', 'table' => 'transactions', 'number' => 'invoice_no', 'amount' => 'final_amount', 'branch' => 'branch_id', 'creator' => 'created_by', 'pending' => $transaction('sell', 'final')],
            'stockadjustment' => ['label' => 'Stock adjustment', 'prefix' => 'stock-adjustment-approvals', 'permission' => '/stockadjustment/approval', 'table' => 'transactions', 'number' => 'invoice_no', 'amount' => 'final_amount', 'branch' => 'branch_id', 'creator' => 'created_by', 'pending' => $transaction('adjustment', 'pending')],
            'stocktransfer' => ['label' => 'Stock transfer', 'prefix' => 'stock-transfer-approvals', 'permission' => '/stocktransfer/approval', 'table' => 'transactions', 'number' => 'invoice_no', 'amount' => 'final_amount', 'branch' => 'branch_id', 'creator' => 'created_by', 'pending' => $transaction('transfer', 'pending')],
            'cashcollection' => ['label' => 'Cash collection', 'prefix' => 'cash-collection-approvals', 'permission' => '/cashcollection/approval', 'table' => 'cash_collections', 'number' => 'reference', 'amount' => 'amount', 'branch' => 'branch_id', 'creator' => 'collected_by', 'pending' => fn (Builder $q): Builder => $q->where('status', 'pending')],
            'creditlimit' => ['label' => 'Credit limit request', 'prefix' => 'credit-limit-approvals', 'permission' => '/creditlimit/approval', 'table' => 'credit_limit_requests', 'number' => 'id', 'amount' => 'requested_limit', 'branch' => null, 'creator' => 'requested_by', 'pending' => fn (Builder $q): Builder => $q->where('status', 'pending')],
            'salesorder' => ['label' => 'Sales order', 'prefix' => 'sales-orders', 'permission' => '/salesorder/approve', 'table' => 'sales_orders', 'number' => 'order_no', 'amount' => 'total', 'branch' => 'branch_id', 'creator' => 'created_by', 'pending' => fn (Builder $q): Builder => $q->where('status', 'pending_approval')],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forPrefix(string $prefix): ?array
    {
        foreach (self::all() as $type => $definition) {
            if ($definition['prefix'] === $prefix) {
                return ['type' => $type] + $definition;
            }
        }

        return null;
    }

    /**
     * The row of a document, as the registry reads it: amount, branch, creator and number.
     *
     * @return array{amount: float, branch_id: int|null, creator_id: int|null, number: string, company_id: int|null}|null
     */
    public static function describe(string $type, int $id): ?array
    {
        $definition = self::all()[$type] ?? null;

        if ($definition === null) {
            return null;
        }

        $row = DB::table($definition['table'])->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return [
            'amount' => $definition['amount'] === null ? 0.0 : round((float) ($row->{$definition['amount']} ?? 0), 2),
            'branch_id' => $definition['branch'] === null ? null : (isset($row->{$definition['branch']}) ? (int) $row->{$definition['branch']} : null),
            'creator_id' => isset($row->{$definition['creator']}) ? (int) $row->{$definition['creator']} : null,
            'number' => (string) ($row->{$definition['number']} ?? $id),
            'company_id' => isset($row->company_id) ? (int) $row->company_id : null,
        ];
    }
}
