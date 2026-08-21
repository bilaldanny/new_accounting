<?php

namespace App\Http\Controllers;

use App\Models\Warranty;
use App\Support\ImportResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarrantyController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function warrantyFormRules(): array
    {
        return [
            'name' => 'bail|required|string|min:2|max:200',
            'duration' => 'bail|required|integer|min:0|max:9999',
            'type' => 'bail|required|in:year,month,day',
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
        ];
    }

    public function index(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Warranty::query()
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
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $warranties = $query->paginate($show_record);

        if ($cur_page > $warranties->lastPage()) {
            Paginator::currentPageResolver(function () use ($warranties) {
                return $warranties->lastPage();
            });
            $warranties = $query->paginate($show_record);
        }

        $warranties->getCollection()->transform(function (Warranty $warranty) {
            $warranty->company_name = $warranty->company?->name;
            $warranty->duration_label = Warranty::formatDurationLabel($warranty->duration, $warranty->type);

            return $warranty;
        });

        $trash_count = Warranty::onlyTrashed()->count();

        return response()->json(['data' => $warranties, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => Warranty::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Warranty::resolveScopedId($request->company_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate($this->warrantyFormRules());

        DB::beginTransaction();
        try {
            Warranty::createWarranty($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'bail|required|string|min:2|max:200',
            'rows.*.duration' => 'bail|required|integer|min:0|max:9999',
            'rows.*.type' => 'nullable|in:year,month,day',
        ]);

        DB::beginTransaction();

        try {
            $created = 0;
            $updated = 0;

            foreach ($request->rows as $index => $row) {
                if (! is_array($row)) {
                    throw ValidationException::withMessages([
                        'rows' => ['Row '.($index + 1).' is invalid.'],
                    ]);
                }

                if (Warranty::upsertFromImport($row) === 'created') {
                    $created++;
                } else {
                    $updated++;
                }
            }

            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();

            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return ImportResponse::success(
            count($request->rows),
            $created,
            $updated,
            'warranty records'
        );
    }

    public function show($id)
    {
        $warranty = Warranty::findVisibleToCurrentUser((int) $id);

        if ($warranty === null) {
            abort(404);
        }

        return response()->json($warranty);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->warrantyFormRules());

        DB::beginTransaction();
        try {
            Warranty::updateWarranty($request, (int) $id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id)
    {
        if (deletepermission()) {
            $warranty = Warranty::findVisibleToCurrentUser((int) $id);

            if ($warranty === null) {
                abort(404);
            }

            Warranty::deleteWarranty((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Warranty::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $warrantyId) {
                    Warranty::deleteWarranty((int) $warrantyId);
                }

                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Warranty::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Warranty::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request)
    {
        $warranties = Warranty::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($warranties)) {
            DB::beginTransaction();
            try {
                foreach ($warranties as $warranty) {
                    if (isset($request->status)) {
                        $warranty->active = $request->status;
                    } else {
                        if ($warranty->active == false) {
                            $warranty->active = 'true';
                        } else {
                            $warranty->active = 'false';
                        }
                    }
                    $warranty->save();
                }
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        } else {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function restore_records(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Warranty::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Warranty::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function duplicate(Request $request)
    {
        DB::beginTransaction();
        try {
            $warranty = Warranty::findVisibleToCurrentUser((int) $request->id);

            if ($warranty === null) {
                abort(404);
            }

            $duplicator = $warranty->replicate();
            $duplicator->name = $this->duplicateWarrantyName($warranty->name, $warranty->company_id);
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateWarrantyName(string $name, mixed $companyId = null): string
    {
        $companyId = Warranty::resolveScopedId($companyId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Warranty::nameExists($candidate, null, $companyId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $warranties = Warranty::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->select('warranties.*', 'name as text')
            ->get();

        return response()->json($warranties);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Warranty::onlyTrashed()
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
            })
            ->orderBy($sort_by, $sort_type);

        Paginator::currentPageResolver(function () use ($cur_page) {
            return $cur_page;
        });

        $warranties = $query->paginate($show_record);

        if ($cur_page > $warranties->lastPage()) {
            Paginator::currentPageResolver(function () use ($warranties) {
                return $warranties->lastPage();
            });
            $warranties = $query->paginate($show_record);
        }

        $warranties->getCollection()->transform(function (Warranty $warranty) {
            $warranty->duration_label = Warranty::formatDurationLabel($warranty->duration, $warranty->type);

            return $warranty;
        });

        return response()->json(['data' => $warranties]);
    }
}
