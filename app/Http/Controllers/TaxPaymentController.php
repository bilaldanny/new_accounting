<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ChartOfAccount;
use App\Models\TAccount;
use App\Models\TaxPayment;
use App\Services\AccountsVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tax payments and their challans. Each payment is a record (tax type, period, challan number, bank, amount, date) backed by a
 * voucher: Dr the tax payable account / Cr the bank account, through the journal approval rule (AccountsVoucher). A challan number
 * is unique per company and tax type, so the same challan is not recorded twice.
 */
class TaxPaymentController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly AccountsVoucher $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/taxpayment');

        $rows = TaxPayment::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('tax_type'), fn ($q) => $q->where('tax_type', $request->input('tax_type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_on', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_on', '<=', $request->input('to')))
            ->orderByDesc('paid_on')->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        $states = TAccount::query()->whereIn('id', $rows->getCollection()->pluck('t_account_id')->filter())->pluck('status', 'id');
        $accounts = ChartOfAccount::query()->whereIn('id', $rows->getCollection()->pluck('tax_account_id'))->pluck('name', 'id');

        $rows->getCollection()->transform(function (TaxPayment $row) use ($states, $accounts): TaxPayment {
            $row->setAttribute('voucher_status', $states[$row->t_account_id] ?? null);
            $row->setAttribute('tax_account', $accounts[$row->tax_account_id] ?? null);

            return $row;
        });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/taxpayment/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'tax_type' => 'bail|required|string|max:60',
            'period' => 'nullable|string|max:30',
            'challan_no' => 'required|string|max:60',
            'bank_name' => 'nullable|string|max:120',
            'amount' => 'required|numeric|gt:0',
            'paid_on' => 'required|date_format:Y-m-d',
            'tax_account_id' => 'required|integer',
            'bank_account_id' => 'required|integer',
            'note' => 'nullable|string|max:1000',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        foreach (['tax_account_id', 'bank_account_id'] as $field) {
            if (! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => ['Choose an account of this company.']]);
            }
        }

        if ((int) $data['tax_account_id'] === (int) $data['bank_account_id']) {
            throw ValidationException::withMessages(['bank_account_id' => ['The bank account must differ from the tax account.']]);
        }

        if (TaxPayment::query()->where('company_id', $companyId)->where('tax_type', $data['tax_type'])->where('challan_no', $data['challan_no'])->exists()) {
            throw ValidationException::withMessages(['challan_no' => ['This challan number is already recorded for this tax.']]);
        }

        $payment = DB::transaction(function () use ($data, $companyId, $branchId): TaxPayment {
            $amount = round((float) $data['amount'], 2);
            $text = "{$data['tax_type']} {$data['period']} challan {$data['challan_no']}";
            $voucher = $this->vouchers->post(
                $companyId, $branchId, $data['paid_on'],
                [
                    ['account_id' => (int) $data['tax_account_id'], 'debit' => $amount, 'description' => trim($text)],
                    ['account_id' => (int) $data['bank_account_id'], 'credit' => $amount, 'description' => trim($text)],
                ],
                'Tax payment: '.trim($text),
                (string) $data['challan_no'],
            );

            return TaxPayment::query()->create(collect($data)->except(['company_id', 'branch_id'])->all() + [
                'company_id' => $companyId, 'branch_id' => $branchId, 't_account_id' => $voucher->id, 'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Tax payment recorded', 'data' => $payment], 201);
    }

    /**
     * Removes the record together with its voucher (the period lock decides whether the voucher's day is still open).
     */
    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/taxpayment/delete');

        $payment = TaxPayment::query()->visibleToCurrentUser()->findOrFail($id);

        DB::transaction(function () use ($payment): void {
            if ($payment->t_account_id !== null) {
                TAccount::query()->whereKey($payment->t_account_id)->get()->each->delete();
            }

            $payment->delete();
        });

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Totals by tax type for a date range: what was paid, for the year-end tax reconciliation.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/taxpayment');

        $rows = TaxPayment::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('paid_on', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('paid_on', '<=', $request->input('to')))
            ->selectRaw('tax_type, count(*) as payments, sum(amount) as total')->groupBy('tax_type')->orderBy('tax_type')->get();

        return response()->json(['data' => $rows->map(fn ($row): array => [
            'tax_type' => $row->tax_type, 'payments' => (int) $row->payments, 'total' => round((float) $row->total, 2),
        ])->values()]);
    }
}
