<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\BadDebtWriteoff;
use App\Models\Branch;
use App\Services\BadDebt;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bad debt: settings (accounts and bucket rates), write-off requests with a separate approval, their reversal, and the period-end
 * allowance (see Services\BadDebt).
 */
class BadDebtController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly BadDebt $badDebt, private readonly PartyAgingReport $aging) {}

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt');

        return response()->json(['data' => $this->badDebt->settings($this->scopeCompanyId($request))]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt/settings');

        $data = $request->validate([
            'expense_account_id' => 'nullable|integer',
            'allowance_account_id' => 'nullable|integer',
            'rates' => 'nullable|array',
            'rates.*' => 'nullable|numeric|min:0|max:100',
        ]);

        $setting = $this->badDebt->saveSettings(
            $this->scopeCompanyId($request),
            isset($data['expense_account_id']) ? (int) $data['expense_account_id'] : null,
            isset($data['allowance_account_id']) ? (int) $data['allowance_account_id'] : null,
            $data['rates'] ?? [],
        );

        return response()->json(['message' => 'Successfully Saved', 'data' => $setting]);
    }

    public function writeoffs(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt');

        $rows = BadDebtWriteoff::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')->limit(300)->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * Overdue invoices a write-off could be requested for.
     */
    public function invoices(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt');

        $data = $request->validate(['min_days' => 'nullable|integer|min:0', 'contact_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);
        $rows = $this->aging->openInvoiceRows('customer', $companyId, null, ['contact_id' => $data['contact_id'] ?? null])
            ->filter(fn (array $row): bool => $row['days_past_due'] >= (int) ($data['min_days'] ?? 1))
            ->sortByDesc('days_past_due')->values();

        return response()->json(['data' => $rows]);
    }

    public function request(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt/add');

        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0',
            'written_off_on' => 'required|date_format:Y-m-d',
            'method' => ['required', Rule::in(['direct', 'allowance'])],
            'reason' => 'required|string|min:3|max:255',
        ]);

        $writeoff = $this->badDebt->request($this->scopeCompanyId($request), (int) $data['transaction_id'], (float) $data['amount'], $data['written_off_on'], $data['method'], $data['reason']);

        return response()->json(['message' => 'Write-off requested', 'data' => $writeoff], 201);
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt/approve');

        return response()->json(['message' => 'Write-off posted', 'data' => $this->badDebt->approve($this->find($id))]);
    }

    public function reject(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt/approve');

        return response()->json(['message' => 'Write-off rejected', 'data' => $this->badDebt->reject($this->find($id))]);
    }

    public function reverse(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt/reverse');

        return response()->json(['message' => 'Write-off reversed', 'data' => $this->badDebt->reverse($this->find($id))]);
    }

    public function provisionPreview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt');

        $data = $request->validate(['as_of' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $this->badDebt->provisionPreview($this->scopeCompanyId($request), $data['as_of'] ?? now()->toDateString())]);
    }

    public function provision(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/baddebt/provision');

        $data = $request->validate(['as_of' => 'required|date_format:Y-m-d']);
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['message' => 'Allowance set', 'data' => $this->badDebt->provision($companyId, $this->scopeBranchId($request, $companyId) ?? (int) Branch::query()->where('company_id', $companyId)->orderBy('id')->value('id'), $data['as_of'])], 201);
    }

    private function find(int $id): BadDebtWriteoff
    {
        return BadDebtWriteoff::query()->visibleToCurrentUser()->findOrFail($id);
    }
}
