<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\FinancialYear;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountCurrentBalance
{
    /**
     * Replace opening-only tree amounts with opening + posted activity for the active FY.
     *
     * @param  Collection<int, ChartOfAccount>  $roots
     */
    public function applyToTree(Collection $roots, int $companyId, int $branchId): void
    {
        ChartOfAccount::appendOpeningBalancesToTree($roots, $companyId, $branchId);

        $financialYear = FinancialYear::query()
            ->where('company_id', $companyId)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();

        if ($financialYear?->start_date === null || $financialYear->end_date === null) {
            return;
        }

        $codes = ChartOfAccount::transactionalCodes($roots);

        if ($codes === []) {
            return;
        }

        $from = $financialYear->start_date->toDateString();
        $to = $financialYear->end_date->toDateString();

        ChartOfAccount::applyActivityToTree(
            $roots,
            $this->activityNets($companyId, $branchId, $codes, $from, $to),
        );
    }

    /**
     * @param  list<string>  $accountCodes
     * @return array<string, float>
     */
    public function activityNets(
        int $companyId,
        int $branchId,
        array $accountCodes,
        string $from,
        string $to,
    ): array {
        $nets = array_fill_keys($accountCodes, 0.0);
        $journalCodes = $this->codesWithJournals($companyId, $branchId, $accountCodes);

        foreach ($this->journalNets($companyId, $branchId, $journalCodes, $from, $to) as $code => $net) {
            $nets[$code] = $net;
        }

        $transactionCodes = array_values(array_diff($accountCodes, $journalCodes));

        foreach ($this->contactTransactionNets($companyId, $branchId, $transactionCodes, $from, $to) as $code => $net) {
            $nets[$code] = $net;
        }

        return $nets;
    }

    /**
     * @param  list<string>  $accountCodes
     * @return list<string>
     */
    private function codesWithJournals(int $companyId, int $branchId, array $accountCodes): array
    {
        if ($accountCodes === [] || ! $this->hasJournalTables()) {
            return [];
        }

        return DB::table('t_account_details')
            ->join('t_accounts as taccount', 'taccount.id', '=', 't_account_details.t_account_id')
            ->where('taccount.company_id', $companyId)
            ->where('taccount.branch_id', $branchId)
            ->where('taccount.status', 'approved')
            ->whereIn('t_account_details.account_code', $accountCodes)
            ->distinct()
            ->pluck('t_account_details.account_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();
    }

    /**
     * @param  list<string>  $accountCodes
     * @return array<string, float>
     */
    private function journalNets(
        int $companyId,
        int $branchId,
        array $accountCodes,
        string $from,
        string $to,
    ): array {
        if ($accountCodes === [] || ! $this->hasJournalTables()) {
            return [];
        }

        $natures = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('code', $accountCodes)
            ->pluck('acc_nature', 'code');

        $totals = DB::table('t_account_details')
            ->join('t_accounts as taccount', 'taccount.id', '=', 't_account_details.t_account_id')
            ->where('taccount.company_id', $companyId)
            ->where('taccount.branch_id', $branchId)
            ->where('taccount.status', 'approved')
            ->whereIn('t_account_details.account_code', $accountCodes)
            ->whereBetween('taccount.voucher_date', [$from, $to])
            ->groupBy('t_account_details.account_code')
            ->selectRaw('t_account_details.account_code')
            ->selectRaw('COALESCE(SUM(t_account_details.debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(t_account_details.credit), 0) as credit_total')
            ->get();

        $nets = [];

        foreach ($totals as $row) {
            $code = (string) $row->account_code;
            $debit = (float) $row->debit_total;
            $credit = (float) $row->credit_total;
            $nature = (string) ($natures[$code] ?? 'dr');

            $nets[$code] = round($nature === 'cr' ? $credit - $debit : $debit - $credit, 2);
        }

        return $nets;
    }

    /**
     * @param  list<string>  $accountCodes
     * @return array<string, float>
     */
    private function contactTransactionNets(
        int $companyId,
        int $branchId,
        array $accountCodes,
        string $from,
        string $to,
    ): array {
        if ($accountCodes === [] || ! Schema::hasTable('transactions')) {
            return [];
        }

        $contacts = Contact::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where(function ($query) use ($accountCodes): void {
                $query->whereIn('supplier_gl_id', $accountCodes)
                    ->orWhereIn('customer_gl_id', $accountCodes);
            })
            ->get(['id', 'user_type', 'supplier_gl_id', 'customer_gl_id']);

        if ($contacts->isEmpty()) {
            return [];
        }

        $documentTotals = DB::table('transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('contact_id', $contacts->pluck('id'))
            ->whereIn('type', [
                Transaction::TYPE_PURCHASE,
                Transaction::TYPE_PURCHASE_RETURN,
                Transaction::TYPE_SELL,
            ])
            ->whereDate('transaction_date', '>=', $from)
            ->whereDate('transaction_date', '<=', $to)
            ->groupBy('contact_id', 'type')
            ->selectRaw('contact_id, type, COALESCE(SUM(final_amount), 0) as total')
            ->get()
            ->groupBy('contact_id');

        $paymentTotals = collect();

        if (Schema::hasTable('payments')) {
            $paymentTotals = DB::table('payments')
                ->join('transactions', 'transactions.id', '=', 'payments.transaction_id')
                ->where('transactions.company_id', $companyId)
                ->where('transactions.branch_id', $branchId)
                ->whereIn('transactions.contact_id', $contacts->pluck('id'))
                ->whereIn('transactions.type', [Transaction::TYPE_PURCHASE, Transaction::TYPE_SELL])
                ->whereDate('payments.paid_on', '>=', $from)
                ->whereDate('payments.paid_on', '<=', $to)
                ->groupBy('transactions.contact_id', 'transactions.type')
                ->selectRaw('transactions.contact_id, transactions.type, COALESCE(SUM(payments.amount), 0) as total')
                ->get()
                ->groupBy('contact_id');
        }

        $nets = [];

        foreach ($contacts as $contact) {
            $documents = $documentTotals->get($contact->id, collect())->keyBy('type');
            $payments = $paymentTotals->get($contact->id, collect())->keyBy('type');

            $purchases = (float) ($documents[Transaction::TYPE_PURCHASE]->total ?? 0);
            $purchaseReturns = (float) ($documents[Transaction::TYPE_PURCHASE_RETURN]->total ?? 0);
            $sells = (float) ($documents[Transaction::TYPE_SELL]->total ?? 0);
            $purchasePaid = (float) ($payments[Transaction::TYPE_PURCHASE]->total ?? 0);
            $sellPaid = (float) ($payments[Transaction::TYPE_SELL]->total ?? 0);

            $supplierNet = round($purchases - $purchaseReturns - $purchasePaid, 2);
            $customerNet = round($sells - $sellPaid, 2);

            if (in_array((string) $contact->supplier_gl_id, $accountCodes, true)) {
                $code = (string) $contact->supplier_gl_id;
                $nets[$code] = round(($nets[$code] ?? 0) + $supplierNet, 2);
            }

            if (in_array((string) $contact->customer_gl_id, $accountCodes, true)) {
                $code = (string) $contact->customer_gl_id;
                $nets[$code] = round(($nets[$code] ?? 0) + $customerNet, 2);
            }
        }

        return $nets;
    }

    private function hasJournalTables(): bool
    {
        return Schema::hasTable('t_account_details') && Schema::hasTable('t_accounts');
    }
}
