<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Cashier;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cashier master: code, PIN and targets, and the PIN check the POS asks before a sale is tagged to a cashier. A wrong PIN is
 * counted; five in a row lock the cashier for 15 minutes. `performance` sets each cashier's sales of today and this month against
 * their targets.
 */
class CashierController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/cashier');

        return response()->json(['data' => Cashier::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name')
            ->get()
            ->map(fn (Cashier $cashier): array => $cashier->toArray() + ['has_pin' => $cashier->pin_hash !== null])
            ->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/cashier/add');
        $companyId = $this->scopeCompanyId($request);
        $data = $this->validated($request);

        if (Cashier::query()->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['This cashier code is already used.']]);
        }

        $cashier = Cashier::query()->create($this->attributes($data) + ['company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId)]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $cashier], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashier/:id/edit');

        $cashier = Cashier::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request, $cashier);

        if (Cashier::query()->where('company_id', $cashier->company_id)->where('code', $data['code'])->whereKeyNot($cashier->id)->exists()) {
            throw ValidationException::withMessages(['code' => ['This cashier code is already used.']]);
        }

        $cashier->update($this->attributes($data) + ($request->filled('pin') ? ['failed_attempts' => 0, 'locked_until' => null] : []));

        return response()->json(['message' => 'Successfully Saved', 'data' => $cashier->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashier/delete');

        Cashier::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * The POS asks this before tagging a sale: code and PIN in, the cashier (never the hash) out.
     */
    public function verifyPin(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/sell/pos');

        $data = $request->validate(['code' => 'required|string|max:30', 'pin' => 'required|string|max:20', 'company_id' => 'nullable|integer']);

        $companyId = $this->scopeCompanyId($request);
        $cashier = Cashier::query()->where('company_id', $companyId)->where('code', $data['code'])->where('is_active', true)->first();

        if ($cashier === null || $cashier->pin_hash === null) {
            throw ValidationException::withMessages(['code' => ['That code or PIN is not right.']]);
        }

        if ($cashier->locked_until !== null && $cashier->locked_until->isFuture()) {
            throw ValidationException::withMessages(['code' => ['This cashier is locked until '.$cashier->locked_until->format('H:i').'.']]);
        }

        if (! Hash::check($data['pin'], $cashier->pin_hash)) {
            $attempts = (int) $cashier->failed_attempts + 1;
            $cashier->update(['failed_attempts' => $attempts, 'locked_until' => $attempts >= 5 ? now()->addMinutes(15) : null]);

            throw ValidationException::withMessages(['code' => ['That code or PIN is not right.']]);
        }

        $cashier->update(['failed_attempts' => 0, 'locked_until' => null]);

        return response()->json(['data' => ['id' => $cashier->id, 'name' => $cashier->name, 'code' => $cashier->code]]);
    }

    public function performance(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/cashier');

        $companyId = $this->scopeCompanyId($request);
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $sales = fn (string $from) => DB::table('transactions')
            ->where('company_id', $companyId)->where('type', Transaction::TYPE_SELL)->whereNull('deleted_at')
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->whereNotNull('cashier_id')
            ->whereDate('transaction_date', '>=', $from)->whereDate('transaction_date', '<=', $today)
            ->groupBy('cashier_id')->selectRaw('cashier_id, sum(final_amount) as total, count(*) as sales')->get()->keyBy('cashier_id');

        $day = $sales($today);
        $month = $sales($monthStart);

        return response()->json(['data' => Cashier::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get()->map(function (Cashier $cashier) use ($day, $month): array {
            $dayTotal = round((float) ($day[$cashier->id]->total ?? 0), 2);
            $monthTotal = round((float) ($month[$cashier->id]->total ?? 0), 2);

            return [
                'id' => $cashier->id, 'name' => $cashier->name, 'code' => $cashier->code,
                'today' => $dayTotal, 'today_sales' => (int) ($day[$cashier->id]->sales ?? 0), 'daily_target' => $cashier->daily_target,
                'daily_percent' => $cashier->daily_target > 0 ? round($dayTotal / $cashier->daily_target * 100, 1) : null,
                'month' => $monthTotal, 'monthly_target' => $cashier->monthly_target,
                'monthly_percent' => $cashier->monthly_target > 0 ? round($monthTotal / $cashier->monthly_target * 100, 1) : null,
            ];
        })->values()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Cashier $existing = null): array
    {
        return $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:120',
            'code' => 'required|string|max:30',
            'pin' => ($existing === null ? 'required' : 'nullable').'|digits_between:4,8',
            'daily_target' => 'nullable|numeric|min:0',
            'monthly_target' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = collect($data)->only(['user_id', 'name', 'code', 'daily_target', 'monthly_target', 'is_active'])->all();

        if (! empty($data['pin'])) {
            $attributes['pin_hash'] = Hash::make((string) $data['pin']);
        }

        return $attributes;
    }
}
