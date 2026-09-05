<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Currency;
use App\Services\CountryStateCityApiSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CurrencyController extends Controller
{
    use HandlesIndexAndBulkDelete;

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
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

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
            });

        $currencies = $this->paginateSorted($query, $request);

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

    public function fetchFromApi(CountryStateCityApiSync $sync): JsonResponse
    {
        $this->authorizeMenuPermission('/currency/add');

        set_time_limit(180);

        try {
            $result = $sync->syncCurrencies();
        } catch (RuntimeException $e) {
            return response()->json(['errormessage' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['errormessage' => 'Failed to fetch currencies from the API.'], 422);
        }

        return response()->json([
            'message' => "Fetched currencies from API. Created {$result['created']}, updated {$result['updated']}.",
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/currency/add');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
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
        $this->authorizeMenuPermission('/currency/:id/edit');

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

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission('/currency/delete')) {
            Currency::deleteCurrency($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/currency/delete', 'Successfully Deleted', function () use ($request) {
            Currency::query()->whereIn('id', $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/currency/delete', 'Successfully Deleted', function () use ($request) {
            Currency::query()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/currency/restore')) {
            DB::beginTransaction();
            try {
                Currency::query()->whereIn('id', $request->all())->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/currency/:id/edit');
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

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function trash(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

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
            });

        $currencies = $this->paginateSorted($query, $request);

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
