<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class ChartOfAccountMapping extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'name',
        'key',
        'value',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $accountSetup
     */
    public static function syncFromRequest(Request $request, int $companyId, int $branchId, array $accountSetup): void
    {
        $existing = self::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('key');

        foreach ($accountSetup as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = (string) ($item['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $mapping = $existing->get($key) ?? new self([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'key' => $key,
            ]);

            $mapping->company_id = $companyId;
            $mapping->branch_id = $branchId;
            $mapping->name = (string) ($item['name'] ?? $mapping->name ?? $key);
            $mapping->key = $key;
            $mapping->value = $item['value'] ?? null;
            $mapping->save();
        }
    }

    /**
     * @return Collection<int, self>
     */
    public static function forBranch(int $companyId, int $branchId)
    {
        $mappings = self::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->orderBy('id')
            ->get();

        if ($mappings->isEmpty()) {
            accountMapping($companyId, $branchId);

            return self::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->orderBy('id')
                ->get();
        }

        return $mappings;
    }
}
