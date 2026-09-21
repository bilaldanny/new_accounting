<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Discount;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DiscountController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * What `apply` tells the caller for each rejection reason.
     *
     * @var array<string, string>
     */
    private const REJECTIONS = [
        'not_found' => 'This coupon code does not exist.',
        'inactive' => 'This discount is switched off.',
        'not_started' => 'This discount is not valid yet.',
        'expired' => 'This discount has expired.',
        'min_purchase' => 'The purchase is below the minimum amount for this discount.',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function discountFormRules(Request $request, ?int $ignoreId = null): array
    {
        $isPercentage = $request->input('discount_type') === Discount::TYPE_PERCENTAGE;

        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'name' => 'bail|required|min:3|max:200',
            'code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', $this->uniqueCodeRule($request, $ignoreId)],
            'discount_type' => ['required', Rule::in(Discount::TYPES)],
            'value' => ['bail', 'required', 'numeric', 'gt:0', $isPercentage ? 'max:100' : 'max:9999999.99'],
            'min_purchase_amount' => 'nullable|numeric|min:0|max:9999999.99',
            'max_discount_amount' => 'nullable|numeric|gt:0|max:9999999.99',
            'starts_at' => 'nullable|date_format:Y-m-d',
            'expires_at' => 'nullable|date_format:Y-m-d|after_or_equal:starts_at',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * A coupon code is unique among ALL of a company's discounts, trashed ones included (restoring a
     * discount must never bring back a second live rule with the same code), whatever its case.
     */
    private function uniqueCodeRule(Request $request, ?int $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request, $ignoreId): void {
            $code = strtoupper(trim((string) $value));

            if ($code === '') {
                return;
            }

            $taken = Discount::withTrashed()
                ->where('company_id', Discount::scopedCompanyId($request))
                ->where('code', $code)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($taken) {
                $fail('This coupon code is already used by another discount.');
            }
        };
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery(Discount::query(), $request)
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            });

        $discounts = $this->paginateSorted($query, $this->withSafeSort($request));

        $discounts->getCollection()->transform(function (Discount $discount) {
            $discount->company_name = $discount->company?->name;

            return $discount;
        });

        $trash_count = Discount::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $discounts, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/discount/add');

        $request->validate($this->discountFormRules($request));

        DB::beginTransaction();
        try {
            Discount::createDiscount($request);
            DB::commit();
        } catch (ValidationException|ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $discount = Discount::query()
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->find($id);

        if ($discount === null) {
            abort(404);
        }

        return response()->json($discount);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/discount/:id/edit');

        $request->validate($this->discountFormRules($request, (int) $id));

        DB::beginTransaction();
        try {
            Discount::updateDiscount($request, $id);
            DB::commit();
        } catch (ValidationException|ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/discount/delete')) {
            Discount::deleteDiscount($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/discount/delete', 'Successfully Deleted', function () use ($request) {
            Discount::query()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/discount/delete', 'Successfully Deleted', function () use ($request) {
            Discount::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/discount/restore', 'Successfully Restored', function () use ($request) {
            Discount::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function updatestatus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/discount/:id/edit');

        $discounts = Discount::query()
            ->visibleToCurrentUser()
            ->whereIn('id', (array) $request->ids)
            ->get();

        if ($discounts->isEmpty()) {
            return response()->json(['errormessage' => 'Something went wrong']);
        }

        DB::beginTransaction();
        try {
            foreach ($discounts as $discount) {
                $discount->is_active = isset($request->status) ? (bool) $request->status : ! $discount->is_active;
                $discount->save();
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
        $query = $this->listQuery(Discount::onlyTrashed(), $request);

        $discounts = $this->paginateSorted($query, $this->withSafeSort($request));

        $discounts->getCollection()->transform(function (Discount $discount) {
            $discount->company_name = $discount->company?->name;

            return $discount;
        });

        return response()->json(['data' => $discounts]);
    }

    /**
     * Prices a coupon code against a subtotal: the discount amount and the total after it, or a 422 with
     * the reason (`not_found`, `inactive`, `not_started`, `expired`, `min_purchase`). Nothing is saved;
     * open to every signed-in user of the company since it only reads that company's own rules.
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:50',
            'subtotal' => 'required|numeric|min:0|max:9999999.99',
            'company_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $companyId = $user?->hasRole('superadmin')
            ? Discount::resolveScopedId($request->company_id)
            : ($user?->company_id ? (int) $user->company_id : null);

        $discount = $companyId === null ? null : Discount::findByCode($data['code'], $companyId);
        $subtotal = (float) $data['subtotal'];
        $reason = $discount === null ? 'not_found' : $discount->rejectionReason($subtotal);

        if ($reason !== null) {
            return response()->json([
                'message' => self::REJECTIONS[$reason] ?? 'This discount cannot be used.',
                'reason' => $reason,
            ], 422);
        }

        $amount = $discount->discountFor($subtotal);

        return response()->json([
            'discount_id' => $discount->id,
            'name' => $discount->name,
            'code' => $discount->code,
            'discount_type' => $discount->discount_type,
            'value' => $discount->value,
            'discount_amount' => $amount,
            'total_after_discount' => round($subtotal - $amount, 2),
        ]);
    }

    /**
     * The discounts of the user's company that can be used today (active and inside their validity
     * window), for a dropdown.
     */
    public function fetch(Request $request): JsonResponse
    {
        $discounts = Discount::query()
            ->visibleToCurrentUser()
            ->validOn()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('discounts.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($discounts);
    }

    /**
     * @param  Builder<Discount>  $query
     * @return Builder<Discount>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with('company:id,name')
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('is_active', $status);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), Discount::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
