<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactLedgerWatch;
use App\Models\FinancialYear;
use App\Services\ContactLedger;
use App\Services\ContactStatement;
use App\Services\MessageDispatcher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

class PartyLedgerReportController extends Controller
{
    /**
     * The menus a party ledger is opened from: the customer and supplier pages and the ledger report.
     */
    private function authorizePartyLedger(): void
    {
        abort_unless(
            hasAnyMenuPermission('/customer', '/supplier', '/report/ledger', '/report/account-ledger'),
            403,
            'You do not have permission to perform this action.'
        );
    }

    public function fetchLedger(Request $request, ContactLedger $ledger): JsonResponse
    {
        $this->authorizePartyLedger();

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

    /**
     * The statement of one party for a period as a PDF download: the same rows, running balance and closing balance the ledger tab shows.
     */
    public function statementPdf(Request $request, ContactStatement $statements): Response
    {
        $this->authorizePartyLedger();
        $request->validate([
            'contact_id' => 'required|integer', 'branch_id' => 'nullable|string', 'start_date' => 'nullable|string', 'end_date' => 'nullable|string',
        ]);

        $contact = Contact::findVisibleContact((int) $request->contact_id) ?? abort(404);
        [$from, $to] = $statements->range($contact, $request->start_date, $request->end_date);
        $branch = $request->filled('branch_id') ? (string) $request->branch_id : null;

        return $this->pdfResponse($contact, $statements->pdf($statements->build($contact, $from, $to, $branch)), true);
    }

    /**
     * Sends the statement to the party: by email (the PDF attached), or by WhatsApp or SMS (a message with a link to the PDF that
     * works for 14 days without signing in). The recipient defaults to the party's own email or mobile and can be changed for this send.
     * The answer says what really happened; nothing is reported as sent that was not.
     */
    public function sendStatement(Request $request, ContactStatement $statements, MessageDispatcher $dispatcher): JsonResponse
    {
        $this->authorizePartyLedger();
        $data = $request->validate([
            'contact_id' => 'required|integer', 'channel' => 'required|in:email,whatsapp,sms', 'recipient' => 'nullable|string|max:190', 'message' => 'nullable|string|max:1000',
            'branch_id' => 'nullable|string', 'start_date' => 'nullable|string', 'end_date' => 'nullable|string',
        ]);

        $contact = Contact::findVisibleContact((int) $data['contact_id']) ?? abort(404);
        [$fromDate, $toDate] = $statements->range($contact, $data['start_date'] ?? null, $data['end_date'] ?? null);
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();
        $channel = $data['channel'];
        $recipient = trim((string) ($data['recipient'] ?? ''));

        if ($recipient === '') {
            $recipient = trim((string) ($channel === 'email' ? $contact->email : $contact->mobile));
        }

        if ($channel === 'email' && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => 'failed', 'error' => 'This party has no valid email address. Type one to send to.', 'recipient' => $recipient], 422);
        }

        if ($channel !== 'email' && preg_match('/\d{6,}/', $recipient) !== 1) {
            return response()->json(['status' => 'failed', 'error' => 'This party has no phone number. Type one to send to.', 'recipient' => $recipient], 422);
        }

        $url = URL::temporarySignedRoute('ledger.statement.public', now()->addDays(14), ['contact' => $contact->id, 'from' => $from, 'to' => $to]);
        $party = trim((string) ($contact->business_name ?: trim($contact->first_name.' '.$contact->last_name)));
        $company = (string) ($contact->company?->name ?? '');
        $note = trim((string) ($data['message'] ?? ''));
        $body = "Dear {$party},\n\n".($note !== '' ? $note."\n\n" : '')."Your account statement from {$from} to {$to}".($company !== '' ? " from {$company}" : '').' is ready.';

        if ($channel === 'email') {
            try {
                $pdf = $statements->pdf($statements->build($contact, $fromDate, $toDate, $data['branch_id'] ?? null));
                Mail::raw($body."\n\nThe statement is attached as a PDF. You can also open it here: {$url}", function ($mail) use ($recipient, $pdf, $from, $to, $party, $company): void {
                    $mail->to($recipient)->subject('Account statement '.$from.' to '.$to.($company !== '' ? ' - '.$company : ''))
                        ->attachData($pdf, 'statement-'.preg_replace('/[^A-Za-z0-9]+/', '-', $party).'-'.$from.'-'.$to.'.pdf', ['mime' => 'application/pdf']);
                });
            } catch (Throwable $e) {
                return response()->json(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 300), 'recipient' => $recipient, 'url' => $url], 502);
            }

            return response()->json(['status' => 'sent', 'error' => null, 'recipient' => $recipient, 'url' => $url]);
        }

        $result = $dispatcher->send((int) $contact->company_id, $channel, $recipient, $body."\n\nOpen the PDF: {$url}");

        return response()->json(['status' => $result['status'], 'error' => $result['error'] ?? null, 'recipient' => $recipient, 'url' => $url], $result['status'] === 'sent' ? 200 : 502);
    }

    /**
     * The link in a sent statement: valid only with its signature (the party id and the period cannot be changed), and for 14 days.
     */
    public function publicStatement(Request $request, ContactStatement $statements): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'This statement link is not valid any more.');

        $contact = Contact::query()->with('company')->find((int) $request->query('contact')) ?? abort(404);
        $from = Carbon::parse((string) $request->query('from'));
        $to = Carbon::parse((string) $request->query('to'));

        return $this->pdfResponse($contact, $statements->pdf($statements->build($contact, $from, $to)), false);
    }

    private function pdfResponse(Contact $contact, string $pdf, bool $download): Response
    {
        $name = 'statement-'.preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) ($contact->business_name ?: $contact->first_name))).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function saveLedgerWatch(Request $request): JsonResponse
    {
        $this->authorizePartyLedger();

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
