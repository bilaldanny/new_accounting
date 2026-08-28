<?php

namespace App\Http\Controllers;

use App\Models\AccountBalance;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FinancialYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountBalanceController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function storeRules(): array
    {
        $actor = Auth::user();

        return [
            'company_id' => $actor?->hasRole('superadmin') ? 'bail|required|integer' : 'nullable|integer',
            'branch_id' => ($actor?->hasRole('superadmin') || $actor?->hasRole('companyadmin'))
                ? 'bail|required|integer'
                : 'nullable|integer',
            'financial_id' => 'bail|required|integer',
            'accounts' => 'bail|required|array|min:1',
            'accounts.*.id' => 'bail|required|integer',
            'accounts.*.opening_balance' => 'bail|required|numeric',
            'accounts.*.acc_nature' => 'bail|required|in:cr,dr',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fetchBalanceRules(): array
    {
        $actor = Auth::user();

        return [
            'company_id' => $actor?->hasRole('superadmin') ? 'bail|required|integer' : 'nullable|integer',
            'branch_id' => ($actor?->hasRole('superadmin') || $actor?->hasRole('companyadmin'))
                ? 'bail|required|integer'
                : 'nullable|integer',
            'financial_id' => 'bail|required|integer',
            'account_id' => 'bail|required|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/opening-balance/add');

        $request->validate($this->storeRules());

        [$companyId, $branchId, $financialId] = $this->resolveOwnedOpeningBalanceScope($request);
        $this->assertAccountsBelongToScope((array) $request->input('accounts', []), $companyId, $branchId);

        DB::beginTransaction();

        try {
            AccountBalance::upsertOpeningBalances(
                $companyId,
                $branchId,
                $financialId,
                $request->input('accounts'),
            );

            DB::commit();
        } catch (ValidationException $exception) {
            DB::rollBack();

            throw $exception;
        } catch (Throwable $exception) {
            DB::rollBack();

            return response()->json(['errormessage' => $exception->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function fetchBalance(Request $request): JsonResponse
    {
        $request->validate($this->fetchBalanceRules());

        [$companyId, $branchId, $financialId] = $this->resolveOwnedOpeningBalanceScope($request);
        $this->assertAccountBelongsToScope($request->integer('account_id'), $companyId, $branchId);

        $accounts = AccountBalance::transactionAccountsWithBalances(
            $companyId,
            $branchId,
            $financialId,
            $request->integer('account_id'),
        );

        return response()->json($accounts);
    }

    /**
     * Superadmin may pass company/branch IDs (verified as owned together). Everyone else
     * is locked to Auth::user()->company_id, and to their branch unless they are companyadmin.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function resolveOwnedOpeningBalanceScope(Request $request): array
    {
        $actor = $request->user();

        if ($actor?->hasRole('superadmin')) {
            $companyId = $request->integer('company_id');
            $branchId = $request->integer('branch_id');
        } else {
            $companyId = (int) ($actor?->company_id ?? 0);

            if ($companyId < 1) {
                abort(403);
            }

            if ($actor?->hasRole('companyadmin')) {
                $branchId = $request->integer('branch_id');
            } else {
                $branchId = (int) ($actor?->branch_id ?? 0);
            }
        }

        if ($companyId < 1 || $branchId < 1) {
            abort(403);
        }

        $branchBelongsToCompany = Branch::query()
            ->where('id', $branchId)
            ->where('company_id', $companyId)
            ->exists();

        if (! $branchBelongsToCompany) {
            abort(403);
        }

        $financialBelongsToCompany = FinancialYear::query()
            ->where('id', $request->integer('financial_id'))
            ->where('company_id', $companyId)
            ->exists();

        if (! $financialBelongsToCompany) {
            abort(403);
        }

        return [$companyId, $branchId, $request->integer('financial_id')];
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function assertAccountsBelongToScope(array $accounts, int $companyId, int $branchId): void
    {
        $ids = collect($accounts)
            ->pluck('id')
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'accounts' => ['The accounts field is required.'],
            ]);
        }

        $ownedCount = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('id', $ids)
            ->count();

        if ($ownedCount !== $ids->count()) {
            throw ValidationException::withMessages([
                'accounts' => ['One or more accounts do not belong to the selected company and branch.'],
            ]);
        }
    }

    private function assertAccountBelongsToScope(int $accountId, int $companyId, int $branchId): void
    {
        $owned = ChartOfAccount::query()
            ->where('id', $accountId)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->exists();

        if (! $owned) {
            abort(403);
        }
    }
}
