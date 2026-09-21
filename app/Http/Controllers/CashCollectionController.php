<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\CashCollection;
use App\Services\CashCollections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CashCollectionController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * What the workflow endpoints tell the caller for each refusal reason.
     *
     * @var array<string, string>
     */
    private const REJECTIONS = [
        'not_pending' => 'Only a pending collection can be changed.',
        'not_completed' => 'Only a completed collection can do this.',
        'allocation_mismatch' => 'The invoice amounts must add up to exactly the collected amount, or be less with the rest kept as an advance, and never more.',
        'advance_exceeded' => 'The amounts must be above zero and no more than the advance that is left.',
        'disabled' => 'Cash collection is switched off for this company. Switch it on in Company Settings.',
    ];

    public function __construct(private readonly CashCollections $collections) {}

    /**
     * @return array<string, mixed>
     */
    protected function collectionFormRules(Request $request): array
    {
        $user = Auth::user();
        $companyId = $this->companyIdFor($request);

        return [
            'company_id' => $user?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both'])->whereNull('deleted_at')],
            'collected_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            'amount' => 'required|numeric|decimal:0,2|gt:0|max:9999999.99',
            'note' => 'nullable|string|max:500',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery(CashCollection::query(), $request)
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('contact_id'), fn (Builder $q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('from_date'), fn (Builder $q) => $q->whereDate('collected_on', '>=', $request->string('from_date')->toString()))
            ->when($request->filled('to_date'), fn (Builder $q) => $q->whereDate('collected_on', '<=', $request->string('to_date')->toString()));

        $collections = $this->paginateSorted($query, $this->withSafeSort($request));

        $collections->getCollection()->transform(fn (CashCollection $collection) => $this->withNames($collection));

        $trash_count = CashCollection::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $collections, 'trash_count' => $trash_count]);
    }

    /**
     * Records a pending collection. Nothing is posted yet.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/add');

        $request->validate($this->collectionFormRules($request));

        $companyId = (int) $this->companyIdFor($request);

        if (! $this->collections->enabledFor($companyId)) {
            return $this->rejection('disabled');
        }

        $branchId = $this->branchIdFor($request);

        $collection = CashCollection::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'contact_id' => $request->integer('contact_id'),
            'reference' => CashCollection::nextReference($companyId),
            'collected_on' => $request->string('collected_on')->toString(),
            'amount' => round((float) $request->amount, 2),
            'note' => filled($request->note) ? trim((string) $request->note) : null,
            'status' => CashCollection::STATUS_PENDING,
            'collected_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'id' => $collection->id, 'reference' => $collection->reference]);
    }

    public function show($id): JsonResponse
    {
        $collection = CashCollection::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name,first_name,last_name', 'collector:id,first_name,last_name'])
            ->find($id);

        if ($collection === null) {
            abort(404);
        }

        $payload = $this->withNames($collection)->toArray();
        $payload['collector_name'] = trim(($collection->collector?->first_name ?? '').' '.($collection->collector?->last_name ?? '')) ?: null;
        $payload['advance_remaining'] = $collection->advanceRemaining();
        $payload['enabled'] = $this->collections->enabledFor((int) $collection->company_id);
        $payload['allocations'] = $collection->allocations()
            ->with(['transaction:id,invoice_no', 'payment:id,payment_ref_no'])
            ->orderBy('id')
            ->get()
            ->map(fn ($allocation): array => [
                'id' => $allocation->id,
                'kind' => $allocation->kind,
                'transaction_id' => $allocation->transaction_id,
                'invoice_no' => $allocation->transaction?->invoice_no,
                'payment_id' => $allocation->payment_id,
                'payment_ref_no' => $allocation->payment?->payment_ref_no,
                'amount' => $allocation->amount,
            ])
            ->all();

        return response()->json($payload);
    }

    /**
     * Edits a pending collection (customer, branch, date, amount, note). The company stays the same.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/:id/edit');

        $collection = $this->findVisible($id);

        $request->merge(['company_id' => $collection->company_id]);
        $request->validate($this->collectionFormRules($request));

        if (! $collection->isPending()) {
            return $this->rejection('not_pending');
        }

        $collection->update([
            'branch_id' => $this->branchIdFor($request),
            'contact_id' => $request->integer('contact_id'),
            'collected_on' => $request->string('collected_on')->toString(),
            'amount' => round((float) $request->amount, 2),
            'note' => filled($request->note) ? trim((string) $request->note) : null,
        ]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * The customer's posted invoices that still owe something, oldest first, for the Complete screen.
     */
    public function openInvoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_id' => 'required|integer',
            'company_id' => 'nullable|integer',
        ]);

        $user = Auth::user();
        $companyId = $user?->hasRole('superadmin')
            ? (isset($data['company_id']) ? (int) $data['company_id'] : null)
            : ($user?->company_id ? (int) $user->company_id : null);

        if ($companyId === null) {
            return response()->json([]);
        }

        return response()->json($this->collections->openInvoices($companyId, (int) $data['contact_id']));
    }

    /**
     * Completes a pending collection: `allocations` spreads the amount over the customer's invoices and
     * `payment_account` is the cash / bank account that receives it. This is what posts the payments.
     */
    public function complete(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/complete');

        $collection = $this->findVisible($id);

        $keepAdvance = $request->boolean('keep_advance');

        $data = $request->validate([
            'payment_account' => 'required|integer|exists:chart_of_accounts,id',
            'keep_advance' => 'nullable|boolean',
            'allocations' => [$keepAdvance ? 'nullable' : 'required', 'array', $keepAdvance ? 'min:0' : 'min:1', 'max:200'],
            'allocations.*.transaction_id' => 'required|integer|distinct',
            'allocations.*.amount' => 'required|numeric|decimal:0,2|gt:0|max:9999999.99',
        ]);

        if (! $this->collections->enabledFor((int) $collection->company_id)) {
            return $this->rejection('disabled');
        }

        try {
            $completed = $this->collections->complete($collection, (int) $data['payment_account'], $data['allocations'] ?? [], Auth::id(), $keepAdvance);
        } catch (RuntimeException $e) {
            return $this->rejection($e->getMessage());
        }

        return response()->json([
            'message' => 'Successfully Saved',
            'payments' => $completed->allocations()->pluck('payment_id')->all(),
            'advance_amount' => $completed->advance_amount,
        ]);
    }

    /**
     * Uses the advance a completed collection kept to settle invoices of the customer (no cash moves).
     */
    public function applyAdvance(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/advance');

        $collection = $this->findVisible($id);

        $data = $request->validate([
            'allocations' => 'required|array|min:1|max:200',
            'allocations.*.transaction_id' => 'required|integer|distinct',
            'allocations.*.amount' => 'required|numeric|decimal:0,2|gt:0|max:9999999.99',
        ]);

        if (! $this->collections->enabledFor((int) $collection->company_id)) {
            return $this->rejection('disabled');
        }

        try {
            $this->collections->applyAdvance($collection, $data['allocations']);
        } catch (RuntimeException $e) {
            return $this->rejection($e->getMessage());
        }

        return response()->json(['message' => 'Successfully Saved', 'advance_remaining' => $collection->fresh()->advanceRemaining()]);
    }

    /**
     * Undoes a completed collection: its payments are removed and it is pending again.
     */
    public function reverse(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/reverse');

        $collection = $this->findVisible($id);
        $data = $request->validate(['note' => 'nullable|string|max:300']);

        try {
            $this->collections->reverse($collection, Auth::id(), $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->rejection($e->getMessage());
        }

        return response()->json(['message' => 'Successfully Reversed']);
    }

    public function cancel($id): JsonResponse
    {
        $this->authorizeMenuPermission('/cashcollection/cancel');

        $collection = $this->findVisible($id);

        try {
            $this->collections->cancel($collection);
        } catch (RuntimeException $e) {
            return $this->rejection($e->getMessage());
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/cashcollection/delete')) {
            CashCollection::query()->visibleToCurrentUser()->where('status', '!=', CashCollection::STATUS_COMPLETED)->find($id)?->delete();

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    /**
     * Only pending or cancelled collections can be deleted: a completed one is the record of its payments.
     */
    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/cashcollection/delete', 'Successfully Deleted', function () use ($request) {
            CashCollection::query()
                ->visibleToCurrentUser()
                ->where('status', '!=', CashCollection::STATUS_COMPLETED)
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/cashcollection/delete', 'Successfully Deleted', function () use ($request) {
            CashCollection::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/cashcollection/restore', 'Successfully Restored', function () use ($request) {
            CashCollection::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $query = $this->listQuery(CashCollection::onlyTrashed(), $request);

        $collections = $this->paginateSorted($query, $this->withSafeSort($request));

        $collections->getCollection()->transform(fn (CashCollection $collection) => $this->withNames($collection));

        return response()->json(['data' => $collections]);
    }

    /**
     * The company a collection is saved under: the superadmin names one, everyone else is their own.
     */
    private function companyIdFor(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $request->filled('company_id') ? $request->integer('company_id') : null;
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }

    /**
     * The branch: as asked, except that a user tied to a branch (not the company admin) always collects
     * for their own.
     */
    private function branchIdFor(Request $request): int
    {
        $user = Auth::user();

        if ($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin')) {
            if ($request->integer('branch_id') !== (int) $user->branch_id) {
                throw ValidationException::withMessages(['branch_id' => ['You can only collect for your own branch.']]);
            }

            return (int) $user->branch_id;
        }

        return $request->integer('branch_id');
    }

    private function findVisible(mixed $id): CashCollection
    {
        $collection = CashCollection::query()->visibleToCurrentUser()->find($id);

        if ($collection === null) {
            abort(404);
        }

        return $collection;
    }

    private function rejection(string $reason): JsonResponse
    {
        return response()->json(['message' => self::REJECTIONS[$reason] ?? 'The collection could not be saved.', 'reason' => $reason], 422);
    }

    private function withNames(CashCollection $collection): CashCollection
    {
        $collection->company_name = $collection->company?->name;
        $collection->branch_name = $collection->branch?->name;
        $collection->contact_name = $collection->contactName();

        return $collection;
    }

    /**
     * @param  Builder<CashCollection>  $query
     * @return Builder<CashCollection>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'contact:id,business_name,first_name,last_name'])
            ->when(in_array($status, [CashCollection::STATUS_PENDING, CashCollection::STATUS_COMPLETED, CashCollection::STATUS_CANCELLED], true), function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('reference', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), CashCollection::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
