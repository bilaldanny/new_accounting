<?php

namespace App\Services;

use App\Models\AccountBalance;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\FinancialYear;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContactLedger
{
    /**
     * @return array{
     *     taccount: list<array<string, mixed>>,
     *     openingbalance: float,
     *     closingbalance: float,
     *     total_purchase: float,
     *     total_paid_purchase: float,
     *     total_sell: float,
     *     total_paid_sell: float,
     * }
     */
    public function forContact(
        Contact $contact,
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        ?string $branchId = null,
    ): array {
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();
        $accountCodes = $this->accountCodes($contact);
        $resolvedBranchId = $this->resolvedBranchId($branchId);
        $hasJournals = $this->hasJournalLines($contact, $accountCodes, $resolvedBranchId);

        $scope = [
            'company_id' => $contact->company_id,
            'branch_id' => $resolvedBranchId ?? $contact->branch_id,
            'contact_id' => $contact->id,
            'from' => $from,
            'to' => $to,
        ];

        $rows = $hasJournals
            ? $this->journalRows($contact, $accountCodes, $from, $to, $resolvedBranchId)
            : $this->transactionRows($contact, $from, $to, $resolvedBranchId);
        $opening = $this->openingBalance($contact, $accountCodes, $from, $resolvedBranchId, $hasJournals);

        return [
            'taccount' => $rows,
            'openingbalance' => $opening,
            'closingbalance' => $this->closingFromRows($contact, $opening, $rows),
            'total_purchase' => $this->sumTransactions($scope, Transaction::TYPE_PURCHASE),
            'total_paid_purchase' => $this->sumPaidTransactions($scope, Transaction::TYPE_PURCHASE),
            'total_sell' => $this->sumTransactions($scope, Transaction::TYPE_SELL),
            'total_paid_sell' => $this->sumPaidTransactions($scope, Transaction::TYPE_SELL),
        ];
    }

    /**
     * @return list<string>
     */
    private function accountCodes(Contact $contact): array
    {
        $codes = match ($contact->user_type) {
            'customer' => [$contact->customer_gl_id],
            'supplier' => [$contact->supplier_gl_id],
            default => [$contact->supplier_gl_id, $contact->customer_gl_id],
        };

        return array_values(array_filter($codes, fn (mixed $code): bool => filled($code)));
    }

    /**
     * @return list<string>
     */
    private function statementTypes(Contact $contact): array
    {
        return match ($contact->user_type) {
            'customer' => [Transaction::TYPE_SELL],
            'supplier' => [Transaction::TYPE_PURCHASE, Transaction::TYPE_PURCHASE_RETURN],
            default => [Transaction::TYPE_PURCHASE, Transaction::TYPE_PURCHASE_RETURN, Transaction::TYPE_SELL],
        };
    }

    private function accountNature(Contact $contact): string
    {
        return $contact->user_type === 'customer' ? 'dr' : 'cr';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function closingFromRows(Contact $contact, float $opening, array $rows): float
    {
        $balance = $opening;
        $nature = $this->accountNature($contact);

        foreach ($rows as $row) {
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            $rowNature = (string) ($row['acc_nature'] ?? $nature);

            $balance = $rowNature === 'cr'
                ? $balance + $credit - $debit
                : $balance + $debit - $credit;
        }

        return round($balance, 2);
    }

    private function resolvedBranchId(?string $branchId): int|string|null
    {
        if ($branchId === null || $branchId === '' || $branchId === 'all') {
            return null;
        }

        return $branchId;
    }

    /**
     * @param  list<string>  $accountCodes
     */
    private function openingBalance(
        Contact $contact,
        array $accountCodes,
        string $from,
        int|string|null $branchId,
        bool $hasJournals,
    ): float {
        $stored = $this->storedOpeningBalance($contact, $accountCodes, $branchId);
        $prior = $hasJournals
            ? $this->priorJournalNet($contact, $accountCodes, $from, $branchId)
            : $this->priorTransactionNet($contact, $from, $branchId);

        return round($stored + $prior, 2);
    }

    /**
     * @param  list<string>  $accountCodes
     */
    private function storedOpeningBalance(Contact $contact, array $accountCodes, int|string|null $branchId): float
    {
        if ($accountCodes === [] || ! Schema::hasTable('account_balances')) {
            return 0;
        }

        $financialYear = FinancialYear::query()
            ->where('company_id', $contact->company_id)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();

        if ($financialYear === null) {
            return 0;
        }

        $accounts = ChartOfAccount::query()
            ->where('company_id', $contact->company_id)
            ->whereIn('code', $accountCodes)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->when($branchId === null, fn ($query) => $query->where('branch_id', $contact->branch_id))
            ->get(['id']);

        if ($accounts->isEmpty()) {
            return 0;
        }

        return (float) AccountBalance::query()
            ->where('company_id', $contact->company_id)
            ->where('financial_id', $financialYear->id)
            ->whereIn('coa_id', $accounts->pluck('id'))
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->when($branchId === null, fn ($query) => $query->where('branch_id', $contact->branch_id))
            ->sum('opening_balance');
    }

    /**
     * @param  list<string>  $accountCodes
     */
    private function hasJournalLines(Contact $contact, array $accountCodes, int|string|null $branchId): bool
    {
        if ($accountCodes === [] || ! $this->hasJournalTables()) {
            return false;
        }

        return $this->journalQuery($contact, $accountCodes, $branchId)->exists();
    }

    /**
     * @param  list<string>  $accountCodes
     * @return list<array<string, mixed>>
     */
    private function journalRows(
        Contact $contact,
        array $accountCodes,
        string $from,
        string $to,
        int|string|null $branchId,
    ): array {
        if ($accountCodes === [] || ! $this->hasJournalTables()) {
            return [];
        }

        return $this->journalQuery($contact, $accountCodes, $branchId)
            ->whereBetween('taccount.voucher_date', [$from, $to])
            ->orderBy('taccount.voucher_date')
            ->orderBy('t_account_details.id')
            ->select([
                't_account_details.id',
                't_account_details.debit',
                't_account_details.credit',
                't_account_details.description',
                't_account_details.acc_nature',
                't_account_details.highlight',
                'taccount.voucher_date',
                'taccount.voucher_no',
                'taccount.ref_no',
                'taccount.type',
                'taccount.cheque_no',
                'branches.name as branch_name',
            ])
            ->get()
            ->map(fn ($row): array => $this->presentRow(
                id: $row->id,
                date: $row->voucher_date,
                voucherNo: $row->voucher_no,
                refNo: $row->ref_no,
                description: $row->description,
                debit: $row->debit,
                credit: $row->credit,
                accNature: $row->acc_nature ?: $this->accountNature($contact),
                highlight: (int) $row->highlight,
                branchName: $row->branch_name,
                paymentStatus: '-',
                type: $row->type,
                chequeNo: $row->cheque_no,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $accountCodes
     */
    private function priorJournalNet(
        Contact $contact,
        array $accountCodes,
        string $from,
        int|string|null $branchId,
    ): float {
        $financialYear = FinancialYear::query()
            ->where('company_id', $contact->company_id)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();

        $periodStart = $financialYear?->start_date?->toDateString() ?? $from;

        if ($from <= $periodStart) {
            return 0;
        }

        $totals = $this->journalQuery($contact, $accountCodes, $branchId)
            ->where('taccount.voucher_date', '>=', $periodStart)
            ->where('taccount.voucher_date', '<', $from)
            ->selectRaw('COALESCE(SUM(t_account_details.debit), 0) as debit_total')
            ->selectRaw('COALESCE(SUM(t_account_details.credit), 0) as credit_total')
            ->first();

        $debit = (float) ($totals->debit_total ?? 0);
        $credit = (float) ($totals->credit_total ?? 0);

        return $this->accountNature($contact) === 'cr'
            ? round($credit - $debit, 2)
            : round($debit - $credit, 2);
    }

    /**
     * @param  list<string>  $accountCodes
     */
    private function journalQuery(Contact $contact, array $accountCodes, int|string|null $branchId): Builder
    {
        return DB::table('t_account_details')
            ->join('t_accounts as taccount', 'taccount.id', '=', 't_account_details.t_account_id')
            ->leftJoin('branches', 'branches.id', '=', 't_account_details.branch_id')
            ->where('taccount.company_id', $contact->company_id)
            ->where('taccount.status', 'approved')
            ->whereIn('t_account_details.account_code', $accountCodes)
            ->when($branchId !== null, fn ($query) => $query->where('taccount.branch_id', $branchId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transactionRows(
        Contact $contact,
        string $from,
        string $to,
        int|string|null $branchId,
    ): array {
        $rows = $this->documentRows($contact, $from, $to, $branchId)
            ->concat($this->paymentRows($contact, $from, $to, $branchId))
            ->sortBy([
                ['voucher_date', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        return $rows->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documentRows(
        Contact $contact,
        string $from,
        string $to,
        int|string|null $branchId,
    ): Collection {
        if (! Schema::hasTable('transactions')) {
            return collect();
        }

        $accountNature = $this->accountNature($contact);

        return $this->transactionQuery($contact, $branchId)
            ->whereIn('transactions.type', $this->statementTypes($contact))
            ->whereDate('transactions.transaction_date', '>=', $from)
            ->whereDate('transactions.transaction_date', '<=', $to)
            ->leftJoin('branches', 'branches.id', '=', 'transactions.branch_id')
            ->orderBy('transactions.transaction_date')
            ->orderBy('transactions.id')
            ->get([
                'transactions.id',
                'transactions.type',
                'transactions.invoice_no',
                'transactions.sup_ref_no',
                'transactions.additional_note',
                'transactions.transaction_date',
                'transactions.final_amount',
                'transactions.payment_status',
                'branches.name as branch_name',
            ])
            ->map(function ($row) use ($accountNature): array {
                [$debit, $credit] = $this->documentSides((string) $row->type, (float) $row->final_amount);

                return $this->presentRow(
                    id: 'txn-'.$row->id,
                    date: $this->dateString($row->transaction_date),
                    voucherNo: $row->invoice_no,
                    refNo: $row->sup_ref_no,
                    description: $row->additional_note ?: $this->documentLabel((string) $row->type),
                    debit: $debit,
                    credit: $credit,
                    accNature: $accountNature,
                    highlight: 0,
                    branchName: $row->branch_name,
                    paymentStatus: $row->payment_status,
                    type: $row->type,
                    chequeNo: null,
                );
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function paymentRows(
        Contact $contact,
        string $from,
        string $to,
        int|string|null $branchId,
    ): Collection {
        if (! Schema::hasTable('payments') || ! Schema::hasTable('transactions')) {
            return collect();
        }

        $accountNature = $this->accountNature($contact);
        $paymentTypes = array_values(array_intersect(
            $this->statementTypes($contact),
            [Transaction::TYPE_PURCHASE, Transaction::TYPE_SELL],
        ));

        if ($paymentTypes === []) {
            return collect();
        }

        return DB::table('payments')
            ->join('transactions', 'transactions.id', '=', 'payments.transaction_id')
            ->leftJoin('branches', 'branches.id', '=', 'payments.branch_id')
            ->where('transactions.company_id', $contact->company_id)
            ->where('transactions.contact_id', $contact->id)
            ->whereIn('transactions.type', $paymentTypes)
            ->whereDate('payments.paid_on', '>=', $from)
            ->whereDate('payments.paid_on', '<=', $to)
            ->when($branchId !== null, fn ($query) => $query->where('transactions.branch_id', $branchId))
            ->orderBy('payments.paid_on')
            ->orderBy('payments.id')
            ->get([
                'payments.id',
                'payments.amount',
                'payments.method',
                'payments.cheque_number',
                'payments.payment_ref_no',
                'payments.paid_on',
                'payments.note',
                'transactions.type as transaction_type',
                'transactions.invoice_no',
                'transactions.payment_status',
                'branches.name as branch_name',
            ])
            ->map(function ($row) use ($accountNature): array {
                [$debit, $credit] = $this->paymentSides((string) $row->transaction_type, (float) $row->amount);

                return $this->presentRow(
                    id: 'pay-'.$row->id,
                    date: $this->dateString($row->paid_on),
                    voucherNo: $row->payment_ref_no ?: $row->invoice_no,
                    refNo: $row->invoice_no,
                    description: $row->note ?: 'Payment',
                    debit: $debit,
                    credit: $credit,
                    accNature: $accountNature,
                    highlight: 0,
                    branchName: $row->branch_name,
                    paymentStatus: $row->payment_status,
                    type: $row->method,
                    chequeNo: $row->cheque_number,
                );
            });
    }

    private function priorTransactionNet(Contact $contact, string $from, int|string|null $branchId): float
    {
        $financialYear = FinancialYear::query()
            ->where('company_id', $contact->company_id)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();

        $periodStart = $financialYear?->start_date?->toDateString() ?? $from;

        if ($from <= $periodStart) {
            return 0;
        }

        $net = 0.0;

        foreach ($this->statementTypes($contact) as $type) {
            $amount = (float) $this->transactionQuery($contact, $branchId)
                ->where('transactions.type', $type)
                ->whereDate('transactions.transaction_date', '>=', $periodStart)
                ->whereDate('transactions.transaction_date', '<', $from)
                ->sum('transactions.final_amount');

            $net += match ($type) {
                Transaction::TYPE_PURCHASE, Transaction::TYPE_SELL => $amount,
                Transaction::TYPE_PURCHASE_RETURN => -$amount,
                default => 0.0,
            };
        }

        if (Schema::hasTable('payments')) {
            $paymentTypes = array_values(array_intersect(
                $this->statementTypes($contact),
                [Transaction::TYPE_PURCHASE, Transaction::TYPE_SELL],
            ));

            if ($paymentTypes !== []) {
                $paid = (float) DB::table('payments')
                    ->join('transactions', 'transactions.id', '=', 'payments.transaction_id')
                    ->where('transactions.company_id', $contact->company_id)
                    ->where('transactions.contact_id', $contact->id)
                    ->whereIn('transactions.type', $paymentTypes)
                    ->whereDate('payments.paid_on', '>=', $periodStart)
                    ->whereDate('payments.paid_on', '<', $from)
                    ->when($branchId !== null, fn ($query) => $query->where('transactions.branch_id', $branchId))
                    ->sum('payments.amount');

                $net -= $paid;
            }
        }

        return round($net, 2);
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function documentSides(string $type, float $amount): array
    {
        return match ($type) {
            Transaction::TYPE_PURCHASE => [null, $amount],
            Transaction::TYPE_PURCHASE_RETURN, Transaction::TYPE_SELL => [$amount, null],
            default => [null, $amount],
        };
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function paymentSides(string $transactionType, float $amount): array
    {
        return $transactionType === Transaction::TYPE_SELL
            ? [null, $amount]
            : [$amount, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(
        int|string $id,
        mixed $date,
        ?string $voucherNo,
        ?string $refNo,
        ?string $description,
        mixed $debit,
        mixed $credit,
        string $accNature,
        int $highlight,
        ?string $branchName,
        ?string $paymentStatus,
        ?string $type,
        ?string $chequeNo,
    ): array {
        return [
            'id' => (string) $id,
            'voucher_date' => $date ? substr((string) $date, 0, 10) : null,
            'voucher_no' => $voucherNo,
            'ref_no' => $refNo,
            'description' => $description,
            'debit' => $debit,
            'credit' => $credit,
            'acc_nature' => $accNature,
            'highlight' => $highlight,
            'balance_amount' => 0,
            'branch' => ['name' => $branchName],
            'transaction' => ['parent' => ['payment_status' => $paymentStatus ?: '-']],
            'type' => $type ?: '-',
            'cheque_no' => $chequeNo ?: '-',
        ];
    }

    private function transactionQuery(Contact $contact, int|string|null $branchId): Builder
    {
        return DB::table('transactions')
            ->where('transactions.company_id', $contact->company_id)
            ->where('transactions.contact_id', $contact->id)
            ->when($branchId !== null, fn ($query) => $query->where('transactions.branch_id', $branchId));
    }

    /**
     * @param  array{company_id: int|string, branch_id: int|string|null, contact_id: int, from: string, to: string}  $scope
     */
    private function sumTransactions(array $scope, string $type): float
    {
        if (! Schema::hasTable('transactions')) {
            return 0;
        }

        return (float) DB::table('transactions')
            ->where('company_id', $scope['company_id'])
            ->where('contact_id', $scope['contact_id'])
            ->where('type', $type)
            ->whereDate('transaction_date', '>=', $scope['from'])
            ->whereDate('transaction_date', '<=', $scope['to'])
            ->when($scope['branch_id'] !== null, fn ($query) => $query->where('branch_id', $scope['branch_id']))
            ->sum('final_amount');
    }

    /**
     * @param  array{company_id: int|string, branch_id: int|string|null, contact_id: int, from: string, to: string}  $scope
     */
    private function sumPaidTransactions(array $scope, string $type): float
    {
        if (! Schema::hasTable('transactions')) {
            return 0;
        }

        $paid = (float) DB::table('transactions')
            ->where('company_id', $scope['company_id'])
            ->where('contact_id', $scope['contact_id'])
            ->where('type', $type)
            ->where('payment_status', 'paid')
            ->whereDate('transaction_date', '>=', $scope['from'])
            ->whereDate('transaction_date', '<=', $scope['to'])
            ->when($scope['branch_id'] !== null, fn ($query) => $query->where('branch_id', $scope['branch_id']))
            ->sum('final_amount');

        $partial = 0.0;

        if (Schema::hasTable('payments')) {
            $partial = (float) DB::table('transactions')
                ->join('payments', 'payments.transaction_id', '=', 'transactions.id')
                ->where('transactions.company_id', $scope['company_id'])
                ->where('transactions.contact_id', $scope['contact_id'])
                ->where('transactions.type', $type)
                ->where('transactions.payment_status', 'partial')
                ->whereDate('transactions.transaction_date', '>=', $scope['from'])
                ->whereDate('transactions.transaction_date', '<=', $scope['to'])
                ->when($scope['branch_id'] !== null, fn ($query) => $query->where('transactions.branch_id', $scope['branch_id']))
                ->sum('payments.amount');
        }

        return $paid + $partial;
    }

    private function hasJournalTables(): bool
    {
        return Schema::hasTable('t_account_details') && Schema::hasTable('t_accounts');
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function documentLabel(string $type): string
    {
        return match ($type) {
            Transaction::TYPE_PURCHASE => 'Purchase order',
            Transaction::TYPE_PURCHASE_RETURN => 'Purchase return',
            Transaction::TYPE_SELL => 'Sale',
            default => Str::headline($type),
        };
    }
}
