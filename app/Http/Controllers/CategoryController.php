<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Support\ImportResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CategoryController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function categoryFormRules(): array
    {
        return [
            'name' => 'bail|required|string|min:3|max:200',
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

        $query = Category::query()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'parent:id,name',
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

        $categories = $query->paginate($show_record);

        if ($cur_page > $categories->lastPage()) {
            Paginator::currentPageResolver(function () use ($categories) {
                return $categories->lastPage();
            });
            $categories = $query->paginate($show_record);
        }

        $categories->getCollection()->transform(function (Category $category) {
            $category->company_name = $category->company?->name;
            $category->parent_name = $category->parent?->name;

            return $category;
        });

        $trash_count = Category::onlyTrashed()->count();

        return response()->json(['data' => $categories, 'trash_count' => $trash_count]);
    }

    public function checkName(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
        ]);

        return response()->json([
            'name_taken' => Category::nameExists(
                $request->string('name')->toString(),
                $request->integer('except_id') ?: null,
                Category::resolveScopedId($request->company_id),
            ),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate($this->categoryFormRules());

        DB::beginTransaction();
        try {
            Category::createCategory($request);
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
            'rows.*.name' => 'bail|required|string|min:3|max:200',
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

                if (Category::upsertFromImport($row) === 'created') {
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
            'category records'
        );
    }

    public function show($id)
    {
        $category = Category::findVisibleToCurrentUser((int) $id);

        if ($category === null) {
            abort(404);
        }

        return response()->json($category);
    }

    public function update(Request $request, $id)
    {
        $request->validate($this->categoryFormRules());

        DB::beginTransaction();
        try {
            Category::updateCategory($request, (int) $id);
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
            $category = Category::findVisibleToCurrentUser((int) $id);

            if ($category === null) {
                abort(404);
            }

            Category::deleteCategory((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                $ids = Category::query()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $categoryId) {
                    Category::deleteCategory((int) $categoryId);
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
                $ids = Category::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Category::whereIn('id', $ids)->forceDelete();
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
        $categories = Category::query()
            ->visibleToCurrentUser()
            ->whereIn('id', $request->ids)
            ->get();

        if (isset($categories)) {
            DB::beginTransaction();
            try {
                foreach ($categories as $category) {
                    if (isset($request->status)) {
                        $category->active = $request->status;
                    } else {
                        if ($category->active == false) {
                            $category->active = 'true';
                        } else {
                            $category->active = 'false';
                        }
                    }
                    $category->save();
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
                $ids = Category::query()
                    ->onlyTrashed()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Category::whereIn('id', $ids)->restore();
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
            $category = Category::findVisibleToCurrentUser((int) $request->id);

            if ($category === null) {
                abort(404);
            }

            $duplicator = $category->replicate();
            $duplicator->name = $this->duplicateCategoryName($category->name, $category->company_id);
            $duplicator->save();
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }
    }

    private function duplicateCategoryName(string $name, mixed $companyId = null): string
    {
        $companyId = Category::resolveScopedId($companyId);
        $candidate = $name.' Copy';
        $suffix = 1;

        while (Category::nameExists($candidate, null, $companyId)) {
            $suffix++;
            $candidate = $name.' Copy '.$suffix;
        }

        return $candidate;
    }

    public function fetch(Request $request)
    {
        $categories = Category::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->whereNull('parent_id')
            ->when($request->filled('except_id'), function ($q) use ($request) {
                $q->where('id', '!=', $request->except_id);
            })
            ->select('categories.*', 'name as text')
            ->get();

        return response()->json($categories);
    }

    public function fetchsub(Request $request)
    {
        $categories = Category::query()
            ->visibleToCurrentUser()
            ->where('active', '=', 1)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $q->where('parent_id', $request->category_id);
            })
            ->select('categories.*', 'name as text')
            ->get();

        return response()->json($categories);
    }

    public function trash(Request $request)
    {
        $sort_by = $request->sort_by ?? 'created_at';
        $sort_type = $request->sort_type ?? 'desc';
        $show_record = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $cur_page = $request->cur_page ?? 1;

        $query = Category::onlyTrashed()
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

        $categories = $query->paginate($show_record);

        if ($cur_page > $categories->lastPage()) {
            Paginator::currentPageResolver(function () use ($categories) {
                return $categories->lastPage();
            });
            $categories = $query->paginate($show_record);
        }

        return response()->json(['data' => $categories]);
    }
}
