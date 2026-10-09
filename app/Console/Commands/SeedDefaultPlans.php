<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPlan;
use Illuminate\Console\Command;

/**
 * Creates a ready-made set of subscription packages (Starter, Growth, Business, each monthly and annual) so a new install has something
 * to show on the pricing section. A plan whose code already exists is left exactly as it is, so running it again never overwrites
 * prices or limits someone has edited. The prices are starting points: change them under Billing > Subscription Plans.
 */
class SeedDefaultPlans extends Command
{
    /**
     * @var string
     */
    protected $signature = 'plans:seed-defaults {--dry-run : List what would be created without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Create the default Starter / Growth / Business subscription packages (existing codes are never touched)';

    /**
     * Monthly price and limits per package. The annual plan of each is priced at ten months.
     *
     * @var array<string, array{name: string, price: int, featured: bool, users: int, branches: int, warehouses: int, products: int, invoices: int, storage_mb: int}>
     */
    public const PACKAGES = [
        'starter' => ['name' => 'Starter', 'price' => 4999, 'featured' => false, 'users' => 3, 'branches' => 1, 'warehouses' => 1, 'products' => 1000, 'invoices' => 300, 'storage_mb' => 2048],
        'growth' => ['name' => 'Growth', 'price' => 12999, 'featured' => true, 'users' => 10, 'branches' => 3, 'warehouses' => 3, 'products' => 10000, 'invoices' => 2000, 'storage_mb' => 10240],
        'business' => ['name' => 'Business', 'price' => 29999, 'featured' => false, 'users' => 30, 'branches' => 10, 'warehouses' => 10, 'products' => 50000, 'invoices' => 10000, 'storage_mb' => 51200],
    ];

    public function handle(): int
    {
        $order = 0;

        foreach (['monthly' => 1, 'annual' => 10] as $cycle => $months) {
            foreach (self::PACKAGES as $key => $package) {
                $order++;
                $code = "{$key}-{$cycle}";

                if (SubscriptionPlan::withTrashed()->where('code', $code)->exists()) {
                    $this->line("{$code}: already there, left as it is.");

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line("{$code}: would be created at ".number_format($package['price'] * $months).'.');

                    continue;
                }

                SubscriptionPlan::query()->create([
                    'name' => $package['name'],
                    'code' => $code,
                    'billing_cycle' => $cycle,
                    'price' => $package['price'] * $months,
                    'trial_days' => 14,
                    'max_users' => $package['users'],
                    'max_branches' => $package['branches'],
                    'max_warehouses' => $package['warehouses'],
                    'max_products' => $package['products'],
                    'max_invoices_per_month' => $package['invoices'],
                    'max_storage_mb' => $package['storage_mb'],
                    'sort_order' => $order,
                    'is_active' => true,
                    'is_featured' => $package['featured'],
                ]);
                $this->info("{$code}: created.");
            }
        }

        return self::SUCCESS;
    }
}
