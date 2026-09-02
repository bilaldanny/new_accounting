<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactLedgerWatch;
use App\Models\FinancialYear;
use App\Services\ContactLedger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function fetchLedger(Request $request, ContactLedger $ledger): JsonResponse
    {
        $request->validate([
            'contact_id' => 'required|integer',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|string',
            'start_date' => 'nullable|string',
            'end_date' => 'nullable|string',
        ]);

        $contact = Contact::findVisibleContact((int) $request->contact_id);

        if ($contact === null) {
            abort(404);
        }

        $financialYear = $this->activeFinancialYear($contact);
        $fromDate = $this->parseLedgerDate(
            $request->start_date,
            $financialYear?->start_date?->copy() ?? now()->startOfMonth(),
        );
        $toDate = $this->parseLedgerDate(
            $request->end_date,
            $financialYear?->end_date?->copy() ?? now(),
        );

        [$fromDate, $toDate] = $this->constrainToFinancialYear($fromDate, $toDate, $financialYear);

        $payload = $ledger->forContact(
            $contact,
            $fromDate,
            $toDate,
            $request->filled('branch_id') ? (string) $request->branch_id : null,
        );
        $payload['watched_until'] = ContactLedgerWatch::payloadFor($request->user(), $contact);

        return response()->json($payload);
    }

    public function saveLedgerWatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'row_id' => ['nullable', 'string', 'max:64'],
            'voucher_date' => ['nullable', 'required_with:row_id', 'date'],
            'voucher_no' => ['nullable', 'string', 'max:191'],
        ]);

        $contact = Contact::findVisibleContact((int) $validated['contact_id']);

        if ($contact === null) {
            abort(404);
        }

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        return response()->json([
            'watched_until' => ContactLedgerWatch::markUntil(
                $user,
                $contact,
                $validated['row_id'] ?? null,
                isset($validated['voucher_date']) ? substr((string) $validated['voucher_date'], 0, 10) : null,
                $validated['voucher_no'] ?? null,
            ),
        ]);
    }

    private function parseLedgerDate(?string $value, CarbonInterface $fallback): CarbonInterface
    {
        if ($value === null || $value === '') {
            return $fallback->copy();
        }

        $normalized = str_replace('/', '-', $value);

        return Carbon::parse($normalized);
    }

    private function activeFinancialYear(Contact $contact): ?FinancialYear
    {
        return FinancialYear::query()
            ->where('company_id', $contact->company_id)
            ->where('status', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function constrainToFinancialYear(
        CarbonInterface $fromDate,
        CarbonInterface $toDate,
        ?FinancialYear $financialYear,
    ): array {
        if ($financialYear?->start_date === null || $financialYear->end_date === null) {
            return [$fromDate, $toDate];
        }

        $yearStart = $financialYear->start_date->copy()->startOfDay();
        $yearEnd = $financialYear->end_date->copy()->startOfDay();

        if ($fromDate->lt($yearStart)) {
            $fromDate = $yearStart->copy();
        }

        if ($toDate->gt($yearEnd)) {
            $toDate = $yearEnd->copy();
        }

        if ($fromDate->gt($toDate)) {
            return [$yearStart->copy(), $yearEnd->copy()];
        }

        return [$fromDate, $toDate];
    }
}
