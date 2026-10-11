<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ChartOfAccount;
use App\Models\Loan;
use App\Models\LoanScheduleRow;
use App\Models\TAccount;
use App\Services\AccountsVoucher;
use App\Services\LoanSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The loan and financing register. A loan has its repayment schedule (LoanSchedule) and, when it is booked, a disbursement voucher
 * (Dr bank / Cr the loan liability account). Paying an instalment posts Dr the loan account (principal) and Dr the interest expense
 * account / Cr the bank account, through the journal approval rule (AccountsVoucher). The outstanding principal is the sum of the
 * principal parts not yet paid.
 */
class LoanController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly AccountsVoucher $vouchers) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/loan');

        $loans = Loan::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->limit(200)->get();

        $rows = LoanScheduleRow::query()->whereIn('loan_id', $loans->pluck('id'))->get()->groupBy('loan_id');

        return response()->json(['data' => $loans->map(function (Loan $loan) use ($rows): array {
            $own = $rows->get($loan->id, collect());
            $next = $own->whereNull('paid_on')->sortBy('number')->first();

            return $loan->toArray() + [
                'outstanding_principal' => round((float) $own->whereNull('paid_on')->sum('principal'), 2),
                'paid_instalments' => $own->whereNotNull('paid_on')->count(),
                'next_due_on' => $next?->due_on?->toDateString(),
                'next_amount' => $next?->total,
            ];
        })->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/loan');

        $loan = Loan::query()->visibleToCurrentUser()->with('schedule')->findOrFail($id);
        $states = TAccount::query()->whereIn('id', $loan->schedule->pluck('t_account_id')->filter())->pluck('status', 'id');

        return response()->json($loan->toArray() + ['schedule' => $loan->schedule->map(fn (LoanScheduleRow $row): array => $row->toArray() + [
            'voucher_status' => $states[$row->t_account_id] ?? null,
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/loan/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:120',
            'lender' => 'nullable|string|max:120',
            'principal' => 'required|numeric|gt:0',
            'annual_rate' => 'required|numeric|min:0|max:100',
            'installments' => 'required|integer|min:1|max:600',
            'frequency' => ['required', Rule::in(array_keys(LoanSchedule::FREQUENCIES))],
            'start_date' => 'required|date_format:Y-m-d',
            'loan_account_id' => 'required|integer',
            'interest_account_id' => 'required|integer',
            'bank_account_id' => 'required|integer',
            'post_disbursement' => 'nullable|boolean',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        foreach (['loan_account_id', 'interest_account_id', 'bank_account_id'] as $field) {
            if (! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => ['Choose an account of this company.']]);
            }
        }

        if (count(array_unique([$data['loan_account_id'], $data['interest_account_id'], $data['bank_account_id']])) < 3) {
            throw ValidationException::withMessages(['loan_account_id' => ['The loan, interest and bank accounts must be three different accounts.']]);
        }

        $loan = DB::transaction(function () use ($data, $companyId, $branchId): Loan {
            $loan = Loan::query()->create(collect($data)->except(['company_id', 'branch_id', 'post_disbursement'])->all() + [
                'company_id' => $companyId, 'branch_id' => $branchId, 'status' => 'active', 'created_by' => Auth::id(),
            ]);

            foreach (LoanSchedule::build((float) $data['principal'], (float) $data['annual_rate'], (int) $data['installments'], $data['frequency'], $data['start_date']) as $row) {
                $loan->schedule()->create($row);
            }

            if ($data['post_disbursement'] ?? false) {
                $amount = round((float) $data['principal'], 2);
                $voucher = $this->vouchers->post(
                    $companyId, $branchId, $data['start_date'],
                    [
                        ['account_id' => (int) $data['bank_account_id'], 'debit' => $amount, 'description' => 'Loan received: '.$data['name']],
                        ['account_id' => (int) $data['loan_account_id'], 'credit' => $amount, 'description' => 'Loan received: '.$data['name']],
                    ],
                    'Loan disbursement: '.$data['name'],
                );
                $loan->update(['disbursement_t_account_id' => $voucher->id]);
            }

            return $loan;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $loan->load('schedule')], 201);
    }

    /**
     * Pays one instalment (the next unpaid one unless `row_id` names another).
     */
    public function pay(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/loan/pay');

        $data = $request->validate(['paid_on' => 'required|date_format:Y-m-d', 'row_id' => 'nullable|integer']);

        $loan = Loan::query()->visibleToCurrentUser()->findOrFail($id);

        if ($loan->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['This loan is closed.']]);
        }

        $row = LoanScheduleRow::query()->where('loan_id', $loan->id)->whereNull('paid_on')
            ->when(isset($data['row_id']), fn ($q) => $q->whereKey((int) $data['row_id']), fn ($q) => $q->orderBy('number'))
            ->first();

        if ($row === null) {
            throw ValidationException::withMessages(['row_id' => ['There is no unpaid instalment to pay.']]);
        }

        DB::transaction(function () use ($loan, $row, $data): void {
            $lines = [
                ['account_id' => (int) $loan->loan_account_id, 'debit' => (float) $row->principal, 'description' => "Loan {$loan->name}: instalment {$row->number} principal"],
            ];

            if ((float) $row->interest > 0) {
                $lines[] = ['account_id' => (int) $loan->interest_account_id, 'debit' => (float) $row->interest, 'description' => "Loan {$loan->name}: instalment {$row->number} interest"];
            }

            $lines[] = ['account_id' => (int) $loan->bank_account_id, 'credit' => (float) $row->total, 'description' => "Loan {$loan->name}: instalment {$row->number}"];

            $voucher = $this->vouchers->post((int) $loan->company_id, $loan->branch_id === null ? null : (int) $loan->branch_id, $data['paid_on'], $lines, "Loan {$loan->name}: instalment {$row->number}");

            $row->update(['paid_on' => $data['paid_on'], 't_account_id' => $voucher->id]);

            if (! LoanScheduleRow::query()->where('loan_id', $loan->id)->whereNull('paid_on')->exists()) {
                $loan->update(['status' => 'closed']);
            }
        });

        return response()->json(['message' => 'Instalment paid', 'data' => $loan->fresh('schedule')]);
    }
}
