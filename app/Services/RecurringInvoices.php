<?php

namespace App\Services;

use App\Models\RecurringInvoice;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The generator of standing invoices, in the pattern of the customer subscription invoice generator: find what is due
 * (`next_run_on` on or before the day, still active), make one sale per due schedule through SaleDocumentBuilder, then move the
 * schedule on. A run that fails (a customer on hold, a missing product, a locked period) is recorded with its message and the
 * schedule is left to be retried; it never stops the other schedules. A schedule catches up one run per call so a long gap does
 * not produce a pile of invoices at once.
 */
class RecurringInvoices
{
    public function __construct(private readonly SaleDocumentBuilder $builder) {}

    public static function supported(): bool
    {
        return Schema::hasTable('recurring_invoices');
    }

    /**
     * @return array{generated: int, failed: int}
     */
    public function generateDue(?int $companyId = null, ?CarbonInterface $on = null): array
    {
        $day = ($on ?? now())->copy()->startOfDay();
        $generated = 0;
        $failed = 0;

        RecurringInvoice::query()
            ->where('status', 'active')
            ->whereDate('next_run_on', '<=', $day->toDateString())
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id')
            ->each(function (RecurringInvoice $schedule) use ($day, &$generated, &$failed): void {
                $this->runOne($schedule, $day) ? $generated++ : $failed++;
            });

        return ['generated' => $generated, 'failed' => $failed];
    }

    public function runOne(RecurringInvoice $schedule, ?CarbonInterface $day = null): bool
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $runDate = $schedule->next_run_on->copy()->startOfDay();

        try {
            $sale = DB::transaction(function () use ($schedule, $runDate): Transaction {
                $built = $this->builder->lines((int) $schedule->company_id, (int) $schedule->branch_id, (int) $schedule->contact_id, (array) $schedule->lines);

                return $this->builder->create([
                    'company_id' => $schedule->company_id, 'branch_id' => $schedule->branch_id, 'contact_id' => $schedule->contact_id,
                    'transaction_date' => $runDate->toDateString(), 'note' => trim('Recurring invoice '.$schedule->name.($schedule->note ? ': '.$schedule->note : '')),
                ], $built['lines'], $built['total'], $schedule->post_as === 'final' ? 'final' : 'draft');
            });
        } catch (Throwable $e) {
            DB::table('recurring_invoice_runs')->insert([
                'recurring_invoice_id' => $schedule->id, 'transaction_id' => null, 'run_on' => $runDate->toDateString(), 'status' => 'failed',
                'message' => mb_substr($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage(), 0, 500),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return false;
        }

        $runs = (int) $schedule->runs + 1;
        $next = $schedule->nextAfter($runDate);
        $ended = ($schedule->max_runs !== null && $runs >= (int) $schedule->max_runs) || ($schedule->ends_on !== null && $next->toDateString() > $schedule->ends_on->toDateString());

        $schedule->update([
            'runs' => $runs, 'next_run_on' => $next->toDateString(), 'last_run_at' => now(), 'last_transaction_id' => $sale->id,
            'status' => $ended ? 'ended' : $schedule->status,
        ]);

        DB::table('recurring_invoice_runs')->insert([
            'recurring_invoice_id' => $schedule->id, 'transaction_id' => $sale->id, 'run_on' => $runDate->toDateString(), 'status' => 'ok',
            'message' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }
}
