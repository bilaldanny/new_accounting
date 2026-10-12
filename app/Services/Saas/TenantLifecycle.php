<?php

namespace App\Services\Saas;

use App\Models\Company;
use App\Models\SubscriptionInvoice;
use App\Services\AppNotifier;
use App\Services\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * What happens to a tenant over its life, on the manual-payment model (nobody is charged by the application):
 *
 *  - usage snapshots (users, branches, products, invoices this month, storage) kept per day, for the trend;
 *  - billing run: a subscription set to end at the period end is cancelled when it ends; overdue invoices get reminders
 *    (day 1, day 5 and a final warning); a company still unpaid after the grace days is suspended, and reactivated when it pays;
 *  - the tenant's data export (a zip of CSV files);
 *  - deletion on cancellation: requested, confirmed by typing the company code, waits a grace period, and only then purged, and
 *    only for a company whose subscription is cancelled.
 */
class TenantLifecycle
{
    public const GRACE_DAYS = 7;

    public const DELETION_GRACE_DAYS = 14;

    /** Tables exported to the tenant (company scoped, no secrets). */
    public const EXPORT_TABLES = [
        'branches', 'contacts', 'products', 'product_details', 'chart_of_accounts', 'transactions', 'payments', 't_accounts', 'financial_years',
    ];

    public function __construct(private readonly AppNotifier $notifier) {}

    // ------------------------------------------------------------------------------------------------ usage

    /**
     * @return array<string, int|float|string>
     */
    public function snapshot(Company $company, ?string $day = null): array
    {
        $row = [
            'users' => (int) DB::table('users')->where('company_id', $company->id)->count(),
            'branches' => (int) $company->branches()->count(),
            'products' => (int) DB::table('products')->where('company_id', $company->id)->whereNull('deleted_at')->count(),
            'invoices_month' => (int) $company->invoicesThisMonth(),
            'storage_mb' => round($company->storageUsedBytes() / 1024 / 1024, 2),
        ];

        if (Schema::hasTable('tenant_usage_snapshots')) {
            DB::table('tenant_usage_snapshots')->updateOrInsert(
                ['company_id' => $company->id, 'day' => $day ?? now()->toDateString()],
                $row + ['updated_at' => now(), 'created_at' => now()],
            );
        }

        return $row + ['day' => $day ?? now()->toDateString()];
    }

    public function snapshotAll(): int
    {
        $count = 0;

        Company::query()->where('is_active', true)->each(function (Company $company) use (&$count): void {
            $this->snapshot($company);
            $count++;
        });

        return $count;
    }

    /**
     * @return array{points: list<array<string, mixed>>, plan: array<string, int|null>, change: array<string, int|float>}
     */
    public function trend(int $companyId, int $days = 30): array
    {
        $points = DB::table('tenant_usage_snapshots')->where('company_id', $companyId)->whereDate('day', '>=', now()->subDays($days)->toDateString())
            ->orderBy('day')->get(['day', 'users', 'branches', 'products', 'invoices_month', 'storage_mb'])->map(fn (object $r): array => (array) $r)->all();
        $limits = DB::table('companies')->where('id', $companyId)->first(['max_users', 'max_branches', 'max_products', 'max_invoices_per_month', 'max_storage_mb']);
        $first = $points[0] ?? null;
        $last = $points === [] ? null : $points[count($points) - 1];

        return [
            'points' => $points,
            'plan' => (array) $limits,
            'change' => $first === null || $last === null ? [] : [
                'users' => $last['users'] - $first['users'], 'products' => $last['products'] - $first['products'],
                'invoices_month' => $last['invoices_month'] - $first['invoices_month'], 'storage_mb' => round($last['storage_mb'] - $first['storage_mb'], 2),
            ],
        ];
    }

    // ------------------------------------------------------------------------------------------------ billing run

    /**
     * @return array{cancelled: int, reminders: int, suspended: int}
     */
    public function runBilling(?int $companyId = null, ?int $graceDays = null): array
    {
        $grace = $graceDays ?? (int) config('saas.grace_days', self::GRACE_DAYS);
        $result = ['cancelled' => 0, 'reminders' => 0, 'suspended' => 0];

        Company::query()
            ->where('cancel_at_period_end', true)->whereNotNull('current_period_ends_at')
            ->whereDate('current_period_ends_at', '<=', now()->toDateString())->whereIn('tenant_status', ['trial', 'active'])
            ->when($companyId !== null, fn ($q) => $q->whereKey($companyId))
            ->each(function (Company $company) use (&$result): void {
                $company->tenant_status = 'cancelled';
                $company->save();
                WebhookDispatcher::fire((int) $company->id, 'subscription.cancelled', ['company_id' => $company->id, 'cancelled_at' => now()->toIso8601String()]);
                $result['cancelled']++;
            });

        SubscriptionInvoice::flagOverdue();

        SubscriptionInvoice::query()->where('status', 'overdue')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->each(function (SubscriptionInvoice $invoice) use (&$result, $grace): void {
            $days = (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false);
            $company = Company::query()->find($invoice->company_id);

            if ($company === null || $days < 1) {
                return;
            }

            $kinds = [['overdue_1', 1, 'Your subscription invoice is overdue'], ['overdue_5', 5, 'Second reminder: subscription invoice overdue']];

            if ($grace > 6) {
                $kinds[] = ['final_warning', $grace - 2, 'Final warning: your account will be suspended in 2 days'];
            }

            foreach ($kinds as [$kind, $from, $subject]) {
                if ($days >= $from && ! DB::table('tenant_billing_notices')->where('subscription_invoice_id', $invoice->id)->where('kind', $kind)->exists()) {
                    $this->remindCompany($company, $invoice, $kind, $subject);
                    $result['reminders']++;
                }
            }

            if ($days > $grace && in_array($company->tenant_status, ['trial', 'active'], true)) {
                $company->tenant_status = 'suspended';
                $company->suspended_at = now();
                $company->suspension_reason = "invoice {$invoice->invoice_no} unpaid for {$days} days";
                $company->save();
                $result['suspended']++;
            }
        });

        return $result;
    }

    /**
     * Brings a company back from a suspension for non-payment once nothing is overdue.
     */
    public function reactivateIfSettled(int $companyId): bool
    {
        $company = Company::query()->find($companyId);

        if ($company === null || $company->tenant_status !== 'suspended' || $company->suspended_at === null) {
            return false;
        }

        if (SubscriptionInvoice::query()->where('company_id', $companyId)->whereIn('status', ['overdue', 'unpaid'])->whereDate('due_date', '<', now()->toDateString())->exists()) {
            return false;
        }

        $company->tenant_status = 'active';
        $company->suspended_at = null;
        $company->suspension_reason = null;
        $company->save();

        return true;
    }

    private function remindCompany(Company $company, SubscriptionInvoice $invoice, string $kind, string $subject): void
    {
        $body = "Invoice {$invoice->invoice_no} for ".number_format((float) $invoice->total_amount, 2)." was due on {$invoice->due_date->toDateString()}. "
            .'Please pay it to keep your account active.';
        $status = 'logged';

        foreach (DB::table('users')->where('company_id', $company->id)->where('is_active', true)->whereIn('role_id', DB::table('roles')->where('name', 'companyadmin')->pluck('id'))->get(['id', 'email']) as $admin) {
            $this->notifier->notify((int) $admin->id, 'billing_overdue', $subject, $body, '/tenant-billing');

            try {
                if (filter_var($admin->email, FILTER_VALIDATE_EMAIL)) {
                    Mail::raw($body, fn ($message) => $message->to($admin->email)->subject($subject));
                    $status = 'sent';
                }
            } catch (Throwable) {
                $status = 'failed';
            }
        }

        DB::table('tenant_billing_notices')->insert(['company_id' => $company->id, 'subscription_invoice_id' => $invoice->id, 'kind' => $kind, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------------------------------------ export

    /**
     * Builds the zip of the company's data and returns the export row.
     *
     * @return array{id: int, path: string, size: int, tables: array<string, int>}
     */
    public function export(int $companyId, ?int $requestedBy): array
    {
        $dir = Storage::disk('local')->path('tenant-exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.'/company-'.$companyId.'-'.now()->format('Ymd-His').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $counts = [];

        foreach (self::EXPORT_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $rows = DB::table($table)->where('company_id', $companyId)->get();
            $counts[$table] = $rows->count();
            $stream = fopen('php://temp', 'r+');

            if ($rows->isNotEmpty()) {
                fputcsv($stream, array_keys((array) $rows->first()));

                foreach ($rows as $row) {
                    fputcsv($stream, array_values((array) $row));
                }
            }

            rewind($stream);
            $zip->addFromString($table.'.csv', (string) stream_get_contents($stream));
            fclose($stream);
        }

        $zip->close();
        $size = (int) filesize($path);
        $id = DB::table('tenant_data_exports')->insertGetId([
            'company_id' => $companyId, 'status' => 'done', 'path' => basename($path), 'size' => $size, 'tables' => json_encode($counts),
            'requested_by' => $requestedBy, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'path' => $path, 'size' => $size, 'tables' => $counts];
    }

    // ------------------------------------------------------------------------------------------------ deletion

    /**
     * @throws ValidationException
     */
    public function requestDeletion(Company $company, ?int $requestedBy): int
    {
        if ($company->tenant_status !== 'cancelled') {
            throw ValidationException::withMessages(['company' => ['Only a company whose subscription is cancelled can be deleted. Cancel the subscription first.']]);
        }

        if (DB::table('tenant_deletion_requests')->where('company_id', $company->id)->whereIn('status', ['requested', 'confirmed'])->exists()) {
            throw ValidationException::withMessages(['company' => ['A deletion request is already open.']]);
        }

        return DB::table('tenant_deletion_requests')->insertGetId(['company_id' => $company->id, 'requested_by' => $requestedBy, 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @throws ValidationException
     */
    public function confirmDeletion(Company $company, string $typedCode): void
    {
        $request = DB::table('tenant_deletion_requests')->where('company_id', $company->id)->where('status', 'requested')->orderByDesc('id')->first();

        if ($request === null) {
            throw ValidationException::withMessages(['company' => ['There is no deletion request to confirm.']]);
        }

        if (strcasecmp(trim($typedCode), (string) $company->code) !== 0) {
            throw ValidationException::withMessages(['confirm' => ['Type the company code exactly to confirm.']]);
        }

        DB::table('tenant_deletion_requests')->where('id', $request->id)->update([
            'status' => 'confirmed', 'confirmed_at' => now(), 'scheduled_for' => now()->addDays(self::DELETION_GRACE_DAYS)->toDateString(), 'updated_at' => now(),
        ]);
    }

    public function cancelDeletion(Company $company): bool
    {
        return DB::table('tenant_deletion_requests')->where('company_id', $company->id)->whereIn('status', ['requested', 'confirmed'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]) > 0;
    }

    /**
     * Purges the data of every confirmed request whose grace period has ended, for a company still cancelled.
     *
     * @return array{purged: int}
     */
    public function purgeDue(?int $companyId = null): array
    {
        $purged = 0;

        DB::table('tenant_deletion_requests')->where('status', 'confirmed')->whereDate('scheduled_for', '<=', now()->toDateString())
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->orderBy('id')->get()
            ->each(function (object $request) use (&$purged): void {
                $company = Company::query()->find($request->company_id);

                if ($company === null || $company->tenant_status !== 'cancelled') {
                    DB::table('tenant_deletion_requests')->where('id', $request->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                    return;
                }

                $result = $this->purge((int) $company->id);
                DB::table('tenant_deletion_requests')->where('id', $request->id)->update(['status' => 'executed', 'executed_at' => now(), 'result' => json_encode($result), 'updated_at' => now()]);
                $purged++;
            });

        return ['purged' => $purged];
    }

    /**
     * Removes the company's rows from every table that carries its id, and the line tables that hang off those; keeps the company
     * row itself (inactive) as the record that it existed.
     *
     * @return array<string, int>
     */
    public function purge(int $companyId): array
    {
        $deleted = [];
        $children = [
            't_account_details' => ['t_accounts', 't_account_id'], 'sell_lines' => ['transactions', 'transaction_id'], 'purchase_lines' => ['transactions', 'transaction_id'],
        ];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($children as $table => [$parent, $key]) {
                if (Schema::hasTable($table) && Schema::hasTable($parent)) {
                    $deleted[$table] = DB::table($table)->whereIn($key, DB::table($parent)->where('company_id', $companyId)->select('id'))->delete();
                }
            }

            foreach (Schema::getTableListing() as $table) {
                $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

                if (in_array($name, ['companies', 'migrations', 'tenant_deletion_requests', 'tenant_data_exports', 'tenant_usage_snapshots'], true) || ! Schema::hasColumn($name, 'company_id')) {
                    continue;
                }

                $count = DB::table($name)->where('company_id', $companyId)->delete();

                if ($count > 0) {
                    $deleted[$name] = $count;
                }
            }

            DB::table('users')->where('company_id', $companyId)->delete();
            DB::table('companies')->where('id', $companyId)->update(['is_active' => false, 'updated_at' => now()]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        foreach (Storage::disk('local')->files('tenant-exports') as $file) {
            if (str_starts_with(basename($file), 'company-'.$companyId.'-')) {
                Storage::disk('local')->delete($file);
            }
        }

        return $deleted;
    }
}
