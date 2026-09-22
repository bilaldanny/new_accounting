<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\Reports\PartyOutstandingReport;
use Illuminate\Support\Facades\DB;

/**
 * What the read-only customer / supplier portal shows one contact: what they owe (or are owed) and their recent
 * documents and payments. The balance is the contact's ledger closing balance from PartyOutstandingReport, so it
 * is the same figure the staff see in the outstanding reports. A contact who is both customer and supplier gets
 * one section for each side. Nothing here writes anything.
 */
class ContactPortal
{
    /**
     * How many recent documents and payments a section lists.
     */
    public const RECENT = 10;

    public function __construct(private readonly PartyOutstandingReport $outstanding) {}

    /**
     * @return array{contact: array<string, mixed>, sections: list<array<string, mixed>>}
     */
    public function summary(Contact $contact): array
    {
        $kinds = match ($contact->user_type) {
            'customer' => ['customer'],
            'supplier' => ['supplier'],
            default => ['customer', 'supplier'],
        };

        return [
            'contact' => [
                'name' => PartyOutstandingReport::contactName($contact),
                'code' => $contact->code,
            ],
            'sections' => array_map(fn (string $kind): array => $this->section($contact, $kind), $kinds),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function section(Contact $contact, string $kind): array
    {
        $type = $kind === 'customer' ? Transaction::TYPE_SELL : Transaction::TYPE_PURCHASE;
        $hidden = $kind === 'customer' ? Transaction::UNPOSTED_SELL_STATUSES : ['draft'];

        $row = $this->outstanding->rows($kind, (int) $contact->company_id, null, ['contact_id' => $contact->id, 'include_zero' => true])->first();
        $balance = round((float) ($row['balance'] ?? 0), 2);

        $documents = Transaction::query()
            ->where('type', $type)
            ->where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->whereNotIn('status', $hidden)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get(['id', 'invoice_no', 'transaction_date', 'final_amount', 'status']);

        $paid = DB::table('payments')
            ->whereIn('transaction_id', $documents->pluck('id'))
            ->groupBy('transaction_id')
            ->pluck(DB::raw('sum(amount)'), 'transaction_id');

        return [
            'kind' => $kind,
            'balance' => $balance,
            'position' => match (true) {
                $balance > 0 => $kind === 'customer' ? 'you_owe' : 'owed_to_you',
                $balance < 0 => 'credit',
                default => 'settled',
            },
            'as_of' => now()->toDateString(),
            'documents' => $documents->map(function (Transaction $document) use ($paid): array {
                $amount = round((float) $document->final_amount, 2);
                $received = round((float) ($paid[$document->id] ?? 0), 2);

                return [
                    'id' => $document->id,
                    'invoice_no' => $document->invoice_no,
                    'date' => $document->transaction_date?->toDateString(),
                    'amount' => $amount,
                    'paid' => $received,
                    'due' => round(max($amount - $received, 0), 2),
                ];
            })->all(),
            'payments' => Payment::query()
                ->join('transactions', 'transactions.id', '=', 'payments.transaction_id')
                ->where('transactions.type', $type)
                ->where('payments.contact_id', $contact->id)
                ->where('payments.is_return', false)
                ->orderByDesc('payments.paid_on')
                ->orderByDesc('payments.id')
                ->limit(self::RECENT)
                ->get(['payments.id', 'payments.amount', 'payments.method', 'payments.paid_on', 'payments.payment_ref_no', 'transactions.invoice_no'])
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'date' => $payment->paid_on === null ? null : substr((string) $payment->paid_on, 0, 10),
                    'amount' => round((float) $payment->amount, 2),
                    'method' => $payment->method,
                    'reference' => $payment->payment_ref_no,
                    'invoice_no' => $payment->invoice_no,
                ])
                ->all(),
        ];
    }
}
