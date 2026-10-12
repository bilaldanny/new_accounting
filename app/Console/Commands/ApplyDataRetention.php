<?php

namespace App\Console\Commands;

use App\Services\System\DataRetention;
use Illuminate\Console\Command;

/**
 * Applies the retention rules to the audit, API, sign-in, notification and reminder logs.
 */
class ApplyDataRetention extends Command
{
    protected $signature = 'system:apply-retention {--company= : One company id}';

    protected $description = 'Delete (and archive) log rows older than the retention rules allow';

    public function handle(DataRetention $retention): int
    {
        $removed = $retention->run($this->option('company') ? (int) $this->option('company') : null);

        foreach ($removed as $entity => $count) {
            $this->line("{$entity}: {$count} rows removed");
        }

        if ($removed === []) {
            $this->info('Nothing to remove.');
        }

        return self::SUCCESS;
    }
}
