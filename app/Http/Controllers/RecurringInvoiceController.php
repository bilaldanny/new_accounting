<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\RecurringInvoice;
use App\Services\RecurringInvoices;
use App\Services\SaleDocumentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Recurring invoices: the standing schedules, a button to generate what is due now, and the run history. Generation is the
 * RecurringInvoices service, the same one the scheduled command calls.
 */
class RecurringInvoiceController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/recurringinvoice');

        $rows = RecurringInvoice::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('next_run_on')
            ->paginate(min($request->integer('per_page', 25), 100));

        $names = Contact::query()->whereIn('id', $rows->getCollection()->pluck('contact_id'))->pluck('business_name', 'id');
        $rows->getCollection()->transform(function (RecurringInvoice $row) use ($names): RecurringInvoice {
            $row->setAttribute('contact_name', $names[$row->contact_id] ?? null);

            return $row;
        });

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/recurringinvoice');

        $row = RecurringInvoice::query()->visibleToCurrentUser()->findOrFail($id);

        return response()->json($row->toArray() + ['runs_history' => DB::table('recurring_invoice_runs')->where('recurring_invoice_id', $row->id)->orderByDesc('id')->limit(50)->get()]);
    }

    public function store(Request $request, SaleDocumentBuilder $builder): JsonResponse
    {
        $this->authorizeMenuPermission('/recurringinvoice/add');

        $data = $this->validated($request);
        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Choose the branch the invoices are made in.']]);
        }

        if (! Contact::query()->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both'])->whereKey($data['contact_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer of this company.']]);
        }

        // validates the products and quantities now rather than at the first run
        $builder->lines($companyId, $branchId, (int) $data['contact_id'], $data['lines']);

        $row = RecurringInvoice::query()->create(collect($data)->except(['company_id', 'branch_id'])->all() + [
            'company_id' => $companyId, 'branch_id' => $branchId, 'runs' => 0, 'status' => 'active', 'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $row], 201);
    }

    public function update(Request $request, int $id, SaleDocumentBuilder $builder): JsonResponse
    {
        $this->authorizeMenuPermission('/recurringinvoice/:id/edit');

        $row = RecurringInvoice::query()->visibleToCurrentUser()->findOrFail($id);
        $request->validate(['status' => ['nullable', Rule::in(['active', 'paused', 'ended'])]]);

        if ($request->has('lines')) {
            $data = collect($this->validated($request))->except(['company_id', 'branch_id'])->all();
            $builder->lines((int) $row->company_id, $row->branch_id === null ? null : (int) $row->branch_id, (int) $data['contact_id'], $data['lines']);
            $row->fill($data);
        }

        if ($request->filled('status')) {
            $row->status = $request->input('status');
        }

        $row->save();

        return response()->json(['message' => 'Successfully Saved', 'data' => $row->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/recurringinvoice/delete');

        RecurringInvoice::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Generates what is due now for the company (or for the one schedule).
     */
    public function generate(Request $request, RecurringInvoices $generator): JsonResponse
    {
        $this->authorizeMenuPermission('/recurringinvoice/generate');

        $companyId = $this->scopeCompanyId($request);
        $result = $generator->generateDue($companyId);

        return response()->json(['message' => "Generated {$result['generated']} invoice(s), {$result['failed']} failed.", 'data' => $result]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'required|integer',
            'name' => 'bail|required|string|min:2|max:150',
            'frequency' => ['required', Rule::in(RecurringInvoice::FREQUENCIES)],
            'interval' => 'nullable|integer|min:1|max:60',
            'next_run_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:next_run_on',
            'max_runs' => 'nullable|integer|min:1|max:1000',
            'post_as' => 'nullable|in:draft,final',
            'note' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.product_id' => 'required|integer',
            'lines.*.variation_id' => 'nullable|integer',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount' => 'nullable|numeric|min:0',
        ]);
    }
}
