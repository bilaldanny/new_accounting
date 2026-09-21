<?php

namespace App\Services;

use App\Models\TAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The receipts and payments voucher report, one row per approved voucher.
 *
 * Receipts are the deposit vouchers BD, CD and OD and the SP vouchers the app posts for the payments
 * customers make against sales; payments are BP, CP and OP and the PP vouchers posted for the payments
 * made to suppliers. Only approved vouchers count (a pending or rejected one has not happened), the range
 * is on the voucher date and inclusive at both ends. The amount is the voucher's debit total, which equals
 * its credit total, so it is right for a voucher with several lines. The party is the first contact on
 * the voucher's lines and the account column lists the accounts the voucher moves money against (its
 * lines other than the bank or cash account of the header). The old report read a header-only view and
 * matched the prefixes with LIKE '%BD%' anywhere in the number.
 */
class VoucherReport
{
    /**
     * @var array<string, string> voucher prefix => label
     */
    public const LABELS = [
        'BD' => 'Bank deposit',
        'CD' => 'Cash deposit',
        'OD' => 'Online deposit',
        'SP' => 'Sale payment',
        'BP' => 'Bank payment',
        'CP' => 'Cash payment',
        'OP' => 'Online payment',
        'PP' => 'Purchase payment',
    ];

    public const TYPES = ['all', 'receipt', 'payment'];

    public const SORTABLE = [
        'voucher_date' => 'voucher_date',
        'voucher_no' => 'voucher_no',
        'kind' => 'kind',
        'label' => 'label',
        'party_name' => 'party_name',
        'ref_no' => 'ref_no',
        'cheque_no' => 'cheque_no',
        'branch_name' => 'branch_name',
        'amount' => 'amount',
    ];

    /**
     * @return list<string>
     */
    public static function receiptPrefixes(): array
    {
        return [...TAccount::DEPOSIT_VOUCHER_TYPES, 'SP'];
    }

    /**
     * @return list<string>
     */
    public static function paymentPrefixes(): array
    {
        return [...TAccount::PAYMENT_VOUCHER_TYPES, 'PP'];
    }

    /**
     * @param  array{voucher_type?: ?string, contact_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $type = trim((string) ($filters['voucher_type'] ?? ''));
        $contactId = $filters['contact_id'] ?? null;
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        $prefixes = match ($type) {
            'receipt' => self::receiptPrefixes(),
            'payment' => self::paymentPrefixes(),
            default => [...self::receiptPrefixes(), ...self::paymentPrefixes()],
        };

        $amounts = DB::table('t_account_details')
            ->select('t_account_id', DB::raw('coalesce(sum(debit), 0) as amount'))
            ->groupBy('t_account_id');

        $vouchers = DB::table('t_accounts as a')
            ->leftJoinSub($amounts, 'amt', 'amt.t_account_id', '=', 'a.id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('chart_of_accounts as h', 'h.id', '=', 'a.coa_id')
            ->where('a.status', TAccount::STATUS_APPROVED)
            ->whereNull('a.transaction_id')
            ->where(function (Builder $query) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('a.voucher_no', 'like', "{$prefix}-%");
                }
            })
            ->when($companyId !== null, fn (Builder $query) => $query->where('a.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('a.branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->whereExists(fn (Builder $exists) => $exists
                ->select(DB::raw(1))
                ->from('t_account_details as pd')
                ->whereColumn('pd.t_account_id', 'a.id')
                ->where('pd.contact_id', $contactId)))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('a.voucher_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('a.voucher_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('a.voucher_no', 'like', "%{$search}%")
                    ->orWhere('a.ref_no', 'like', "%{$search}%")
                    ->orWhere('a.cheque_no', 'like', "%{$search}%")
                    ->orWhere('a.comments', 'like', "%{$search}%");
            }))
            ->select([
                'a.id',
                'a.voucher_no',
                'a.voucher_date',
                'a.ref_no',
                'a.cheque_no',
                'a.cheque_post_date',
                'a.comments',
                'a.coa_id',
                'h.name as header_account',
                'b.name as branch_name',
                DB::raw('coalesce(amt.amount, 0) as amount'),
            ])
            ->get();

        $lines = $this->lines($vouchers->pluck('id')->all());

        return $vouchers
            ->map(fn (stdClass $voucher): array => $this->present($voucher, $lines[$voucher->id] ?? collect()))
            ->sortBy([['voucher_date', 'desc'], ['id', 'desc']])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, receipts: float, payments: float, net: float, receipt_count: int, payment_count: int}
     */
    public function summary(Collection $rows): array
    {
        $receipts = round((float) $rows->where('kind', 'receipt')->sum('amount'), 2);
        $payments = round((float) $rows->where('kind', 'payment')->sum('amount'), 2);

        return [
            'count' => $rows->count(),
            'receipts' => $receipts,
            'payments' => $payments,
            'net' => round($receipts - $payments, 2),
            'receipt_count' => $rows->where('kind', 'receipt')->count(),
            'payment_count' => $rows->where('kind', 'payment')->count(),
        ];
    }

    /**
     * The lines of the vouchers, grouped by voucher, with the contact and account names.
     *
     * @param  list<int|string>  $voucherIds
     * @return Collection<int|string, Collection<int, stdClass>>
     */
    private function lines(array $voucherIds): Collection
    {
        if ($voucherIds === []) {
            return collect();
        }

        return DB::table('t_account_details as d')
            ->leftJoin('contacts as c', 'c.id', '=', 'd.contact_id')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'd.coa_id')
            ->whereIn('d.t_account_id', $voucherIds)
            ->orderBy('d.id')
            ->select(['d.t_account_id', 'd.coa_id', 'd.account_code', 'd.contact_id', 'coa.name as account_name', 'c.business_name', 'c.first_name', 'c.last_name'])
            ->get()
            ->groupBy('t_account_id');
    }

    /**
     * @param  Collection<int, stdClass>  $lines
     * @return array<string, mixed>
     */
    private function present(stdClass $voucher, Collection $lines): array
    {
        $prefix = strtoupper((string) strstr((string) $voucher->voucher_no, '-', true));
        $party = $lines->first(fn (stdClass $line): bool => $line->contact_id !== null);
        $counter = $lines
            ->reject(fn (stdClass $line): bool => $voucher->coa_id !== null && (int) $line->coa_id === (int) $voucher->coa_id)
            ->map(fn (stdClass $line): string => trim($line->account_code.' '.($line->account_name ?? '')))
            ->filter()
            ->unique()
            ->values();

        return [
            'id' => (int) $voucher->id,
            'voucher_no' => $voucher->voucher_no,
            'voucher_date' => substr((string) $voucher->voucher_date, 0, 10),
            'kind' => in_array($prefix, self::receiptPrefixes(), true) ? 'receipt' : 'payment',
            'label' => self::LABELS[$prefix] ?? $prefix,
            'header_account' => (string) ($voucher->header_account ?? ''),
            'party_name' => $party === null ? '' : TransactionListReport::contactName($party),
            'accounts' => $counter->implode(', '),
            'ref_no' => (string) ($voucher->ref_no ?? ''),
            'cheque_no' => (string) ($voucher->cheque_no ?? ''),
            'cheque_date' => $voucher->cheque_post_date === null ? '' : substr((string) $voucher->cheque_post_date, 0, 10),
            'description' => (string) ($voucher->comments ?? ''),
            'branch_name' => (string) ($voucher->branch_name ?? ''),
            'amount' => round((float) $voucher->amount, 2),
        ];
    }
}
