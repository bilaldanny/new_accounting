<?php

namespace App\Services\Manufacturing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing settings of a company, one JSON document with a default for every key. Ledger posting is off until a company turns it
 * on and maps its accounts; with it off, stock still moves (completed stock adjustments) and costs are still worked out and kept on
 * the work orders, but no voucher is made.
 */
class ManufacturingSettings
{
    public const ACCOUNT_SLOTS = ['raw_material_inventory', 'wip', 'finished_goods_inventory', 'labor_applied', 'overhead_applied', 'scrap_loss'];

    public const DEFAULTS = [
        'posting_enabled' => false,
        'require_qc_before_receipt' => false,
        'allow_over_issue' => true,
        'accounts' => ['raw_material_inventory' => null, 'wip' => null, 'finished_goods_inventory' => null, 'labor_applied' => null, 'overhead_applied' => null, 'scrap_loss' => null],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('manufacturing_settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function all(int $companyId): array
    {
        $saved = self::available() ? (array) json_decode((string) DB::table('manufacturing_settings')->where('company_id', $companyId)->value('settings'), true) : [];
        $values = array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
        $values['accounts'] = array_merge(self::DEFAULTS['accounts'], array_intersect_key((array) ($saved['accounts'] ?? []), self::DEFAULTS['accounts']));

        return $values;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function save(int $companyId, array $input): array
    {
        $current = $this->all($companyId);
        $errors = [];

        foreach (['posting_enabled', 'require_qc_before_receipt', 'allow_over_issue'] as $key) {
            if (array_key_exists($key, $input)) {
                if (! in_array($input[$key], [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                    $errors[$key] = ['Choose on or off.'];

                    continue;
                }

                $current[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        foreach (array_intersect_key((array) ($input['accounts'] ?? []), self::DEFAULTS['accounts']) as $slot => $accountId) {
            if ($accountId === null || $accountId === '') {
                $current['accounts'][$slot] = null;

                continue;
            }

            if (! DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', (int) $accountId)->exists()) {
                $errors["accounts.{$slot}"] = ['Choose an account of this company.'];

                continue;
            }

            $current['accounts'][$slot] = (int) $accountId;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::table('manufacturing_settings')->updateOrInsert(['company_id' => $companyId], ['settings' => json_encode($current), 'updated_at' => now(), 'created_at' => now()]);

        return $current;
    }
}
