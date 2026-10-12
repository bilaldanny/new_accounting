<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email, SMS and WhatsApp message templates by event. The words are free text with {placeholders} that are replaced by plain values
 * (no code, no markup is evaluated). A company's default template of the event and channel is used; with none, the built-in text.
 *
 * Placeholders: {customer} {invoice} {amount} {balance} {date} {due_date} {days_overdue} {company} {link}
 */
class MessageTemplates
{
    public const EVENTS = ['invoice_created', 'receipt', 'payment_due_soon', 'payment_due', 'payment_overdue', 'payment_received', 'custom'];

    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    public const PLACEHOLDERS = ['customer', 'invoice', 'amount', 'balance', 'date', 'due_date', 'days_overdue', 'company', 'link'];

    /**
     * @var array<string, array{subject: string, body: string}>
     */
    private const BUILT_IN = [
        'invoice_created' => ['subject' => 'Invoice {invoice} from {company}', 'body' => "Dear {customer},\n\nInvoice {invoice} dated {date} for {amount} is ready. View it here: {link}\n\n{company}"],
        'receipt' => ['subject' => 'Receipt {invoice} from {company}', 'body' => 'Your receipt from {company}: invoice {invoice}, {amount}. View it here: {link}'],
        'payment_due_soon' => ['subject' => 'Invoice {invoice} is due on {due_date}', 'body' => "Dear {customer},\n\nA friendly reminder that invoice {invoice} for {balance} is due on {due_date}. {link}\n\n{company}"],
        'payment_due' => ['subject' => 'Invoice {invoice} is due today', 'body' => "Dear {customer},\n\nInvoice {invoice} for {balance} is due today. {link}\n\n{company}"],
        'payment_overdue' => ['subject' => 'Invoice {invoice} is overdue', 'body' => "Dear {customer},\n\nInvoice {invoice} for {balance} was due on {due_date} and is {days_overdue} days overdue. Please arrange payment. {link}\n\n{company}"],
        'payment_received' => ['subject' => 'Payment received for invoice {invoice}', 'body' => "Dear {customer},\n\nWe received your payment for invoice {invoice}. Thank you.\n\n{company}"],
        'custom' => ['subject' => 'Message from {company}', 'body' => 'Dear {customer}, {company}'],
    ];

    public static function available(): bool
    {
        return Schema::hasTable('message_templates');
    }

    /**
     * @return array{id: int|null, subject: string, body: string, source: string}
     */
    public function resolve(int $companyId, string $event, string $channel, string $language = 'en', ?int $templateId = null): array
    {
        if (self::available()) {
            $base = fn () => DB::table('message_templates')->where('company_id', $companyId)->where('event', $event)->where('channel', $channel)->where('is_active', true);
            $row = $templateId !== null
                ? DB::table('message_templates')->where('company_id', $companyId)->where('is_active', true)->where('id', $templateId)->first()
                : ((clone $base())->where('language', $language)->orderByDesc('is_default')->orderBy('id')->first() ?? $base()->where('language', 'en')->orderByDesc('is_default')->orderBy('id')->first());

            if ($row !== null) {
                return ['id' => (int) $row->id, 'subject' => (string) $row->subject, 'body' => (string) $row->body, 'source' => 'template'];
            }
        }

        $built = self::BUILT_IN[$event] ?? self::BUILT_IN['custom'];

        return ['id' => null, 'subject' => $built['subject'], 'body' => $built['body'], 'source' => 'built-in'];
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    public function render(string $text, array $values): string
    {
        $map = [];

        foreach (self::PLACEHOLDERS as $name) {
            $map['{'.$name.'}'] = (string) ($values[$name] ?? '');
        }

        return trim(strtr($text, $map));
    }
}
