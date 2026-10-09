<?php

namespace App\Services;

use App\Models\PosShift;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The X report (a live shift) and, frozen at close, the Z report.
 *
 * What a shift covers: the sales its cashier created on its branch between opening and `$until` (drafts and
 * quotations left out), and the payments recorded against those sales in the same window (returns count
 * negative). Payments carry no cashier of their own, so the cashier is the one who created the sale; a
 * payment taken later by someone else against a sale of this window is not part of this shift.
 *
 * Expected cash = opening float + cash payments + pay-ins - pay-outs. Card, cheque and transfer payments are
 * listed by method but are not in the drawer.
 */
class PosShiftReport
{
    /**
     * @return array{opened_at: string, until: string, sales_count: int, sales_total: float, payments: array<string, float>, cash_payments: float, pay_in: float, pay_out: float, opening_float: float, expected_cash: float}
     */
    public function build(PosShift $shift, CarbonInterface $until): array
    {
        $sales = Transaction::query()
            ->where('type', Transaction::TYPE_SELL)
            ->where('company_id', $shift->company_id)
            ->where('branch_id', $shift->branch_id)
            ->where('created_by', $shift->user_id)
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)
            ->where('created_at', '>=', $shift->opened_at)
            ->where('created_at', '<=', $until);

        $payments = DB::table('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('t.type', Transaction::TYPE_SELL)
            ->where('t.branch_id', $shift->branch_id)
            ->where('t.created_by', $shift->user_id)
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->where('p.created_at', '>=', $shift->opened_at)
            ->where('p.created_at', '<=', $until)
            ->groupBy('p.method')
            ->selectRaw('p.method as method, SUM(CASE WHEN p.is_return = 1 THEN -p.amount ELSE p.amount END) as total')
            ->get()
            ->pluck('total', 'method');

        $byMethod = $payments->map(fn ($amount): float => round((float) $amount, 2))->all();
        $cash = (float) ($byMethod['cash'] ?? 0);

        $movements = $shift->movements()->selectRaw('type, SUM(amount) as total')->groupBy('type')->pluck('total', 'type');
        $payIn = round((float) ($movements['in'] ?? 0), 2);
        $payOut = round((float) ($movements['out'] ?? 0), 2);

        return [
            'opened_at' => $shift->opened_at->toDateTimeString(),
            'until' => $until->toDateTimeString(),
            'sales_count' => (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('final_amount'), 2),
            'payments' => $byMethod,
            'cash_payments' => $cash,
            'pay_in' => $payIn,
            'pay_out' => $payOut,
            'opening_float' => round((float) $shift->opening_float, 2),
            'expected_cash' => round((float) $shift->opening_float + $cash + $payIn - $payOut, 2),
        ];
    }
}
