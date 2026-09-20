<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Customer and supplier aging: what is still owed on each open invoice, sorted into how long it has
 * been overdue, one row per customer or supplier.
 *
 * Buckets count days from the invoice's due date to the "as of" day (today unless given): 0-30, 31-60,
 * 61-90 and 90+. An invoice whose due date is still ahead goes in "Not yet due", so the row total is
 * everything owed on invoices and nothing disappears from the report. The due date is the invoice
 * date plus its pay term (the invoice's own, else the contact's, in days, months or years); with no
 * pay term anywhere the invoice date itself is the due date, so the invoice is aged from the day it
 * was raised instead of being skipped.
 *
 * Only the balance still open is aged, whether the invoice is unpaid or part paid: invoice total,
 * less payments made up to the as-of day, less returns raised against that invoice (parent_id) up to
 * that day, never below zero. Invoices dated after the as-of day do not exist yet and are left out,
 * as are drafts and quotations. Opening balances and payments not tied to an invoice are not
 * invoices, so they are not here; the outstanding report shows the full ledger figure.
 */
class PartyAgingReport
{
    public const KINDS = [
        'customer' => ['type' => Transaction::TYPE_SELL, 'return' => Transaction::TYPE_SELL_RETURN, 'excluded' => Transaction::UNPOSTED_SELL_STATUSES],
        'supplier' => ['type' => Transaction::TYPE_PURCHASE, 'return' => Transaction::TYPE_PURCHASE_RETURN, 'excluded' => ['draft']],
    ];

    /**
     * Bucket keys in order, with the oldest age (in days past due) each one holds.
     *
     * @var array<string, int|null>
     */
    public const BUCKETS = ['not_due' => null, 'days_0_30' => 30, 'days_31_60' => 60, 'days_61_90' => 90, 'days_90_plus' => PHP_INT_MAX];

    public const SORTABLE = [
        'contact_name' => 'contact_name',
        'code' => 'code',
        'invoices' => 'invoices',
        'oldest_days' => 'oldest_days',
        'not_due' => 'not_due',
        'days_0_30' => 'days_0_30',
        'days_31_60' => 'days_31_60',
        'days_61_90' => 'days_61_90',
        'days_90_plus' => 'days_90_plus',
        'total' => 'total',
    ];

    /**
     * @param  array{contact_id?: mixed, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $config = self::KINDS[$kind] ?? throw new InvalidArgumentException("Unknown aging report [{$kind}].");
        $asOf = $this->asOf($filters['end_date'] ?? null);

        return $this->openInvoices($config, $companyId, $branchId, $filters, $asOf)
            ->get()
            ->groupBy('contact_id')
            ->map(function (Collection $invoices) use ($asOf): array {
                $first = $invoices->first();
                $row = [
                    'id' => (int) $first->contact_id,
                    'contact_name' => TransactionListReport::contactName($first),
                    'code' => (string) $first->code,
                    'invoices' => $invoices->count(),
                    'oldest_days' => 0,
                    'not_due' => 0.0,
                    'days_0_30' => 0.0,
                    'days_31_60' => 0.0,
                    'days_61_90' => 0.0,
                    'days_90_plus' => 0.0,
                    'total' => 0.0,
                ];

                foreach ($invoices as $invoice) {
                    $daysPastDue = $this->daysPastDue($invoice, $asOf);
                    $open = (float) $invoice->open_amount;

                    $row[self::bucketFor($daysPastDue)] += $open;
                    $row['total'] += $open;
                    $row['oldest_days'] = max($row['oldest_days'], $daysPastDue);
                }

                foreach (array_keys(self::BUCKETS) as $bucket) {
                    $row[$bucket] = round($row[$bucket], 2);
                }
                $row['total'] = round($row['total'], 2);

                return $row;
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|string>
     */
    public function summary(Collection $rows, ?string $endDate = null): array
    {
        $summary = ['count' => $rows->count(), 'invoices' => (int) $rows->sum('invoices')];

        foreach ([...array_keys(self::BUCKETS), 'total'] as $key) {
            $summary[$key] = round((float) $rows->sum($key), 2);
        }

        $summary['as_of'] = $this->asOf($endDate)->toDateString();

        return $summary;
    }

    /**
     * The bucket a number of days past the due date belongs to; a negative number is not yet due.
     */
    public static function bucketFor(int $daysPastDue): string
    {
        foreach (self::BUCKETS as $bucket => $limit) {
            if ($limit === null ? $daysPastDue < 0 : $daysPastDue <= $limit) {
                return $bucket;
            }
        }

        return 'days_90_plus';
    }

    /**
     * The invoice's due date: its date plus its pay term, or the invoice date when there is no term.
     */
    public static function dueDate(string $invoiceDate, mixed $invoiceTerm, mixed $invoiceUnit, mixed $contactTerm, mixed $contactUnit): Carbon
    {
        $date = Carbon::parse(substr($invoiceDate, 0, 10), 'UTC')->startOfDay();

        [$term, $unit] = self::termOf($invoiceTerm) > 0
            ? [self::termOf($invoiceTerm), $invoiceUnit]
            : [self::termOf($contactTerm), $contactUnit];

        if ($term <= 0) {
            return $date;
        }

        return match (strtolower(trim((string) $unit))) {
            'month' => $date->addMonthsNoOverflow($term),
            'year' => $date->addYearsNoOverflow($term),
            default => $date->addDays($term),
        };
    }

    private static function termOf(mixed $term): int
    {
        return is_numeric($term) ? (int) round((float) $term) : 0;
    }

    private function daysPastDue(stdClass $invoice, Carbon $asOf): int
    {
        $due = self::dueDate(
            (string) $invoice->transaction_date,
            $invoice->pay_term,
            $invoice->pay_type,
            $invoice->contact_pay_term,
            $invoice->contact_pay_type,
        );

        return intdiv($asOf->getTimestamp() - $due->getTimestamp(), 86400);
    }

    private function asOf(?string $endDate): Carbon
    {
        $day = $endDate !== null && trim($endDate) !== '' ? substr(trim($endDate), 0, 10) : now()->toDateString();

        return Carbon::parse($day, 'UTC')->startOfDay();
    }

    /**
     * @param  array{type: string, return: string, excluded: list<string>}  $config
     * @param  array{contact_id?: mixed, search?: ?string}  $filters
     */
    private function openInvoices(array $config, ?int $companyId, ?int $branchId, array $filters, Carbon $asOf): Builder
    {
        $day = $asOf->toDateString();
        $contactId = $filters['contact_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $paid = DB::table('payments')
            ->select('transaction_id', DB::raw('sum(amount) as paid'))
            ->whereRaw('substr(paid_on, 1, 10) <= ?', [$day])
            ->groupBy('transaction_id');

        $returned = DB::table('transactions')
            ->select('parent_id', DB::raw('sum(final_amount) as returned'))
            ->where('type', $config['return'])
            ->whereNull('deleted_at')
            ->whereDate('transaction_date', '<=', $day)
            ->groupBy('parent_id');

        return DB::table('transactions as t')
            ->join('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoinSub($paid, 'pay', 'pay.transaction_id', '=', 't.id')
            ->leftJoinSub($returned, 'ret', 'ret.parent_id', '=', 't.id')
            ->where('t.type', $config['type'])
            ->whereNull('t.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNotIn('t.status', $config['excluded'])
            ->whereDate('t.transaction_date', '<=', $day)
            ->whereRaw('(t.final_amount - coalesce(pay.paid, 0) - coalesce(ret.returned, 0)) > 0.004')
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->where('t.contact_id', $contactId))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('c.business_name', 'like', "%{$search}%")
                    ->orWhere('c.first_name', 'like', "%{$search}%")
                    ->orWhere('c.last_name', 'like', "%{$search}%")
                    ->orWhere('c.code', 'like', "%{$search}%");
            }))
            ->select([
                't.id',
                't.contact_id',
                't.transaction_date',
                't.pay_term',
                't.pay_type',
                'c.pay_term as contact_pay_term',
                'c.pay_type as contact_pay_type',
                'c.business_name',
                'c.first_name',
                'c.last_name',
                'c.code',
            ])
            ->selectRaw('(t.final_amount - coalesce(pay.paid, 0) - coalesce(ret.returned, 0)) as open_amount')
            ->orderBy('t.contact_id')
            ->orderBy('t.id');
    }
}
