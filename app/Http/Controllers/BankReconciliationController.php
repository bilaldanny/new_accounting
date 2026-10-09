<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Services\BankReconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation (BRS): enter a bank statement from CSV rows, match its lines to the ledger (by hand or
 * automatically), read the reconciliation, and lock the statement once the difference is nil.
 */
class BankReconciliationController extends Controller
{
    use ResolvesReportScope;

    public function __construct(private readonly BankReconciliation $reconciliation) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation');

        $statements = BankStatement::query()
            ->visibleToCurrentUser()
            ->withCount(['lines', 'lines as matched_lines_count' => fn ($q) => $q->whereNotNull('matched_detail_id')])
            ->when($request->filled('account_code'), fn ($q) => $q->where('account_code', $request->input('account_code')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('statement_to')
            ->orderByDesc('id')
            ->paginate(min((int) ($request->input('show_record') ?: 15), 100));

        return response()->json(['data' => $statements]);
    }

    /**
     * The bank and cash accounts the company can reconcile.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation');

        [$companyId, $branchId] = $this->reportScope($request);

        abort_if($companyId === null, 422, 'Choose a company.');

        return response()->json(['data' => ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId)
            ->unique('code')
            ->map(fn (ChartOfAccount $account): array => ['code' => (string) $account->code, 'name' => (string) $account->name])
            ->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation/add');

        $data = $request->validate([
            'account_code' => 'required|string|max:50',
            'statement_from' => 'required|date',
            'statement_to' => 'required|date|after_or_equal:statement_from',
            'opening_balance' => 'required|numeric',
            'closing_balance' => 'required|numeric',
            'note' => 'nullable|string|max:500',
            'rows' => 'required|array|min:1|max:5000',
            'rows.*.txn_date' => 'required|date',
            'rows.*.amount' => 'required|numeric',
            'rows.*.description' => 'nullable|string|max:500',
            'rows.*.reference' => 'nullable|string|max:100',
        ]);

        [$companyId, $branchId] = $this->reportScope($request);

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        if (! ChartOfAccount::query()->where('company_id', $companyId)->where('code', $data['account_code'])->exists()) {
            throw ValidationException::withMessages(['account_code' => ['That account does not exist in this company.']]);
        }

        $statement = DB::transaction(function () use ($data, $companyId, $branchId): BankStatement {
            $statement = BankStatement::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'account_code' => $data['account_code'],
                'statement_from' => $data['statement_from'],
                'statement_to' => $data['statement_to'],
                'opening_balance' => $data['opening_balance'],
                'closing_balance' => $data['closing_balance'],
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->reconciliation->importLines($statement, $data['rows']);

            return $statement;
        });

        return response()->json(['message' => 'Statement saved', 'id' => $statement->id]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation');

        $statement = $this->find($id);

        return response()->json(['data' => $this->detail($statement)]);
    }

    public function autoMatch(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation/match');

        $days = $request->validate(['days' => 'nullable|integer|min:0|max:60'])['days'] ?? BankReconciliation::DEFAULT_DAY_WINDOW;
        $statement = $this->find($id);
        $matched = $this->reconciliation->autoMatch($statement, (int) $days);

        return response()->json(['message' => "{$matched} line(s) matched", 'matched' => $matched, 'data' => $this->detail($statement)]);
    }

    public function match(Request $request, int $id, int $lineId): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation/match');

        $detailId = (int) $request->validate(['detail_id' => 'required|integer'])['detail_id'];
        $statement = $this->find($id);
        $this->reconciliation->match($statement, $this->line($statement, $lineId), $detailId);

        return response()->json(['message' => 'Matched', 'data' => $this->detail($statement)]);
    }

    public function unmatch(int $id, int $lineId): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation/match');

        $statement = $this->find($id);
        $this->reconciliation->unmatch($statement, $this->line($statement, $lineId));

        return response()->json(['message' => 'Unmatched', 'data' => $this->detail($statement)]);
    }

    public function reconcile(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation/reconcile');

        $statement = $this->reconciliation->reconcile($this->find($id));

        return response()->json(['message' => 'Reconciled', 'data' => $this->detail($statement)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankreconciliation/delete');

        $statement = $this->find($id);
        $this->reconciliation->assertOpen($statement);
        $statement->lines()->delete();
        $statement->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function find(int $id): BankStatement
    {
        return BankStatement::query()->visibleToCurrentUser()->findOrFail($id);
    }

    private function line(BankStatement $statement, int $lineId): BankStatementLine
    {
        return $statement->lines()->findOrFail($lineId);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(BankStatement $statement): array
    {
        $statement->refresh();

        return [
            'id' => $statement->id,
            'account_code' => $statement->account_code,
            'statement_from' => $statement->statement_from->toDateString(),
            'statement_to' => $statement->statement_to->toDateString(),
            'opening_balance' => $statement->opening_balance,
            'closing_balance' => $statement->closing_balance,
            'status' => $statement->status,
            'reconciled_at' => $statement->reconciled_at?->format('Y-m-d H:i'),
            'note' => $statement->note,
            'lines' => $statement->lines()->get()->map(fn (BankStatementLine $line): array => [
                'id' => $line->id,
                'txn_date' => $line->txn_date->toDateString(),
                'description' => $line->description,
                'reference' => $line->reference,
                'amount' => $line->amount,
                'matched_detail_id' => $line->matched_detail_id,
            ])->all(),
            'summary' => $this->reconciliation->summary($statement),
        ];
    }
}
