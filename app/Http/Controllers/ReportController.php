<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function fetchLedger(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|integer',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|string',
            'start_date' => 'nullable|string',
            'end_date' => 'nullable|string',
        ]);

        $contact = Contact::findVisibleSupplier((int) $request->contact_id);

        if ($contact === null) {
            abort(404);
        }

        $fromDate = $this->parseLedgerDate($request->start_date, now()->startOfMonth());
        $toDate = $this->parseLedgerDate($request->end_date, now());

        $transactionRaw = $this->transactionScopeRaw($contact, $fromDate, $toDate);

        $totalPurchase = $this->sumTransactions($transactionRaw, 'purchaseorder');
        $totalPaidPurchase = $this->sumPaidTransactions($transactionRaw, 'purchaseorder');
        $totalSell = $this->sumTransactions($transactionRaw, 'sell');
        $totalPaidSell = $this->sumPaidTransactions($transactionRaw, 'sell');

        $taccount = [];

        if (
            Schema::hasTable('t_account_details')
            && Schema::hasTable('t_accounts')
            && filled($contact->supplier_gl_id)
        ) {
            $taccountQuery = DB::table('t_account_details')
                ->join('t_accounts as taccount', 'taccount.id', '=', 't_account_details.t_account_id')
                ->leftJoin('branches', 'branches.id', '=', 't_account_details.branch_id')
                ->where('taccount.company_id', $contact->company_id)
                ->whereBetween('taccount.voucher_date', [$fromDate->toDateString(), $toDate->toDateString()])
                ->where('taccount.status', 'approved')
                ->where('t_account_details.account_code', $contact->supplier_gl_id);

            if ($request->filled('branch_id') && $request->branch_id !== 'all') {
                $taccountQuery->where('taccount.branch_id', $request->branch_id);
            }

            $taccount = $taccountQuery
                ->orderBy('taccount.voucher_date')
                ->select([
                    't_account_details.id',
                    't_account_details.debit',
                    't_account_details.credit',
                    't_account_details.description',
                    't_account_details.acc_nature',
                    't_account_details.highlight',
                    'taccount.voucher_date',
                    'taccount.voucher_no',
                    'taccount.ref_no',
                    'branches.name as branch_name',
                ])
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'voucher_date' => $row->voucher_date,
                        'voucher_no' => $row->voucher_no,
                        'ref_no' => $row->ref_no,
                        'description' => $row->description,
                        'debit' => $row->debit,
                        'credit' => $row->credit,
                        'acc_nature' => $row->acc_nature,
                        'highlight' => (int) $row->highlight,
                        'balance_amount' => 0,
                        'branch' => ['name' => $row->branch_name],
                        'transaction' => ['parent' => ['payment_status' => '-']],
                        'type' => '-',
                        'cheque_no' => '-',
                    ];
                })
                ->values()
                ->all();
        }

        return response()->json([
            'taccount' => $taccount,
            'openingbalance' => 0,
            'total_purchase' => $totalPurchase,
            'total_paid_purchase' => $totalPaidPurchase,
            'total_sell' => $totalSell,
            'total_paid_sell' => $totalPaidSell,
        ]);
    }

    private function parseLedgerDate(?string $value, CarbonInterface $fallback): CarbonInterface
    {
        if ($value === null || $value === '') {
            return $fallback->copy();
        }

        $normalized = str_replace('/', '-', $value);

        return Carbon::parse($normalized);
    }

    /**
     * @return array{company_id: int|string, branch_id: int|string, contact_id: int, from: string, to: string}
     */
    private function transactionScopeRaw(Contact $contact, CarbonInterface $fromDate, CarbonInterface $toDate): array
    {
        return [
            'company_id' => $contact->company_id,
            'branch_id' => $contact->branch_id,
            'contact_id' => $contact->id,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
        ];
    }

    /**
     * @param  array{company_id: int|string, branch_id: int|string, contact_id: int, from: string, to: string}  $scope
     */
    private function sumTransactions(array $scope, string $type): float
    {
        if (! Schema::hasTable('transactions')) {
            return 0;
        }

        return (float) DB::table('transactions')
            ->where('company_id', $scope['company_id'])
            ->where('branch_id', $scope['branch_id'])
            ->where('contact_id', $scope['contact_id'])
            ->where('type', $type)
            ->whereBetween('transaction_date', [$scope['from'], $scope['to']])
            ->sum('final_amount');
    }

    /**
     * @param  array{company_id: int|string, branch_id: int|string, contact_id: int, from: string, to: string}  $scope
     */
    private function sumPaidTransactions(array $scope, string $type): float
    {
        if (! Schema::hasTable('transactions')) {
            return 0;
        }

        $paid = (float) DB::table('transactions')
            ->where('company_id', $scope['company_id'])
            ->where('branch_id', $scope['branch_id'])
            ->where('contact_id', $scope['contact_id'])
            ->where('type', $type)
            ->where('payment_status', 'paid')
            ->whereBetween('transaction_date', [$scope['from'], $scope['to']])
            ->sum('final_amount');

        $partial = 0.0;

        if (Schema::hasTable('payments')) {
            $partial = (float) DB::table('transactions')
                ->join('payments', 'payments.transaction_id', '=', 'transactions.id')
                ->where('transactions.company_id', $scope['company_id'])
                ->where('transactions.branch_id', $scope['branch_id'])
                ->where('transactions.contact_id', $scope['contact_id'])
                ->where('transactions.type', $type)
                ->where('transactions.payment_status', 'partial')
                ->whereBetween('transactions.transaction_date', [$scope['from'], $scope['to']])
                ->sum('payments.amount');
        }

        return $paid + $partial;
    }
}
