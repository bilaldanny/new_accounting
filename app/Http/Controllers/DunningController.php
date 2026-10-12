<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\CollectionCase;
use App\Models\DunningEvent;
use App\Services\Dunning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Invoice dunning and collection management (see Services\Dunning): level templates, the reviewed batch of reminders, the log of
 * what was sent, and the collection status of a customer.
 */
class DunningController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly Dunning $dunning) {}

    public function levels(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning');

        return response()->json(['data' => $this->dunning->levels($this->scopeCompanyId($request))->values()]);
    }

    public function updateLevel(Request $request, int $level): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning/levels');

        $data = $request->validate([
            'name' => 'sometimes|string|min:2|max:60',
            'days_past_due' => 'sometimes|integer|min:1|max:3650',
            'subject' => 'sometimes|string|min:3|max:150',
            'body' => 'sometimes|string|min:10|max:4000',
            'is_active' => 'sometimes|boolean',
        ]);

        abort_unless(in_array($level, [1, 2, 3], true), 404);

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->dunning->updateLevel($this->scopeCompanyId($request), $level, $data)]);
    }

    public function candidates(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning');

        $data = $request->validate(['as_of' => 'nullable|date_format:Y-m-d']);
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => $this->dunning->candidates($companyId, $this->scopeBranchId($request, $companyId), $data['as_of'] ?? now()->toDateString())]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning/send');

        $data = $request->validate([
            'channel' => ['required', Rule::in(['email', 'log'])],
            'as_of' => 'nullable|date_format:Y-m-d',
            'items' => 'required|array|min:1|max:300',
            'items.*.transaction_id' => 'required|integer',
            'items.*.level' => 'required|integer|in:1,2,3',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $result = $this->dunning->send($companyId, $this->scopeBranchId($request, $companyId), $data['items'], $data['channel'], $data['as_of'] ?? now()->toDateString(), Auth::id());

        return response()->json(['message' => 'Reminders processed', 'data' => $result]);
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning');

        $rows = DunningEvent::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->orderByDesc('id')->limit(300)->get();

        return response()->json(['data' => $rows]);
    }

    public function cases(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning');

        return response()->json(['data' => CollectionCase::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('updated_at')->limit(300)->get()]);
    }

    public function setCase(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dunning/case');

        $data = $request->validate([
            'contact_id' => 'required|integer',
            'status' => ['required', Rule::in(CollectionCase::STATUSES)],
            'promised_on' => 'nullable|date_format:Y-m-d',
            'note' => 'nullable|string|max:500',
        ]);

        $case = $this->dunning->setCase($this->scopeCompanyId($request), (int) $data['contact_id'], $data['status'], $data['promised_on'] ?? null, $data['note'] ?? null, Auth::id());

        return response()->json(['message' => 'Successfully Saved', 'data' => $case]);
    }
}
