<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CurrencyController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function currencyFormRules(?int $exceptId = null): array
    {
        return [
            'currency_name' => 'bail|required|string|max:255',
            'code' => [
                'bail',
                'required',
                'string',
                'size:3',
                Rule::unique('currencies', 'code')->ignore($exceptId),
            ],
            'symbol' => 'bail|required|string|max:20',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Currency::query()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['currency_name', 'code', 'symbol'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $currencies = $query->paginate($showRecord);

        if ($curPage > $currencies->lastPage()) {
            Paginator::currentPageResolver(function () use ($currencies) {
                return $currencies->lastPage();
            });
            $currencies = $query->paginate($showRecord);
        }

        $trashCount = Currency::onlyTrashed()->count();

        return response()->json(['data' => $currencies, 'trash_count' => $trashCount]);
    }

    public function checkCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:3',
            'except_id' => 'nullable|integer',
        ]);

        return response()->json([
            'code_taken' => Currency::codeExists(
                $request->string('code')->toString(),
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge([
            'code' => Currency::normalizeCode($request->input('code')),
        ]);

        $request->validate($this->currencyFormRules());

        DB::beginTransaction();
        try {
            Currency::storeFromRequest($request);
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

    public function show(int $id): JsonResponse
    {
        $currency = Currency::query()->findOrFail($id);

        return response()->json($currency);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'code' => Currency::normalizeCode($request->input('code')),
        ]);

        $request->validate($this->currencyFormRules($id));

        DB::beginTransaction();
        try {
            $currency = Currency::query()->findOrFail($id);
            $currency->updateFromRequest($request);
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

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission()) {
            Currency::deleteCurrency($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Currency::query()->whereIn('id', $request->all())->delete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Currency::query()->whereIn('id', (array) $request->all())->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission()) {
            DB::beginTransaction();
            try {
                Currency::query()->whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $currencies = Currency::query()->whereIn('id', $request->ids)->get();

        if ($currencies->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($currencies as $currency) {
                if (isset($request->status)) {
                    $currency->is_active = $request->status;
                } else {
                    $currency->is_active = ! $currency->is_active;
                }
                $currency->save();
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request): JsonResponse
    {
        $sortBy = $request->sort_by ?? 'created_at';
        $sortType = $request->sort_type ?? 'desc';
        $showRecord = $request->show_record ?? 10;
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';
        $curPage = $request->cur_page ?? 1;

        $query = Currency::onlyTrashed()
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($status === 'all', function ($q) {
                $q->whereIn('is_active', [0, 1]);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['currency_name', 'code', 'symbol'], 'like', "%{$search}%");
                });
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $currencies = $query->paginate($showRecord);

        if ($curPage > $currencies->lastPage()) {
            Paginator::currentPageResolver(function () use ($currencies) {
                return $currencies->lastPage();
            });
            $currencies = $query->paginate($showRecord);
        }

        return response()->json(['data' => $currencies]);
    }

    public function fetch(): JsonResponse
    {
        $currencies = Currency::query()
            ->where('is_active', true)
            ->select('currencies.*', DB::raw("CONCAT(currency_name, ' - ', code, ' - ', symbol) AS text"))
            ->orderBy('currency_name')
            ->get();

        return response()->json($currencies);
    }
}
