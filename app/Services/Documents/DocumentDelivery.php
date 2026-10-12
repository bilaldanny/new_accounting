<?php

namespace App\Services\Documents;

use App\Models\Company;
use App\Models\Contact;
use App\Models\ReceiptShare;
use App\Models\Transaction;
use App\Services\MessageDispatcher;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a sale to its customer: a share link (the public page of the receipt route, random token, 30 days) delivered by email through
 * the app's mail settings or by WhatsApp / SMS through the company's configured provider. The words come from the message templates
 * (MessageTemplates) of the event. With no provider configured the share is recorded as `not_configured` and nothing is sent.
 *
 * `afterSale()` is the instant delivery: when the company switched it on, a posted sale is sent to the customer as soon as it is
 * saved. It never throws - a failed delivery never fails the sale - and it only reads the sale.
 */
class DocumentDelivery
{
    public function __construct(private readonly MessageDispatcher $dispatcher, private readonly MessageTemplates $templates) {}

    /**
     * @return array{status: string, error: string|null, url: string, share_id: int, recipient: string|null}
     */
    public function send(Transaction $sale, string $channel, ?string $recipient, string $event = 'invoice_created', string $language = 'en', ?int $templateId = null, ?int $userId = null): array
    {
        $companyId = (int) $sale->getRawOriginal('company_id');
        $contact = $sale->contact_id === null ? null : Contact::query()->find($sale->contact_id);
        $recipient = trim((string) $recipient);

        if ($recipient === '' && $channel !== 'link') {
            $recipient = (string) ($channel === 'email' ? $contact?->email : $contact?->mobile);
        }

        $share = ReceiptShare::query()->create([
            'company_id' => $companyId, 'transaction_id' => $sale->id, 'token' => Str::random(40), 'channel' => $channel,
            'recipient' => $recipient !== '' ? $recipient : null, 'status' => 'created', 'expires_at' => now()->addDays(30), 'created_by' => $userId,
        ]);

        $url = route('receipt.public', ['token' => $share->token]);
        $company = (string) Company::query()->where('id', $companyId)->value('name');
        $paid = round((float) DB::table('payments')->where('transaction_id', $sale->id)->where('is_return', false)->sum('amount'), 2);
        $due = PartyAgingReport::dueDate((string) $sale->getRawOriginal('transaction_date'), $sale->pay_term, $sale->pay_type, $contact?->pay_term, $contact?->pay_type);
        $values = [
            'customer' => $contact === null ? '' : trim((string) ($contact->business_name ?: trim($contact->first_name.' '.$contact->last_name))),
            'invoice' => (string) $sale->invoice_no, 'amount' => number_format((float) $sale->final_amount, 2), 'balance' => number_format((float) $sale->final_amount - $paid, 2),
            'date' => substr((string) $sale->getRawOriginal('transaction_date'), 0, 10), 'due_date' => $due->toDateString(),
            'days_overdue' => (string) max(0, (int) $due->diffInDays(Carbon::today(), false)), 'company' => $company, 'link' => $url,
        ];

        $message = $this->templates->resolve($companyId, $event, $channel === 'link' ? 'email' : $channel, $language);
        $subject = $this->templates->render($message['subject'], $values);
        $body = $this->templates->render($message['body'], $values);

        if ($channel === 'email') {
            if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $share->update(['status' => 'failed', 'error' => 'No valid email address.']);
            } else {
                try {
                    Mail::raw($body, fn ($mail) => $mail->to($recipient)->subject($subject));
                    $share->update(['status' => 'sent', 'sent_at' => now()]);
                } catch (Throwable $e) {
                    $share->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)]);
                }
            }
        } elseif ($channel !== 'link') {
            if ($recipient === '') {
                $share->update(['status' => 'failed', 'error' => 'No phone number.']);
            } else {
                $result = $this->dispatcher->send($companyId, $channel, $recipient, $body);
                $share->update(['status' => $result['status'], 'error' => $result['error'], 'sent_at' => $result['status'] === 'sent' ? now() : null]);
            }
        }

        $share->refresh();

        return ['status' => (string) $share->status, 'error' => $share->error, 'url' => $url, 'share_id' => (int) $share->id, 'recipient' => $share->recipient];
    }

    /**
     * Instant delivery of a posted sale, when the company turned it on.
     */
    public function afterSale(Transaction $sale): void
    {
        try {
            if (! Schema::hasTable('document_delivery_settings') || in_array($sale->status, Transaction::UNPOSTED_SELL_STATUSES, true)) {
                return;
            }

            $setting = DB::table('document_delivery_settings')->where('company_id', (int) $sale->getRawOriginal('company_id'))->where('auto_send_sale', true)->first();

            if ($setting === null) {
                return;
            }

            $this->send($sale, (string) $setting->channel, null, 'invoice_created', (string) $setting->language);
        } catch (Throwable) {
            // delivery must never break saving a sale
        }
    }
}
