<?php

namespace App\Http\Controllers;

use App\Models\AccountBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return [
            'company_id' => 'bail|required|integer',
            'branch_id' => 'bail|required|integer',
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
        return [
            'company_id' => 'bail|required|integer',
            'branch_id' => 'bail|required|integer',
            'financial_id' => 'bail|required|integer',
            'account_id' => 'bail|required|integer',
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate($this->storeRules());

        DB::beginTransaction();

        try {
            AccountBalance::upsertOpeningBalances(
                $request->integer('company_id'),
                $request->integer('branch_id'),
                $request->integer('financial_id'),
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

        $accounts = AccountBalance::transactionAccountsWithBalances(
            $request->integer('company_id'),
            $request->integer('branch_id'),
            $request->integer('financial_id'),
            $request->integer('account_id'),
        );

        return response()->json($accounts);
    }
}
