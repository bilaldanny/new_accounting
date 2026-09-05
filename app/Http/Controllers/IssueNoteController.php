<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class IssueNoteController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function issueNoteRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'contact_id' => 'bail|required',
            'transaction_id' => 'bail|required',
            'selllines' => 'bail|required|array|min:1',
            'selllines.*.id' => 'bail|required',
            'selllines.*.quantity_issue' => 'bail|required|numeric|min:0',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'sort_by',
            'sort_type',
            'show_record',
            'status',
            'search',
            'cur_page',
            'page',
            'payment_status',
            'company_id',
            'branch_id',
            'contact_id',
            'transaction_date',
        ]);

        return response()->json([
            'data' => Transaction::paginateIssueNotes($filters),
            'trash_count' => Transaction::onlyTrashed()->issueNotes()->visibleToCurrentUser()->count(),
        ]);
    }

    public function eligibleSells(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(Transaction::eligibleSells(
            $companyId,
            Transaction::resolveScopedId($request->branch_id),
            Transaction::resolveScopedId($request->contact_id),
        ));
    }

    public function sellLines(int $id): JsonResponse
    {
        $sell = Transaction::findVisibleSell($id);

        if ($sell === null) {
            abort(404);
        }

        return response()->json([
            'id' => $sell->id,
            'invoice_no' => $sell->invoice_no,
            'selllines' => $sell->issueNoteLines(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/issuenote/add');

        $request->validate($this->issueNoteRules());

        DB::beginTransaction();
        try {
            Transaction::createIssueNote($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        $note = Transaction::findVisibleIssueNote($id);

        if ($note === null) {
            abort(404);
        }

        return response()->json($note->presentIssueNote());
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/issuenote/:id/edit');
        $request->validate([
            'selllines' => 'bail|required|array|min:1',
            'selllines.*.id' => 'bail|required',
            'selllines.*.quantity_issue' => 'bail|required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            Transaction::updateIssueNote($request, $id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission('/issuenote/delete')) {
            Transaction::deleteIssueNote($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission('/issuenote/delete')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->issueNotes()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $id) {
                    Transaction::deleteIssueNote((int) $id);
                }

                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission('/issuenote/delete')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->issueNotes()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/issuenote/restore')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->issueNotes()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e->getMessage()], 500);
            }
        }

        return response()->json('406');
    }

    public function trash(Request $request): JsonResponse
    {
        $filters = $request->only([
            'sort_by',
            'sort_type',
            'show_record',
            'status',
            'search',
            'cur_page',
            'page',
            'payment_status',
            'company_id',
            'branch_id',
            'contact_id',
            'transaction_date',
        ]);

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortType = $filters['sort_type'] ?? 'desc';
        $showRecord = $filters['show_record'] ?? 10;
        $search = $filters['search'] ?? '';
        $curPage = (int) ($filters['cur_page'] ?? $filters['page'] ?? 1);

        $query = Transaction::onlyTrashed()
            ->issueNotes()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'parent:id,invoice_no',
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(fn () => $curPage);

        $notes = $query->paginate($showRecord);

        $notes->getCollection()->transform(function (Transaction $note) {
            $note->presentForIndex();
            $note->sell_order_no = $note->parent?->invoice_no;

            return $note;
        });

        return response()->json(['data' => $notes]);
    }
}
