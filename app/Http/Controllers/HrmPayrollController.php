<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Hrm\PayrollEngine;
use App\Services\Hrm\PayrollPosting;
use App\Services\Hrm\PayslipRenderer;
use App\Services\Reports\ReportScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Payroll: bonuses and employee loans / advances, the monthly run (preview, create, recalculate, approve, post, cancel), the payslip
 * of a line and the bank salary file. Preparing needs `/hrm/payroll`; approving and posting to the ledger need the separate
 * `/hrm/payroll/approve`. Nothing posts to the ledger unless the company turned posting on and mapped its accounts (HR settings).
 */
class HrmPayrollController extends Controller
{
    use ScopesToCompany;

    public const BANK_FORMATS = ['generic', 'ibft'];

    public function __construct(private readonly PayrollEngine $engine, private readonly PayrollPosting $posting) {}

    // ---------------------------------------------------------------- bonuses

    public function bonuses(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('employee_bonuses as b')->join('employees as e', 'e.id', '=', 'b.employee_id')->where('b.company_id', $companyId)
            ->when($request->filled('period'), fn ($q) => $q->where('b.period', (string) $request->query('period')))
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->orderByDesc('b.id')->limit(500)->get(['b.id', 'b.employee_id', 'e.employee_no', 'e.first_name', 'e.last_name', 'b.period', 'b.title', 'b.amount', 'b.is_taxable', 'b.payroll_run_id'])]);
    }

    public function saveBonus(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'employee_id' => 'required|integer', 'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'title' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01|max:100000000', 'is_taxable' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);
        $this->employee($companyId, (int) $data['employee_id']);

        if (DB::table('payroll_runs')->where('company_id', $companyId)->where('period', $data['period'])->whereIn('status', ['approved', 'posted'])->exists()) {
            throw ValidationException::withMessages(['period' => ['Payroll of this month is already approved. Cancel it to add a bonus.']]);
        }

        $id = DB::table('employee_bonuses')->insertGetId([
            'company_id' => $companyId, 'employee_id' => (int) $data['employee_id'], 'period' => $data['period'], 'title' => $data['title'], 'amount' => round((float) $data['amount'], 2),
            'is_taxable' => (bool) ($data['is_taxable'] ?? true), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function deleteBonus(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $row = DB::table('employee_bonuses')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->first();
        abort_if($row === null, 404);

        if ($row->payroll_run_id !== null) {
            throw ValidationException::withMessages(['bonus' => ['This bonus was taken into an approved payroll.']]);
        }

        DB::table('employee_bonuses')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- loans and advances

    public function loans(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('employee_loans as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->where('l.company_id', $companyId)
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->orderByDesc('l.id')->limit(500)->get(['l.id', 'l.employee_id', 'e.employee_no', 'e.first_name', 'e.last_name', 'l.kind', 'l.amount', 'l.installment', 'l.start_period', 'l.balance', 'l.status', 'l.note'])]);
    }

    public function saveLoan(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'employee_id' => 'required|integer', 'kind' => ['required', Rule::in(['loan', 'advance'])], 'amount' => 'required|numeric|min:0.01|max:100000000',
            'installment' => 'required|numeric|min:0.01|max:100000000', 'start_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'note' => 'nullable|string|max:200', 'company_id' => 'nullable|integer',
        ]);
        $this->employee($companyId, (int) $data['employee_id']);
        $amount = round((float) $data['amount'], 2);

        $id = DB::table('employee_loans')->insertGetId([
            'company_id' => $companyId, 'employee_id' => (int) $data['employee_id'], 'kind' => $data['kind'], 'amount' => $amount, 'installment' => min($amount, round((float) $data['installment'], 2)),
            'start_period' => $data['start_period'], 'balance' => $amount, 'status' => 'active', 'note' => $data['note'] ?? null, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function closeLoan(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $affected = DB::table('employee_loans')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->where('status', 'active')->update(['status' => 'closed', 'updated_at' => now()]);
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Loan closed']);
    }

    // ---------------------------------------------------------------- runs

    public function runs(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('payroll_runs')->where('company_id', $companyId)->orderByDesc('period')->orderByDesc('id')->limit(120)->get()
            ->map(fn (object $row): array => $this->presentRun($row))->all()]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $result = $this->engine->calculate($companyId, (string) $request->query('period'), $this->visibleBranch());

        return response()->json(['data' => $result]);
    }

    public function showRun(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $run = $this->engine->run($id, $companyId);
        $lines = DB::table('payroll_lines as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->where('l.company_id', $companyId)->where('l.payroll_run_id', $id)
            ->orderBy('e.employee_no')->get(['l.id', 'l.employee_id', 'e.employee_no', 'e.first_name', 'e.last_name', 'l.basic', 'l.gross', 'l.attendance_deduction', 'l.statutory_employee', 'l.statutory_employer', 'l.tax', 'l.loan_deduction', 'l.net', 'l.accrual', 'l.detail'])
            ->map(fn (object $line): array => [
                'id' => (int) $line->id, 'employee_id' => (int) $line->employee_id, 'employee_no' => $line->employee_no, 'name' => trim($line->first_name.' '.$line->last_name),
                'basic' => (float) $line->basic, 'gross' => (float) $line->gross, 'attendance_deduction' => (float) $line->attendance_deduction, 'statutory_employee' => (float) $line->statutory_employee,
                'statutory_employer' => (float) $line->statutory_employer, 'tax' => (float) $line->tax, 'loan_deduction' => (float) $line->loan_deduction, 'net' => (float) $line->net, 'accrual' => (float) $line->accrual,
                'detail' => json_decode((string) $line->detail, true),
            ]);

        return response()->json(['data' => $this->presentRun($run) + ['lines' => $lines]]);
    }

    public function createRun(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'company_id' => 'nullable|integer']);
        $id = $this->engine->create($companyId, (string) $request->input('period'), $this->visibleBranch(), Auth::id());

        return response()->json(['message' => 'Payroll prepared', 'data' => ['id' => $id]], 201);
    }

    public function recalculate(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $this->engine->recalculate($id, $this->scopeCompanyId($request));

        return response()->json(['message' => 'Payroll recalculated']);
    }

    public function cancelRun(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $this->engine->cancel($id, $this->scopeCompanyId($request));

        return response()->json(['message' => 'Payroll cancelled']);
    }

    public function approveRun(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll/approve');
        $companyId = $this->scopeCompanyId($request);
        $this->engine->approve($id, $companyId, Auth::id());

        return response()->json(['message' => 'Payroll approved']);
    }

    public function postRun(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll/approve');
        $voucherId = $this->posting->post($id, $this->scopeCompanyId($request));

        return response()->json(['message' => 'Payroll posted to the ledger', 'data' => ['voucher_id' => $voucherId]]);
    }

    // ---------------------------------------------------------------- payslip and bank file

    public function payslip(Request $request, int $lineId): Response
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $this->line($companyId, $lineId);
        $language = $request->query('lang') === 'ur' ? 'ur' : 'en';

        return response(app(PayslipRenderer::class)->render($lineId, $companyId, $language), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function emailPayslip(Request $request, int $lineId): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/payroll');
        $companyId = $this->scopeCompanyId($request);
        $line = $this->line($companyId, $lineId);
        $email = (string) DB::table('employees')->where('company_id', $companyId)->where('id', $line->employee_id)->value('email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['email' => ['This employee has no valid email address.']]);
        }

        if ($line->run_status === 'draft' || $line->run_status === 'cancelled') {
            throw ValidationException::withMessages(['status' => ['Approve the payroll before sending payslips.']]);
        }

        $html = app(PayslipRenderer::class)->render($lineId, $companyId);

        try {
            Mail::html($html, fn ($message) => $message->to($email)->subject('Payslip '.$line->period));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['email' => ['The payslip could not be sent: '.mb_substr($e->getMessage(), 0, 120)]]);
        }

        return response()->json(['message' => 'Payslip sent to '.$email]);
    }

    /**
     * Salary transfer instructions of an approved or posted run as CSV. `generic` lists employee, bank, account and amount; `ibft`
     * is the beneficiary / account / bank / amount / reference layout. Employees without a bank account or with nothing to pay are
     * left out and counted in `X-Skipped`. Bank file layouts differ between banks: check the layout with your bank before uploading.
     */
    public function bankFile(Request $request, int $id): Response
    {
        $this->authorizeMenuPermission('/hrm/payroll/approve');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['format' => ['nullable', Rule::in(self::BANK_FORMATS)]]);
        $run = $this->engine->run($id, $companyId);

        if (! in_array($run->status, ['approved', 'posted'], true)) {
            throw ValidationException::withMessages(['status' => ['Approve the payroll before making the bank file.']]);
        }

        $format = (string) $request->query('format', 'generic');
        $lines = DB::table('payroll_lines as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->where('l.company_id', $companyId)->where('l.payroll_run_id', $id)
            ->orderBy('e.employee_no')->get(['e.employee_no', 'e.first_name', 'e.last_name', 'e.cnic', 'e.bank_name', 'e.bank_account', 'l.net']);
        $skipped = 0;
        $rows = collect();

        foreach ($lines as $line) {
            if ((float) $line->net <= 0 || trim((string) $line->bank_account) === '') {
                $skipped++;

                continue;
            }

            $name = trim($line->first_name.' '.$line->last_name);
            $rows->push($format === 'ibft'
                ? ['name' => $name, 'account' => $line->bank_account, 'bank' => $line->bank_name, 'amount' => number_format((float) $line->net, 2, '.', ''), 'reference' => 'Salary '.$run->period.' '.$line->employee_no]
                : ['employee_no' => $line->employee_no, 'name' => $name, 'cnic' => $line->cnic, 'bank' => $line->bank_name, 'account' => $line->bank_account, 'amount' => number_format((float) $line->net, 2, '.', ''), 'narration' => 'Salary '.$run->period]);
        }

        $headers = $format === 'ibft'
            ? ['name' => 'Beneficiary Name', 'account' => 'Beneficiary Account', 'bank' => 'Bank', 'amount' => 'Amount', 'reference' => 'Reference']
            : ['employee_no' => 'Employee No', 'name' => 'Name', 'cnic' => 'CNIC', 'bank' => 'Bank', 'account' => 'Account', 'amount' => 'Amount', 'narration' => 'Narration'];

        return response(app(ReportScheduler::class)->csv($headers, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="salary-'.$run->period.'-'.$format.'.csv"', 'X-Skipped' => (string) $skipped,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function presentRun(object $run): array
    {
        return [
            'id' => (int) $run->id, 'period' => $run->period, 'status' => $run->status, 'branch_id' => $run->branch_id === null ? null : (int) $run->branch_id,
            'totals' => json_decode((string) $run->totals, true) ?: [], 'voucher_id' => $run->voucher_id === null ? null : (int) $run->voucher_id,
            'created_by' => $run->created_by, 'approved_by' => $run->approved_by, 'approved_at' => $run->approved_at, 'posted_at' => $run->posted_at,
        ];
    }

    private function line(int $companyId, int $lineId): object
    {
        $line = DB::table('payroll_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')->where('l.company_id', $companyId)->where('l.id', $lineId)
            ->first(['l.id', 'l.employee_id', 'r.period', 'r.status as run_status']);
        abort_if($line === null, 404);
        $this->employee($companyId, (int) $line->employee_id);

        return $line;
    }

    private function visibleBranch(): ?int
    {
        $user = Auth::user();

        return $user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin') ? (int) $user->branch_id : null;
    }

    private function employee(int $companyId, int $id): object
    {
        $employee = DB::table('employees')->where('company_id', $companyId)->where('id', $id)->whereNull('deleted_at')
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('branch_id', $this->visibleBranch()))->first();
        abort_if($employee === null, 404);

        return $employee;
    }
}
