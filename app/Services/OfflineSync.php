<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\OfflineSyncEntry;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Throwable;

/**
 * Offline mode, server side: the entries a device made without a network, replayed once each through the real controllers.
 * The design is in docs/offline-mode-design.md.
 *
 * An entry is replayed AS THE USER WHO MADE IT, through the same endpoints the screens use, so the validation, the tenant scope, the
 * permission check, the period lock, the credit limit, the stock rules and the tax of an online save all apply, and the numbers that come
 * out are the ones an online save gives. A sale and its payment are one unit: if the payment is refused the sale is rolled back.
 * Nothing is ever dropped: a refusal is kept as a conflict with a code and the server's own words.
 */
class OfflineSync
{
    public const KINDS = ['sale', 'purchase', 'payment', 'expense', 'contact', 'stock_adjustment'];

    /** Menu permission(s) a kind needs on the device (the controllers check them again at sync). */
    public const PERMISSIONS = [
        'sale' => ['/sell/add'], 'purchase' => ['/purchase/add'], 'payment' => ['/sell/payment/add', '/purchase/payment/add'],
        'expense' => ['/expense/add'], 'contact' => ['/customer/add', '/supplier/add'], 'stock_adjustment' => ['/stockadjustment/add'],
    ];

    public const MAX_PAYLOAD_BYTES = 262144;

    public static function available(): bool
    {
        return Schema::hasTable('offline_sync_entries');
    }

    /**
     * The user's own branch, or null for a company-level user (the User model answers an empty string for no branch, which is not null).
     */
    private function ownBranch(User $user): ?int
    {
        $id = (int) $user->branch_id;

        return $id > 0 ? $id : null;
    }

    /** A company-level user (no branch of their own) makes entries in the company's first branch. */
    private function defaultBranch(int $companyId): ?int
    {
        $id = (int) Branch::query()->where('company_id', $companyId)->orderBy('id')->value('id');

        return $id > 0 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $data  validated: client_uuid, kind, payload, device_id, provisional_ref, created_at
     * @return array{http: int, body: array<string, mixed>}
     */
    public function sync(User $user, array $data): array
    {
        $companyId = (int) $user->company_id;

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company' => ['Offline entries belong to a company: sign in as a user of one.']]);
        }

        $existing = OfflineSyncEntry::query()->where('company_id', $companyId)->where('client_uuid', $data['client_uuid'])->first();

        if ($existing !== null) {
            abort_unless((int) $existing->user_id === (int) $user->id, 409, 'This entry belongs to another user.');

            return $this->respond($existing, true);
        }

        $branchId = $this->ownBranch($user);

        if ($branchId === null && isset($data['payload']['branch_id'])) {
            $branchId = Branch::query()->where('company_id', $companyId)->whereKey((int) $data['payload']['branch_id'])->value('id');
        }

        $branchId ??= $this->defaultBranch($companyId);

        try {
            $entry = OfflineSyncEntry::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'user_id' => $user->id, 'device_id' => $data['device_id'] ?? null,
                'client_uuid' => $data['client_uuid'], 'kind' => $data['kind'], 'provisional_ref' => $data['provisional_ref'] ?? null, 'status' => 'conflict',
                'payload' => $data['payload'], 'created_at_client' => $data['created_at'] ?? null,
            ]);
        } catch (QueryException) {
            // another request with the same UUID won the race: it answers
            return $this->respond(OfflineSyncEntry::query()->where('company_id', $companyId)->where('client_uuid', $data['client_uuid'])->firstOrFail(), true);
        }

        return $this->process($entry, $user);
    }

    /**
     * The user edited a conflict (or only asks for it to be tried again).
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{http: int, body: array<string, mixed>}
     */
    public function retry(OfflineSyncEntry $entry, User $user, ?array $payload = null): array
    {
        abort_unless($entry->status === 'conflict', 422, 'Only an entry waiting for a decision can be tried again.');
        abort_unless((int) $entry->user_id === (int) $user->id || hasMenuPermission('/offline/resolve'), 403);

        if ($payload !== null) {
            $entry->payload = $payload;
            $entry->save();
        }

        return $this->process($entry, $entry->user_id === $user->id ? $user : (User::query()->find($entry->user_id) ?? $user));
    }

    public function discard(OfflineSyncEntry $entry, User $user, string $reason): void
    {
        abort_unless($entry->status === 'conflict', 422, 'Only an entry waiting for a decision can be discarded.');
        abort_unless((int) $entry->user_id === (int) $user->id || hasMenuPermission('/offline/resolve'), 403);

        $old = ['status' => $entry->status, 'conflict_code' => $entry->conflict_code, 'error' => $entry->error];
        $entry->update(['status' => 'discarded', 'processed_at' => now()]);

        AuditLog::record($entry, 'offline_discarded', $old, ['reason' => $reason, 'kind' => $entry->kind, 'provisional_ref' => $entry->provisional_ref, 'discarded_by' => $user->id]);
    }

    /**
     * @return array{http: int, body: array<string, mixed>}
     */
    private function process(OfflineSyncEntry $entry, User $user): array
    {
        $entry->increment('attempts');
        $original = app('request');
        $actor = Auth::user();

        try {
            $result = DB::transaction(fn (): array => $this->replay($entry, $user));
            $entry->update([
                'status' => 'synced', 'conflict_code' => null, 'error' => null, 'result_type' => $result['type'], 'result_id' => $result['id'],
                'real_ref' => $result['ref'], 'processed_at' => now(),
            ]);
        } catch (OfflineRefusal $e) {
            if ($e->http === 401) {
                $entry->decrement('attempts');

                return ['http' => 401, 'body' => ['status' => 'needs_login', 'client_uuid' => $entry->client_uuid, 'message' => 'Sign in again to send this entry.']];
            }

            $entry->update(['status' => 'conflict', 'conflict_code' => $e->conflict, 'error' => mb_substr($e->getMessage(), 0, 1000), 'processed_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            $entry->update(['status' => 'conflict', 'conflict_code' => 'server_error', 'error' => mb_substr($e->getMessage(), 0, 500), 'processed_at' => now()]);
        } finally {
            app()->instance('request', $original);

            if ($actor !== null) {
                Auth::setUser($actor);
            }
        }

        return $this->respond($entry->refresh(), false);
    }

    /**
     * @return array{type: string, id: int, ref: string|null}
     *
     * @throws OfflineRefusal
     */
    private function replay(OfflineSyncEntry $entry, User $user): array
    {
        $p = (array) $entry->payload;
        $scope = ['company_id' => (int) $entry->company_id, 'branch_id' => (int) $entry->branch_id];
        $marker = 'Offline '.($entry->provisional_ref ?: 'entry').' ('.$entry->client_uuid.')';

        if ($scope['branch_id'] === 0) {
            throw new OfflineRefusal('rejected', 'The entry must belong to a branch of the company.');
        }

        return match ($entry->kind) {
            'sale' => $this->sale($user, $scope, $p, $marker),
            'purchase' => $this->purchase($user, $scope, $p, $marker),
            'payment' => $this->payment($user, $scope, $p, $marker),
            'expense' => $this->expense($user, $scope, $p, $marker),
            'contact' => $this->contact($user, $scope, $p),
            'stock_adjustment' => $this->stockAdjustment($user, $scope, $p, $marker),
            default => throw new OfflineRefusal('rejected', 'This kind of entry cannot be taken offline.'),
        };
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: int, ref: string|null}
     */
    private function sale(User $user, array $scope, array $p, string $marker): array
    {
        $lines = collect((array) ($p['lines'] ?? []))->map(fn (array $line): array => [
            'product_id' => (int) $line['product_id'], 'variation_id' => (int) $line['variation_id'], 'unit_id' => (int) $line['unit_id'], 'quantity' => (float) $line['quantity'],
            'packing_qty' => 1, 'unit_price' => round((float) $line['unit_price'], 2), 'unit_price_after_discount' => round((float) $line['unit_price'], 2), 'discount_percent' => 0,
            'row_subtotal' => round((float) $line['quantity'] * (float) $line['unit_price'], 2),
        ])->values()->all();
        $total = round(array_sum(array_column($lines, 'row_subtotal')), 2);
        $this->assertProductsAvailable($scope['company_id'], $lines);
        $this->assertContactAvailable($scope['company_id'], (int) ($p['contact_id'] ?? 0));

        $this->call($user, 'POST', '/api/sells', $scope + [
            'contact_id' => (int) ($p['contact_id'] ?? 0), 'transaction_date' => (string) ($p['date'] ?? now()->toDateString()), 'pay_term' => 0, 'pay_type' => 'day',
            'status' => 'final', 'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0, 'final_amount' => $total, 'total_item' => count($lines),
            'is_direct' => false, 'additional_note' => $marker, 'selllines' => $lines,
        ]);

        $sale = $this->find('transactions', $scope['company_id'], 'additional_note', $marker);
        $payment = (array) ($p['payment'] ?? []);

        if ($payment !== [] && (float) ($payment['amount'] ?? 0) > 0) {
            $this->call($user, 'POST', '/api/sell-payments', [
                'company_id' => $scope['company_id'], 'transaction_id' => $sale->id, 'amount' => round((float) $payment['amount'], 2),
                'paid_on' => (string) ($p['date'] ?? now()->toDateString()), 'method' => (string) ($payment['method'] ?? 'cash'),
                'payment_account' => (int) $payment['payment_account'], 'note' => $marker,
            ]);
        }

        return ['type' => 'transaction', 'id' => (int) $sale->id, 'ref' => $sale->invoice_no];
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: int, ref: string|null}
     */
    private function purchase(User $user, array $scope, array $p, string $marker): array
    {
        $lines = collect((array) ($p['lines'] ?? []))->map(fn (array $line): array => [
            'product_id' => (int) $line['product_id'], 'variation_id' => (int) $line['variation_id'], 'unit_id' => (int) $line['unit_id'], 'quantity' => (float) $line['quantity'],
            'packing_qty' => 1, 'pp_without_discount' => round((float) $line['unit_cost'], 2), 'discount_percent' => 0, 'purchase_rate' => round((float) $line['unit_cost'], 2),
            'profit_percent' => 0, 'default_sell_price' => round((float) ($line['sell_price'] ?? $line['unit_cost']), 2),
        ])->values()->all();
        $total = round(collect($lines)->sum(fn (array $line): float => $line['quantity'] * $line['purchase_rate']), 2);
        $this->assertProductsAvailable($scope['company_id'], $lines);
        $this->assertContactAvailable($scope['company_id'], (int) ($p['contact_id'] ?? 0));

        $this->call($user, 'POST', '/api/purchases', $scope + [
            'contact_id' => (int) ($p['contact_id'] ?? 0), 'transaction_date' => (string) ($p['date'] ?? now()->toDateString()), 'pay_term' => 0, 'pay_type' => 'day',
            'discount_type' => 'none', 'discount_amount' => 0, 'shipping_charges' => 0, 'final_amount' => $total, 'total_item' => count($lines), 'is_direct' => false,
            'additional_note' => $marker, 'purchaselines' => $lines,
        ]);

        $purchase = $this->find('transactions', $scope['company_id'], 'additional_note', $marker);
        $payment = (array) ($p['payment'] ?? []);

        if ($payment !== [] && (float) ($payment['amount'] ?? 0) > 0) {
            $this->call($user, 'POST', '/api/purchase-payments', [
                'company_id' => $scope['company_id'], 'transaction_id' => $purchase->id, 'amount' => round((float) $payment['amount'], 2),
                'paid_on' => (string) ($p['date'] ?? now()->toDateString()), 'method' => (string) ($payment['method'] ?? 'cash'),
                'payment_account' => (int) $payment['payment_account'], 'note' => $marker,
            ]);
        }

        return ['type' => 'transaction', 'id' => (int) $purchase->id, 'ref' => $purchase->invoice_no];
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: int, ref: string|null}
     */
    private function payment(User $user, array $scope, array $p, string $marker): array
    {
        $receipt = ($p['direction'] ?? 'receipt') === 'receipt';
        $transaction = Transaction::query()->where('company_id', $scope['company_id'])->whereKey((int) ($p['transaction_id'] ?? 0))->first();

        if ($transaction === null) {
            throw new OfflineRefusal('product_missing', 'The invoice this payment was for no longer exists.');
        }

        $this->call($user, 'POST', $receipt ? '/api/sell-payments' : '/api/purchase-payments', [
            'company_id' => $scope['company_id'], 'transaction_id' => $transaction->id, 'amount' => round((float) ($p['amount'] ?? 0), 2),
            'paid_on' => (string) ($p['date'] ?? now()->toDateString()), 'method' => (string) ($p['method'] ?? 'cash'), 'payment_account' => (int) ($p['payment_account'] ?? 0),
            'note' => $marker,
        ]);

        $row = $this->find('payments', $scope['company_id'], 'note', $marker);

        return ['type' => 'payment', 'id' => (int) $row->id, 'ref' => $transaction->invoice_no];
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: int, ref: string|null}
     */
    private function expense(User $user, array $scope, array $p, string $marker): array
    {
        $amount = round((float) ($p['amount'] ?? 0), 2);
        $description = (string) ($p['description'] ?? 'Expense');

        $this->call($user, 'POST', '/api/expenses', $scope + [
            'voucher_type' => 'EXP', 'voucher_date' => (string) ($p['date'] ?? now()->toDateString()), 'comments' => $marker.' '.$description,
            'taccountdetails' => [
                ['account_id' => (int) ($p['expense_account_id'] ?? 0), 'description' => $description, 'debit' => $amount, 'credit' => 0],
                ['account_id' => (int) ($p['payment_account_id'] ?? 0), 'description' => $description, 'debit' => 0, 'credit' => $amount],
            ],
        ]);

        $row = DB::table('t_accounts')->where('company_id', $scope['company_id'])->where('comments', 'like', $marker.'%')->orderByDesc('id')->first();

        if ($row === null) {
            throw new OfflineRefusal('rejected', 'The expense was not recorded.');
        }

        return ['type' => 't_account', 'id' => (int) $row->id, 'ref' => $row->voucher_no];
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: int, ref: string|null}
     */
    private function contact(User $user, array $scope, array $p): array
    {
        $supplier = ($p['type'] ?? 'customer') === 'supplier';
        $fields = array_intersect_key($p, array_flip([
            'business_name', 'first_name', 'last_name', 'mobile', 'email', 'address', 'ntn_number', 'strn_number', 'pay_term', 'pay_type',
        ]));
        $before = (int) DB::table('contacts')->where('company_id', $scope['company_id'])->max('id');

        $this->call($user, 'POST', $supplier ? '/api/suppliers' : '/api/customers', $scope + $fields + ['user_type' => $supplier ? 'supplier' : 'customer', 'type' => 'local']);

        $row = DB::table('contacts')->where('company_id', $scope['company_id'])->where('id', '>', $before)->orderBy('id')->first();

        if ($row === null) {
            throw new OfflineRefusal('rejected', 'The contact was not saved.');
        }

        return ['type' => 'contact', 'id' => (int) $row->id, 'ref' => $row->code];
    }

    /**
     * @param  array<string, int>  $scope
     * @param  array<string, mixed>  $p
     * @return array{type: string, id: int, ref: string|null}
     */
    private function stockAdjustment(User $user, array $scope, array $p, string $marker): array
    {
        $lines = collect((array) ($p['lines'] ?? []))->map(fn (array $line): array => [
            'product_id' => (int) $line['product_id'], 'variation_id' => (int) $line['variation_id'], 'unit_id' => (int) $line['unit_id'], 'quantity_adjustment' => (float) $line['quantity'],
        ])->values()->all();

        $this->assertProductsAvailable($scope['company_id'], $lines);

        // an adjustment taken offline is always sent as pending: posting it is the approver's decision
        $this->call($user, 'POST', '/api/stockadjustments', $scope + [
            'transaction_date' => (string) ($p['date'] ?? now()->toDateString()), 'adjustment_type' => 'normal', 'status' => 'pending', 'additional_note' => $marker.' '.($p['note'] ?? ''),
            'total_item' => count($lines), 'purchaselines' => $lines,
        ]);

        $row = DB::table('transactions')->where('company_id', $scope['company_id'])->where('additional_note', 'like', $marker.'%')->orderByDesc('id')->first();

        if ($row === null) {
            throw new OfflineRefusal('rejected', 'The adjustment was not recorded.');
        }

        return ['type' => 'transaction', 'id' => (int) $row->id, 'ref' => $row->invoice_no];
    }

    /**
     * A line of an entry made yesterday may name a product that was deleted or stopped since: that is a conflict, not a sale of it.
     *
     * @param  array<int, array<string, mixed>>  $lines
     *
     * @throws OfflineRefusal
     */
    private function assertProductsAvailable(int $companyId, array $lines): void
    {
        $variations = collect($lines)->pluck('variation_id')->map(fn ($id): int => (int) $id)->unique()->values();

        $found = DB::table('product_details as d')->join('products as p', 'p.id', '=', 'd.product_id')
            ->where('p.company_id', $companyId)->where('p.active', 1)->whereNull('p.deleted_at')->whereIn('d.id', $variations)->pluck('d.id')->map(fn ($id): int => (int) $id);

        if ($variations->diff($found)->isNotEmpty()) {
            throw new OfflineRefusal('product_missing', 'A product on this entry was deleted or is no longer sold.');
        }
    }

    private function assertContactAvailable(int $companyId, int $contactId): void
    {
        if (! DB::table('contacts')->where('company_id', $companyId)->where('id', $contactId)->whereNull('deleted_at')->exists()) {
            throw new OfflineRefusal('product_missing', 'The customer or supplier on this entry was deleted.');
        }
    }

    private function find(string $table, int $companyId, string $column, string $value): object
    {
        $row = DB::table($table)->where('company_id', $companyId)->where($column, $value)->orderByDesc('id')->first();

        if ($row === null) {
            throw new OfflineRefusal('rejected', 'The document was not recorded.');
        }

        return $row;
    }

    /**
     * One call to a real endpoint, as the user, in this process.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws OfflineRefusal
     */
    private function call(User $user, string $method, string $uri, array $payload): array
    {
        Sanctum::actingAs($user, ['*']);
        $request = Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($payload));
        $response = app(Kernel::class)->handle($request);
        $body = json_decode((string) $response->getContent(), true);
        $body = is_array($body) ? $body : [];
        $status = $response->getStatusCode();

        if ($status < 400) {
            return $body;
        }

        $message = (string) ($body['errormessage'] ?? $body['message'] ?? '');

        if ($status === 422 && isset($body['errors']) && is_array($body['errors'])) {
            $message = (string) (collect($body['errors'])->flatten()->first() ?: $message);
        }

        if ($status === 401) {
            throw new OfflineRefusal('needs_login', 'Sign in again.', 401);
        }

        throw new OfflineRefusal($this->classify($status, $message), $message !== '' ? $message : "The server answered {$status}.", $status);
    }

    /**
     * The conflict code of a refusal, from what the server said: the codes the screen shows a reason for.
     */
    private function classify(int $status, string $message): string
    {
        $text = strtolower($message);

        return match (true) {
            $status === 403 => 'no_permission',
            str_contains($text, 'locked') || str_contains($text, 'period') => 'period_locked',
            str_contains($text, 'credit limit') => 'credit_limit',
            str_contains($text, 'stock') || str_contains($text, 'insufficient') || str_contains($text, 'available') => 'insufficient_stock',
            str_contains($text, 'blacklist') || str_contains($text, 'on hold') => 'contact_blocked',
            $status === 404 || str_contains($text, 'does not exist') || str_contains($text, 'inactive') || str_contains($text, 'invalid product') || str_contains($text, 'selected product') => 'product_missing',
            default => 'rejected',
        };
    }

    /**
     * @return array{http: int, body: array<string, mixed>}
     */
    private function respond(OfflineSyncEntry $entry, bool $repeat): array
    {
        return ['http' => 200, 'body' => [
            'status' => $entry->status, 'repeat' => $repeat, 'id' => $entry->id, 'client_uuid' => $entry->client_uuid, 'kind' => $entry->kind,
            'provisional_ref' => $entry->provisional_ref, 'real_ref' => $entry->real_ref, 'result_type' => $entry->result_type, 'result_id' => $entry->result_id,
            'conflict_code' => $entry->conflict_code, 'message' => $entry->error,
        ]];
    }

    /**
     * What a device caches to fill the offline forms: the user's company and branch only, read only.
     *
     * @return array<string, mixed>
     */
    public function reference(User $user): array
    {
        $companyId = (int) $user->company_id;
        $branchId = $this->ownBranch($user);

        $products = DB::table('product_details as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.company_id', $companyId)->where('p.active', 1)->whereNull('p.deleted_at')
            ->orderBy('p.name')->orderBy('d.id')->limit(5001)
            ->get(['d.id as variation_id', 'p.id as product_id', 'p.unit_id', 'u.short_name as unit', 'p.name as product_name', 'd.name', 'd.sku', 'd.default_sell_price as sell_price', 'd.default_purchase_price as purchase_price']);
        $barcodes = Schema::hasTable('product_barcodes')
            ? DB::table('product_barcodes')->where('company_id', $companyId)->get(['product_id', 'barcode'])->groupBy('product_id')->map(fn ($rows) => $rows->pluck('barcode')->values()->all())
            : collect();

        $contacts = DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branchId)->orWhereNull('branch_id')))
            ->orderBy('business_name')->limit(1000)->get(['id', 'business_name', 'first_name', 'last_name', 'user_type', 'mobile', 'pay_term', 'pay_type', 'is_blacklisted', 'is_on_hold']);

        $paid = DB::table('payments')->where('company_id', $companyId)->where('is_return', 0)->groupBy('transaction_id')->selectRaw('transaction_id, sum(amount) as paid')->pluck('paid', 'transaction_id');
        $open = DB::table('transactions')->where('company_id', $companyId)->whereIn('type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE])->whereNull('deleted_at')
            ->whereNotIn('status', ['draft', 'quotation'])->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('id')->limit(800)->get(['id', 'invoice_no', 'type', 'contact_id', 'final_amount'])
            ->map(fn (object $t): array => [
                'id' => (int) $t->id, 'invoice_no' => $t->invoice_no, 'direction' => $t->type === Transaction::TYPE_SELL ? 'receipt' : 'payment', 'contact_id' => (int) $t->contact_id,
                'total' => round((float) $t->final_amount, 2), 'due' => round((float) $t->final_amount - (float) ($paid[$t->id] ?? 0), 2),
            ])->filter(fn (array $t): bool => $t['due'] > 0.004)->values();
        $dueByContact = $open->groupBy('contact_id')->map(fn ($rows) => round($rows->sum('due'), 2));

        $kinds = collect(self::PERMISSIONS)->map(fn (array $paths): array => array_values(array_filter($paths, fn (string $path): bool => hasMenuPermission($path))));

        return [
            'generated_at' => now()->toIso8601String(),
            'user_id' => (int) $user->id, 'company_id' => $companyId, 'branch_id' => $branchId ?? $this->defaultBranch($companyId),
            'truncated' => $products->count() > 5000,
            'products' => $products->take(5000)->map(fn (object $row): array => [
                'variation_id' => (int) $row->variation_id, 'product_id' => (int) $row->product_id, 'unit_id' => (int) $row->unit_id, 'unit' => $row->unit,
                'name' => (string) ($row->name ?: $row->product_name), 'sku' => (string) $row->sku, 'sell_price' => round((float) $row->sell_price, 2),
                'purchase_price' => round((float) $row->purchase_price, 2), 'barcodes' => $barcodes[$row->product_id] ?? [],
            ])->values(),
            'contacts' => $contacts->map(fn (object $row): array => [
                'id' => (int) $row->id, 'name' => trim((string) ($row->business_name ?: $row->first_name.' '.$row->last_name)), 'type' => $row->user_type, 'mobile' => $row->mobile,
                'pay_term' => $row->pay_term, 'pay_type' => $row->pay_type, 'blocked' => (bool) ($row->is_blacklisted || $row->is_on_hold), 'due' => $dueByContact[$row->id] ?? 0.0,
            ])->values(),
            'balances_read_at' => now()->toIso8601String(),
            'open_invoices' => $open,
            'units' => DB::table('units')->where('company_id', $companyId)->get(['id', 'short_name'])->map(fn (object $u): array => ['id' => (int) $u->id, 'name' => $u->short_name])->values(),
            'taxes' => Schema::hasTable('taxes') ? DB::table('taxes')->where('company_id', $companyId)->whereNull('deleted_at')->get(['id', 'name', 'rate'])->values() : [],
            'methods' => Payment::METHODS,
            'payment_accounts' => collect(ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId))
                ->map(fn ($account): array => ['id' => (int) data_get($account, 'id'), 'name' => (string) (data_get($account, 'text') ?? data_get($account, 'name') ?? '')])->values(),
            'expense_accounts' => DB::table('chart_of_accounts')->where('company_id', $companyId)->where('acc_type', 't')->where('acc_nature', 'dr')->where('bs', 0)->where('active', 1)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderBy('code')->limit(300)->get(['id', 'code', 'name'])->values(),
            'branches' => DB::table('branches')->where('company_id', $companyId)->get(['id', 'name'])->values(),
            'warehouses' => Schema::hasTable('warehouses') ? DB::table('warehouses')->where('company_id', $companyId)->get(['id', 'name'])->values() : [],
            'allowed' => $kinds,
        ];
    }
}
