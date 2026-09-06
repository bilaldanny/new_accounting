<?php

namespace App\Models;

use App\Support\Base64Upload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    public const METHODS = ['cash', 'card', 'cheque', 'bank_transfer', 'other'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'transaction_id',
        'contact_id',
        'payment_account',
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
            'amount' => 'decimal:2',
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
        $remaining = self::remainingAmountForTransaction($transaction, $exceptPaymentId);

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

        $count = self::query()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('payment_ref_no', 'like', $prefix.'%')
            ->count();

        return $prefix.$period.'-'.str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
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
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

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

    public static function storeDocument(object $request, ?string $existing = null): ?string
    {
        if (is_object($request) && method_exists($request, 'hasFile')) {
            if ($request->hasFile('attachment') && $request->file('attachment') instanceof UploadedFile) {
                return self::saveDocumentFile($request->file('attachment'));
            }

            if ($request->hasFile('document') && $request->file('document') instanceof UploadedFile) {
                return self::saveDocumentFile($request->file('document'));
            }
        }

        $document = $request->attachment ?? $request->document ?? null;

        if (is_string($document) && str_starts_with($document, 'data:')) {
            return self::saveDocumentFromBase64($document);
        }

        if (is_string($document) && $document !== '') {
            return $document;
        }

        return $existing;
    }

    public static function saveDocumentFile(UploadedFile $file): string
    {
        $filename = time().'.payment.'.$file->getClientOriginalExtension();
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
        $payment->document = self::storeDocument($request);
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'purchase');
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
        $payment->document = self::storeDocument($request);
        $payment->payment_ref_no = self::generatePaymentRefNo($companyId, $branchId, 'sell');
        $payment->save();

        self::syncTransactionPaymentStatus((int) $sell->id);

        return $payment;
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
        $payment->document = self::storeDocument($request, $payment->document);
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
        $payment->document = self::storeDocument($request, $payment->document);
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
