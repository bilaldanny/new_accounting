<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedPeriods;
use App\Services\LedgerJournal;
use App\Support\Base64Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    use GuardsLockedPeriods;

    public static function periodLockDateColumn(): string
    {
        return 'paid_on';
    }

    /**
     * @return list<string>
     */
    public static function periodLockBookkeeping(): array
    {
        return ['updated_at', 'updated_by', 't_account_id', 'document', 'note', 'is_withholding'];
    }

    public const METHODS = ['cash', 'card', 'cheque', 'bank_transfer', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'transaction_id',
        'contact_id',
        'payment_account',
        't_account_id',
        'is_return',
        'amount',
        'method',
        'card_transaction_number',
        'card_number',
        'card_type',
        'card_holder_name',
        'card_month',
        'card_year',
        'card_security',
        'cheque_number',
        'cheque_date',
        'bank_account_number',
        'paid_on',
        'note',
        'document',
        'payment_ref_no',
    ];

    protected $attributes = [
        'is_return' => 0,
        'method' => 'cash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_return' => 'boolean',
            'is_withholding' => 'boolean',
            'amount' => 'decimal:2',
            'cheque_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'payment_account');
    }

    /**
     * @return BelongsTo<TAccount, $this>
     */
    public function tAccount(): BelongsTo
    {
        return $this->belongsTo(TAccount::class, 't_account_id');
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('company_id', $user->company_id);

        if ($user?->branch_id && ! $user?->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    public function scopeForPurchases(Builder $query): Builder
    {
        return $query->whereHas('transaction', function (Builder $transactionQuery): void {
            $transactionQuery->where('type', Transaction::TYPE_PURCHASE);
        });
    }

    public function scopeForSells(Builder $query): Builder
    {
        return $query->whereHas('transaction', function (Builder $transactionQuery): void {
            $transactionQuery->where('type', Transaction::TYPE_SELL);
        });
    }

    public static function findVisiblePurchasePayment(int $id): ?self
    {
        return self::query()->forPurchases()->visibleToCurrentUser()->find($id);
    }

    public static function findVisibleSellPayment(int $id): ?self
    {
        return self::query()->forSells()->visibleToCurrentUser()->find($id);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function paidAmountForTransaction(int $transactionId, ?int $exceptPaymentId = null): float
    {
        $query = self::query()->where('transaction_id', $transactionId);

        if ($exceptPaymentId !== null) {
            $query->where('id', '!=', $exceptPaymentId);
        }

        return round((float) $query->sum('amount'), 2);
    }

    public static function remainingAmountForTransaction(Transaction $transaction, ?int $exceptPaymentId = null): float
    {
        $remaining = round((float) $transaction->final_amount, 2)
            - self::paidAmountForTransaction((int) $transaction->id, $exceptPaymentId);

        return round(max($remaining, 0), 2);
    }

    /**
     * @throws ValidationException
     */
    public static function assertAmountWithinRemaining(Transaction $transaction, float $amount, ?int $exceptPaymentId = null): void
    {
        // Lock the parent transaction row so two concurrent payment
        // submissions against it serialize instead of both reading the same
        // "remaining" balance and jointly overpaying it.
        $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->first() ?? $transaction;

        $remaining = self::remainingAmountForTransaction($locked, $exceptPaymentId);

        if ($amount > $remaining) {
            throw ValidationException::withMessages([
                'amount' => ['Amount cannot be greater than the remaining balance of '.$remaining.'.'],
            ]);
        }
    }

    public static function generatePaymentRefNo(int $companyId, int $branchId, string $family = 'purchase'): string
    {
        $settingKey = $family === 'sell' ? 'sell_payment' : 'purchase_payment';
        $defaultPrefix = $family === 'sell' ? 'SP' : 'PP';

        $prefix = CompanySetting::query()
            ->where('company_id', $companyId)
            ->value($settingKey);

        $prefix = is_string($prefix) && trim($prefix) !== '' ? trim($prefix) : $defaultPrefix;
        $period = Carbon::now()->format('Y-m');
        $pattern = $prefix.$period.'-';

        return DB::transaction(function () use ($companyId, $branchId, $pattern): string {
            $last = self::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('payment_ref_no', 'like', $pattern.'%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('payment_ref_no');

            $next = 1;

            if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
                $next = (int) $matches[1] + 1;
            }

            do {
                $refNo = $pattern.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
                $next++;
            } while (
                self::query()
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('payment_ref_no', $refNo)
                    ->lockForUpdate()
                    ->exists()
            );

            return $refNo;
        });
    }

    public static function syncTransactionPaymentStatus(int $transactionId): Transaction
    {
        $transaction = Transaction::query()->findOrFail($transactionId);
        $paid = self::paidAmountForTransaction($transactionId);
        $final = round((float) $transaction->final_amount, 2);

        if ($paid <= 0) {
            $status = 'due';
        } elseif ($paid >= $final) {
            $status = 'paid';
        } else {
            $status = 'partial';
        }

        $transaction->payment_status = $status;
        $transaction->paid_amount = $paid;
        $transaction->save();

        if (! in_array($transaction->type, [Transaction::TYPE_PURCHASE_RETURN, Transaction::TYPE_SELL_RETURN], true)) {
            Transaction::query()
                ->where('parent_id', $transaction->id)
                ->get()
                ->each(function (Transaction $child) use ($status, $paid): void {
                    $child->payment_status = $status;
                    $child->paid_amount = $paid;
                    $child->save();
                });
        }

        return $transaction->fresh() ?? $transaction;
    }

    public static function imageDirectory(): string
    {
        $path = public_path('images/payment_images');
        File::isDirectory($path) or File::makeDirectory($path, 0755, true, true);

        return $path;
    }

    public static function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return asset(ltrim($path, '/'));
        }

        return asset('images/payment_images/'.$path);
    }

    public static function storeDocument(object $request, ?string $existing = null, ?int $companyId = null): ?string
    {
        if (is_object($request) && method_exists($request, 'hasFile')) {
            if ($request->hasFile('attachment') && $request->file('attachment') instanceof UploadedFile) {
                $file = $request->file('attachment');
                if ($companyId !== null) {
                    Company::find($companyId)?->assertCanAddStorageBytes((int) $file->getSize());
                }

                return self::saveDocumentFile($file);
            }

            if ($request->hasFile('document') && $request->file('document') instanceof UploadedFile) {
                $file = $request->file('document');
                if ($companyId !== null) {
                    Company::find($companyId)?->assertCanAddStorageBytes((int) $file->getSize());
                }

                return self::saveDocumentFile($file);
            }
        }

        $document = $request->attachment ?? $request->document ?? null;

        if (is_string($document) && str_starts_with($document, 'data:')) {
            if ($companyId !== null) {
                Company::find($companyId)?->assertCanAddStorageBytes((int) strlen($document));
            }

            return self::saveDocumentFromBase64($document);
        }

        if (is_string($document) && $document !== '') {
            return $document;
        }

        return $existing;
    }

    /**
     * @throws ValidationException
     */
    public static function saveDocumentFile(UploadedFile $file): string
    {
        $extension = Base64Upload::extensionForMime((string) $file->getMimeType());

        if ($extension === null) {
            throw ValidationException::withMessages([
                'document' => ['The document must be a JPEG, PNG, or PDF file.'],
            ]);
        }

        $filename = time().'.payment.'.$extension;
        $file->move(self::imageDirectory(), $filename);

        return $filename;
    }

    public static function saveDocumentFromBase64(string $image): ?string
    {
        $decoded = Base64Upload::decode($image);

        if ($decoded === null) {
            return null;
        }

        $filename = time().'.payment.'.$decoded['extension'];
        file_put_contents(self::imageDirectory().'/'.$filename, $decoded['binary']);

        return $filename;
    }

    public static function parsePaidOn(mixed $value): string
    {
        $raw = is_string($value) ? str_replace('/', '-', $value) : $value;

        return Carbon::parse($raw)->format('Y-m-d H:i');
    }

    /**
     * @return array<string, mixed>
     */
    public static function methodPayload(object $request): array
    {
        $method = (string) ($request->method ?? 'cash');

        $payload = [
            'card_transaction_number' => null,
            'card_number' => null,
            'card_type' => null,
            'card_holder_name' => null,
            'card_month' => null,
            'card_year' => null,
            'card_security' => null,
            'cheque_number' => null,
            'cheque_date' => null,
            'bank_account_number' => null,
        ];

        if ($method === 'card') {
            $payload['card_transaction_number'] = $request->card_transaction_number;
            $payload['card_number'] = $request->card_number;
            $payload['card_type'] = $request->card_type;
            $payload['card_holder_name'] = $request->card_holder_name;
            $payload['card_month'] = $request->card_month;
            $payload['card_year'] = $request->card_year;
            $payload['card_security'] = $request->card_security;
        }

        if ($method === 'cheque') {
            $payload['cheque_number'] = $request->cheque_number;
            $payload['cheque_date'] = filled($request->cheque_date) ? $request->cheque_date : null;
        }

        if ($method === 'bank_transfer') {
            $payload['bank_account_number'] = $request->bank_account_number;
        }

        return $payload;
    }

    /**
     * @throws ValidationException
     */
    public static function createPurchasePayment(object $request): self
    {
        $purchase = self::visiblePurchaseFromRequest($request);
        $amount = round((float) $request->amount, 2);

        self::assertAmountWithinRemaining($purchase, $amount);

        $companyId = (int) $purchase->company_id;
        $branchId = (int) $purchase->branch_id;

        $payment = new self;
        $payment->fill(self::methodPayload($request));
        $payment->company_id = $companyId;
        $payment->branch_id = $branchId;
        $payment->transaction_id = $purchase->id;
        $payment->contact_id = $purchase->contact_id;
        $payment->payment_account = self::resolveScopedId($request->payment_account);
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = $request->method;
        $payment->paid_on = self::parsePaidOn($request->paid_on);
        $payment->note = $request->note;
        $payment->document = self::storeDocument($request, companyId: $companyId);
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'purchase');
        self::postLedgerEntry($purchase, $payment);
        $payment->save();

        self::syncTransactionPaymentStatus((int) $purchase->id);

        return $payment;
    }

    /**
     * @throws ValidationException
     */
    public static function createSellPayment(object $request): self
    {
        $sell = self::visibleSellFromRequest($request);
        $amount = round((float) $request->amount, 2);

        self::assertAmountWithinRemaining($sell, $amount);

        $companyId = (int) $sell->company_id;
        $branchId = (int) $sell->branch_id;

        $payment = new self;
        $payment->fill(self::methodPayload($request));
        $payment->company_id = $companyId;
        $payment->branch_id = $branchId;
        $payment->transaction_id = $sell->id;
        $payment->contact_id = $sell->contact_id;
        $payment->payment_account = self::resolveScopedId($request->payment_account);
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = $request->method;
        $payment->paid_on = self::parsePaidOn($request->paid_on);
        $payment->note = $request->note;
        $payment->document = self::storeDocument($request, companyId: $companyId);
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'sell');
        self::postLedgerEntry($sell, $payment);
        $payment->save();

        self::syncTransactionPaymentStatus((int) $sell->id);

        return $payment;
    }

    /**
     * Money received from a customer before any invoice asks for it (the part of a cash collection that was
     * more than the invoices it settled). It is posted like a sell payment (cash / bank debit, the customer's
     * account credit, so the ledger shows the customer's credit) but it belongs to no invoice: `transaction_id`
     * stays empty and the row is only reached through the collection that made it.
     *
     * @throws ValidationException
     */
    public static function createSellAdvance(Contact $contact, int $companyId, int $branchId, float $amount, string $paidOn, int $cashOrBankAccountId, string $note): self
    {
        $cashOrBank = ChartOfAccount::query()->find($cashOrBankAccountId);

        if ($cashOrBank === null) {
            throw ValidationException::withMessages([
                'payment_account' => ['Select a valid cash or bank account before saving the payment.'],
            ]);
        }

        $anchor = new Transaction;
        $anchor->company_id = $companyId;
        $anchor->branch_id = $branchId;

        $ledger = app(LedgerJournal::class);
        $contactAccount = $ledger->accountByCode($anchor, (string) ($contact->customer_gl_id ?: $contact->gl_id));

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['Link the customer to a chart of account before keeping an advance for them.'],
            ]);
        }

        $amount = round($amount, 2);
        $contactName = $contact->business_name ?: trim($contact->first_name.' '.$contact->last_name);

        $payment = new self;
        $payment->company_id = $companyId;
        $payment->branch_id = $branchId;
        $payment->transaction_id = null;
        $payment->contact_id = $contact->id;
        $payment->payment_account = $cashOrBank->id;
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = 'cash';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'sell');

        $journal = $ledger->postPayment($payment, $cashOrBank, 'Advance from '.$contactName, 'SP', [
            ['account' => $cashOrBank, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $contact->id],
            ['account' => $contactAccount, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $contact->id],
        ]);

        $payment->t_account_id = $journal->id;
        $payment->save();

        return $payment;
    }

    /**
     * Money paid back to a customer out of their store credit: cash / bank is credited and the customer's account debited (their
     * receivable is back where it was before the return), with no invoice attached. `is_return` marks it as money going out.
     *
     * @throws ValidationException
     */
    public static function createSellRefund(Contact $customer, int $companyId, int $branchId, float $amount, string $paidOn, int $cashOrBankAccountId, string $method, string $note): self
    {
        $cashOrBank = ChartOfAccount::query()->where('company_id', $companyId)->find($cashOrBankAccountId);

        if ($cashOrBank === null) {
            throw ValidationException::withMessages([
                'payment_account' => ['Select a valid cash or bank account before saving the refund.'],
            ]);
        }

        $anchor = new Transaction;
        $anchor->company_id = $companyId;
        $anchor->branch_id = $branchId;

        $ledger = app(LedgerJournal::class);
        $contactAccount = $ledger->accountByCode($anchor, (string) ($customer->customer_gl_id ?: $customer->gl_id));

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['Link the customer to a chart of account before refunding them.'],
            ]);
        }

        $amount = round($amount, 2);
        $contactName = $customer->business_name ?: trim($customer->first_name.' '.$customer->last_name);

        $payment = new self;
        $payment->company_id = $companyId;
        $payment->branch_id = $branchId;
        $payment->transaction_id = null;
        $payment->contact_id = $customer->id;
        $payment->payment_account = $cashOrBank->id;
        $payment->is_return = true;
        $payment->amount = $amount;
        $payment->method = in_array($method, ['cash', 'bank', 'cheque', 'card', 'online', 'other'], true) ? $method : 'cash';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'sell');

        $journal = $ledger->postPayment($payment, $cashOrBank, 'Refund to '.$contactName, 'SP', [
            ['account' => $contactAccount, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $customer->id],
            ['account' => $cashOrBank, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $customer->id],
        ]);

        $payment->t_account_id = $journal->id;
        $payment->save();

        return $payment;
    }

    /**
     * An early-payment discount taken on an invoice or bill. It settles part of the document without cash moving: on a sale the
     * customer's account is credited and the discount account debited (a sales discount); on a purchase the supplier's account is
     * debited and the discount account credited (a purchase discount received). The row counts as a payment on the document, so
     * its balance and payment status follow.
     *
     * @throws ValidationException
     */
    public static function createDocumentDiscount(Transaction $document, ChartOfAccount $discountAccount, float $amount, string $paidOn, string $note): self
    {
        $amount = round($amount, 2);

        self::assertAmountWithinRemaining($document, $amount);

        $document->loadMissing('contact');
        $contact = $document->contact;
        $isPurchase = $document->type === Transaction::TYPE_PURCHASE;
        $ledger = app(LedgerJournal::class);

        $contactCode = $contact === null ? '' : ($isPurchase ? ($contact->supplier_gl_id ?: $contact->gl_id) : ($contact->customer_gl_id ?: $contact->gl_id));
        $contactAccount = $contact === null ? null : $ledger->accountByCode($document, (string) $contactCode);

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'transaction_id' => ['Link the '.($isPurchase ? 'supplier' : 'customer').' to a chart of account before taking a discount.'],
            ]);
        }

        $payment = new self;
        $payment->company_id = $document->company_id;
        $payment->branch_id = $document->branch_id;
        $payment->transaction_id = $document->id;
        $payment->contact_id = $document->contact_id;
        $payment->payment_account = null;
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = 'other';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo((int) $document->company_id, (int) $document->branch_id, $isPurchase ? 'purchase' : 'sell');

        $lines = $isPurchase
            ? [
                ['account' => $contactAccount, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $contact->id],
                ['account' => $discountAccount, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $contact->id],
            ]
            : [
                ['account' => $discountAccount, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $contact->id],
                ['account' => $contactAccount, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $contact->id],
            ];

        $journal = $ledger->postPayment($payment, $discountAccount, 'Early payment discount '.$document->invoice_no, $isPurchase ? 'PP' : 'SP', $lines);

        $payment->t_account_id = $journal->id;
        $payment->save();

        self::syncTransactionPaymentStatus((int) $document->id);

        return $payment;
    }

    /**
     * Money paid to a supplier before there is a bill for it: the cash / bank account is credited and the supplier's account
     * debited (it reduces what is owed), with no bill attached. The mirror of createSellAdvance.
     *
     * @throws ValidationException
     */
    public static function createPurchaseAdvance(Contact $supplier, int $companyId, int $branchId, float $amount, string $paidOn, int $cashOrBankAccountId, string $note): self
    {
        $cashOrBank = ChartOfAccount::query()->where('company_id', $companyId)->find($cashOrBankAccountId);

        if ($cashOrBank === null) {
            throw ValidationException::withMessages([
                'payment_account' => ['Select a valid cash or bank account before saving the payment.'],
            ]);
        }

        $anchor = new Transaction;
        $anchor->company_id = $companyId;
        $anchor->branch_id = $branchId;

        $ledger = app(LedgerJournal::class);
        $contactAccount = $ledger->accountByCode($anchor, (string) ($supplier->supplier_gl_id ?: $supplier->gl_id));

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['Link the supplier to a chart of account before paying them in advance.'],
            ]);
        }

        $amount = round($amount, 2);
        $contactName = $supplier->business_name ?: trim($supplier->first_name.' '.$supplier->last_name);

        $payment = new self;
        $payment->company_id = $companyId;
        $payment->branch_id = $branchId;
        $payment->transaction_id = null;
        $payment->contact_id = $supplier->id;
        $payment->payment_account = $cashOrBank->id;
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = 'cash';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'purchase');

        $journal = $ledger->postPayment($payment, $cashOrBank, 'Advance to '.$contactName, 'PP', [
            ['account' => $contactAccount, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $supplier->id],
            ['account' => $cashOrBank, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $supplier->id],
        ]);

        $payment->t_account_id = $journal->id;
        $payment->save();

        return $payment;
    }

    /**
     * Money a supplier pays back out of an advance that was not used: cash / bank is debited and the supplier's account credited
     * (their balance is back to what it was before the advance), with no bill attached. The mirror of createSellRefund.
     *
     * @throws ValidationException
     */
    public static function createPurchaseAdvanceRefund(Contact $supplier, int $companyId, int $branchId, float $amount, string $paidOn, int $cashOrBankAccountId, string $method, string $note): self
    {
        $cashOrBank = ChartOfAccount::query()->where('company_id', $companyId)->find($cashOrBankAccountId);

        if ($cashOrBank === null) {
            throw ValidationException::withMessages([
                'payment_account' => ['Select a valid cash or bank account before saving the refund.'],
            ]);
        }

        $anchor = new Transaction;
        $anchor->company_id = $companyId;
        $anchor->branch_id = $branchId;

        $ledger = app(LedgerJournal::class);
        $contactAccount = $ledger->accountByCode($anchor, (string) ($supplier->supplier_gl_id ?: $supplier->gl_id));

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['Link the supplier to a chart of account before taking an advance back.'],
            ]);
        }

        $amount = round($amount, 2);
        $contactName = $supplier->business_name ?: trim($supplier->first_name.' '.$supplier->last_name);

        $payment = new self;
        $payment->company_id = $companyId;
        $payment->branch_id = $branchId;
        $payment->transaction_id = null;
        $payment->contact_id = $supplier->id;
        $payment->payment_account = $cashOrBank->id;
        $payment->is_return = true;
        $payment->amount = $amount;
        $payment->method = in_array($method, ['cash', 'bank', 'cheque', 'card', 'online', 'other'], true) ? $method : 'cash';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'purchase');

        $journal = $ledger->postPayment($payment, $cashOrBank, 'Advance refund from '.$contactName, 'PP', [
            ['account' => $cashOrBank, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $supplier->id],
            ['account' => $contactAccount, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $supplier->id],
        ]);

        $payment->t_account_id = $journal->id;
        $payment->save();

        return $payment;
    }

    /**
     * A payment on a bill that is settled out of an advance already paid: no cash moves and no voucher is posted (the
     * supplier's account already holds the debit); it only settles the bill's payment status.
     *
     * @throws ValidationException
     */
    public static function createPurchasePaymentFromAdvance(Transaction $purchase, float $amount, string $paidOn, string $note): self
    {
        $amount = round($amount, 2);

        self::assertAmountWithinRemaining($purchase, $amount);

        $payment = new self;
        $payment->company_id = $purchase->company_id;
        $payment->branch_id = $purchase->branch_id;
        $payment->transaction_id = $purchase->id;
        $payment->contact_id = $purchase->contact_id;
        $payment->payment_account = null;
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = 'other';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo((int) $purchase->company_id, (int) $purchase->branch_id, 'purchase');
        $payment->save();

        self::syncTransactionPaymentStatus((int) $purchase->id);

        return $payment;
    }

    /**
     * A payment on an invoice that is paid out of an advance already taken. No cash moves and no journal is
     * posted (the customer's account already holds the credit): it only settles the invoice's payment status.
     *
     * @throws ValidationException
     */
    public static function createSellPaymentFromAdvance(Transaction $sell, float $amount, string $paidOn, string $note): self
    {
        $amount = round($amount, 2);

        self::assertAmountWithinRemaining($sell, $amount);

        $payment = new self;
        $payment->company_id = $sell->company_id;
        $payment->branch_id = $sell->branch_id;
        $payment->transaction_id = $sell->id;
        $payment->contact_id = $sell->contact_id;
        $payment->payment_account = null;
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = 'other';
        $payment->paid_on = self::parsePaidOn($paidOn);
        $payment->note = $note;
        $payment->payment_ref_no = self::generatePaymentRefNo((int) $sell->company_id, (int) $sell->branch_id, 'sell');
        $payment->save();

        self::syncTransactionPaymentStatus((int) $sell->id);

        return $payment;
    }

    /**
     * Removes a payment made through a cash collection: its ledger voucher (when it has one) and the row, and
     * puts its invoice's payment status right again.
     */
    public static function removeCollectedPayment(self $payment): void
    {
        $transactionId = $payment->transaction_id;

        self::deleteLedgerEntry($payment);
        $payment->delete();

        if ($transactionId !== null) {
            self::syncTransactionPaymentStatus((int) $transactionId);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function updatePurchasePayment(object $request, int $id): self
    {
        $payment = self::findVisiblePurchasePayment($id);

        if ($payment === null) {
            abort(404);
        }

        $purchase = self::visiblePurchaseFromRequest($request);
        $amount = round((float) $request->amount, 2);
        $originalTransactionId = (int) $payment->transaction_id;

        self::assertAmountWithinRemaining($purchase, $amount, $payment->id);

        $payment->fill(self::methodPayload($request));
        $payment->company_id = $purchase->company_id;
        $payment->branch_id = $purchase->branch_id;
        $payment->transaction_id = $purchase->id;
        $payment->contact_id = $purchase->contact_id;
        $payment->payment_account = self::resolveScopedId($request->payment_account);
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = $request->method;
        $payment->paid_on = self::parsePaidOn($request->paid_on);
        $payment->note = $request->note;
        $payment->document = self::storeDocument($request, $payment->document, (int) $purchase->company_id);
        self::postLedgerEntry($purchase, $payment);
        $payment->save();

        if ($originalTransactionId !== (int) $purchase->id) {
            self::syncTransactionPaymentStatus($originalTransactionId);
        }

        self::syncTransactionPaymentStatus((int) $purchase->id);

        return $payment;
    }

    /**
     * @throws ValidationException
     */
    public static function updateSellPayment(object $request, int $id): self
    {
        $payment = self::findVisibleSellPayment($id);

        if ($payment === null) {
            abort(404);
        }

        $sell = self::visibleSellFromRequest($request);
        $amount = round((float) $request->amount, 2);
        $originalTransactionId = (int) $payment->transaction_id;

        self::assertAmountWithinRemaining($sell, $amount, $payment->id);

        $payment->fill(self::methodPayload($request));
        $payment->company_id = $sell->company_id;
        $payment->branch_id = $sell->branch_id;
        $payment->transaction_id = $sell->id;
        $payment->contact_id = $sell->contact_id;
        $payment->payment_account = self::resolveScopedId($request->payment_account);
        $payment->is_return = false;
        $payment->amount = $amount;
        $payment->method = $request->method;
        $payment->paid_on = self::parsePaidOn($request->paid_on);
        $payment->note = $request->note;
        $payment->document = self::storeDocument($request, $payment->document, (int) $sell->company_id);
        self::postLedgerEntry($sell, $payment);
        $payment->save();

        if ($originalTransactionId !== (int) $sell->id) {
            self::syncTransactionPaymentStatus($originalTransactionId);
        }

        self::syncTransactionPaymentStatus((int) $sell->id);

        return $payment;
    }

    public static function deletePurchasePayment(int $id): void
    {
        $payment = self::findVisiblePurchasePayment($id);

        if ($payment === null) {
            abort(404);
        }

        $transactionId = (int) $payment->transaction_id;
        self::deleteLedgerEntry($payment);
        $payment->delete();
        self::syncTransactionPaymentStatus($transactionId);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public static function deletePurchasePayments(array $ids): void
    {
        $payments = self::query()
            ->forPurchases()
            ->visibleToCurrentUser()
            ->whereIn('id', $ids)
            ->get();

        $transactionIds = $payments->pluck('transaction_id')->unique()->filter()->all();

        foreach ($payments as $payment) {
            self::deleteLedgerEntry($payment);
            $payment->delete();
        }

        foreach ($transactionIds as $transactionId) {
            self::syncTransactionPaymentStatus((int) $transactionId);
        }
    }

    public static function deleteSellPayment(int $id): void
    {
        $payment = self::findVisibleSellPayment($id);

        if ($payment === null) {
            abort(404);
        }

        $transactionId = (int) $payment->transaction_id;
        self::deleteLedgerEntry($payment);
        $payment->delete();
        self::syncTransactionPaymentStatus($transactionId);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    public static function deleteSellPayments(array $ids): void
    {
        $payments = self::query()
            ->forSells()
            ->visibleToCurrentUser()
            ->whereIn('id', $ids)
            ->get();

        $transactionIds = $payments->pluck('transaction_id')->unique()->filter()->all();

        foreach ($payments as $payment) {
            self::deleteLedgerEntry($payment);
            $payment->delete();
        }

        foreach ($transactionIds as $transactionId) {
            self::syncTransactionPaymentStatus((int) $transactionId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toFormArray(): array
    {
        $transaction = $this->transaction;
        $remaining = $transaction !== null
            ? self::remainingAmountForTransaction($transaction, $this->id)
            : 0;
        $contactName = $this->contact?->business_name ?? $this->contact?->first_name;

        $data = $this->toArray();
        unset($data['document']);

        return array_merge($data, [
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'supplier_name' => $contactName,
            'customer_name' => $contactName,
            'invoice_no' => $transaction?->invoice_no,
            'final_amount' => $transaction?->final_amount,
            'remaining_amount' => $remaining,
            'attachment' => $this->document,
            'document_url' => self::imageUrl($this->document),
        ]);
    }

    /**
     * Post (or rewrite) the ledger voucher for a purchase/sell payment:
     * cash/bank vs. the contact's payable/receivable account.
     *
     * @throws ValidationException
     */
    private static function postLedgerEntry(Transaction $document, self $payment): void
    {
        $document->loadMissing('contact');
        $contact = $document->contact;

        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['The contact for this payment could not be found.'],
            ]);
        }

        $cashOrBankAccount = ChartOfAccount::query()->find($payment->payment_account);

        if ($cashOrBankAccount === null) {
            throw ValidationException::withMessages([
                'payment_account' => ['Select a valid cash or bank account before saving the payment.'],
            ]);
        }

        $isPurchase = $document->type === Transaction::TYPE_PURCHASE;
        $ledger = app(LedgerJournal::class);

        $contactCode = $isPurchase
            ? ($contact->supplier_gl_id ?: $contact->gl_id)
            : ($contact->customer_gl_id ?: $contact->gl_id);

        $contactAccount = $ledger->accountByCode($document, (string) $contactCode);

        if ($contactAccount === null) {
            throw ValidationException::withMessages([
                'contact_id' => [$isPurchase
                    ? 'Link the supplier to a chart of account before recording a payment.'
                    : 'Link the customer to a chart of account before recording a payment.'],
            ]);
        }

        $amount = round((float) $payment->amount, 2);
        $contactName = $contact->business_name ?: trim($contact->first_name.' '.$contact->last_name);

        // Paying a supplier debits payable (reduces the liability) and
        // credits cash/bank (reduces the asset). Receiving from a customer
        // debits cash/bank (increases the asset) and credits receivable
        // (reduces the asset).
        $lines = $isPurchase
            ? [
                ['account' => $contactAccount, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $contact->id],
                ['account' => $cashOrBankAccount, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $contact->id],
            ]
            : [
                ['account' => $cashOrBankAccount, 'debit' => $amount, 'credit' => 0.0, 'contact_id' => $contact->id],
                ['account' => $contactAccount, 'debit' => 0.0, 'credit' => $amount, 'contact_id' => $contact->id],
            ];

        $description = ($isPurchase ? 'Payment to ' : 'Payment from ').$contactName.' against '.$document->invoice_no;

        $journal = $ledger->postPayment(
            $payment,
            $cashOrBankAccount,
            $description,
            $isPurchase ? 'PP' : 'SP',
            $lines,
        );

        $payment->t_account_id = $journal->id;
    }

    private static function deleteLedgerEntry(self $payment): void
    {
        app(LedgerJournal::class)->deleteForPayment($payment);
    }

    private static function visiblePurchaseFromRequest(object $request): Transaction
    {
        $purchase = Transaction::findVisiblePurchase((int) $request->transaction_id);

        if ($purchase === null) {
            throw ValidationException::withMessages([
                'transaction_id' => ['The selected purchase was not found.'],
            ]);
        }

        return $purchase;
    }

    private static function visibleSellFromRequest(object $request): Transaction
    {
        $sell = Transaction::findVisibleSell((int) $request->transaction_id);

        if ($sell === null) {
            throw ValidationException::withMessages([
                'transaction_id' => ['The selected sell was not found.'],
            ]);
        }

        return $sell;
    }
}
