<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\FixedAssetDepreciationEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Books depreciation for every company up to a month (default: last month). Safe to run again: each asset-month is
 * booked once. Registered monthly in routes/console.php, which only runs when the server's cron calls
 * `schedule:run`; it can always be run by hand or from the Depreciation page.
 */
class RunFixedAssetDepreciation extends Command
{
    /**
     * @var string
     */
    protected $signature = 'assets:run-depreciation {--period= : Last month to book, Y-m (default: last month)} {--company= : One company id}';

    /**
     * @var string
     */
    protected $description = 'Book fixed asset depreciation up to a month';

    public function handle(FixedAssetDepreciationEngine $engine): int
    {
        $period = (string) ($this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m'));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            $this->error('The period must look like 2026-09.');

            return self::FAILURE;
        }

        $booked = 0;

        Company::query()
            ->when($this->option('company'), fn ($q) => $q->whereKey((int) $this->option('company')))
            ->each(function (Company $company) use ($engine, $period, &$booked): void {
                $actor = User::query()->where('company_id', $company->id)->whereHas('role', fn ($q) => $q->where('name', 'companyadmin'))->orderBy('id')->first();

                if ($actor === null) {
                    $this->warn("Company {$company->id}: no company admin to book as, skipped.");

                    return;
                }

                Auth::setUser($actor);
                $result = $engine->run((int) $company->id, $period);
                $booked += $result['count'];

                if ($result['count'] > 0) {
                    $this->info("Company {$company->id}: {$result['count']} asset-months, {$result['total']} in {$result['vouchers']} vouchers.");
                }
            });

        $this->info("Done: {$booked} asset-months booked up to {$period}.");

        return self::SUCCESS;
    }
}
