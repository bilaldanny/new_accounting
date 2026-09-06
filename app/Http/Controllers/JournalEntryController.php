<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\TAccount;
use App\Services\LedgerJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class JournalEntryController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function journalFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'voucher_type' => ['bail', 'required', Rule::in(TAccount::VOUCHER_TYPES)],
            'voucher_no' => 'nullable|string|max:100',
            'voucher_date' => 'bail|required|date',
            'comments' => 'nullable|string',
            'taccountdetails' => 'bail|required|array|min:1',
            'taccountdetails.*.account_id' => 'bail|required|integer|exists:chart_of_accounts,id',
            'taccountdetails.*.description' => 'nullable|string',
            'taccountdetails.*.debit' => 'nullable|numeric|min:0',
            'taccountdetails.*.credit' => 'nullable|numeric|min:0',
            'attachments' => 'nullable|array',
            'attachments.*.file_name' => 'nullable|string|max:255',
            'attachments.*.data_url' => 'nullable|string',
            'attachments.*.ext' => 'nullable|string|max:20',
        ];
    }

    public function index(Request $request)
    {
        $status = $request->status ?? 'all';
        $search = $request->search ?? '';

        $query = TAccount::query()
            ->manualJournals()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
            ])
            ->when($status !== 'all', function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->whereAny(['voucher_no', 'comments'], 'like', "%{$search}%");
                });
            })
            ->when($request->filled('company_id'), function ($q) use ($request) {
                $q->where('company_id', $request->company_id);
            })
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });

        $journals = $this->paginateSorted($query, $request);

        $journals->getCollection()->transform(function (TAccount $journal) {
            return $journal->presentForIndex();
        });

        return response()->json(['data' => $journals, 'trash_count' => 0]);
    }

    public function voucherNo(Request $request, LedgerJournal $ledgerJournal)
    {
        $request->validate([
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'required',
            'type' => ['required', Rule::in(TAccount::VOUCHER_TYPES)],
        ]);

        $companyId = Auth::user()?->hasRole('superadmin')
            ? TAccount::resolveScopedId($request->company_id)
            : TAccount::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(
            $ledgerJournal->nextVoucherNo(
                (int) $companyId,
                (int) TAccount::resolveScopedId($request->branch_id),
                strtoupper((string) $request->type),
            )
        );
    }

    public function store(Request $request)
    {
        $this->authorizeMenuPermission('/journalentry/add');

        $request->validate($this->journalFormRules());
        $this->assertLinesAreBalanced($request);

        DB::beginTransaction();
        try {
            TAccount::createJournalEntry($request);
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

    public function show($id)
    {
        $journal = TAccount::findVisibleManualJournal((int) $id);

        if ($journal === null) {
            abort(404);
        }

        return response()->json($journal->presentForForm());
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/journalentry/:id/edit');

        $request->validate($this->journalFormRules());
        $this->assertLinesAreBalanced($request);

        DB::beginTransaction();
        try {
            TAccount::updateJournalEntry($request, (int) $id);
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

    public function destroy($id)
    {
        if (deletepermission('/journalentry/delete')) {
            TAccount::deleteJournalEntry((int) $id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/journalentry/delete', 'Successfully Deleted', function () use ($request) {
            $ids = TAccount::query()
                ->manualJournals()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            TAccount::whereIn('id', $ids)->delete();
        });
    }

    public function duplicate(Request $request)
    {
        $this->authorizeMenuPermission('/journalentry/add');

        DB::beginTransaction();
        try {
            TAccount::duplicateJournalEntry((int) $request->id);
            DB::commit();

            return response()->json(['message' => 'Successfully Duplicated']);
        } catch (HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }
    }

    private function assertLinesAreBalanced(Request $request): void
    {
        $debit = 0.0;
        $credit = 0.0;

        foreach ((array) $request->input('taccountdetails', []) as $index => $line) {
            $lineDebit = round((float) ($line['debit'] ?? 0), 2);
            $lineCredit = round((float) ($line['credit'] ?? 0), 2);

            if ($lineDebit <= 0 && $lineCredit <= 0) {
                throw ValidationException::withMessages([
                    "taccountdetails.{$index}.debit" => ['Each line needs a debit or credit amount.'],
                ]);
            }

            if ($lineDebit > 0 && $lineCredit > 0) {
                throw ValidationException::withMessages([
                    "taccountdetails.{$index}.debit" => ['A line cannot have both debit and credit.'],
                ]);
            }

            $debit += $lineDebit;
            $credit += $lineCredit;
        }

        if (round($debit, 2) !== round($credit, 2)) {
            throw ValidationException::withMessages([
                'taccountdetails' => ['Journal is not balanced. Total debit must equal total credit.'],
            ]);
        }
    }
}
