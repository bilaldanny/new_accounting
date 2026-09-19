<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\TAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntryApprovalController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $query = TAccount::query()
            ->manualJournals()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->matchingListFilters($request, (string) $request->input('status', TAccount::STATUS_PENDING));

        $journals = $this->paginateSorted($query, $request);

        $journals->getCollection()->transform(fn (TAccount $journal) => $journal->presentForIndex());

        return response()->json(['data' => $journals, 'trash_count' => 0]);
    }

    public function show(int $id): JsonResponse
    {
        $journal = TAccount::findVisibleManualJournal($id);

        if ($journal === null) {
            abort(404);
        }

        return response()->json($journal->presentForForm());
    }

    public function approve(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/journalentry/:id/approve');

        TAccount::approveJournal($id);

        return response()->json(['message' => 'Successfully Approved']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/journalentry/:id/reject');

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        TAccount::rejectJournal($id, $validated['reason'] ?? null);

        return response()->json(['message' => 'Successfully Rejected']);
    }
}
