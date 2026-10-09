<?php

namespace App\Http\Controllers;

use App\Models\FixedAssetDepreciation;
use App\Services\FixedAssetDepreciationEngine as DepreciationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Depreciation Engine screen: preview what is due up to a month, run it, and list what was booked.
 */
class FixedAssetDepreciationController extends Controller
{
    public function __construct(private readonly DepreciationEngine $engine) {}

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/depreciation');

        [$companyId, $period, $filters] = $this->input($request);
        $rows = $this->engine->preview($companyId, $period, $filters);

        return response()->json(['data' => [
            'period' => $period,
            'rows' => $rows,
            'total' => round((float) $rows->sum('amount'), 2),
        ]]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/depreciation/run');

        [$companyId, $period, $filters] = $this->input($request);

        return response()->json(['message' => 'Depreciation booked', 'data' => $this->engine->run($companyId, $period, $filters)]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/depreciation');

        $user = $request->user();

        $runs = FixedAssetDepreciation::query()
            ->with(['asset:id,code,name', 'voucher:id,voucher_no,status'])
            ->when(! $user?->hasRole('superadmin'), fn ($q) => $q->where('company_id', (int) $user?->company_id))
            ->when($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin'), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->input('company_id')))
            ->when($request->filled('period'), fn ($q) => $q->where('period', $request->input('period')))
            ->when($request->filled('asset_id'), fn ($q) => $q->where('fixed_asset_id', $request->input('asset_id')))
            ->orderByDesc('period')->orderBy('fixed_asset_id')
            ->paginate(min((int) ($request->input('show_record') ?: 50), 200));

        $runs->getCollection()->transform(fn (FixedAssetDepreciation $row) => [
            'id' => $row->id,
            'asset_id' => $row->fixed_asset_id,
            'code' => $row->asset?->code,
            'name' => $row->asset?->name,
            'period' => $row->period,
            'amount' => $row->amount,
            'opening_book_value' => $row->opening_book_value,
            'closing_book_value' => $row->closing_book_value,
            'voucher_no' => $row->voucher?->voucher_no,
            'voucher_status' => $row->voucher?->status,
        ]);

        return response()->json(['data' => $runs]);
    }

    /**
     * @return array{0: int, 1: string, 2: array{branch_id: int|null, category_id: int|null}}
     */
    private function input(Request $request): array
    {
        $user = $request->user();
        $companyId = (int) ($user?->company_id ?: $request->integer('company_id'));

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', 'before_or_equal:'.now()->format('Y-m')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->where('company_id', $companyId)],
        ]);

        $branchId = $data['branch_id'] ?? null;

        if ($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin')) {
            $branchId = (int) $user->branch_id;
        }

        return [$companyId, $data['period'], ['branch_id' => $branchId, 'category_id' => $data['category_id'] ?? null]];
    }
}
