<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Company;
use App\Services\AccountsVoucher;
use App\Support\Base64Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee expense claims: draft, submitted, approved or rejected by a user with `/expenseclaim/approve` (never the claimant, and
 * covered by the Approval Center and maker-checker through the `expense-claims` approve route), then paid by a user with
 * `/expenseclaim/pay`, which makes one expense voucher through AccountsVoucher (period lock, expense approval setting and numbering
 * apply). Submitting and approving change nothing in the books. A user sees their own claims; approvers and payers see them all.
 */
class ExpenseClaimController extends Controller
{
    use ScopesToCompany;

    private const DISK = 'local';

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim');
        $companyId = $this->scopeCompanyId($request);
        $status = (string) $request->query('status', '');

        return response()->json(['data' => DB::table('expense_claims as c')->leftJoin('employees as e', 'e.id', '=', 'c.employee_id')->where('c.company_id', $companyId)
            ->when(! $this->seesAll(), fn ($q) => $q->where('c.created_by', Auth::id()))
            ->when($status !== '', fn ($q) => $q->where('c.status', $status))->orderByDesc('c.id')->limit(500)
            ->get(['c.id', 'c.claim_no', 'c.title', 'c.claim_date', 'c.status', 'c.total', 'c.paid_on', 'c.employee_id', 'e.first_name', 'e.last_name'])]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->claim($companyId, $id);

        return response()->json(['data' => (array) $claim + [
            'lines' => DB::table('expense_claim_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')->where('l.expense_claim_id', $id)->orderBy('l.id')
                ->get(['l.id', 'l.expense_date', 'l.account_id', 'a.name as account', 'l.description', 'l.amount']),
            'receipts' => DB::table('expense_claim_receipts')->where('expense_claim_id', $id)->orderBy('id')->get(['id', 'file_name', 'ext', 'size']),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/add');
        $companyId = $this->scopeCompanyId($request);
        $data = $this->validated($request);
        [$lines, $total] = $this->lines($companyId, $data['lines']);

        $id = (int) DB::transaction(function () use ($companyId, $request, $data, $lines, $total): int {
            $next = (int) DB::table('expense_claims')->where('company_id', $companyId)->count() + 1;

            do {
                $number = 'EC-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
            } while (DB::table('expense_claims')->where('company_id', $companyId)->where('claim_no', $number)->exists());

            $id = (int) DB::table('expense_claims')->insertGetId([
                'company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId), 'claim_no' => $number, 'employee_id' => $this->employeeId($companyId, $data['employee_id'] ?? null),
                'title' => strip_tags($data['title']), 'claim_date' => $data['claim_date'] ?? now()->toDateString(), 'status' => 'draft', 'total' => $total, 'note' => isset($data['note']) ? strip_tags($data['note']) : null,
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->writeLines($companyId, $id, $lines);

            return $id;
        });

        return response()->json(['message' => 'Claim saved', 'data' => ['id' => $id]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/add');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->own($companyId, $id);

        if ($claim->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft claim can be edited.']]);
        }

        $data = $this->validated($request);
        [$lines, $total] = $this->lines($companyId, $data['lines']);

        DB::transaction(function () use ($companyId, $id, $data, $lines, $total): void {
            DB::table('expense_claims')->where('id', $id)->update([
                'title' => strip_tags($data['title']), 'claim_date' => $data['claim_date'] ?? now()->toDateString(), 'employee_id' => $this->employeeId($companyId, $data['employee_id'] ?? null),
                'total' => $total, 'note' => isset($data['note']) ? strip_tags($data['note']) : null, 'updated_at' => now(),
            ]);
            $this->writeLines($companyId, $id, $lines);
        });

        return response()->json(['message' => 'Claim saved']);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/add');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->own($companyId, $id);

        if ($claim->status !== 'draft' || (float) $claim->total <= 0) {
            throw ValidationException::withMessages(['status' => ['Only a draft claim with an amount can be submitted.']]);
        }

        DB::table('expense_claims')->where('id', $id)->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Claim submitted']);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/add');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->own($companyId, $id);

        if (! in_array($claim->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['status' => ['Only a draft or submitted claim can be cancelled.']]);
        }

        DB::table('expense_claims')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);

        return response()->json(['message' => 'Claim cancelled']);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, true);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, false);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/pay');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->claim($companyId, $id);
        $data = $request->validate(['payment_account_id' => 'required|integer', 'paid_on' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer']);

        if ($claim->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Only an approved claim can be paid.']]);
        }

        if (! DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', (int) $data['payment_account_id'])->exists()) {
            throw ValidationException::withMessages(['payment_account_id' => ['Choose the cash or bank account of this company.']]);
        }

        $date = $data['paid_on'] ?? now()->toDateString();
        $voucher = DB::transaction(function () use ($companyId, $claim, $id, $data, $date): int {
            $lines = DB::table('expense_claim_lines')->where('expense_claim_id', $id)->selectRaw('account_id, sum(amount) as amount')->groupBy('account_id')->get()
                ->map(fn (object $row): array => ['account_id' => (int) $row->account_id, 'debit' => round((float) $row->amount, 2), 'credit' => 0.0, 'description' => 'Expense claim '.$claim->claim_no])->all();
            $lines[] = ['account_id' => (int) $data['payment_account_id'], 'debit' => 0.0, 'credit' => round((float) $claim->total, 2), 'description' => 'Expense claim '.$claim->claim_no];

            $voucher = app(AccountsVoucher::class)->post($companyId, $claim->branch_id === null ? null : (int) $claim->branch_id, $date, $lines, 'Expense claim '.$claim->claim_no.': '.$claim->title, $claim->claim_no, 'EXP');
            DB::table('expense_claims')->where('id', $id)->update(['status' => 'paid', 'paid_on' => $date, 'payment_account_id' => (int) $data['payment_account_id'], 'voucher_id' => $voucher->id, 'updated_at' => now()]);

            return (int) $voucher->id;
        });

        return response()->json(['message' => 'Claim paid', 'data' => ['voucher_id' => $voucher]]);
    }

    public function uploadReceipt(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/add');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->own($companyId, $id);

        if (! in_array($claim->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['status' => ['Receipts are attached before the claim is decided.']]);
        }

        $request->validate(['file' => 'bail|required|file|max:5120']);
        $file = $request->file('file');
        $extension = Base64Upload::extensionForMime((string) $file->getMimeType());

        if ($extension === null) {
            throw ValidationException::withMessages(['file' => ['Upload a PDF or an image of the receipt.']]);
        }

        Company::query()->find($companyId)?->assertCanAddStorageBytes((int) $file->getSize());
        $path = $file->storeAs('expense-receipts/'.$companyId.'/'.$id, Str::ulid().'.'.$extension, self::DISK);
        $receiptId = DB::table('expense_claim_receipts')->insertGetId([
            'company_id' => $companyId, 'expense_claim_id' => $id, 'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180), 'path' => (string) $path, 'ext' => $extension,
            'size' => (int) $file->getSize(), 'uploaded_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Receipt uploaded', 'data' => ['id' => $receiptId]], 201);
    }

    public function downloadReceipt(Request $request, int $id, int $receiptId): StreamedResponse
    {
        $this->authorizeMenuPermission('/expenseclaim');
        $companyId = $this->scopeCompanyId($request);
        $this->claim($companyId, $id);
        $row = DB::table('expense_claim_receipts')->where('company_id', $companyId)->where('expense_claim_id', $id)->where('id', $receiptId)->first();
        abort_if($row === null || ! Storage::disk(self::DISK)->exists($row->path), 404);

        return Storage::disk(self::DISK)->download($row->path, $row->file_name);
    }

    // ---------------------------------------------------------------- helpers

    private function decide(Request $request, int $id, bool $approve): JsonResponse
    {
        $this->authorizeMenuPermission('/expenseclaim/approve');
        $companyId = $this->scopeCompanyId($request);
        $claim = $this->claim($companyId, $id);
        $request->validate(['note' => 'nullable|string|max:300']);

        if ($claim->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => ['Only a submitted claim can be decided.']]);
        }

        $own = (int) $claim->created_by === (int) Auth::id()
            || ($claim->employee_id !== null && (int) DB::table('employees')->where('id', $claim->employee_id)->value('user_id') === (int) Auth::id());

        if ($own) {
            throw ValidationException::withMessages(['status' => ['You cannot decide your own claim.']]);
        }

        DB::table('expense_claims')->where('id', $id)->update([
            'status' => $approve ? 'approved' : 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(), 'decision_note' => $request->filled('note') ? strip_tags((string) $request->input('note')) : null, 'updated_at' => now(),
        ]);

        return response()->json(['message' => $approve ? 'Claim approved' : 'Claim rejected']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:150', 'claim_date' => 'nullable|date_format:Y-m-d', 'employee_id' => 'nullable|integer', 'note' => 'nullable|string|max:300',
            'lines' => 'required|array|min:1|max:50', 'lines.*.expense_date' => 'required|date_format:Y-m-d', 'lines.*.account_id' => 'required|integer', 'lines.*.description' => 'required|string|max:200',
            'lines.*.amount' => 'required|numeric|gt:0|max:100000000', 'branch_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private function lines(int $companyId, array $rows): array
    {
        $lines = [];
        $total = 0.0;

        foreach ($rows as $index => $row) {
            $account = DB::table('chart_of_accounts')->where('company_id', $companyId)->where('id', (int) $row['account_id'])->first(['id', 'pl']);

            if ($account === null || ! (bool) $account->pl) {
                throw ValidationException::withMessages(["lines.{$index}.account_id" => ['Choose an expense account of this company.']]);
            }

            $amount = round((float) $row['amount'], 2);
            $total += $amount;
            $lines[] = ['expense_date' => $row['expense_date'], 'account_id' => (int) $account->id, 'description' => strip_tags((string) $row['description']), 'amount' => $amount];
        }

        return [$lines, round($total, 2)];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(int $companyId, int $claimId, array $lines): void
    {
        DB::table('expense_claim_lines')->where('expense_claim_id', $claimId)->delete();

        foreach ($lines as $line) {
            DB::table('expense_claim_lines')->insert($line + ['company_id' => $companyId, 'expense_claim_id' => $claimId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function employeeId(int $companyId, mixed $given): ?int
    {
        if ($given !== null && $given !== '') {
            if (! DB::table('employees')->where('company_id', $companyId)->where('id', (int) $given)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['employee_id' => ['Choose an employee of this company.']]);
            }

            return (int) $given;
        }

        $mine = DB::table('employees')->where('company_id', $companyId)->where('user_id', Auth::id())->whereNull('deleted_at')->value('id');

        return $mine === null ? null : (int) $mine;
    }

    private function seesAll(): bool
    {
        return hasMenuPermission('/expenseclaim/approve') || hasMenuPermission('/expenseclaim/pay');
    }

    private function claim(int $companyId, int $id): object
    {
        $claim = DB::table('expense_claims')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($claim === null || (! $this->seesAll() && (int) $claim->created_by !== (int) Auth::id()), 404);

        return $claim;
    }

    private function own(int $companyId, int $id): object
    {
        $claim = DB::table('expense_claims')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($claim === null || (int) $claim->created_by !== (int) Auth::id(), 404);

        return $claim;
    }
}
