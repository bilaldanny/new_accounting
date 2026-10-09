<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CouponController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function couponFormRules(?int $couponId = null): array
    {
        $codeRule = Rule::unique('coupons', 'code');

        if ($couponId !== null) {
            $codeRule = $codeRule->ignore($couponId);
        }

        return [
            'code' => ['bail', 'required', 'string', 'max:50', $codeRule],
            'type' => ['bail', 'required', Rule::in(Coupon::TYPES)],
            'value' => 'bail|required|numeric|min:0',
            'max_redemptions' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/coupons');

        $query = Coupon::query()
            ->when($request->status && $request->status !== 'all', fn ($q) => $q->where('is_active', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%'.$request->search.'%'));

        $coupons = $this->paginateSorted($query, $this->withSafeSort($request));
        $trash_count = Coupon::onlyTrashed()->count();

        return response()->json(['data' => $coupons, 'trash_count' => $trash_count]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/coupons/add');
        $request->validate($this->couponFormRules());

        try {
            Coupon::createCoupon($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $coupon = Coupon::query()->find($id);

        if ($coupon === null) {
            abort(404);
        }

        return response()->json($coupon);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/coupons/:id/edit');
        $request->validate($this->couponFormRules((int) $id));

        try {
            Coupon::updateCoupon($request, $id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/coupons/delete')) {
            Coupon::deleteCoupon($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function fetch(): JsonResponse
    {
        $coupons = Coupon::query()
            ->where('is_active', true)
            ->select('coupons.*')
            ->selectRaw('code as text')
            ->orderBy('code')
            ->get()
            ->filter(fn (Coupon $coupon) => $coupon->isRedeemable())
            ->values();

        return response()->json($coupons);
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/coupons/delete', 'Successfully Deleted', function () use ($request) {
            Coupon::query()->whereIn('id', (array) $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/coupons/delete', 'Successfully Deleted', function () use ($request) {
            Coupon::onlyTrashed()->whereIn('id', (array) $request->all())->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/coupons/restore', 'Successfully Restored', function () use ($request) {
            Coupon::onlyTrashed()->whereIn('id', (array) $request->all())->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $query = Coupon::onlyTrashed()
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%'.$request->search.'%'));

        $coupons = $this->paginateSorted($query, $this->withSafeSort($request));

        return response()->json(['data' => $coupons]);
    }

    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), Coupon::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
