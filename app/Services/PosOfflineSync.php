<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PosOfflineSale;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Offline POS: the catalog a terminal caches, and the replay of the sales it rang up offline.
 *
 * What may be done offline is deliberately small: a sale to the walk-in or a known customer, paid in full in cash or by a card
 * slip recorded at the till, at the catalog price. Returns, credit sales, discounts, loyalty and gift cards, and closing the day
 * or the books all need the server and stay online.
 *
 * A sale replayed here is one unit (the sale and its payments, in one transaction) and idempotent on its client UUID. The server
 * stays the authority: a sale it cannot accept as sent is parked for a supervisor instead of being forced in:
 *
 *   price_changed    the catalog price moved since the terminal cached it (the sale is shown with both prices)
 *   product_missing  a product is no longer sold (deleted, inactive, or not of this company)
 *   period_locked    the day of the sale is in a locked period (a supervisor may post it today)
 *   total_mismatch   the server's total (tax, rounding) is not the one the customer was charged
 *
 * Stock is the one exception, as agreed: a sale that takes stock below zero is posted and the lines are flagged (`oversold`), as
 * the goods have already left. Provisional receipt numbers carry the terminal's own prefix (OFF-...), so two terminals can never
 * collide; the invoice number is the server's, assigned at sync and shown next to the provisional one.
 */
class PosOfflineSync
{
    public const METHODS = ['cash', 'card'];

    public static function available(): bool
    {
        return Schema::hasTable('pos_offline_sales');
    }

    /**
     * What a terminal caches: sellable variations with their price, the cash / bank accounts a payment can go to, the default
     * customer, and a version that changes when a price does.
     *
     * @return array<string, mixed>
     */
    public function catalog(int $companyId, ?int $branchId): array
    {
        $products = DB::table('product_details as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $companyId)->where('p.active', 1)->whereNull('p.deleted_at')
            ->orderBy('p.name')->orderBy('d.id')
            ->limit(5001)
            ->get(['d.id as variation_id', 'p.id as product_id', 'p.unit_id', 'u.short_name as unit', 'p.name as product_name', 'd.name', 'd.sku', 'd.default_sell_price as price']);

        $truncated = $products->count() > 5000;
        $rows = $products->take(5000)->map(fn (object $row): array => [
            'variation_id' => (int) $row->variation_id, 'product_id' => (int) $row->product_id, 'unit_id' => (int) $row->unit_id, 'unit' => $row->unit,
            'name' => (string) ($row->name ?: $row->product_name), 'sku' => (string) $row->sku, 'price' => round((float) $row->price, 2),
        ])->values();

        $accounts = collect(ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId))
            ->map(fn ($account): array => ['id' => (int) data_get($account, 'id'), 'name' => (string) (data_get($account, 'text') ?? data_get($account, 'name') ?? '')])->values();
        $company = Company::query()->find($companyId);
        $customers = Contact::query()->where('company_id', $companyId)->whereIn('user_type', ['customer', 'both'])->orderBy('business_name')->limit(500)
            ->get(['id', 'business_name', 'first_name', 'last_name'])
            ->map(fn (Contact $contact): array => ['id' => (int) $contact->id, 'name' => trim((string) ($contact->business_name ?: $contact->first_name.' '.$contact->last_name))])->values();

        return [
            'catalog_version' => substr(md5($rows->map(fn (array $row): string => $row['variation_id'].':'.$row['price'])->implode('|')), 0, 16),
            'generated_at' => now()->toIso8601String(),
            'truncated' => $truncated,
            'company' => ['id' => $companyId, 'name' => $company?->name],
            'default_customer_id' => CompanySetting::query()->where('company_id', $companyId)->value('default_customer'),
            'customers' => $customers,
            'payment_accounts' => $accounts,
            'methods' => self::METHODS,
            'products' => $rows,
        ];
    }

    /**
     * Replays one offline sale. Returns the HTTP status and the body to send back.
     *
     * @param  array<string, mixed>  $data  validated: client_uuid, device_id, offline_receipt_no, sold_at, catalog_version, contact_id, total, lines, payments
     * @return array{http: int, body: array<string, mixed>}
     */
    public function sync(User $user, array $data): array
    {
        $companyId = (int) $user->company_id;
        $branchId = $user->branch_id !== null ? (int) $user->branch_id : null;

        if ($branchId === null && isset($data['branch_id'])) {
            $branchId = Branch::query()->where('company_id', $companyId)->whereKey((int) $data['branch_id'])->value('id');
        }

        if ($companyId === 0 || $branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['The terminal must belong to a branch of the company.']]);
        }

        $row = PosOfflineSale::query()->where('company_id', $companyId)->where('client_uuid', $data['client_uuid'])->first();

        if ($row !== null && in_array($row->status, ['synced', 'discarded', 'conflict'], true)) {
            return $this->respond($row, true);
        }

        try {
            $row ??= PosOfflineSale::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'client_uuid' => $data['client_uuid'], 'device_id' => $data['device_id'] ?? null,
                'offline_receipt_no' => $data['offline_receipt_no'] ?? null, 'sold_at' => $data['sold_at'] ?? null,
                'catalog_version' => $data['catalog_version'] ?? null, 'total' => round((float) $data['total'], 2), 'payload' => $data,
                'status' => 'failed', 'created_by' => $user->id,
            ]);
        } catch (QueryException) {
            // another request with the same UUID won the race: it is the one that answers
            $row = PosOfflineSale::query()->where('company_id', $companyId)->where('client_uuid', $data['client_uuid'])->firstOrFail();

            return $this->respond($row, true);
        }

        return $this->process($row, $data, ['accept_prices' => false, 'use_today' => false]);
    }

    /**
     * A supervisor's decision on a parked sale: post it as it was sold, or discard it.
     *
     * @return array{http: int, body: array<string, mixed>}
     */
    public function resolve(PosOfflineSale $sale, string $action): array
    {
        if ($sale->status !== 'conflict') {
            throw ValidationException::withMessages(['status' => ['Only a sale waiting for review can be resolved.']]);
        }

        if ($action === 'discard') {
            $sale->update(['status' => 'discarded', 'resolved_by' => Auth::id(), 'resolved_at' => now()]);

            return $this->respond($sale, false);
        }

        $result = $this->process($sale, (array) $sale->payload, ['accept_prices' => true, 'use_today' => $sale->conflict_code === 'period_locked']);

        if ($result['body']['status'] === 'synced') {
            $sale->refresh()->update(['resolved_by' => Auth::id(), 'resolved_at' => now()]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{accept_prices: bool, use_today: bool}  $options
     * @return array{http: int, body: array<string, mixed>}
     */
    private function process(PosOfflineSale $row, array $data, array $options): array
    {
        $companyId = (int) $row->company_id;
        $branchId = (int) $row->branch_id;

        // 1. what this terminal may not have done offline
        foreach ((array) ($data['payments'] ?? []) as $index => $payment) {
            if (! in_array($payment['method'] ?? null, self::METHODS, true)) {
                return $this->fail($row, "payments.{$index}.method", 'Only cash and card slips recorded at the till can be taken offline.');
            }
        }

        foreach ((array) ($data['lines'] ?? []) as $index => $line) {
            if ((float) ($line['discount_percent'] ?? 0) > 0 || (float) ($line['discount'] ?? 0) > 0) {
                return $this->fail($row, "lines.{$index}.discount", 'Discounts need approval and cannot be given offline.');
            }
        }

        $paid = round(array_sum(array_map(fn (array $payment): float => (float) $payment['amount'], (array) $data['payments'])), 2);

        if (abs($paid - round((float) $data['total'], 2)) > 0.005) {
            return $this->fail($row, 'payments', 'An offline sale must be paid in full; credit sales are made online.');
        }

        $accountIds = collect(ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId))->map(fn ($account): int => (int) data_get($account, 'id'))->all();

        foreach ((array) $data['payments'] as $index => $payment) {
            if (! in_array((int) $payment['payment_account'], $accountIds, true)) {
                return $this->fail($row, "payments.{$index}.payment_account", 'The payment account is not a cash or bank account of this branch.');
            }
        }

        $contactId = (int) ($data['contact_id'] ?? 0) ?: (int) CompanySetting::query()->where('company_id', $companyId)->value('default_customer');

        if ($contactId === 0 || ! Contact::query()->where('company_id', $companyId)->whereKey($contactId)->exists()) {
            return $this->fail($row, 'contact_id', 'The customer is not a customer of this company.');
        }

        // 2. what the server will not take as sent: parked for review
        $variationIds = collect((array) $data['lines'])->pluck('variation_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $current = DB::table('product_details as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->where('p.company_id', $companyId)->where('p.active', 1)->whereNull('p.deleted_at')
            ->whereIn('d.id', $variationIds)->pluck('d.default_sell_price', 'd.id');

        $missing = array_values(array_diff($variationIds, $current->keys()->map(fn ($id): int => (int) $id)->all()));

        if ($missing !== []) {
            return $this->park($row, 'product_missing', ['variation_ids' => $missing]);
        }

        if (! $options['accept_prices']) {
            $changed = [];

            foreach ((array) $data['lines'] as $line) {
                $server = round((float) $current[(int) $line['variation_id']], 2);

                if (abs($server - round((float) $line['unit_price'], 2)) > 0.005) {
                    $changed[] = ['variation_id' => (int) $line['variation_id'], 'sold_at_price' => round((float) $line['unit_price'], 2), 'current_price' => $server];
                }
            }

            if ($changed !== []) {
                return $this->park($row, 'price_changed', ['lines' => $changed]);
            }
        }

        $soldAt = Carbon::parse((string) ($data['sold_at'] ?? now()));
        $day = $options['use_today'] || $soldAt->isFuture() ? now()->toDateString() : $soldAt->toDateString();
        $reason = app(PeriodLock::class)->blocks($companyId, $branchId, $day);

        if ($reason !== null) {
            return $this->park($row, 'period_locked', ['day' => $day, 'reason' => $reason]);
        }

        // 3. post it: the sale and its payments as one unit
        try {
            $result = DB::transaction(function () use ($row, $data, $companyId, $branchId, $contactId, $day): array {
                $request = new Request([
                    'company_id' => $companyId, 'branch_id' => $branchId, 'contact_id' => $contactId, 'transaction_date' => $day, 'status' => 'final',
                    'is_pos' => true, 'payment_status' => 'due', 'final_amount' => round((float) $data['total'], 2),
                    'additional_note' => trim('Offline sale '.($data['offline_receipt_no'] ?? '').' ('.$data['client_uuid'].')'),
                    'selllines' => collect((array) $data['lines'])->map(fn (array $line): array => [
                        'product_id' => (int) $line['product_id'], 'variation_id' => (int) $line['variation_id'], 'unit_id' => (int) $line['unit_id'],
                        'quantity' => (float) $line['quantity'], 'packing_qty' => 1, 'unit_price' => round((float) $line['unit_price'], 2),
                        'unit_price_after_discount' => round((float) $line['unit_price'], 2), 'discount_percent' => 0,
                        'row_subtotal' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
                    ])->all(),
                ]);

                /** @var Transaction $sale */
                $sale = Transaction::allowingOversell(fn (): Transaction => Transaction::createSell($request));

                if (abs((float) $sale->final_amount - round((float) $data['total'], 2)) > 0.01) {
                    throw new TotalMismatch((float) $sale->final_amount);
                }

                foreach ((array) $data['payments'] as $payment) {
                    Payment::createSellPayment(new Request([
                        'company_id' => $companyId, 'branch_id' => $branchId, 'transaction_id' => $sale->id, 'amount' => round((float) $payment['amount'], 2),
                        'paid_on' => $day, 'method' => $payment['method'], 'payment_account' => (int) $payment['payment_account'],
                        'note' => 'Offline sale '.($data['offline_receipt_no'] ?? ''),
                    ]));
                }

                $row->update([
                    'status' => 'synced', 'transaction_id' => $sale->id, 'invoice_no' => $sale->invoice_no, 'synced_at' => now(), 'error' => null,
                    'conflict_code' => null, 'conflict_detail' => null,
                    'oversold' => collect((array) ($sale->stockWarnings ?? []))->map(fn (array $line): array => [
                        'variation_id' => $line['variation_id'], 'product_name' => $line['product_name'], 'requested' => $line['requested'], 'available' => $line['available'],
                    ])->values()->all() ?: null,
                ]);

                return ['sale' => $sale];
            });
        } catch (TotalMismatch $e) {
            return $this->park($row, 'total_mismatch', ['client_total' => round((float) $data['total'], 2), 'server_total' => $e->serverTotal]);
        } catch (PeriodLockedException $e) {
            return $this->park($row, 'period_locked', ['day' => $day, 'reason' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return $this->fail($row, 'sale', collect($e->errors())->flatten()->first() ?: 'The sale was refused.');
        } catch (Throwable $e) {
            report($e);

            return $this->fail($row, 'sale', 'The server could not post this sale: '.mb_substr($e->getMessage(), 0, 200));
        }

        return $this->respond($row->refresh(), false);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{http: int, body: array<string, mixed>}
     */
    private function park(PosOfflineSale $row, string $code, array $detail): array
    {
        $row->update(['status' => 'conflict', 'conflict_code' => $code, 'conflict_detail' => $detail, 'error' => null]);

        return $this->respond($row->refresh(), false);
    }

    /**
     * @return array{http: int, body: array<string, mixed>}
     */
    private function fail(PosOfflineSale $row, string $field, string $message): array
    {
        $row->update(['status' => 'failed', 'error' => $message]);

        return ['http' => 422, 'body' => ['status' => 'failed', 'client_uuid' => $row->client_uuid, 'message' => $message, 'errors' => [$field => [$message]]]];
    }

    /**
     * @return array{http: int, body: array<string, mixed>}
     */
    private function respond(PosOfflineSale $row, bool $repeat): array
    {
        return ['http' => 200, 'body' => [
            'status' => $row->status, 'repeat' => $repeat, 'client_uuid' => $row->client_uuid, 'offline_receipt_no' => $row->offline_receipt_no,
            'id' => $row->id, 'transaction_id' => $row->transaction_id, 'invoice_no' => $row->invoice_no, 'oversold' => $row->oversold ?? [],
            'conflict_code' => $row->conflict_code, 'conflict_detail' => $row->conflict_detail,
        ]];
    }
}
