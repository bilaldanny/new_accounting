<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class TaxController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'all');
        $search = $request->input('search', '');

        $query = Tax::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->integer('type')))
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
        $this->authorizeCompanySettingMenuPermission();

        if ((int) $request->input('type') === 1) {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'sub_tax' => 'bail|required|array|min:1',
            ]);
        } else {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'percentage' => 'bail|required|integer|min:0',
            ]);
        }

        Tax::storeFromRequest($request);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Tax::query()->visibleToCurrentUser()->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeCompanySettingMenuPermission();
        $tax = Tax::query()->visibleToCurrentUser()->findOrFail($id);

        if ((int) $request->input('type', $tax->type) === 1) {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'sub_tax' => 'bail|required|array|min:1',
            ]);
        } else {
            $request->validate([
                'name' => 'bail|required|min:3|max:200',
                'percentage' => 'bail|required|integer|min:0',
            ]);
        }

        $tax->updateFromRequest($request);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (! hasCompanySettingMenuPermission()) {
            return response()->json('406');
        }

        Tax::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (! hasCompanySettingMenuPermission()) {
            return response()->json('406');
        }

        return $this->runInTransaction('Successfully Deleted', function () use ($request) {
            Tax::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $this->authorizeCompanySettingMenuPermission();
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

    public function fetch(Request $request): JsonResponse
    {
        $taxes = Tax::query()
            ->visibleToCurrentUser()
            ->where('status', true)
            ->where('type', 0)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'percentage'])
            ->map(fn (Tax $tax) => [
                'id' => $tax->id,
                'text' => $tax->name,
                'name' => $tax->name,
                'percentage' => $tax->percentage,
                'company_id' => $tax->company_id,
            ]);

        return response()->json($taxes);
    }
}
