<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\InterbranchTransaction;
use App\Services\InterBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Inter-branch transfers inside one company and their consolidation with eliminations (see Services\InterBranch). A user tied to a
 * branch only sees transfers that touch it and may only give from it.
 */
class InterBranchController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly InterBranch $interBranch) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/interbranch');

        $companyId = $this->scopeCompanyId($request);
        $branch = $this->ownBranch();

        $rows = InterbranchTransaction::query()->where('company_id', $companyId)
            ->when($branch !== null, fn ($q) => $q->where(fn ($sub) => $sub->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch)))
            ->orderByDesc('id')->limit(300)->get();

        return response()->json(['data' => $rows, 'settings' => $this->interBranch->settings($companyId)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/interbranch/settings');

        $data = $request->validate(['receivable_account_id' => 'nullable|integer', 'payable_account_id' => 'nullable|integer']);
        $this->interBranch->saveSettings($this->scopeCompanyId($request), $data['receivable_account_id'] ?? null, $data['payable_account_id'] ?? null);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/interbranch/add');

        $data = $request->validate([
            'from_branch_id' => 'required|integer',
            'to_branch_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0',
            'transaction_date' => 'required|date_format:Y-m-d',
            'from_account_id' => 'required|integer',
            'to_account_id' => 'required|integer',
            'reference' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:255',
        ]);

        $own = $this->ownBranch();

        if ($own !== null && (int) $data['from_branch_id'] !== $own) {
            throw ValidationException::withMessages(['from_branch_id' => ['You can only transfer from your own branch.']]);
        }

        $row = $this->interBranch->transfer(
            $this->scopeCompanyId($request), (int) $data['from_branch_id'], (int) $data['to_branch_id'], (float) $data['amount'], $data['transaction_date'],
            (int) $data['from_account_id'], (int) $data['to_account_id'], $data['reference'] ?? null, $data['description'] ?? null,
        );

        return response()->json(['message' => 'Successfully Saved', 'data' => $row], 201);
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/interbranch/reverse');

        $row = InterbranchTransaction::query()->where('company_id', $this->scopeCompanyId($request))->findOrFail($id);
        $own = $this->ownBranch();

        if ($own !== null && ! in_array($own, [(int) $row->from_branch_id, (int) $row->to_branch_id], true)) {
            abort(404);
        }

        return response()->json(['message' => 'Transfer reversed', 'data' => $this->interBranch->reverse($row)]);
    }

    public function consolidation(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/interbranch');

        $data = $request->validate(['as_of' => 'nullable|date_format:Y-m-d']);
        $companyId = $this->scopeCompanyId($request);

        return response()->json($this->interBranch->consolidation($companyId, $this->ownBranch(), $data['as_of'] ?? now()->toDateString()));
    }

    /**
     * The branch a user is tied to (null for a company admin or the superadmin, who see every branch).
     */
    private function ownBranch(): ?int
    {
        $user = Auth::user();

        if ($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin')) {
            return (int) $user->branch_id;
        }

        return null;
    }
}
