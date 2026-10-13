<?php

namespace App\Console\Commands;

use App\Support\BaselineMenus;
use Illuminate\Console\Command;

/**
 * Step of a fresh install: after `migrate`, adds the menu rows the original menu dump would have provided and gives the shared
 * `companyadmin` role the company-level menus. Safe to run again; it never changes a menu that already exists.
 */
class InstallBaselineMenus extends Command
{
    /**
     * @var string
     */
    protected $signature = 'install:baseline-menus {--dry-run : Count what would be added without writing}';

    /**
     * @var string
     */
    protected $description = 'Add the missing baseline menu rows and give the shared companyadmin role the company-level menus';

    public function handle(): int
    {
        $result = BaselineMenus::ensure((bool) $this->option('dry-run'));

        $this->info(($this->option('dry-run') ? 'Would add ' : 'Added ').$result['added'].' menu rows.');

        if (! $this->option('dry-run')) {
            $this->info('The shared companyadmin role '.($result['role_created'] ? 'was created and ' : '').'was given '.$result['granted'].' menus.');
        }

        return self::SUCCESS;
    }
}
