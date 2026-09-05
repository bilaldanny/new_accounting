<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesBulkImport;
use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class BrandController extends Controller
{
    use HandlesBulkImport, HandlesIndexAndBulkDelete;

    /**
     * @return array<string, string>
     */
    protected function brandFormRules(): array
    {
        return [
            'name' => 'bail|required|string|min:3|max:200',
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
        ];
    }

    public function index(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Brand::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $brands = $this->paginateSorted($query, $request);

        $brands->getCollection()->transform(function (Brand $brand) {
            $brand->company_name = $brand->company?->name;

            return $brand;
        });

        $trash_count = Brand::onlyTrashed()->count();

        return response()->json(['data' => $brands, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => Brand::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Brand::resolveScopedId($request->company_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/brand/add');

        $request->validate($this->brandFormRules());

        DB::beginTransaction();
        try {
            Brand::createBrand($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function import(Request $request)
    {
        $this->authorizeMenuPermission('/brand/import');

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string|min:3|max:200',
        ]);

        return $this->importRows($request, Brand::class, 'brand records');
    }

    public function show($id)
    {
        $brand = Brand::findVisibleToCurrentUser((int) $id);

        if ($brand === null) {
            abort(404);
        }

        return response()->json($brand);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/brand/:id/edit');

        $request->validate($this->brandFormRules());

        DB::beginTransaction();
        try {
            Brand::updateBrand($request, (int) $id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id)
    {
        if (deletepermission('/brand/delete')) {
            $brand = Brand::findVisibleToCurrentUser((int) $id);

            if ($brand === null) {
                abort(404);
            }

            Brand::deleteBrand((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/brand/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Brand::query()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            foreach ($ids as $brandId) {
                Brand::deleteBrand((int) $brandId);
            }
        });
    }

    public function bulk_delete_per(Request $request)
    {
        return $this->guardedBulkAction('/brand/delete', 'Successfully Deleted', function () use ($request) {
            $ids = Brand::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->pluck('id');

            Brand::whereIn('id', $ids)->forceDelete();
        });
    }

    public function updatestatus(Request $request)
    {
        $this->authorizeMenuPermission('/brand/:id/edit');

        $brands = Brand::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($brands)) {
            DB::beginTransaction();
            try {
                foreach ($brands as $brand) {
                    if (isset($request->status)) {
                        $brand->active = $request->status;
                    } else {
                        if ($brand->active == false) {
                            $brand->active = 'true';
                        } else {
                            $brand->active = 'false';
                        }
                    }
                    $brand->save();
                }
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission('/brand/restore')) {
            DB::beginTransaction();
            try {
                $ids = Brand::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Brand::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request)
    {
        $this->authorizeMenuPermission('/brand/add');

        DB::beginTransaction();
        try {
            $brand = Brand::findVisibleToCurrentUser((int) $request->id);

            if ($brand === null) {
                abort(404);
            }

            $duplicator = $brand->replicate();
            $duplicator->name = $this->duplicateBrandName($brand->name, $brand->company_id);
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    private function duplicateBrandName(string $name, mixed $companyId = null): string
    {
        $companyId = Brand::resolveScopedId($companyId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Brand::nameExists($candidate, null, $companyId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $brands = Brand::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->select('brands.*', 'name as text')
            ->get();

        return response()->json($brands);
    }

    public function trash(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = Brand::onlyTrashed()
            ->visibleToCurrentUser()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%");
                });
            });

        $brands = $this->paginateSorted($query, $request);

        return response()->json(['data' => $brands]);
    }
}
