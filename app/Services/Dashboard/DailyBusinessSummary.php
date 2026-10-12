<?php

namespace App\Services\Dashboard;

use App\Models\Transaction;
use App\Services\MessageDispatcher;
use App\Services\Reports\PurchaseSaleSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The owner's daily business summary: what the company did on one day (net sales and purchases from the same summary the reports
 * use, money received and paid out, expense vouchers, the three best selling products), sent by email and, through the company's
 * messaging provider, by WhatsApp or SMS. A company chooses its recipients and channels in the settings; nothing is sent until it is
 * switched on. Each recipient and channel is recorded once a day (`daily_summary_runs`), so running the command twice does not send
 * twice, and a failure is recorded with its message. Reading figures changes nothing in the books.
 */
class DailyBusinessSummary
{
    public const CHANNELS = ['email', 'whatsapp', 'sms'];

    public function __construct(private readonly PurchaseSaleSummary $trade, private readonly MessageDispatcher $messages) {}

    /**
     * @return array<string, mixed>
     */
    public function settings(int $companyId): array
    {
        $row = DB::table('daily_summary_settings')->where('company_id', $companyId)->first();

        return [
            'enabled' => (bool) ($row->enabled ?? false), 'send_hour' => (int) ($row->send_hour ?? 20),
            'channels' => (array) json_decode((string) ($row->channels ?? '["email"]'), true), 'recipients' => (array) json_decode((string) ($row->recipients ?? '[]'), true),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function save(int $companyId, array $input): array
    {
        $channels = array_values(array_unique(array_filter((array) ($input['channels'] ?? []), fn ($c): bool => in_array($c, self::CHANNELS, true))));
        $recipients = [];

        foreach ((array) ($input['recipients'] ?? []) as $recipient) {
            $recipient = trim((string) $recipient);

            if ($recipient === '') {
                continue;
            }

            if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) && preg_match('/^\+?[0-9][0-9\s\-()]{6,20}$/', $recipient) !== 1) {
                throw ValidationException::withMessages(['recipients' => ["'{$recipient}' is not an email address or a phone number."]]);
            }

            $recipients[] = $recipient;
        }

        if (count($recipients) > 10) {
            throw ValidationException::withMessages(['recipients' => ['Use at most 10 recipients.']]);
        }

        $hour = (int) ($input['send_hour'] ?? 20);

        if ($hour < 0 || $hour > 23) {
            throw ValidationException::withMessages(['send_hour' => ['Use an hour from 0 to 23.']]);
        }

        DB::table('daily_summary_settings')->updateOrInsert(['company_id' => $companyId], [
            'enabled' => (bool) ($input['enabled'] ?? false), 'send_hour' => $hour, 'channels' => json_encode($channels === [] ? ['email'] : $channels),
            'recipients' => json_encode(array_values(array_unique($recipients))), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->settings($companyId);
    }

    /**
     * The figures of one day.
     *
     * @return array<string, mixed>
     */
    public function figures(int $companyId, string $date): array
    {
        $totals = $this->trade->build($companyId, null, ['start_date' => $date, 'end_date' => $date])['totals'];
        $received = (float) DB::table('payments as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')->where('p.company_id', $companyId)->where('t.type', Transaction::TYPE_SELL)
            ->where('p.is_return', 0)->whereDate('p.paid_on', $date)->sum('p.amount');
        $paid = (float) DB::table('payments as p')->join('transactions as t', 't.id', '=', 'p.transaction_id')->where('p.company_id', $companyId)->where('t.type', Transaction::TYPE_PURCHASE)
            ->where('p.is_return', 0)->whereDate('p.paid_on', $date)->sum('p.amount');
        $expenses = (float) DB::table('t_accounts')->where('company_id', $companyId)->where('status', 'approved')->where('voucher_no', 'like', 'EXP-%')->whereDate('voucher_date', $date)->sum('total_amount');
        $invoices = (int) DB::table('transactions')->where('company_id', $companyId)->where('type', Transaction::TYPE_SELL)->whereNull('deleted_at')
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->whereDate('transaction_date', $date)->count();
        $top = DB::table('sell_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('t.company_id', $companyId)->where('t.type', Transaction::TYPE_SELL)->whereNull('t.deleted_at')->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->whereDate('t.transaction_date', $date)->groupBy('l.product_id', 'p.name')->selectRaw('p.name, sum(l.subtotal) as amount')->orderByDesc('amount')->limit(3)->get()
            ->map(fn (object $r): array => ['name' => (string) $r->name, 'amount' => round((float) $r->amount, 2)])->all();

        return [
            'date' => $date, 'invoices' => $invoices, 'net_sales' => (float) $totals['net_sales'], 'net_purchases' => (float) $totals['net_purchases'],
            'received' => round($received, 2), 'paid' => round($paid, 2), 'expenses' => round($expenses, 2), 'top_products' => $top,
        ];
    }

    public function text(int $companyId, string $date): string
    {
        $f = $this->figures($companyId, $date);
        $name = (string) (DB::table('companies')->where('id', $companyId)->value('name') ?? '');
        $m = fn (float $v): string => number_format($v, 2);
        $lines = [
            "Daily summary {$f['date']}".($name !== '' ? " - {$name}" : ''),
            "Sales: {$m($f['net_sales'])} ({$f['invoices']} invoices)", "Purchases: {$m($f['net_purchases'])}",
            "Received: {$m($f['received'])}", "Paid to suppliers: {$m($f['paid'])}", "Expense vouchers: {$m($f['expenses'])}",
        ];

        if ($f['top_products'] !== []) {
            $lines[] = 'Top products: '.implode(', ', array_map(fn (array $p): string => $p['name'].' '.$m($p['amount']), $f['top_products']));
        }

        return implode("\n", $lines);
    }

    /**
     * Sends the summary of a day to every recipient on every channel it was not sent on yet.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function send(int $companyId, string $date, bool $force = false): array
    {
        $settings = $this->settings($companyId);

        if (! $force && ! $settings['enabled']) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $text = $this->text($companyId, $date);
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($settings['recipients'] as $recipient) {
            $isEmail = filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;

            foreach ($settings['channels'] as $channel) {
                if (($channel === 'email') !== $isEmail) {
                    continue;
                }

                if (DB::table('daily_summary_runs')->where('company_id', $companyId)->where('run_date', $date)->where('channel', $channel)->where('recipient', $recipient)->where('status', 'sent')->exists()) {
                    $skipped++;

                    continue;
                }

                [$status, $error] = $this->deliver($companyId, $channel, $recipient, $date, $text);
                DB::table('daily_summary_runs')->updateOrInsert(
                    ['company_id' => $companyId, 'run_date' => $date, 'channel' => $channel, 'recipient' => $recipient],
                    ['status' => $status, 'error' => $error, 'created_at' => now()],
                );
                $status === 'sent' ? $sent++ : $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function deliver(int $companyId, string $channel, string $recipient, string $date, string $text): array
    {
        try {
            if ($channel === 'email') {
                Mail::raw($text, fn ($message) => $message->to($recipient)->subject('Daily business summary '.$date));

                return ['sent', null];
            }

            $result = $this->messages->send($companyId, $channel, $recipient, $text);

            return [$result['status'] === 'sent' ? 'sent' : 'failed', $result['status'] === 'sent' ? null : mb_substr((string) ($result['error'] ?? $result['status']), 0, 300)];
        } catch (Throwable $e) {
            return ['failed', mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
