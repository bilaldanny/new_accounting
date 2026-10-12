<?php

namespace App\Services\Manufacturing;

use App\Services\AccountsVoucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ledger entries of manufacturing, made only when the company switched manufacturing posting on and mapped its six accounts. Every
 * voucher goes through AccountsVoucher (the journal approval setting, the period lock and numbering apply), inside the same database
 * transaction as the stock movement it belongs to: a locked month refuses the whole operation and nothing is left behind.
 *
 *   material issue      Dr WIP                    Cr Raw material inventory
 *   direct labour       Dr WIP                    Cr Labour applied      (overhead: Cr Overhead applied)
 *   finished receipt    Dr Finished goods         Cr WIP                 (by-products and co-products are part of it)
 *   abnormal scrap      Dr Scrap and variance     Cr WIP
 *   work order closing  Dr Scrap and variance     Cr WIP                 (cost left over after the last receipt)
 *
 * With posting off, every method returns null and writes nothing.
 */
class ManufacturingPosting
{
    public function __construct(private readonly ManufacturingSettings $settings, private readonly AccountsVoucher $vouchers) {}

    public function enabled(int $companyId): bool
    {
        return (bool) $this->settings->all($companyId)['posting_enabled'];
    }

    /**
     * Refuses an operation up front when posting is on but an account is not mapped.
     *
     * @throws ValidationException
     */
    public function assertReady(int $companyId): void
    {
        $settings = $this->settings->all($companyId);

        if (! $settings['posting_enabled']) {
            return;
        }

        foreach (ManufacturingSettings::ACCOUNT_SLOTS as $slot) {
            if ($settings['accounts'][$slot] === null) {
                throw ValidationException::withMessages(['accounts' => ['Map the '.str_replace('_', ' ', $slot).' account in the manufacturing settings before using manufacturing posting.']]);
            }
        }
    }

    /**
     * @param  array<string, float>  $debits  slot => amount
     * @param  array<string, float>  $credits  slot => amount
     */
    public function post(int $companyId, ?int $branchId, string $date, array $debits, array $credits, string $label, string $reference): ?int
    {
        if (! $this->enabled($companyId)) {
            return null;
        }

        $accounts = $this->settings->all($companyId)['accounts'];
        $lines = [];

        foreach ($debits as $slot => $amount) {
            if (round($amount, 2) > 0) {
                $lines[] = ['account_id' => (int) $accounts[$slot], 'debit' => round($amount, 2), 'credit' => 0, 'description' => $label];
            }
        }

        foreach ($credits as $slot => $amount) {
            if (round($amount, 2) > 0) {
                $lines[] = ['account_id' => (int) $accounts[$slot], 'debit' => 0, 'credit' => round($amount, 2), 'description' => $label];
            }
        }

        $debit = round((float) array_sum(array_column($lines, 'debit')), 2);
        $credit = round((float) array_sum(array_column($lines, 'credit')), 2);

        if ($lines === []) {
            return null;
        }

        if (abs($debit - $credit) > 0.009) {
            throw ValidationException::withMessages(['posting' => ["The manufacturing entry does not balance (debit {$debit}, credit {$credit})."]]);
        }

        return (int) DB::transaction(fn (): int => (int) $this->vouchers->post($companyId, $branchId, $date, $lines, $label, $reference)->id);
    }
}
