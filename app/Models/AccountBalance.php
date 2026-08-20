<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AccountBalance extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'financial_id',
        'opening_balance',
        'acc_nature',
        'coa_id',
    ];

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    public static function createForContact(object $request, int $financialId, int $coaId, string $accNature): self
    {
        return self::query()->create([
            'company_id' => $request->company_id,
            'branch_id' => $request->branch_id,
            'financial_id' => $financialId,
            'opening_balance' => $request->opening_balance ?? 0,
            'acc_nature' => $accNature,
            'coa_id' => $coaId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public static function createAccountBalance(
        array $account,
        int $financialId,
        int $branchId,
        int $companyId,
    ): self {
        return self::query()->create([
            'company_id' => $companyId,
            'financial_id' => $financialId,
            'branch_id' => $branchId,
            'opening_balance' => (int) $account['opening_balance'],
            'acc_nature' => $account['acc_nature'],
            'coa_id' => $account['id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $account
     */
    public static function updateAccountBalance(
        int $id,
        array $account,
        int $financialId,
        int $branchId,
        int $companyId,
    ): self {
        $balance = self::query()->findOrFail($id);
        $balance->financial_id = $financialId;
        $balance->company_id = $companyId;
        $balance->branch_id = $branchId;
        $balance->opening_balance = (int) $account['opening_balance'];
        $balance->coa_id = $account['id'];
        $balance->acc_nature = $account['acc_nature'];
        $balance->save();

        return $balance;
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    public static function upsertOpeningBalances(
        int $companyId,
        int $branchId,
        int $financialId,
        array $accounts,
    ): void {
        foreach ($accounts as $account) {
            $existing = self::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('financial_id', $financialId)
                ->where('coa_id', $account['id'])
                ->first();

            if ($existing === null) {
                self::createAccountBalance($account, $financialId, $branchId, $companyId);
            } else {
                self::updateAccountBalance($existing->id, $account, $financialId, $branchId, $companyId);
            }
        }
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    public static function transactionAccountsWithBalances(
        int $companyId,
        int $branchId,
        int $financialId,
        int $accountId,
    ): Collection {
        $parent = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('id', $accountId)
            ->where('active', true)
            ->firstOrFail();

        $accounts = collect();

        $children = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('parent_id', $parent->id)
            ->where('active', true)
            ->get();

        foreach ($children as $child) {
            self::appendAccountWithBalance($child, $accounts, $companyId, $branchId, $financialId);
        }

        return $accounts
            ->filter(fn (ChartOfAccount $account) => $account->acc_type === 't')
            ->values();
    }

    /**
     * @param  Collection<int, ChartOfAccount>  $accounts
     */
    private static function appendAccountWithBalance(
        ChartOfAccount $account,
        Collection $accounts,
        int $companyId,
        int $branchId,
        int $financialId,
    ): void {
        $openingBalance = self::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('financial_id', $financialId)
            ->where('coa_id', $account->id)
            ->first();

        $account->opening_balance = $openingBalance?->opening_balance ?? 0;
        $account->acc_nature = $openingBalance?->acc_nature ?? $account->acc_nature;

        $accounts->push($account);

        $children = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('parent_id', $account->id)
            ->where('active', true)
            ->get();

        foreach ($children as $child) {
            self::appendAccountWithBalance($child, $accounts, $companyId, $branchId, $financialId);
        }
    }
}
