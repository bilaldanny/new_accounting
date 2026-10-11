<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Services\AllocationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Advance allocation for a customer or supplier: what advances they hold, their open invoices or bills, a proposed matching in the
 * order a strategy names, and the action that settles the pairs (App\Services\AllocationEngine).
 */
class AllocationController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly AllocationEngine $engine) {}

    public function suggest(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/allocation');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'role' => 'required|in:customer,supplier',
            'strategy' => 'nullable|in:'.implode(',', AllocationEngine::STRATEGIES),
            'company_id' => 'nullable|integer',
        ]);

        $contact = $this->contact($data['contact_id'], $data['role']);

        return response()->json(['data' => $this->engine->suggest((int) $contact->company_id, (int) $contact->id, $data['role'], $data['strategy'] ?? 'fifo')]);
    }

    public function apply(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/allocation/apply');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'role' => 'required|in:customer,supplier',
            'paid_on' => 'required|date_format:Y-m-d',
            'allocations' => 'required|array|min:1|max:200',
            'allocations.*.source_id' => 'required|integer',
            'allocations.*.transaction_id' => 'required|integer',
            'allocations.*.amount' => 'required|numeric|gt:0',
        ]);

        $contact = $this->contact($data['contact_id'], $data['role']);
        $count = $this->engine->apply((int) $contact->company_id, (int) $contact->id, $data['role'], $data['allocations'], $data['paid_on']);

        return response()->json(['message' => "{$count} allocation(s) applied", 'data' => $this->engine->suggest((int) $contact->company_id, (int) $contact->id, $data['role'], 'fifo')]);
    }

    private function contact(int $id, string $role): Contact
    {
        $contact = Contact::query()->visibleToCurrentUser()->whereIn('user_type', [$role, 'both'])->find($id);

        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a '.$role.' you can see.']]);
        }

        return $contact;
    }
}
