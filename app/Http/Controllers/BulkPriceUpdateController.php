<?php

namespace App\Http\Controllers;

use App\Services\BulkPriceUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Bulk Price Update wizard: preview a percentage, fixed or set change of one price field over a filtered set of
 * product variations, then apply it.
 */
class BulkPriceUpdateController extends Controller
{
    public function __construct(private readonly BulkPriceUpdate $update) {}

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bulkpriceupdate');

        $rows = $this->update->preview($this->validated($request));

        return response()->json([
            'data' => $rows->take(200)->values(),
            'total' => $rows->count(),
            'will_change' => $rows->filter(fn (array $row): bool => ! $row['skipped'] && $row['new_price'] !== $row['old_price'])->count(),
            'skipped' => $rows->where('skipped', true)->count(),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bulkpriceupdate/apply');

        $changed = $this->update->apply($this->validated($request));

        return response()->json(['message' => "{$changed} price(s) updated", 'changed' => $changed]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'field' => 'required|in:'.implode(',', BulkPriceUpdate::FIELDS),
            'mode' => 'required|in:'.implode(',', BulkPriceUpdate::MODES),
            'value' => 'required|numeric|min:-1000000|max:1000000',
            'round_to' => 'nullable|numeric|min:0.01|max:1000',
            'category_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'product_ids' => 'nullable|array|max:5000',
            'product_ids.*' => 'integer',
            'search' => 'nullable|string|max:200',
        ]);

        $user = Auth::user();

        if (! $user->hasRole('superadmin')) {
            $data['company_id'] = (int) $user->company_id;
        } elseif (empty($data['company_id'])) {
            abort(422, 'Choose a company.');
        }

        return $data;
    }
}
