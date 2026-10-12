<?php

namespace App\Console\Commands;

use App\Services\System\ExchangeRateSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pulls exchange rates for every company that has an active rate feed. A failed feed leaves that company's rates unchanged.
 */
class SyncExchangeRates extends Command
{
    protected $signature = 'currency:sync-rates {--company= : One company id}';

    protected $description = 'Sync exchange rates from the configured feeds';

    public function handle(ExchangeRateSync $sync): int
    {
        $companies = DB::table('integration_accounts')->where('kind', 'rates')->where('provider', 'http_json')->where('is_active', true)
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))->pluck('company_id')->unique();

        foreach ($companies as $companyId) {
            $result = $sync->run((int) $companyId);
            $this->line("Company {$companyId}: ".($result['ok'] ? "updated {$result['updated']}, skipped {$result['skipped']}" : 'failed - '.$result['error']));
        }

        return self::SUCCESS;
    }
}
