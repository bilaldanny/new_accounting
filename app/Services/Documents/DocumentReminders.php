<?php

namespace App\Services\Documents;

use App\Models\CollectionCase;
use App\Models\Contact;
use App\Models\Transaction;
use App\Services\Reports\PartyAgingReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Automatic due and overdue reminders for sales, from the company's rules (before the due date, on it, or after it, once or every
 * N days up to a maximum), sent by email / SMS / WhatsApp with the message templates. Run by the daily command.
 *
 * It is separate from Dunning (a reviewed selection a person sends): this one is unattended, so it is opt-in per rule, never
 * writes to the ledger, sends a rule to an invoice at most once per day (a unique log row) and not beyond `max_sends`, leaves out
 * customers whose collection case is disputed, escalated or closed (or promised to pay on a date still ahead), and records every
 * attempt. An invoice with no balance left is never reminded.
 */
class DocumentReminders
{
    public const TRIGGERS = ['before_due', 'on_due', 'after_due'];

    public function __construct(private readonly DocumentDelivery $delivery) {}

    public static function available(): bool
    {
        return Schema::hasTable('document_reminder_rules') && Schema::hasTable('document_reminder_log');
    }

    /**
     * @return array{sent: int, not_sent: int, skipped: int}
     */
    public function runDue(?int $companyId = null, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $result = ['sent' => 0, 'not_sent' => 0, 'skipped' => 0];

        if (! self::available()) {
            return $result;
        }

        $rules = DB::table('document_reminder_rules')->where('is_active', true)->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->orderBy('id')->get();

        foreach ($rules->groupBy('company_id') as $company => $companyRules) {
            $invoices = Transaction::query()->withoutGlobalScopes()->where('company_id', $company)->where('type', Transaction::TYPE_SELL)
                ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->where('payment_status', '!=', 'paid')->whereNull('deleted_at')->whereNotNull('contact_id')->get();

            if ($invoices->isEmpty()) {
                continue;
            }

            $contacts = Contact::query()->withoutGlobalScopes()->whereIn('id', $invoices->pluck('contact_id')->unique()->all())->get()->keyBy('id');
            $cases = CollectionCase::query()->where('company_id', $company)->get()->keyBy('contact_id');
            $paid = DB::table('payments')->whereIn('transaction_id', $invoices->pluck('id')->all())->where('is_return', false)->groupBy('transaction_id')->selectRaw('transaction_id, sum(amount) as paid')->pluck('paid', 'transaction_id');

            foreach ($companyRules as $rule) {
                foreach ($invoices as $invoice) {
                    $contact = $contacts[$invoice->contact_id] ?? null;
                    $balance = round((float) $invoice->final_amount - (float) ($paid[$invoice->id] ?? 0), 2);

                    if ($contact === null || $balance <= 0) {
                        continue;
                    }

                    $due = PartyAgingReport::dueDate((string) $invoice->getRawOriginal('transaction_date'), $invoice->pay_term, $invoice->pay_type, $contact->pay_term, $contact->pay_type)->startOfDay();
                    $days = (int) $today->diffInDays($due, false);
                    $case = $cases[$invoice->contact_id] ?? null;

                    if ($rule->trigger === 'after_due' && $case !== null && (in_array($case->status, ['disputed', 'escalated', 'closed'], true) || ($case->status === 'promised' && $case->promised_on !== null && Carbon::parse($case->promised_on)->gte($today)))) {
                        $result['skipped']++;

                        continue;
                    }

                    $prior = DB::table('document_reminder_log')->where('rule_id', $rule->id)->where('transaction_id', $invoice->id)->where('status', 'sent')->orderByDesc('sent_on')->get(['sent_on']);
                    $isDue = match ($rule->trigger) {
                        'before_due' => $days > 0 && $days <= (int) $rule->days && $prior->isEmpty(),
                        'on_due' => $days === 0 && $prior->isEmpty(),
                        'after_due' => -$days >= max(1, (int) $rule->days) && ($prior->isEmpty()
                            || ($rule->repeat_every_days !== null && $prior->count() < (int) $rule->max_sends
                                && abs($today->diffInDays(Carbon::parse($prior->first()->sent_on)->startOfDay())) >= (int) $rule->repeat_every_days)),
                        default => false,
                    };

                    if (! $isDue) {
                        continue;
                    }

                    if (DB::table('document_reminder_log')->where('rule_id', $rule->id)->where('transaction_id', $invoice->id)->whereDate('sent_on', $today->toDateString())->exists()) {
                        $result['skipped']++;

                        continue;
                    }

                    $recipient = $rule->channel === 'email' ? (string) $contact->email : (string) $contact->mobile;

                    if ($recipient === '' || ($rule->channel === 'email' && ! filter_var($recipient, FILTER_VALIDATE_EMAIL))) {
                        $result['skipped']++;

                        continue;
                    }

                    $event = match ($rule->trigger) {
                        'before_due' => 'payment_due_soon',
                        'on_due' => 'payment_due',
                        default => 'payment_overdue',
                    };

                    try {
                        $sent = $this->delivery->send($invoice, (string) $rule->channel, $recipient, $event, (string) $rule->language, $rule->message_template_id !== null ? (int) $rule->message_template_id : null);
                    } catch (Throwable $e) {
                        $sent = ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 240)];
                    }

                    DB::table('document_reminder_log')->insert([
                        'company_id' => $company, 'rule_id' => $rule->id, 'transaction_id' => $invoice->id, 'sent_on' => $today->toDateString(), 'channel' => $rule->channel,
                        'recipient' => mb_substr($recipient, 0, 190), 'status' => $sent['status'], 'error' => $sent['error'] !== null ? mb_substr((string) $sent['error'], 0, 250) : null, 'created_at' => now(),
                    ]);
                    $result[$sent['status'] === 'sent' ? 'sent' : 'not_sent']++;
                }

                DB::table('document_reminder_rules')->where('id', $rule->id)->update(['last_run_at' => now()]);
            }
        }

        return $result;
    }
}
