<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\TAccount;
use App\Services\LedgerJournal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Credit Note (`CN`, a customer's balance goes down) and Debit Note (`DN`, a supplier's balance goes
 * down): a standalone 2-line balanced voucher in the same t_accounts engine every other manual voucher
 * family uses (TAccount::createJournalEntry/updateJournalEntry), family 'creditdebitnote'.
 *
 * Unlike Journal Entry, the two lines are never typed in free-form: the caller only picks a contact and
 * one offsetting chart of account plus an amount, and this controller builds the balanced pair itself
 * so a Credit Note can only ever touch the chosen customer's own receivable account (never an arbitrary
 * one) - Credit Note requires the contact to be a customer with a `customer_gl_id` linked, Debit Note a
 * supplier with a `supplier_gl_id` linked. The offsetting account is free choice (any chart of account),
 * as is the optional `ref_no` text reference to whatever invoice or reason prompted the note.
 */
class CreditDebitNoteController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function noteFormRules(): array
    {
        return [
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'bail|required',
            'voucher_type' => ['bail', 'required', Rule::in(TAccount::CREDIT_DEBIT_NOTE_VOUCHER_TYPES)],
            'voucher_no' => 'nullable|string|max:100',
            'voucher_date' => 'bail|required|date',
            'ref_no' => 'nullable|string|max:100',
            'comments' => 'nullable|string',
            'contact_id' => 'bail|required|integer',
            'account_id' => 'bail|required|integer|exists:chart_of_accounts,id',
            'amount' => 'bail|required|numeric|gt:0',
            'attachments' => 'nullable|array',
            'attachments.*.file_name' => 'nullable|string|max:255',
            'attachments.*.data_url' => 'nullable|string',
            'attachments.*.ext' => 'nullable|string|max:20',
        ];
    }

    public function index(Request $request)
    {
        $this->authorizeMenuPermission('/creditdebitnote');
        $query = TAccount::query()
            ->manualCreditDebitNotes()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'details.contact:id,business_name,first_name,last_name',
            ])
            ->matchingListFilters($request, is_string($request->status) ? $request->status : 'all');

        $notes = $this->paginateSorted($query, $request);

        $notes->getCollection()->transform(function (TAccount $note) {
            return $note->presentForIndex();
        });

        return response()->json(['data' => $notes, 'trash_count' => 0]);
    }

    public function voucherNo(Request $request, LedgerJournal $ledgerJournal)
    {
        $request->validate([
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'required',
            'type' => ['required', Rule::in(TAccount::CREDIT_DEBIT_NOTE_VOUCHER_TYPES)],
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
        $this->authorizeMenuPermission('/creditdebitnote/add');

        $request->validate($this->noteFormRules());
        $this->applyNoteLines($request);

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
        $note = TAccount::findVisibleManualCreditDebitNote((int) $id);

        if ($note === null) {
            abort(404);
        }

        return response()->json($note->presentForForm());
    }

    public function update(Request $request, $id)
    {
        $this->authorizeMenuPermission('/creditdebitnote/:id/edit');

        $request->validate($this->noteFormRules());
        $this->applyNoteLines($request);

        DB::beginTransaction();
        try {
            TAccount::updateJournalEntry($request, (int) $id, 'creditdebitnote');
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
        if (deletepermission('/creditdebitnote/delete')) {
            TAccount::deleteJournalEntry((int) $id, 'creditdebitnote');

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request)
    {
        return $this->guardedBulkAction('/creditdebitnote/delete', 'Successfully Deleted', function () use ($request) {
            $ids = TAccount::query()
                ->manualCreditDebitNotes()
                ->visibleToCurrentUser()
                ->whereIn('id', $request->all())
                ->pluck('id');

            TAccount::whereIn('id', $ids)->delete();
        });
    }

    public function duplicate(Request $request)
    {
        $this->authorizeMenuPermission('/creditdebitnote/add');

        DB::beginTransaction();
        try {
            TAccount::duplicateJournalEntry((int) $request->id, 'creditdebitnote');
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

    /**
     * Resolves the contact and the offsetting account, builds the balanced 2-line pair and merges it
     * into the request as `taccountdetails` so TAccount::createJournalEntry/updateJournalEntry (the
     * exact mechanism Journal Entry uses) can be called unchanged.
     *
     * @throws ValidationException
     */
    private function applyNoteLines(Request $request): void
    {
        $companyId = TAccount::resolveScopedId($request->company_id) ?? Auth::user()?->company_id;
        $branchId = TAccount::resolveScopedId($request->branch_id);
        $voucherType = strtoupper((string) $request->input('voucher_type'));
        $isCreditNote = $voucherType === 'CN';

        $contact = Contact::query()
            ->visibleToCurrentUser()
            ->where('active', true)
            ->find($request->integer('contact_id'));

        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['The selected contact was not found.'],
            ]);
        }

        if ($isCreditNote) {
            if (! in_array($contact->user_type, ['customer', 'both'], true)) {
                throw ValidationException::withMessages([
                    'contact_id' => ['A Credit Note can only be raised against a customer.'],
                ]);
            }

            $contactCode = $contact->customer_gl_id;

            if (blank($contactCode)) {
                throw ValidationException::withMessages([
                    'contact_id' => ['Link this customer to a chart of account before raising a Credit Note.'],
                ]);
            }
        } else {
            if (! in_array($contact->user_type, ['supplier', 'both'], true)) {
                throw ValidationException::withMessages([
                    'contact_id' => ['A Debit Note can only be raised against a supplier.'],
                ]);
            }

            $contactCode = $contact->supplier_gl_id;

            if (blank($contactCode)) {
                throw ValidationException::withMessages([
                    'contact_id' => ['Link this supplier to a chart of account before raising a Debit Note.'],
                ]);
            }
        }

        $contactAccount = ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
            ->where('code', $contactCode)
            ->first();

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['The chart of account linked to this contact could not be found for the selected branch.'],
            ]);
        }

        $offsetAccount = ChartOfAccount::query()->find($request->integer('account_id'));

        if ($offsetAccount === null) {
            throw ValidationException::withMessages([
                'account_id' => ['The selected account was not found.'],
            ]);
        }

        $amount = round((float) $request->input('amount'), 2);
        $description = (string) ($request->input('comments') ?? '');

        // Credit Note: credit the customer's receivable (reduces what they owe), debit the offset.
        // Debit Note: debit the supplier's payable (reduces what is owed to them), credit the offset.
        $contactLine = [
            'account_id' => $contactAccount->id,
            'code' => $contactAccount->code,
            'description' => $description,
            'contact_id' => $contact->id,
            'debit' => $isCreditNote ? 0 : $amount,
            'credit' => $isCreditNote ? $amount : 0,
        ];
        $offsetLine = [
            'account_id' => $offsetAccount->id,
            'code' => $offsetAccount->code,
            'description' => $description,
            'contact_id' => null,
            'debit' => $isCreditNote ? $amount : 0,
            'credit' => $isCreditNote ? 0 : $amount,
        ];

        $request->merge(['taccountdetails' => [$contactLine, $offsetLine]]);
    }
}
