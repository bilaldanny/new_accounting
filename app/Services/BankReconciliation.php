<?php

namespace App\Services;

use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Services\Reports\AccountLedgerReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation of one bank account against its ledger (AccountLedgerReport, approved vouchers only).
 *
 * A statement line (money in positive) is matched to one ledger line of the account: money in is a debit,
 * money out a credit. The ledger lines considered are those from the account's reconciliation start (the
 * earliest statement period entered for the account) up to the statement's end, so cheques still outstanding
 * from an earlier month stay on the list until they clear. The books are assumed to agree with the bank up to
 * that start; if they do not, the difference shows it.
 *
 *   difference = (statement closing + ledger lines not on the statement)
 *              - (ledger balance + statement lines not in the ledger)
 *
 * A statement can be marked reconciled only when the difference is nil. Bank charges and other items the books
 * lack are booked with an ordinary journal entry and then matched; this does not post anything itself.
 */
class BankReconciliation
{
    public const DEFAULT_DAY_WINDOW = 5;

    public function __construct(private readonly AccountLedgerReport $ledger) {}

    /**
     * @param  list<array{txn_date: string, description?: ?string, reference?: ?string, amount: float|int|string}>  $rows
     */
    public function importLines(BankStatement $statement, array $rows): void
    {
        foreach ($rows as $row) {
            $statement->lines()->create([
                'txn_date' => $row['txn_date'],
                'description' => $row['description'] ?? null,
                'reference' => $row['reference'] ?? null,
                'amount' => round((float) $row['amount'], 2),
            ]);
        }
    }

    /**
     * The posted ledger lines of the statement's account that could still be matched, with their side.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, nature: string, ledger_balance: float}
     */
    public function ledgerLines(BankStatement $statement): array
    {
        $start = BankStatement::query()
            ->where('company_id', $statement->company_id)
            ->where('account_code', $statement->account_code)
            ->min('statement_from');

        $result = $this->ledger->statement(
            (int) $statement->company_id,
            $statement->branch_id === null ? null : (int) $statement->branch_id,
            (string) $statement->account_code,
            (string) ($start ?? $statement->statement_from->toDateString()),
            $statement->statement_to->toDateString(),
        );

        return [
            'rows' => $result['rows'],
            'nature' => (string) ($result['account']['nature'] ?? 'dr'),
            'ledger_balance' => (float) $result['summary']['closing'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(BankStatement $statement): array
    {
        ['rows' => $rows, 'nature' => $nature, 'ledger_balance' => $ledgerBalance] = $this->ledgerLines($statement);
        $matched = $this->matchedDetailIds($statement);

        $outstanding = $rows->reject(fn (array $row): bool => in_array($row['id'], $matched, true))->values();
        $outstandingNet = round((float) $outstanding->sum(fn (array $row): float => $this->signed($row, $nature)), 2);

        $lines = $statement->lines()->get();
        $unmatched = $lines->reject(fn (BankStatementLine $line): bool => $line->isMatched());
        $unmatchedNet = round((float) $unmatched->sum('amount'), 2);

        $difference = round(($statement->closing_balance + $outstandingNet) - ($ledgerBalance + $unmatchedNet), 2);

        return [
            'statement_closing' => round((float) $statement->closing_balance, 2),
            'ledger_balance' => round($ledgerBalance, 2),
            'outstanding_ledger_net' => $outstandingNet,
            'outstanding_ledger' => $outstanding->map(fn (array $row): array => $row + ['signed_amount' => $this->signed($row, $nature)])->all(),
            'unmatched_statement_net' => $unmatchedNet,
            'unmatched_statement_count' => $unmatched->count(),
            'matched_count' => $lines->count() - $unmatched->count(),
            'difference' => $difference,
            'is_balanced' => abs($difference) < 0.005,
            'lines_total_matches_closing' => abs(round($statement->opening_balance + (float) $lines->sum('amount'), 2) - round((float) $statement->closing_balance, 2)) < 0.005,
        ];
    }

    /**
     * Matches every unmatched statement line to the one ledger line with the same amount and side dated within
     * `$days` of it, preferring a reference or cheque number that agrees, then the nearest date. One ledger
     * line is used once.
     *
     * @return int the lines matched
     */
    public function autoMatch(BankStatement $statement, int $days = self::DEFAULT_DAY_WINDOW): int
    {
        $this->assertOpen($statement);

        ['rows' => $rows, 'nature' => $nature] = $this->ledgerLines($statement);
        $taken = $this->matchedDetailIds($statement);
        $available = $rows->reject(fn (array $row): bool => in_array($row['id'], $taken, true))->values();
        $count = 0;

        foreach ($statement->lines()->whereNull('matched_detail_id')->get() as $line) {
            $candidates = $available->filter(function (array $row) use ($line, $nature, $days): bool {
                return abs($this->signed($row, $nature) - $line->amount) < 0.005
                    && abs(Carbon::parse($row['voucher_date'])->diffInDays($line->txn_date, false)) <= $days;
            });

            if ($candidates->isEmpty()) {
                continue;
            }

            $reference = strtolower(trim((string) $line->reference));

            $best = $candidates->sortBy(fn (array $row): array => [
                $reference !== '' && in_array($reference, [strtolower((string) $row['cheque_no']), strtolower((string) $row['ref_no']), strtolower((string) $row['voucher_no'])], true) ? 0 : 1,
                abs(Carbon::parse($row['voucher_date'])->diffInDays($line->txn_date, false)),
            ])->first();

            $this->attach($line, (int) $best['id']);
            $available = $available->reject(fn (array $row): bool => $row['id'] === $best['id'])->values();
            $count++;
        }

        return $count;
    }

    /**
     * @throws ValidationException
     */
    public function match(BankStatement $statement, BankStatementLine $line, int $detailId): void
    {
        $this->assertOpen($statement);

        ['rows' => $rows, 'nature' => $nature] = $this->ledgerLines($statement);
        $row = $rows->firstWhere('id', $detailId);

        if ($row === null) {
            throw ValidationException::withMessages(['detail_id' => ['That ledger line is not in this account\'s ledger up to the statement date.']]);
        }

        if (in_array($detailId, $this->matchedDetailIds($statement, $line->id), true)) {
            throw ValidationException::withMessages(['detail_id' => ['That ledger line is already matched to another statement line.']]);
        }

        if (abs($this->signed($row, $nature) - $line->amount) >= 0.005) {
            throw ValidationException::withMessages(['detail_id' => ['The amounts differ: a statement line can only be matched to a ledger line of the same amount and side.']]);
        }

        $this->attach($line, $detailId);
    }

    /**
     * @throws ValidationException
     */
    public function unmatch(BankStatement $statement, BankStatementLine $line): void
    {
        $this->assertOpen($statement);

        $line->forceFill(['matched_detail_id' => null, 'matched_at' => null, 'matched_by' => null])->save();
    }

    /**
     * @throws ValidationException
     */
    public function reconcile(BankStatement $statement): BankStatement
    {
        $this->assertOpen($statement);

        $summary = $this->summary($statement);

        if (! $summary['is_balanced']) {
            throw ValidationException::withMessages(['difference' => ['The statement does not reconcile yet: the difference is '.number_format($summary['difference'], 2, '.', '').'.']]);
        }

        $statement->forceFill([
            'status' => BankStatement::STATUS_RECONCILED,
            'reconciled_at' => now(),
            'reconciled_by' => Auth::id(),
        ])->save();

        return $statement;
    }

    /**
     * @throws ValidationException
     */
    public function assertOpen(BankStatement $statement): void
    {
        if ($statement->isReconciled()) {
            throw ValidationException::withMessages(['status' => ['This statement is already reconciled and locked.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function signed(array $row, string $nature): float
    {
        $debit = (float) $row['debit'];
        $credit = (float) $row['credit'];

        return round($nature === 'dr' ? $debit - $credit : $credit - $debit, 2);
    }

    private function attach(BankStatementLine $line, int $detailId): void
    {
        $line->forceFill(['matched_detail_id' => $detailId, 'matched_at' => now(), 'matched_by' => Auth::id()])->save();
    }

    /**
     * The ledger lines already matched on any statement of the same account (optionally leaving one line out).
     *
     * @return list<int>
     */
    private function matchedDetailIds(BankStatement $statement, ?int $exceptLineId = null): array
    {
        return BankStatementLine::query()
            ->whereNotNull('matched_detail_id')
            ->when($exceptLineId !== null, fn ($query) => $query->where('id', '!=', $exceptLineId))
            ->whereHas('statement', fn ($query) => $query->where('company_id', $statement->company_id)->where('account_code', $statement->account_code))
            ->pluck('matched_detail_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
