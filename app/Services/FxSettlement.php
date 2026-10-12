<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\TAccountDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Settlement of a foreign-currency voucher line. A line against a customer or supplier that carries a foreign amount stays at the
 * rate it was entered at until it is settled; the cash or bank voucher that settles it is entered like any voucher (at the rate of
 * the day). The settlement records the foreign amount settled and posts the realised gain or loss, the difference between the base
 * amount the line was carried at and the base amount it settled at, to the company's exchange gain / loss account, clearing it
 * against the party's account so the party's ledger nets to nil:
 *
 *   gain:  Dr the party account / Cr exchange gain / loss   (more base currency than it was carried at)
 *   loss:  Dr exchange gain / loss / Cr the party account
 *
 * The same two lines serve a receivable and a payable (a rise in the rate that is a gain on one is a loss on the other, which is
 * already in the sign of the difference). A line that is not settled has no gain or loss: nothing is posted at entry.
 */
class FxSettlement
{
    public function __construct(private readonly AccountsVoucher $vouchers) {}

    public static function available(): bool
    {
        return MultiCurrency::installed() && Schema::hasTable('fx_settlements');
    }

    /**
     * The party lines in a foreign currency of a company with what is still open of each, in foreign currency.
     *
     * @return list<array<string, mixed>>
     */
    public function openLines(int $companyId): array
    {
        if (! self::available()) {
            return [];
        }

        $settled = DB::table('fx_settlements')->where('company_id', $companyId)->groupBy('t_account_detail_id')
            ->selectRaw('t_account_detail_id, sum(foreign_amount) as settled')->pluck('settled', 't_account_detail_id');

        return DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->leftJoin('currencies as c', 'c.id', '=', 'd.currency_id')
            ->leftJoin('contacts as k', 'k.id', '=', 'd.contact_id')
            ->where('a.company_id', $companyId)->where('a.status', 'approved')
            ->whereNotNull('d.currency_id')->whereNotNull('d.contact_id')
            ->orderBy('a.voucher_date')->orderBy('d.id')
            ->get(['d.id', 'a.voucher_no', 'a.voucher_date', 'd.account_code', 'd.debit', 'd.credit', 'd.currency_id', 'c.code as currency_code', 'd.exchange_rate', 'd.foreign_amount', 'd.contact_id', 'k.business_name', 'k.first_name', 'k.last_name'])
            ->map(function (object $row) use ($settled): array {
                $open = round((float) $row->foreign_amount - (float) ($settled[$row->id] ?? 0), 2);

                return [
                    'id' => (int) $row->id, 'voucher_no' => $row->voucher_no, 'voucher_date' => substr((string) $row->voucher_date, 0, 10),
                    'side' => (float) $row->debit > 0 ? 'debit' : 'credit', 'currency_id' => (int) $row->currency_id, 'currency_code' => $row->currency_code,
                    'exchange_rate' => (float) $row->exchange_rate, 'foreign_amount' => (float) $row->foreign_amount, 'open_foreign' => $open,
                    'base_amount' => round(max((float) $row->debit, (float) $row->credit), 2),
                    'contact' => trim((string) ($row->business_name ?: $row->first_name.' '.$row->last_name)),
                ];
            })
            ->filter(fn (array $row): bool => $row['open_foreign'] > 0)
            ->values()->all();
    }

    /**
     * @return array{id: int, fx_amount: float, base_original: float, base_settled: float, t_account_id: int|null}
     *
     * @throws ValidationException
     */
    public function settle(int $companyId, ?int $branchId, int $detailId, float $foreignAmount, float $settlementRate, string $date): array
    {
        if (! MultiCurrency::enabled($companyId) || ! self::available()) {
            throw ValidationException::withMessages(['detail_id' => ['Multi-currency vouchers are not switched on for this company.']]);
        }

        $accountId = (int) DB::table('company_settings')->where('company_id', $companyId)->value('fx_gain_loss_account_id');
        $fxAccount = $accountId === 0 ? null : ChartOfAccount::query()->where('company_id', $companyId)->find($accountId);

        if ($fxAccount === null) {
            throw ValidationException::withMessages(['detail_id' => ['Set the exchange gain / loss account first.']]);
        }

        return DB::transaction(function () use ($companyId, $branchId, $detailId, $foreignAmount, $settlementRate, $date, $fxAccount): array {
            $line = TAccountDetail::query()
                ->join('t_accounts', 't_accounts.id', '=', 't_account_details.t_account_id')
                ->where('t_accounts.company_id', $companyId)->where('t_accounts.status', 'approved')
                ->whereNotNull('t_account_details.currency_id')->whereNotNull('t_account_details.contact_id')
                ->where('t_account_details.id', $detailId)
                ->lockForUpdate()
                ->first(['t_account_details.*']);

            if ($line === null) {
                throw ValidationException::withMessages(['detail_id' => ['Choose an approved foreign-currency line against a customer or supplier of this company.']]);
            }

            $settledBefore = (float) DB::table('fx_settlements')->where('t_account_detail_id', $detailId)->sum('foreign_amount');
            $open = round((float) $line->foreign_amount - $settledBefore, 2);
            $foreignAmount = round($foreignAmount, 2);

            if ($foreignAmount <= 0 || $foreignAmount > $open + 0.000001) {
                throw ValidationException::withMessages(['foreign_amount' => ["The amount is more than is still open ({$open})."]]);
            }

            $originalRate = (float) $line->exchange_rate;
            $baseOriginal = round($foreignAmount / $originalRate, 2);
            $baseSettled = round($foreignAmount / $settlementRate, 2);
            $fx = round($baseSettled - $baseOriginal, 2);
            $voucher = null;

            if (abs($fx) >= 0.005) {
                $amount = abs($fx);
                $party = (int) $line->coa_id;
                $voucher = $this->vouchers->post(
                    $companyId, $branchId, $date,
                    $fx > 0
                        ? [['account_id' => $party, 'debit' => $amount, 'contact_id' => $line->contact_id], ['account_id' => (int) $fxAccount->id, 'credit' => $amount]]
                        : [['account_id' => (int) $fxAccount->id, 'debit' => $amount], ['account_id' => $party, 'credit' => $amount, 'contact_id' => $line->contact_id]],
                    'Exchange '.($fx > 0 ? 'gain' : 'loss').' on settlement of '.number_format($foreignAmount, 2),
                    'FX-'.$detailId,
                );
            }

            $id = DB::table('fx_settlements')->insertGetId([
                'company_id' => $companyId, 't_account_detail_id' => $detailId, 'currency_id' => $line->currency_id, 'foreign_amount' => $foreignAmount,
                'original_rate' => $originalRate, 'settlement_rate' => $settlementRate, 'base_original' => $baseOriginal, 'base_settled' => $baseSettled,
                'fx_amount' => $fx, 'settled_on' => $date, 't_account_id' => $voucher?->id, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => $id, 'fx_amount' => $fx, 'base_original' => $baseOriginal, 'base_settled' => $baseSettled, 't_account_id' => $voucher?->id];
        });
    }
}
