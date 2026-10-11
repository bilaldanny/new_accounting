<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\BankInstrument;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The register of bank guarantees and letters of credit: reference, bank, party, amount, margin, charges, issue and expiry, and a
 * status. It is a register only: nothing is posted to the ledger. An open instrument past its expiry date reads as `expired`
 * (the stored status is left as it was until someone closes it), and the list can be limited to those expiring soon.
 */
class BankInstrumentController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankinstrument');

        $today = now()->toDateString();
        $rows = BankInstrument::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->input('kind')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('expiring_within'), fn ($q) => $q->where('status', 'open')->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($request->integer('expiring_within'))->toDateString()))
            ->orderByRaw('case when expires_on is null then 1 else 0 end, expires_on')
            ->paginate(min($request->integer('per_page', 25), 100));

        $names = Contact::query()->whereIn('id', $rows->getCollection()->pluck('contact_id')->filter())->pluck('business_name', 'id');

        $rows->getCollection()->transform(function (BankInstrument $row) use ($names, $today): BankInstrument {
            $expires = $row->expires_on?->toDateString();
            $row->setAttribute('contact_name', $names[$row->contact_id] ?? null);
            $row->setAttribute('days_to_expiry', $expires === null ? null : (int) now()->startOfDay()->diffInDays($row->expires_on, false));
            $row->setAttribute('state', $row->status === 'open' && $expires !== null && $expires < $today ? 'expired' : $row->status);

            return $row;
        });

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/bankinstrument/add');

        $data = $this->validated($request);
        $companyId = $this->scopeCompanyId($request);
        $this->assertContact($companyId, $data['contact_id'] ?? null);

        $row = BankInstrument::query()->create(collect($data)->except(['company_id', 'branch_id'])->all() + [
            'company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId), 'status' => $data['status'] ?? 'open', 'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $row], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankinstrument/:id/edit');

        $row = BankInstrument::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request);
        $this->assertContact((int) $row->company_id, $data['contact_id'] ?? null);

        $row->update(collect($data)->except(['company_id', 'branch_id'])->all());

        return response()->json(['message' => 'Successfully Saved', 'data' => $row->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/bankinstrument/delete');

        BankInstrument::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    private function assertContact(int $companyId, mixed $contactId): void
    {
        if ($contactId !== null && ! Contact::query()->where('company_id', $companyId)->whereKey((int) $contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer or supplier of this company.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'kind' => ['required', Rule::in(BankInstrument::KINDS)],
            'reference_no' => 'bail|required|string|max:60',
            'bank_name' => 'nullable|string|max:120',
            'contact_id' => 'nullable|integer',
            'amount' => 'required|numeric|gt:0',
            'currency' => 'nullable|string|max:8',
            'margin_percent' => 'nullable|numeric|min:0|max:100',
            'charges' => 'nullable|numeric|min:0',
            'issued_on' => 'nullable|date_format:Y-m-d',
            'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:issued_on',
            'status' => ['nullable', Rule::in(BankInstrument::STATUSES)],
            'purpose' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:1000',
        ]);

        foreach (['margin_percent', 'charges'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] === null) {
                $data[$column] = 0;
            }
        }

        return $data;
    }
}
