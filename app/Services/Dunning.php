<?php

namespace App\Services;

use App\Mail\DunningMail;
use App\Models\CollectionCase;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DunningEvent;
use App\Models\DunningLevel;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invoice dunning: three levels (reminder, second reminder, final notice) per company, each with its own days-past-due rule and
 * editable subject and body. A run lists the overdue invoices of every customer with the level each has reached and has not had
 * yet; nothing is sent until a reviewed selection is sent. A send is an email through the company's mail settings (SMTP), or a
 * log entry for a follow-up made by hand, and is recorded so a level is never sent twice for an invoice. Customers marked
 * disputed, escalated or closed, or promised to pay on a date still ahead, are left out. Nothing here posts to the ledger.
 */
class Dunning
{
    public const DEFAULTS = [
        1 => ['name' => 'Reminder', 'days' => 7, 'subject' => 'Payment reminder: invoice {invoice}',
            'body' => "Dear {customer},\n\nOur records show that invoice {invoice} for {amount}, due on {due_date}, is {days_overdue} days past due. If you have already paid, please ignore this reminder; otherwise we would be grateful for your payment.\n\n{company}"],
        2 => ['name' => 'Second reminder', 'days' => 21, 'subject' => 'Second reminder: invoice {invoice} is overdue',
            'body' => "Dear {customer},\n\nInvoice {invoice} for {amount} was due on {due_date} and is now {days_overdue} days overdue. Please arrange payment this week or contact us if there is a problem with the invoice.\n\n{company}"],
        3 => ['name' => 'Final notice', 'days' => 45, 'subject' => 'Final notice: invoice {invoice}',
            'body' => "Dear {customer},\n\nThis is a final notice for invoice {invoice} for {amount}, due on {due_date} and {days_overdue} days overdue. Unless we receive payment or hear from you, we will have to take further steps to recover it.\n\n{company}"],
    ];

    public function __construct(private readonly PartyAgingReport $aging) {}

    public static function available(): bool
    {
        return Schema::hasTable('dunning_levels') && Schema::hasTable('dunning_events');
    }

    /**
     * The company's three levels; the defaults are created the first time they are asked for.
     *
     * @return Collection<int, DunningLevel>
     */
    public function levels(int $companyId): Collection
    {
        foreach (self::DEFAULTS as $level => $default) {
            DunningLevel::query()->firstOrCreate(['company_id' => $companyId, 'level' => $level], [
                'name' => $default['name'], 'days_past_due' => $default['days'], 'subject' => $default['subject'], 'body' => $default['body'], 'is_active' => true,
            ]);
        }

        return DunningLevel::query()->where('company_id', $companyId)->orderBy('level')->get();
    }

    /**
     * @param  array{name?: string, days_past_due?: int, subject?: string, body?: string, is_active?: bool}  $data
     */
    public function updateLevel(int $companyId, int $level, array $data): DunningLevel
    {
        $this->levels($companyId);
        $row = DunningLevel::query()->where('company_id', $companyId)->where('level', $level)->firstOrFail();
        $row->update($data);

        $days = DunningLevel::query()->where('company_id', $companyId)->orderBy('level')->pluck('days_past_due')->all();

        if ($days !== collect($days)->sort()->values()->all()) {
            throw ValidationException::withMessages(['days_past_due' => ['A later level must come at or after the earlier ones.']]);
        }

        return $row;
    }

    /**
     * Overdue invoices that have reached a level and not had it yet, grouped by customer.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $companyId, ?int $branchId, string $asOf): array
    {
        $levels = $this->levels($companyId)->where('is_active', true)->sortByDesc('level');
        $invoices = $this->aging->openInvoiceRows('customer', $companyId, $branchId, [], $asOf)->where('days_past_due', '>', 0);
        $sent = DunningEvent::query()->where('company_id', $companyId)->whereIn('status', ['sent', 'logged'])->get(['transaction_id', 'level'])
            ->map(fn (DunningEvent $event): string => $event->transaction_id.'|'.$event->level)->all();
        $cases = CollectionCase::query()->where('company_id', $companyId)->get()->keyBy('contact_id');
        $rows = [];

        foreach ($invoices as $invoice) {
            $case = $cases->get($invoice['contact_id']);

            if ($case !== null && (in_array($case->status, ['disputed', 'escalated', 'closed'], true)
                || ($case->status === 'promised' && $case->promised_on !== null && $case->promised_on->toDateString() >= $asOf))) {
                continue;
            }

            $level = $levels->first(fn (DunningLevel $candidate): bool => $candidate->days_past_due <= $invoice['days_past_due']);

            if ($level === null || in_array($invoice['id'].'|'.$level->level, $sent, true)) {
                continue;
            }

            $rows[] = $invoice + ['level' => (int) $level->level, 'level_name' => $level->name, 'case_status' => $case?->status ?? 'open'];
        }

        return $rows;
    }

    /**
     * Sends (or logs) the chosen reminders. `$items` is a list of invoice id and level.
     *
     * @param  list<array{transaction_id: int, level: int}>  $items
     * @return array{sent: int, logged: int, skipped: int, failed: int, events: list<int>}
     */
    public function send(int $companyId, ?int $branchId, array $items, string $channel, string $asOf, ?int $userId = null): array
    {
        $levels = $this->levels($companyId)->keyBy('level');
        $invoices = $this->aging->openInvoiceRows('customer', $companyId, $branchId, [], $asOf)->keyBy('id');
        $company = Company::query()->find($companyId);
        $result = ['sent' => 0, 'logged' => 0, 'skipped' => 0, 'failed' => 0, 'events' => []];

        foreach ($items as $item) {
            $invoice = $invoices->get((int) $item['transaction_id']);
            $level = $levels->get((int) $item['level']);

            if ($invoice === null || $level === null) {
                $result['skipped']++;

                continue;
            }

            if (DunningEvent::query()->where('transaction_id', $invoice['id'])->where('level', $level->level)->whereIn('status', ['sent', 'logged'])->exists()) {
                $result['skipped']++;

                continue;
            }

            $contact = Contact::query()->where('company_id', $companyId)->find($invoice['contact_id']);
            $values = [
                '{customer}' => (string) $invoice['contact_name'], '{invoice}' => (string) $invoice['invoice_no'],
                '{amount}' => number_format((float) $invoice['open_amount'], 2), '{due_date}' => (string) $invoice['due_date'],
                '{days_overdue}' => (string) $invoice['days_past_due'], '{company}' => (string) ($company?->name ?? ''),
            ];
            $subject = strtr($level->subject, $values);
            $body = strtr($level->body, $values);
            $recipient = $contact?->email ? (string) $contact->email : null;
            $status = 'logged';

            if ($channel === 'email') {
                if ($recipient === null || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                    $status = 'no_email';
                } else {
                    try {
                        Mail::to($recipient)->send(new DunningMail((string) $invoice['contact_name'], $subject, $body, [
                            'Invoice' => $invoice['invoice_no'], 'Due date' => $invoice['due_date'], 'Amount due' => number_format((float) $invoice['open_amount'], 2),
                            'Days overdue' => $invoice['days_past_due'],
                        ], $company));
                        $status = 'sent';
                    } catch (Throwable) {
                        $status = 'failed';
                    }
                }
            }

            $event = DunningEvent::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $invoice['contact_id'], 'transaction_id' => $invoice['id'],
                'level' => $level->level, 'channel' => $channel, 'status' => $status, 'recipient' => $recipient, 'subject' => $subject, 'body' => $body,
                'amount' => $invoice['open_amount'], 'created_by' => $userId ?? Auth::id(),
            ]);

            $result['events'][] = (int) $event->id;

            match ($status) {
                'sent' => $result['sent']++,
                'logged' => $result['logged']++,
                'failed' => $result['failed']++,
                default => $result['skipped']++,
            };
        }

        return $result;
    }

    public function setCase(int $companyId, int $contactId, string $status, ?string $promisedOn, ?string $note, ?int $userId): CollectionCase
    {
        if (! Contact::query()->where('company_id', $companyId)->whereKey($contactId)->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer of this company.']]);
        }

        if ($status === 'promised' && $promisedOn === null) {
            throw ValidationException::withMessages(['promised_on' => ['A promise needs the date the customer promised to pay by.']]);
        }

        $case = CollectionCase::query()->firstOrNew(['company_id' => $companyId, 'contact_id' => $contactId]);
        $case->fill(['status' => $status, 'promised_on' => $status === 'promised' ? $promisedOn : null, 'note' => $note, 'updated_by' => $userId])->save();

        return $case;
    }
}
