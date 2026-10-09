<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TaxController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingOrMenuPermission('/tax');

        $status = $request->input('status', 'all');
        $search = $request->input('search', '');

        $query = Tax::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->integer('type')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->input('kind')))
            ->when($status !== 'all', fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($status === 'all', fn ($q) => $q->whereIn('status', [0, 1]))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'));

        $taxes = $this->paginateSorted($query, $request);

        $taxes->getCollection()->transform(function (Tax $tax) {
            $tax->company_name = $tax->company?->name;

            return $tax;
        });

        return response()->json([
            'data' => $taxes,
            'trash_count' => 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingOrMenuPermission('/tax/add');

        if ((int) $request->input('type') === 1) {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'sub_tax' => 'bail|required|array|min:1',
                'sub_tax.*' => 'integer',
                'compound' => 'nullable|boolean',
            ]);
        } else {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'percentage' => 'bail|required|numeric|min:0|max:100',
                'kind' => 'nullable|in:sales,withholding',
                'applies_on' => 'nullable|in:net,gross',
            ]);
        }

        $this->assertSubTaxes($request);

        Tax::storeFromRequest($request);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Tax::query()->visibleToCurrentUser()->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeCompanySettingOrMenuPermission('/tax/:id/edit');
        $tax = Tax::query()->visibleToCurrentUser()->findOrFail($id);

        if ((int) $request->input('type', $tax->type) === 1) {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'sub_tax' => 'bail|required|array|min:1',
                'sub_tax.*' => 'integer',
                'compound' => 'nullable|boolean',
            ]);
        } else {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'percentage' => 'bail|required|numeric|min:0|max:100',
                'kind' => 'nullable|in:sales,withholding',
                'applies_on' => 'nullable|in:net,gross',
            ]);
        }

        $this->assertSubTaxes($request);

        $tax->updateFromRequest($request);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (! hasCompanySettingMenuPermission()) {
            return response()->json('406');
        }

        $tax = Tax::query()->visibleToCurrentUser()->findOrFail($id);
        $this->assertNotUsed($tax);
        $tax->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (! hasCompanySettingMenuPermission()) {
            return response()->json('406');
        }

        $taxes = Tax::query()->visibleToCurrentUser()->whereIn('id', (array) $request->all())->get();
        $taxes->each(fn (Tax $tax) => $this->assertNotUsed($tax));

        return $this->runInTransaction('Successfully Deleted', function () use ($taxes) {
            $taxes->each->delete();
        });
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingOrMenuPermission('/tax/:id/edit');
        $ids = $request->input('ids');

        if (! is_array($ids) || $ids === []) {
            $request->validate([
                'id' => 'required|integer|exists:taxes,id',
            ]);
            $ids = [$request->integer('id')];
        }

        $taxes = Tax::query()->visibleToCurrentUser()->whereIn('id', $ids)->get();

        if ($taxes->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();

        try {
            foreach ($taxes as $tax) {
                if ($request->has('status')) {
                    $tax->status = $request->boolean('status');
                } else {
                    $tax->status = ! $tax->status;
                }

                $tax->save();
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * Active taxes for a picker. By default the single sales taxes (what a group is made of); `with_groups=1` adds the groups,
     * `kind=withholding` lists the withholding taxes instead. Every row carries what a form needs to work out the tax itself:
     * the components in their order, and whether they stack.
     */
    public function fetch(Request $request): JsonResponse
    {
        $kind = $request->input('kind') === Tax::KIND_WITHHOLDING ? Tax::KIND_WITHHOLDING : Tax::KIND_SALES;
        $withGroups = $request->boolean('with_groups') && $kind === Tax::KIND_SALES;

        $taxes = Tax::query()
            ->visibleToCurrentUser()
            ->where('status', true)
            ->where('kind', $kind)
            ->when(! $withGroups, fn ($q) => $q->where('type', 0))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name')
            ->get();

        $singles = Tax::query()->whereIn('id', $taxes->where('type', 1)->flatMap->subTaxIds()->unique()->all())->get()->keyBy('id');

        return response()->json($taxes->map(fn (Tax $tax) => [
            'id' => $tax->id,
            'text' => $tax->name,
            'name' => $tax->name,
            'percentage' => $tax->percentage,
            'company_id' => $tax->company_id,
            'type' => $tax->type,
            'kind' => $tax->kind,
            'applies_on' => $tax->applies_on,
            'compound' => $tax->compound,
            'components' => $tax->type === 1
                ? collect($tax->subTaxIds())->map(fn (int $id) => $singles->get($id))->filter()->map(fn (Tax $sub) => ['id' => $sub->id, 'name' => $sub->name, 'percentage' => $sub->percentage])->values()->all()
                : [['id' => $tax->id, 'name' => $tax->name, 'percentage' => $tax->percentage]],
        ])->values());
    }

    /**
     * A group may only hold the single sales taxes of its own company.
     */
    private function assertSubTaxes(Request $request): void
    {
        if ((int) $request->input('type') !== 1) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('sub_tax', []))));
        $valid = Tax::query()
            ->whereIn('id', $ids)
            ->where('type', 0)
            ->where('kind', Tax::KIND_SALES)
            ->when($request->integer('company_id') > 0, fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->count();

        if ($valid !== count($ids)) {
            throw ValidationException::withMessages(['sub_tax' => ['A group can only hold the single sales taxes of its own company.']]);
        }
    }

    private function assertNotUsed(Tax $tax): void
    {
        if ($tax->isUsed()) {
            throw ValidationException::withMessages(['tax' => ["{$tax->name} is used on a sale or purchase, so it cannot be deleted. Switch it off instead."]]);
        }
    }
}
