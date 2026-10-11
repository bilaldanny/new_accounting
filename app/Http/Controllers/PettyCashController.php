<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ChartOfAccount;
use App\Models\PettyCashEntry;
use App\Models\PettyCashFund;
use App\Models\TAccount;
use App\Services\AccountsVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imprest petty cash. A fund is a float of a fixed size in a cash account. Spending posts Dr the expense account / Cr the fund's cash
 * account; replenishing posts Dr the cash account / Cr the bank or cash account it is topped up from, and may not take the fund past
 * its imprest amount. The vouchers go through the journal approval rule like any journal (AccountsVoucher). The fund's balance is what
 * was put in less what was spent, from its own entries.
 */
class PettyCashController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly AccountsVoucher $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash');

        $funds = PettyCashFund::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderBy('name')
            ->get();

        $totals = PettyCashEntry::query()->whereIn('fund_id', $funds->pluck('id'))
            ->selectRaw('fund_id, entry_type, sum(amount) as total')->groupBy('fund_id', 'entry_type')->get()
            ->groupBy('fund_id');

        $accounts = ChartOfAccount::query()->whereIn('id', $funds->pluck('cash_account_id'))->pluck('name', 'id');

        return response()->json(['data' => $funds->map(function (PettyCashFund $fund) use ($totals, $accounts): array {
            $balance = $this->balance($totals->get($fund->id));

            return $fund->toArray() + [
                'balance' => $balance,
                'to_replenish' => round(max((float) $fund->imprest_amount - $balance, 0), 2),
                'cash_account' => $accounts[$fund->cash_account_id] ?? null,
            ];
        })->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash/add');

        $data = $this->validatedFund($request);
        $companyId = $this->scopeCompanyId($request);
        $this->assertAccount($companyId, $data['cash_account_id'], 'cash_account_id');

        $fund = PettyCashFund::query()->create(collect($data)->except(['company_id', 'branch_id'])->all() + ['company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId)]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $fund], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash/:id/edit');

        $fund = PettyCashFund::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validatedFund($request);
        $this->assertAccount((int) $fund->company_id, $data['cash_account_id'], 'cash_account_id');

        if ((int) $data['cash_account_id'] !== (int) $fund->cash_account_id && PettyCashEntry::query()->where('fund_id', $fund->id)->exists()) {
            throw ValidationException::withMessages(['cash_account_id' => ['The cash account cannot change once the fund has entries.']]);
        }

        $fund->update(collect($data)->except(['company_id', 'branch_id'])->all());

        return response()->json(['message' => 'Successfully Saved', 'data' => $fund->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash/delete');

        $fund = PettyCashFund::query()->visibleToCurrentUser()->findOrFail($id);

        if (PettyCashEntry::query()->where('fund_id', $fund->id)->exists()) {
            throw ValidationException::withMessages(['fund' => ['A fund with entries cannot be deleted; switch it off instead.']]);
        }

        $fund->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function entries(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash');

        $fund = PettyCashFund::query()->visibleToCurrentUser()->findOrFail($id);
        $rows = PettyCashEntry::query()->where('fund_id', $fund->id)->orderByDesc('entry_date')->orderByDesc('id')->limit(300)->get();
        $accounts = ChartOfAccount::query()->whereIn('id', $rows->pluck('counter_account_id'))->pluck('name', 'id');
        $states = TAccount::query()->whereIn('id', $rows->pluck('t_account_id')->filter())->pluck('status', 'id');

        return response()->json(['data' => $rows->map(fn (PettyCashEntry $entry): array => $entry->toArray() + [
            'account' => $accounts[$entry->counter_account_id] ?? null,
            'voucher_status' => $states[$entry->t_account_id] ?? null,
        ])->values()]);
    }

    public function expense(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash/expense');

        $data = $request->validate([
            'entry_date' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|gt:0',
            'expense_account_id' => 'required|integer',
            'payee' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:255',
            'receipt_no' => 'nullable|string|max:60',
        ]);

        $fund = $this->activeFund($id);
        $this->assertAccount((int) $fund->company_id, $data['expense_account_id'], 'expense_account_id');

        if ((int) $data['expense_account_id'] === (int) $fund->cash_account_id) {
            throw ValidationException::withMessages(['expense_account_id' => ['Choose an expense account, not the fund\'s own cash account.']]);
        }

        $balance = $this->balance(PettyCashEntry::query()->where('fund_id', $fund->id)->selectRaw('entry_type, sum(amount) as total')->groupBy('entry_type')->get());
        $amount = round((float) $data['amount'], 2);

        if ($amount > $balance + 0.000001) {
            throw ValidationException::withMessages(['amount' => ["The fund holds {$balance}; replenish it before spending more."]]);
        }

        $entry = DB::transaction(function () use ($data, $fund, $amount): PettyCashEntry {
            $voucher = $this->vouchers->post(
                (int) $fund->company_id, $fund->branch_id === null ? null : (int) $fund->branch_id, $data['entry_date'],
                [
                    ['account_id' => (int) $data['expense_account_id'], 'debit' => $amount, 'description' => $data['description'] ?? 'Petty cash expense'],
                    ['account_id' => (int) $fund->cash_account_id, 'credit' => $amount, 'description' => $data['description'] ?? 'Petty cash expense'],
                ],
                trim('Petty cash '.$fund->name.': '.($data['payee'] ?? '').' '.($data['description'] ?? '')),
                (string) ($data['receipt_no'] ?? ''),
            );

            return PettyCashEntry::query()->create([
                'company_id' => $fund->company_id, 'branch_id' => $fund->branch_id, 'fund_id' => $fund->id, 'entry_type' => 'expense',
                'entry_date' => $data['entry_date'], 'payee' => $data['payee'] ?? null, 'description' => $data['description'] ?? null,
                'receipt_no' => $data['receipt_no'] ?? null, 'amount' => $amount, 'counter_account_id' => $data['expense_account_id'],
                't_account_id' => $voucher->id, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Expense recorded', 'data' => $entry], 201);
    }

    public function replenish(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash/replenish');

        $data = $request->validate([
            'entry_date' => 'required|date_format:Y-m-d',
            'source_account_id' => 'required|integer',
            'amount' => 'nullable|numeric|gt:0',
            'description' => 'nullable|string|max:255',
        ]);

        $fund = $this->activeFund($id);
        $this->assertAccount((int) $fund->company_id, $data['source_account_id'], 'source_account_id');

        if ((int) $data['source_account_id'] === (int) $fund->cash_account_id) {
            throw ValidationException::withMessages(['source_account_id' => ['Choose the bank or cash account the money comes from.']]);
        }

        $balance = $this->balance(PettyCashEntry::query()->where('fund_id', $fund->id)->selectRaw('entry_type, sum(amount) as total')->groupBy('entry_type')->get());
        $room = round((float) $fund->imprest_amount - $balance, 2);
        $amount = round((float) ($data['amount'] ?? $room), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['The fund is already at its imprest amount.']]);
        }

        if ($amount > $room + 0.000001) {
            throw ValidationException::withMessages(['amount' => ["The fund can take at most {$room} to stay at its imprest amount."]]);
        }

        $entry = DB::transaction(function () use ($data, $fund, $amount): PettyCashEntry {
            $voucher = $this->vouchers->post(
                (int) $fund->company_id, $fund->branch_id === null ? null : (int) $fund->branch_id, $data['entry_date'],
                [
                    ['account_id' => (int) $fund->cash_account_id, 'debit' => $amount, 'description' => $data['description'] ?? 'Petty cash replenishment'],
                    ['account_id' => (int) $data['source_account_id'], 'credit' => $amount, 'description' => $data['description'] ?? 'Petty cash replenishment'],
                ],
                'Petty cash '.$fund->name.' replenishment',
            );

            return PettyCashEntry::query()->create([
                'company_id' => $fund->company_id, 'branch_id' => $fund->branch_id, 'fund_id' => $fund->id, 'entry_type' => 'replenish',
                'entry_date' => $data['entry_date'], 'description' => $data['description'] ?? null, 'amount' => $amount,
                'counter_account_id' => $data['source_account_id'], 't_account_id' => $voucher->id, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Fund replenished', 'data' => $entry], 201);
    }

    /**
     * Removes an entry together with its voucher (the period lock decides whether the voucher's day is still open).
     */
    public function destroyEntry(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/pettycash/expense');

        $entry = PettyCashEntry::query()->visibleToCurrentUser()->findOrFail($id);

        DB::transaction(function () use ($entry): void {
            if ($entry->t_account_id !== null) {
                TAccount::query()->whereKey($entry->t_account_id)->get()->each->delete();
            }

            $entry->delete();
        });

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * @param  Collection<int, object>|null  $rows  entry_type / total rows of one fund
     */
    private function balance(?Collection $rows): float
    {
        $by = ($rows ?? collect())->pluck('total', 'entry_type');

        return round((float) ($by['replenish'] ?? 0) - (float) ($by['expense'] ?? 0), 2);
    }

    private function activeFund(int $id): PettyCashFund
    {
        $fund = PettyCashFund::query()->visibleToCurrentUser()->findOrFail($id);

        if (! $fund->is_active) {
            throw ValidationException::withMessages(['fund' => ['This fund is switched off.']]);
        }

        return $fund;
    }

    private function assertAccount(int $companyId, int $accountId, string $field): void
    {
        if (! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages([$field => ['Choose an account of this company.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFund(Request $request): array
    {
        return $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:120',
            'imprest_amount' => 'required|numeric|gt:0',
            'cash_account_id' => 'required|integer',
            'custodian_user_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
