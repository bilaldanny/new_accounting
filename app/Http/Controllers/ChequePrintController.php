<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ChequeLayout;
use App\Models\Contact;
use App\Models\TAccount;
use App\Services\AmountInWords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cheque printing: layouts that say where the payee, the amount in words, the amount and the date sit on a cheque leaf (in
 * millimetres), and a print action that returns what to print for a payment voucher or a typed-in cheque. Printing a voucher's cheque
 * marks the voucher as printed; printing it again needs `reprint`, so a leaf is not printed twice by accident.
 */
class ChequePrintController extends Controller
{
    use ScopesToCompany;

    public function layouts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/chequeprint');

        return response()->json(['data' => ChequeLayout::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function storeLayout(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/chequeprint/layout');

        $data = collect($this->validatedLayout($request))->except(['company_id'])->all();
        $companyId = $this->scopeCompanyId($request);

        $layout = DB::transaction(function () use ($data, $companyId): ChequeLayout {
            $this->clearDefault($companyId, $data);

            return ChequeLayout::query()->create($data + ['company_id' => $companyId]);
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $layout], 201);
    }

    public function updateLayout(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/chequeprint/layout');

        $layout = ChequeLayout::query()->visibleToCurrentUser()->findOrFail($id);
        $data = collect($this->validatedLayout($request))->except(['company_id'])->all();

        DB::transaction(function () use ($layout, $data): void {
            $this->clearDefault((int) $layout->company_id, $data, (int) $layout->id);
            $layout->update($data);
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $layout->fresh()]);
    }

    public function destroyLayout(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/chequeprint/layout');

        ChequeLayout::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * What to print: from a payment voucher (`t_account_id`) or typed in (`payee`, `amount`, `date`).
     */
    public function render(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/chequeprint/print');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'layout_id' => 'nullable|integer',
            't_account_id' => 'nullable|integer',
            'reprint' => 'nullable|boolean',
            'payee' => 'nullable|string|max:150',
            'amount' => 'nullable|numeric|gt:0',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $voucher = null;
        $payee = trim((string) ($data['payee'] ?? ''));
        $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : null;
        $date = $data['date'] ?? null;

        if (isset($data['t_account_id'])) {
            $voucher = TAccount::query()->visibleToCurrentUser()->with('details')->find($data['t_account_id']);

            if ($voucher === null) {
                throw ValidationException::withMessages(['t_account_id' => ['Choose a voucher you can see.']]);
            }

            if ($voucher->status !== TAccount::STATUS_APPROVED) {
                throw ValidationException::withMessages(['t_account_id' => ['Only an approved voucher can have its cheque printed.']]);
            }

            if ($voucher->is_print && ! ($data['reprint'] ?? false)) {
                throw ValidationException::withMessages(['t_account_id' => ['The cheque of this voucher was printed before. Confirm a reprint to print it again.']]);
            }

            $contactId = $voucher->details->first(fn ($line): bool => $line->contact_id !== null)?->contact_id;
            $contact = $contactId === null ? null : Contact::query()->find($contactId);
            $payee = $payee !== '' ? $payee : trim((string) ($contact?->business_name ?: trim($contact?->first_name.' '.$contact?->last_name)));
            $amount ??= round((float) $voucher->total_amount, 2);
            $date ??= ($voucher->cheque_post_date ?? $voucher->voucher_date)?->toDateString();
        }

        if ($payee === '' || $amount === null || $date === null) {
            throw ValidationException::withMessages(['payee' => ['Give the payee, the amount and the date, or choose a voucher.']]);
        }

        $companyId = $voucher !== null ? (int) $voucher->company_id : $this->scopeCompanyId($request);
        $layout = ChequeLayout::query()->where('company_id', $companyId)
            ->when(isset($data['layout_id']), fn ($q) => $q->whereKey((int) $data['layout_id']), fn ($q) => $q->orderByDesc('is_default'))
            ->orderBy('id')->first();

        if ($layout === null) {
            throw ValidationException::withMessages(['layout_id' => ['Set up a cheque layout first.']]);
        }

        if ($voucher !== null) {
            $voucher->forceFill(['is_print' => true, 'printed_by' => Auth::id(), 'printed_at' => now()])->saveQuietly();
        }

        return response()->json(['data' => [
            'layout' => $layout,
            'payee' => $payee,
            'amount' => $amount,
            'amount_figures' => number_format($amount, 2),
            'amount_words' => AmountInWords::convert($amount),
            'date' => $date,
            'date_text' => date('d-m-Y', strtotime($date)),
            'account_payee' => (bool) $layout->account_payee,
            'voucher_no' => $voucher?->voucher_no,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function clearDefault(int $companyId, array $data, ?int $exceptId = null): void
    {
        if (! empty($data['is_default'])) {
            ChequeLayout::query()->where('company_id', $companyId)->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->update(['is_default' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedLayout(Request $request): array
    {
        $coordinate = 'required|numeric|min:0|max:500';

        return $request->validate([
            'company_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:80',
            'width_mm' => 'required|numeric|min:50|max:400',
            'height_mm' => 'required|numeric|min:30|max:300',
            'payee_x' => $coordinate, 'payee_y' => $coordinate,
            'words_x' => $coordinate, 'words_y' => $coordinate,
            'amount_x' => $coordinate, 'amount_y' => $coordinate,
            'date_x' => $coordinate, 'date_y' => $coordinate,
            'font_size' => 'required|integer|min:6|max:30',
            'account_payee' => 'nullable|boolean',
            'is_default' => 'nullable|boolean',
        ]);
    }
}
