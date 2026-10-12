<?php

namespace App\Services\System;

use App\Services\Saas\TenantServices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first-run checklist of a company: the onboarding steps of the billing wave plus the settings a new company usually forgets
 * (currency, a tax, a print template, a way to send messages), each with the page where it is done. A step is "done" when the
 * company has the thing; a step the company does not need can be skipped, and the skip is remembered. Nothing here changes any
 * business data.
 */
class SetupWizard
{
    private const LINKS = [
        'company_profile' => '/company/setting', 'branch' => '/branch', 'users' => '/user', 'accounts' => '/chart-of-account', 'financial_year' => '/financialyear',
        'products' => '/product', 'customers' => '/customer', 'suppliers' => '/supplier', 'first_purchase' => '/purchase/add', 'first_sale' => '/sell/add',
        'currency' => '/company/setting', 'tax' => '/tax', 'print_template' => '/documentcenter', 'messaging' => '/messagingsetting', 'number_format' => '/businesssettings',
    ];

    public function __construct(private readonly TenantServices $onboarding) {}

    /**
     * @return array{steps: list<array{key: string, label: string, done: bool, skipped: bool, link: string}>, done: int, total: int, complete: bool}
     */
    public function steps(int $companyId): array
    {
        $has = fn (string $table, array $where = []): bool => Schema::hasTable($table) && DB::table($table)->where('company_id', $companyId)->where($where)->exists();
        $base = $this->onboarding->onboarding($companyId)['items'];
        $extra = [
            ['currency', 'Choose the base currency', (int) DB::table('company_settings')->where('company_id', $companyId)->value('currency_id') > 0],
            ['tax', 'Add the taxes you charge', $has('taxes')],
            ['print_template', 'Design your invoice (optional)', $has('document_templates', ['is_active' => 1])],
            ['messaging', 'Connect WhatsApp or SMS (optional)', $has('messaging_settings', ['is_active' => 1])],
            ['number_format', 'Choose how numbers are written', Schema::hasTable('business_settings') && array_key_exists('number_format', (array) json_decode((string) DB::table('business_settings')->where('company_id', $companyId)->value('settings'), true))],
        ];
        $skipped = $this->skipped($companyId);
        $steps = [];

        foreach (array_merge($base, array_map(fn (array $row): array => ['key' => $row[0], 'label' => $row[1], 'done' => (bool) $row[2]], $extra)) as $step) {
            $steps[] = $step + ['skipped' => ! $step['done'] && in_array($step['key'], $skipped, true), 'link' => self::LINKS[$step['key']] ?? '/dashboard'];
        }

        $done = count(array_filter($steps, fn (array $s): bool => $s['done']));

        return [
            'steps' => $steps, 'done' => $done, 'total' => count($steps),
            'complete' => count(array_filter($steps, fn (array $s): bool => ! $s['done'] && ! $s['skipped'])) === 0,
        ];
    }

    public function skip(int $companyId, string $key, bool $skip): void
    {
        abort_unless(collect($this->steps($companyId)['steps'])->contains('key', $key), 404);

        $current = array_values(array_diff($this->skipped($companyId), [$key]));

        if ($skip) {
            $current[] = $key;
        }

        $raw = (array) json_decode((string) DB::table('business_settings')->where('company_id', $companyId)->value('settings'), true);
        $raw['setup_skipped'] = array_values(array_unique($current));
        DB::table('business_settings')->updateOrInsert(['company_id' => $companyId], ['settings' => json_encode($raw), 'updated_at' => now(), 'created_at' => now()]);
        cache()->forget("business_settings:{$companyId}");
    }

    /**
     * @return list<string>
     */
    private function skipped(int $companyId): array
    {
        if (! Schema::hasTable('business_settings')) {
            return [];
        }

        return array_values((array) (json_decode((string) DB::table('business_settings')->where('company_id', $companyId)->value('settings'), true)['setup_skipped'] ?? []));
    }
}
