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

class ReceivingNoteController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function receivingNoteRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'contact_id' => 'bail|required',
            'transaction_id' => 'bail|required',
            'purchaselines' => 'bail|required|array|min:1',
            'purchaselines.*.id' => 'bail|required',
            'purchaselines.*.quantity_received' => 'bail|required|numeric|min:0',
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
            'data' => Transaction::paginateReceivingNotes($filters),
            'trash_count' => Transaction::onlyTrashed()->receivingNotes()->visibleToCurrentUser()->count(),
        ]);
    }

    public function eligiblePurchases(Request $request): JsonResponse
    {
        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(Transaction::eligiblePurchases(
            $companyId,
            Transaction::resolveScopedId($request->branch_id),
            Transaction::resolveScopedId($request->contact_id),
        ));
    }

    public function purchaseLines(int $id): JsonResponse
    {
        $purchase = Transaction::findVisiblePurchase($id);

        if ($purchase === null) {
            abort(404);
        }

        return response()->json([
            'id' => $purchase->id,
            'invoice_no' => $purchase->invoice_no,
            'purchaselines' => $purchase->receivingNoteLines(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/receivingnote/add');

        $request->validate($this->receivingNoteRules());

        DB::beginTransaction();
        try {
            Transaction::createReceivingNote($request);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        $note = Transaction::findVisibleReceivingNote($id);

        if ($note === null) {
            abort(404);
        }

        return response()->json($note->presentReceivingNote());
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/receivingnote/:id/edit');
        $request->validate([
            'purchaselines' => 'bail|required|array|min:1',
            'purchaselines.*.id' => 'bail|required',
            'purchaselines.*.quantity_received' => 'bail|required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            Transaction::updateReceivingNote($request, $id);
            DB::commit();
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        if (deletepermission('/receivingnote/delete')) {
            Transaction::deleteReceivingNote($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        if (deletepermission('/receivingnote/delete')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->receivingNotes()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                foreach ($ids as $id) {
                    Transaction::deleteReceivingNote((int) $id);
                }

                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        if (deletepermission('/receivingnote/delete')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->receivingNotes()
                    ->visibleToCurrentUser()
                    ->whereIn('id', (array) $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->forceDelete();
                DB::commit();

                return response()->json(['message' => 'Successfully Deleted']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
            }
        }

        return response()->json('406');
    }

    public function restore_records(Request $request): JsonResponse
    {
        if (deletepermission('/receivingnote/restore')) {
            DB::beginTransaction();
            try {
                $ids = Transaction::query()
                    ->onlyTrashed()
                    ->receivingNotes()
                    ->visibleToCurrentUser()
                    ->whereIn('id', $request->all())
                    ->pluck('id');

                Transaction::whereIn('id', $ids)->restore();
                DB::commit();

                return response()->json(['message' => 'Successfully Restored']);
            } catch (Throwable $e) {
                DB::rollBack();

                return response()->json(['errormessage' => $e]);
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
            ->receivingNotes()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'parent:id,invoice_no',
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->whereAny(['invoice_no', 'sup_ref_no'], 'like', "%{$search}%")
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
            $note->purchase_order_no = $note->parent?->invoice_no;

            return $note;
        });

        return response()->json(['data' => $notes]);
    }
}
