<?php

namespace App\Models;

use App\Services\PurchaseJournal;
use App\Services\SellJournal;
use App\Support\Base64Upload;
use Database\Factories\TransactionFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, SoftDeletes;

    public const TYPE_PURCHASE = 'purchaseorder';

    public const TYPE_RECEIVING_NOTE = 'recieving_note';

    public const TYPE_PURCHASE_RETURN = 'purchasereturn';

    public const TYPE_SELL = 'sell';

    public const TYPE_ISSUE_NOTE = 'issue_note';

    protected $fillable = [
        'company_id',
        'branch_id',
        'tobranch_id',
        'contact_id',
        'opening_stock_product_id',
        'parent_id',
        'tax_id',
        'transporter_id',
        'created_by',
        'updated_by',
        'approved_by',
        'direct_contact_id',
        'total_item',
        'pay_term',
        'invoice_no',
        'sup_ref_no',
        'shipping_details',
        'shipping_note',
        'delivered_to',
        'billty_no',
        'billty_date',
        'billty_image',
        'shipping_address',
        'additional_note',
        'packing',
        'attachment',
        'transaction_date',
        'approved_date',
        'pay_type',
        'status',
        'payment_status',
        'adjustment_type',
        'discount_type',
        'type',
        'shipping_status',
        'total_before_tax',
        'tax_amount',
        'discount_amount',
        'shipping_charges',
        'final_amount',
        'paid_amount',
        'link_account',
        'is_direct',
        'is_print',
        'is_edit',
    ];

    protected $attributes = [
        'type' => self::TYPE_PURCHASE,
        'status' => 'pending',
        'payment_status' => 'due',
        'discount_type' => 'none',
        'pay_type' => 'day',
        'is_direct' => 0,
        'link_account' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'billty_date' => 'datetime',
            'approved_date' => 'date',
            'is_direct' => 'boolean',
            'is_print' => 'boolean',
            'is_edit' => 'boolean',
            'link_account' => 'boolean',
            'total_item' => 'integer',
            'discount_amount' => 'decimal:2',
            'shipping_charges' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
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
     * @return BelongsTo<Contact, $this>
     */
    public function directContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'direct_contact_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasMany<PurchaseLine, $this>
     */
    public function purchaselines(): HasMany
    {
        return $this->hasMany(PurchaseLine::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<SellLine, $this>
     */
    public function selllines(): HasMany
    {
        return $this->hasMany(SellLine::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function purchasereturn(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', self::TYPE_PURCHASE_RETURN);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function childReceivingNotes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', self::TYPE_RECEIVING_NOTE);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function childIssueNotes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('type', self::TYPE_ISSUE_NOTE);
    }

    public function scopePurchases(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PURCHASE);
    }

    public function scopeReceivingNotes(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_RECEIVING_NOTE);
    }

    public function scopePurchaseReturns(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PURCHASE_RETURN);
    }

    public function scopeSells(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SELL);
    }

    public function scopeIssueNotes(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_ISSUE_NOTE);
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

    public static function findVisiblePurchase(int $id): ?self
    {
        return self::query()->purchases()->visibleToCurrentUser()->find($id);
    }

    public static function findVisibleSell(int $id): ?self
    {
        return self::query()->sells()->visibleToCurrentUser()->find($id);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function imageDirectory(): string
    {
        $path = public_path('images/purchase_image');
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

        return asset('images/purchase_image/'.$path);
    }

    public static function storeImage(object $request, ?string $existing = null): ?string
    {
        if (is_object($request) && method_exists($request, 'hasFile') && $request->hasFile('attachment') && $request->file('attachment') instanceof UploadedFile) {
            return self::saveImageFile($request->file('attachment'));
        }

        $image = $request->attachment ?? null;

        if (is_string($image) && str_starts_with($image, 'data:image')) {
            return self::saveImageFromBase64($image);
        }

        if (is_string($image) && $image !== '') {
            return $image;
        }

        return $existing;
    }

    public static function saveImageFile(UploadedFile $file): string
    {
        $filename = time().'.purchase.'.$file->getClientOriginalExtension();
        $file->move(self::imageDirectory(), $filename);

        return $filename;
    }

    public static function saveImageFromBase64(string $image): ?string
    {
        $decoded = Base64Upload::decode($image);

        if ($decoded === null) {
            return null;
        }

        $filename = time().'.purchase.'.$decoded['extension'];
        file_put_contents(self::imageDirectory().'/'.$filename, $decoded['binary']);

        return $filename;
    }

    public static function generateInvoiceNo(?int $companyId, ?string $requested = null): string
    {
        $requestedInvoice = trim((string) $requested);

        if ($requestedInvoice !== '') {
            return $requestedInvoice;
        }

        $prefix = 'PO';

        if ($companyId !== null) {
            $settingPrefix = CompanySetting::query()
                ->where('company_id', $companyId)
                ->value('purchase_order');

            if (is_string($settingPrefix) && trim($settingPrefix) !== '') {
                $prefix = trim($settingPrefix);
            }
        }

        $lastId = (int) self::query()
            ->withTrashed()
            ->purchases()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $invoiceNo = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->purchases()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('invoice_no', $invoiceNo)
                ->exists()
        );

        return $invoiceNo;
    }

    public static function generateSellInvoiceNo(?int $companyId, ?string $requested = null): string
    {
        $requestedInvoice = trim((string) $requested);

        if ($requestedInvoice !== '') {
            return $requestedInvoice;
        }

        $prefix = 'INV';

        if ($companyId !== null) {
            $settingPrefix = CompanySetting::query()
                ->where('company_id', $companyId)
                ->value('invoice');

            if (is_string($settingPrefix) && trim($settingPrefix) !== '') {
                $prefix = trim($settingPrefix);
            }
        }

        $lastId = (int) self::query()
            ->withTrashed()
            ->sells()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $invoiceNo = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->sells()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('invoice_no', $invoiceNo)
                ->exists()
        );

        return $invoiceNo;
    }

    /**
     * @param  array<int, mixed>|null  $lines
     *
     * @throws ValidationException
     */
    public static function assertValidLines(?array $lines): void
    {
        if ($lines === null || $lines === []) {
            throw ValidationException::withMessages([
                'purchaselines' => ['Add at least one product line.'],
            ]);
        }
    }

    public static function parseTransactionDate(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return now();
        }

        try {
            return Carbon::parse(str_replace('/', '-', $raw));
        } catch (\Throwable) {
            return now();
        }
    }

    public static function createPurchase(object $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);

        self::assertValidLines($request->purchaselines ?? null);

        $transaction = new self;
        $transaction->fillFromPurchaseRequest($request, $companyId, $branchId);
        $transaction->invoice_no = self::generateInvoiceNo($companyId, $request->invoice_no ?? null);
        $transaction->attachment = self::storeImage($request);
        $transaction->created_by = Auth::id();
        $transaction->save();

        self::syncLines($transaction, $request->purchaselines ?? []);
        app(PurchaseJournal::class)->sync($transaction->fresh(['contact']) ?? $transaction);

        return $transaction;
    }

    public static function updatePurchase(object $request, int $id): self
    {
        $transaction = self::findVisiblePurchase($id);

        if ($transaction === null) {
            abort(404);
        }

        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);

        self::assertValidLines($request->purchaselines ?? null);

        $transaction->fillFromPurchaseRequest($request, $companyId, $branchId);
        $transaction->invoice_no = trim((string) ($request->invoice_no ?? '')) !== ''
            ? trim((string) $request->invoice_no)
            : $transaction->invoice_no;
        $transaction->attachment = self::storeImage($request, $transaction->attachment);
        $transaction->updated_by = Auth::id();
        $transaction->save();

        self::syncLines($transaction, $request->purchaselines ?? []);
        app(PurchaseJournal::class)->sync($transaction->fresh(['contact']) ?? $transaction);

        return $transaction;
    }

    public static function deletePurchase(int $id): void
    {
        $transaction = self::findVisiblePurchase($id);

        if ($transaction === null) {
            abort(404);
        }

        app(PurchaseJournal::class)->deleteFor($transaction);
        $transaction->delete();
    }

    /**
     * @param  array<int, mixed>|null  $lines
     *
     * @throws ValidationException
     */
    public static function assertValidSellLines(?array $lines): void
    {
        if ($lines === null || $lines === []) {
            throw ValidationException::withMessages([
                'selllines' => ['Add at least one product line.'],
            ]);
        }
    }

    public static function billtyImageDirectory(): string
    {
        $path = public_path('images/billty_image');
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        return $path;
    }

    public static function billtyImageUrl(?string $path): ?string
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

        if (str_starts_with($path, 'data:')) {
            return $path;
        }

        return asset('images/billty_image/'.$path);
    }

    public static function storeBilltyImage(object $request, ?string $existing = null): ?string
    {
        $image = $request->billty_image ?? $request->bilty_image ?? null;

        if (is_object($request) && method_exists($request, 'hasFile')) {
            foreach (['billty_image', 'bilty_image'] as $field) {
                if ($request->hasFile($field) && $request->file($field) instanceof UploadedFile) {
                    $filename = time().'.billty.'.$request->file($field)->getClientOriginalExtension();
                    $request->file($field)->move(self::billtyImageDirectory(), $filename);

                    return $filename;
                }
            }
        }

        if (is_string($image) && str_starts_with($image, 'data:image')) {
            if (! preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) {
                return $existing;
            }

            $extension = $matches[1];
            $imageData = base64_decode(substr($image, strpos($image, ',') + 1));

            if ($imageData === false) {
                return $existing;
            }

            $filename = time().'.billty.'.$extension;
            file_put_contents(self::billtyImageDirectory().'/'.$filename, $imageData);

            return $filename;
        }

        if (is_string($image) && $image !== '') {
            return $image;
        }

        return $existing;
    }

    public static function createSell(object $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);

        self::assertValidSellLines($request->selllines ?? null);

        $transaction = new self;
        $transaction->fillFromSellRequest($request, $companyId, $branchId);
        $transaction->invoice_no = self::generateSellInvoiceNo($companyId, $request->invoice_no ?? null);
        $transaction->billty_image = self::storeBilltyImage($request);
        $transaction->created_by = Auth::id();
        $transaction->save();

        self::syncSellLines($transaction, $request->selllines ?? []);
        app(SellJournal::class)->sync($transaction->fresh(['contact']) ?? $transaction);

        return $transaction;
    }

    public static function updateSell(object $request, int $id): self
    {
        $transaction = self::findVisibleSell($id);

        if ($transaction === null) {
            abort(404);
        }

        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);

        self::assertValidSellLines($request->selllines ?? null);

        $transaction->fillFromSellRequest($request, $companyId, $branchId);
        $transaction->invoice_no = trim((string) ($request->invoice_no ?? '')) !== ''
            ? trim((string) $request->invoice_no)
            : $transaction->invoice_no;
        $transaction->billty_image = self::storeBilltyImage($request, $transaction->billty_image);
        $transaction->updated_by = Auth::id();
        $transaction->save();

        self::syncSellLines($transaction, $request->selllines ?? []);
        app(SellJournal::class)->sync($transaction->fresh(['contact']) ?? $transaction);

        return $transaction;
    }

    public static function deleteSell(int $id): void
    {
        $transaction = self::findVisibleSell($id);

        if ($transaction === null) {
            abort(404);
        }

        app(SellJournal::class)->deleteFor($transaction);
        $transaction->delete();
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    public static function syncSellLines(self $transaction, array $lines): void
    {
        $keptIds = [];

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }

            $existing = isset($row['id']) && is_numeric($row['id'])
                ? SellLine::query()
                    ->where('transaction_id', $transaction->id)
                    ->find((int) $row['id'])
                : null;

            if ($existing !== null) {
                $existing->fillFromRow($row, $transaction->id);
                $existing->save();
                $keptIds[] = $existing->id;

                continue;
            }

            $created = SellLine::createFromRow($row, $transaction->id);
            $keptIds[] = $created->id;
        }

        SellLine::query()
            ->where('transaction_id', $transaction->id)
            ->when($keptIds !== [], fn (Builder $query) => $query->whereNotIn('id', $keptIds), fn (Builder $query) => $query)
            ->delete();
    }

    public function fillFromSellRequest(object $request, ?int $companyId, ?int $branchId): void
    {
        $isDirect = $request->is_direct === true
            || $request->is_direct === 1
            || $request->is_direct === '1'
            || $request->is_direct === 'true';

        $status = $request->status ?: 'final';
        $company = $companyId !== null ? Company::query()->with('companySetting')->find($companyId) : null;

        if ($company?->companySetting?->sell_approval === true && $status === 'final') {
            $status = 'approved';
        }

        $shippingStatus = $request->shipping_status ?: null;
        $allowedShipping = ['ordered', 'packed', 'shipped', 'delivered', 'cancelled'];
        $billtyDate = $request->billty_date ?? $request->bilty_date ?? null;

        $this->company_id = $companyId;
        $this->branch_id = $branchId;
        $this->contact_id = self::resolveScopedId($request->contact_id);
        $this->direct_contact_id = $isDirect ? self::resolveScopedId($request->direct_contact_id) : null;
        $this->transaction_date = self::parseTransactionDate($request->transaction_date);
        $this->pay_term = $request->pay_term;
        $this->pay_type = in_array($request->pay_type, ['day', 'month', 'year'], true) ? $request->pay_type : 'day';
        $this->discount_type = in_array($request->discount_type, ['none', 'fixed', 'percentage'], true)
            ? $request->discount_type
            : 'none';
        $this->discount_amount = SellLine::resolveNumeric($request->discount_amount ?? 0);
        $this->is_direct = $isDirect;
        $this->shipping_details = $request->shipping_details;
        $this->shipping_address = $request->shipping_address;
        $this->shipping_note = $request->shipping_note;
        $this->shipping_charges = SellLine::resolveNumeric($request->shipping_charges ?? 0);
        $this->shipping_status = in_array($shippingStatus, $allowedShipping, true) ? $shippingStatus : null;
        $this->delivered_to = $request->delivered_to;
        $this->billty_no = $request->billty_no ?? $request->bilty_no;
        $this->billty_date = $billtyDate ? self::parseTransactionDate($billtyDate) : null;
        $this->packing = $request->packing;
        $this->additional_note = $request->additional_note;
        $this->final_amount = SellLine::resolveNumeric($request->final_amount ?? 0);
        $this->tax_id = self::resolveScopedId($request->tax_id ?? null);
        $this->tax_amount = SellLine::resolveNumeric($request->tax_amount ?? 0);
        $this->total_item = (int) SellLine::resolveNumeric($request->total_item ?? count($request->selllines ?? []), 0);
        $this->payment_status = in_array($request->payment_status, ['paid', 'due', 'partial'], true)
            ? $request->payment_status
            : 'due';
        $this->status = $status;
        $this->type = self::TYPE_SELL;
        $this->link_account = (bool) ($request->link_account ?? false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formattedSellLines(): array
    {
        return $this->selllines
            ->map(function (SellLine $line) {
                $units = self::unitsForProduct(
                    (int) $line->product_id,
                    (int) $line->variation_id,
                    (int) ($line->product?->unit_id ?? $line->unit_id),
                    self::resolveScopedId($this->branch_id),
                    (int) ($line->productdetail?->smallquantity ?? 0),
                    (int) ($line->productdetail?->largequantity ?? 0),
                );

                $quantity = SellLine::resolveNumeric($line->quantity, 1);
                $packingQty = (int) SellLine::resolveNumeric($line->packing_qty, 1);
                $unitPrice = SellLine::resolveNumeric($line->unit_price);
                $discount = SellLine::resolveNumeric($line->discount_percent);
                $priceAfterDiscount = SellLine::resolveNumeric(
                    $line->unit_price_after_discount ?: max($unitPrice - $discount, 0)
                );
                $subtotal = SellLine::resolveNumeric(
                    $line->subtotal ?: ($priceAfterDiscount * $quantity * max($packingQty, 1))
                );

                return [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'variation_id' => $line->variation_id,
                    'itemtype_id' => $line->itemtype_id,
                    'product_name' => $line->product?->name ?? $line->productdetail?->name,
                    'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                    'unit_id' => $line->unit_id,
                    'quantity' => $quantity,
                    'quantity_issue' => $line->quantity_issue,
                    'quantity_returned' => $line->quantity_returned,
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discount,
                    'unit_price_after_discount' => $priceAfterDiscount,
                    'packing_qty' => $packingQty,
                    'row_subtotal' => $subtotal,
                    'subtotal' => $subtotal,
                    'units' => $units,
                    'current_stock' => PurchaseLine::currentStock(
                        (int) $line->product_id,
                        (int) $line->variation_id,
                        (int) $line->unit_id,
                        self::resolveScopedId($this->branch_id),
                    ),
                    'unit_name' => $line->unit?->short_name ?? $line->unit?->name,
                    'brand_name' => $line->product?->brand?->name,
                    'itemtype_name' => $line->product?->itemtype?->name,
                    'variation_name' => $this->variationDisplayName($line->productdetail?->variation_name),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    public static function syncLines(self $transaction, array $lines): void
    {
        $keptIds = [];

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }

            $existing = isset($row['id']) && is_numeric($row['id'])
                ? PurchaseLine::query()
                    ->where('transaction_id', $transaction->id)
                    ->find((int) $row['id'])
                : null;

            if ($existing !== null) {
                $existing->fillFromRow($row, $transaction->id);
                $existing->save();
                $keptIds[] = $existing->id;

                continue;
            }

            $created = PurchaseLine::createFromRow($row, $transaction->id);
            $keptIds[] = $created->id;
        }

        PurchaseLine::query()
            ->where('transaction_id', $transaction->id)
            ->when($keptIds !== [], fn (Builder $query) => $query->whereNotIn('id', $keptIds), fn (Builder $query) => $query)
            ->delete();
    }

    public function fillFromPurchaseRequest(object $request, ?int $companyId, ?int $branchId): void
    {
        $isDirect = $request->is_direct === true
            || $request->is_direct === 1
            || $request->is_direct === '1'
            || $request->is_direct === 'true';

        $status = $request->status ?: 'pending';
        $company = $companyId !== null ? Company::query()->with('companySetting')->find($companyId) : null;

        if ($company?->companySetting?->purchase_approval === true && $status === 'pending') {
            $status = 'approved';
        }

        $this->company_id = $companyId;
        $this->branch_id = $branchId;
        $this->contact_id = self::resolveScopedId($request->contact_id);
        $this->direct_contact_id = $isDirect ? self::resolveScopedId($request->direct_contact_id) : null;
        $this->parent_id = self::resolveScopedId($request->transaction_id ?? $request->parent_id ?? null);
        $this->sup_ref_no = $request->sup_ref_no;
        $this->transaction_date = self::parseTransactionDate($request->transaction_date);
        $this->pay_term = $request->pay_term;
        $this->pay_type = in_array($request->pay_type, ['day', 'month', 'year'], true) ? $request->pay_type : 'day';
        $this->discount_type = in_array($request->discount_type, ['none', 'fixed', 'percentage'], true)
            ? $request->discount_type
            : 'none';
        $this->discount_amount = PurchaseLine::resolveNumeric($request->discount_amount ?? 0);
        $this->is_direct = $isDirect;
        $this->shipping_details = $request->shipping_details;
        $this->shipping_note = $request->shipping_note;
        $this->shipping_charges = PurchaseLine::resolveNumeric($request->shipping_charges ?? 0);
        $this->additional_note = $request->additional_note;
        $this->final_amount = PurchaseLine::resolveNumeric($request->final_amount ?? 0);
        $this->tax_id = self::resolveScopedId($request->tax_id ?? null);
        $this->tax_amount = PurchaseLine::resolveNumeric($request->tax_amount ?? 0);
        $this->total_item = (int) PurchaseLine::resolveNumeric($request->total_item ?? count($request->purchaselines ?? []), 0);
        $this->payment_status = in_array($request->payment_status, ['paid', 'due', 'partial'], true)
            ? $request->payment_status
            : 'due';
        $this->status = $status;
        $this->type = self::TYPE_PURCHASE;
        $this->link_account = (bool) ($request->link_account ?? false);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formattedPurchaseLines(): array
    {
        return $this->purchaselines
            ->map(function (PurchaseLine $line) {
                $units = self::unitsForProduct(
                    (int) $line->product_id,
                    (int) $line->variation_id,
                    (int) ($line->product?->unit_id ?? $line->unit_id),
                    self::resolveScopedId($this->branch_id),
                    (int) ($line->productdetail?->smallquantity ?? 0),
                    (int) ($line->productdetail?->largequantity ?? 0),
                );

                $quantity = PurchaseLine::resolveNumeric($line->quantity, 1);
                $packingQty = (int) PurchaseLine::resolveNumeric($line->packing_qty, 1);
                $purchasePrice = PurchaseLine::resolveNumeric($line->purchase_rate);
                $unitRate = PurchaseLine::resolveNumeric($line->pp_without_discount ?? $purchasePrice);
                $discountPercent = PurchaseLine::resolveNumeric($line->discount_percent);
                $packFactor = max($packingQty, 1);
                $discountRate = round($unitRate * $discountPercent / 100, 2);
                $lineAmount = round($unitRate * $quantity * $packFactor, 2);
                $netAmount = round(($unitRate - $discountRate) * $quantity * $packFactor, 2);

                return [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'variation_id' => $line->variation_id,
                    'itemtype_id' => $line->itemtype_id,
                    'product_name' => $line->product?->name ?? $line->productdetail?->name,
                    'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                    'unit_id' => $line->unit_id,
                    'quantity' => $quantity,
                    'qunatity_sold' => $line->qunatity_sold,
                    'quantity_returned' => $line->quantity_returned,
                    'purchase_rate' => $purchasePrice,
                    'default_sell_price' => $line->default_sell_price,
                    'discount_percent' => $line->discount_percent,
                    'packing_qty' => $packingQty,
                    'profit_percent' => $line->margin,
                    'pp_without_discount' => $line->pp_without_discount,
                    'purchase_price' => $purchasePrice,
                    'row_subtotal' => round($purchasePrice * $quantity * max($packingQty, 1), 2),
                    'units' => $units,
                    'current_stock' => PurchaseLine::currentStock(
                        (int) $line->product_id,
                        (int) $line->variation_id,
                        (int) $line->unit_id,
                        self::resolveScopedId($this->branch_id),
                    ),
                    'unit_name' => $line->unit?->short_name ?? $line->unit?->name,
                    'brand_name' => $line->product?->brand?->name,
                    'itemtype_name' => $line->product?->itemtype?->name,
                    'variation_name' => $this->variationDisplayName($line->productdetail?->variation_name),
                    'unit_rate' => $unitRate,
                    'line_amount' => $lineAmount,
                    'discount_rate' => $discountRate,
                    'net_amount' => $netAmount,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function unitsForProduct(
        int $productId,
        int $variationId,
        int $unitId,
        ?int $branchId,
        int $smallQuantity = 0,
        int $largeQuantity = 0,
    ): array {
        $unit = Unit::query()->with('childrenUnits')->find($unitId);

        if ($unit === null) {
            return [];
        }

        $units = [[
            'id' => $unit->id,
            'text' => $unit->name,
            'short_name' => $unit->short_name,
            'unit_qty' => PurchaseLine::currentStock($productId, $variationId, (int) $unit->id, $branchId),
            'packing_qty' => 1,
        ]];

        foreach ($unit->childrenUnits as $child) {
            $packingQty = 1;

            if ($child->type === 'small') {
                $packingQty = max($smallQuantity, 1);
            }

            if ($child->type === 'large') {
                $packingQty = max($largeQuantity, 1);
            }

            $units[] = [
                'id' => $child->id,
                'text' => $child->name,
                'short_name' => $child->short_name,
                'unit_qty' => PurchaseLine::currentStock($productId, $variationId, (int) $child->id, $branchId),
                'packing_qty' => $packingQty,
            ];
        }

        return $units;
    }

    /**
     * @param  array{category_id?: mixed, subcategory_id?: mixed, itemtype_id?: mixed, product_id?: mixed}  $filters
     * @return array<int, array<string, mixed>>
     */
    public static function searchProducts(?int $companyId, ?int $branchId, ?string $term, array $filters = []): array
    {
        $search = trim((string) $term);
        $categoryId = self::resolveScopedId($filters['category_id'] ?? null);
        $subcategoryId = self::resolveScopedId($filters['subcategory_id'] ?? null);
        $itemTypeId = self::resolveScopedId($filters['itemtype_id'] ?? null);
        $productId = self::resolveScopedId($filters['product_id'] ?? null);

        $details = ProductDetail::query()
            ->with(['product.unit.childrenUnits'])
            ->whereHas('product', function (Builder $query) use ($companyId, $categoryId, $subcategoryId, $itemTypeId, $productId) {
                $query->visibleToCurrentUser()
                    ->where('active', 1)
                    ->when($companyId !== null, fn (Builder $companyQuery) => $companyQuery->where('company_id', $companyId))
                    ->when($categoryId !== null, fn (Builder $categoryQuery) => $categoryQuery->where('category_id', $categoryId))
                    ->when($subcategoryId !== null, fn (Builder $subcategoryQuery) => $subcategoryQuery->where('subcategory_id', $subcategoryId))
                    ->when($itemTypeId !== null, fn (Builder $itemTypeQuery) => $itemTypeQuery->where('itemtype_id', $itemTypeId))
                    ->when($productId !== null, fn (Builder $productQuery) => $productQuery->where('id', $productId));
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhereHas('product', function (Builder $productQuery) use ($search) {
                            $productQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        });
                });
            })
            ->limit($search !== '' ? 20 : 500)
            ->get();

        return $details->map(function (ProductDetail $detail) use ($branchId) {
            $product = $detail->product;
            $unitId = (int) ($product?->unit_id ?? 0);

            return [
                'id' => $detail->id,
                'product_id' => $detail->product_id,
                'itemtype_id' => $product?->itemtype_id,
                'category_id' => $product?->category_id,
                'subcategory_id' => $product?->subcategory_id,
                'product_name' => $product?->name,
                'variation_name' => $detail->variation_name,
                'name' => $detail->name ?: $product?->name,
                'sku' => $detail->sku ?: $product?->sku,
                'unit_id' => $unitId,
                'default_purchase_price' => $detail->default_purchase_price,
                'default_sell_price' => $detail->default_sell_price,
                'profit_percent' => $detail->profit_percent,
                'smallquantity' => $detail->smallquantity,
                'largequantity' => $detail->largequantity,
                'units' => $unitId > 0
                    ? self::unitsForProduct(
                        (int) $detail->product_id,
                        (int) $detail->id,
                        $unitId,
                        $branchId,
                        (int) $detail->smallquantity,
                        (int) $detail->largequantity,
                    )
                    : [],
            ];
        })->values()->all();
    }

    public function presentForIndex(): self
    {
        $this->company_name = $this->company?->name;
        $this->branch_name = $this->branch?->name;
        $this->supplier_name = $this->contact?->business_name;
        $this->customer_name = $this->contact?->business_name;
        $this->status_label = Str::headline((string) $this->status);
        $this->payment_status_label = Str::headline((string) $this->payment_status);
        $this->transaction_date_label = $this->transaction_date?->format('d M Y');
        $this->formatted_amount = number_format((float) $this->final_amount, 2);
        $this->attachment_url = self::imageUrl($this->attachment);

        if ($this->relationLoaded('childReceivingNotes')) {
            $this->receiving_note_id = $this->childReceivingNotes->first()?->id;
        }

        if ($this->relationLoaded('purchasereturn')) {
            $this->purchase_return_id = $this->purchasereturn->first()?->id;
        }

        if ($this->relationLoaded('childIssueNotes')) {
            $this->issue_note_id = $this->childIssueNotes->first()?->id;
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, self>
     */
    public static function paginateForIndex(array $filters = []): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortType = $filters['sort_type'] ?? 'desc';
        $showRecord = $filters['show_record'] ?? 10;
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $curPage = (int) ($filters['cur_page'] ?? $filters['page'] ?? 1);

        $query = self::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'childReceivingNotes' => fn ($query) => $query->select('id', 'parent_id')->latest('id'),
                'purchasereturn' => fn ($query) => $query->select('id', 'parent_id')->latest('id'),
            ])
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(! empty($filters['payment_status']) && $filters['payment_status'] !== 'all', function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->whereAny(['invoice_no', 'sup_ref_no'], 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(! empty($filters['company_id']), function ($query) use ($filters) {
                $query->where('company_id', $filters['company_id']);
            })
            ->when(! empty($filters['branch_id']), function ($query) use ($filters) {
                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['contact_id']), function ($query) use ($filters) {
                $query->where('contact_id', $filters['contact_id']);
            })
            ->when(! empty($filters['transaction_date']), function ($query) use ($filters) {
                $query->whereDate('transaction_date', $filters['transaction_date']);
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $purchases = $query->paginate($showRecord);

        if ($curPage > $purchases->lastPage()) {
            Paginator::currentPageResolver(function () use ($purchases) {
                return $purchases->lastPage();
            });
            $purchases = $query->paginate($showRecord);
        }

        $purchases->getCollection()->transform(function (self $purchase) {
            return $purchase->presentForIndex();
        });

        return $purchases;
    }

    public static function findVisiblePurchaseForApproval(int $id): ?self
    {
        return self::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->with([
                'purchaselines.product.brand',
                'purchaselines.product.itemtype',
                'purchaselines.productdetail',
                'purchaselines.unit',
                'contact',
                'company',
                'branch',
                'approvedBy',
            ])
            ->find($id);
    }

    public static function approvePurchase(int $id): self
    {
        $purchase = self::findVisiblePurchase($id);

        if ($purchase === null) {
            abort(404);
        }

        if ($purchase->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => 'Only pending purchases can be approved.',
            ]);
        }

        $purchase->status = 'approved';
        $purchase->approved_by = Auth::id();
        $purchase->approved_date = now();
        $purchase->save();

        return $purchase;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForApproval(): array
    {
        $lines = $this->formattedPurchaseLines();
        $netSubTotal = collect($lines)->sum(fn (array $line): float => (float) ($line['net_amount'] ?? 0));
        $discountVal = match ($this->discount_type) {
            'percentage' => round($netSubTotal * PurchaseLine::resolveNumeric($this->discount_amount) / 100, 2),
            'fixed' => PurchaseLine::resolveNumeric($this->discount_amount),
            default => 0.0,
        };
        $shippingCharges = PurchaseLine::resolveNumeric($this->shipping_charges);
        $taxAmount = PurchaseLine::resolveNumeric($this->tax_amount);
        $finalAmount = PurchaseLine::resolveNumeric($this->final_amount) ?: round($netSubTotal - $discountVal + $shippingCharges + $taxAmount, 2);

        $lineGroups = collect($lines)
            ->groupBy(fn (array $line): string => (string) ($line['itemtype_name'] ?: 'Items'))
            ->map(fn ($group, $name): array => [
                'itemtype_name' => $name,
                'lines' => $group->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'sup_ref_no' => $this->sup_ref_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'transaction_date_label' => $this->transaction_date?->format('d M Y'),
            'status' => $this->status,
            'status_label' => Str::headline((string) $this->status),
            'payment_status' => $this->payment_status,
            'payment_status_label' => Str::headline((string) $this->payment_status),
            'business_name' => $this->contact?->business_name,
            'address' => $this->contact?->address,
            'mobile' => $this->contact?->mobile,
            'company_name' => $this->company?->name,
            'company_address' => $this->company?->address,
            'branch_name' => $this->branch?->name,
            'shipping_details' => $this->shipping_details,
            'shipping_note' => $this->shipping_note,
            'additional_note' => $this->additional_note,
            'discount_type' => $this->discount_type,
            'discount_amount' => PurchaseLine::resolveNumeric($this->discount_amount),
            'discount_val' => $discountVal,
            'net_sub_total' => $netSubTotal,
            'tax_amount' => $taxAmount,
            'shipping_charges' => $shippingCharges,
            'final_amount' => $finalAmount,
            'formatted_amount' => number_format($finalAmount, 2),
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->approvedBy?->full_name,
            'approved_date' => $this->approved_date?->format('d M Y'),
            'purchaselines' => $lines,
            'line_groups' => $lineGroups,
            'payments' => [],
            'can_approve' => $this->status === 'pending',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, self>
     */
    public static function paginateSellsForIndex(array $filters = []): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortType = $filters['sort_type'] ?? 'desc';
        $showRecord = $filters['show_record'] ?? 10;
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $curPage = (int) ($filters['cur_page'] ?? $filters['page'] ?? 1);

        $query = self::query()
            ->sells()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
            ])
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(! empty($filters['payment_status']) && $filters['payment_status'] !== 'all', function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(! empty($filters['company_id']), function ($query) use ($filters) {
                $query->where('company_id', $filters['company_id']);
            })
            ->when(! empty($filters['branch_id']), function ($query) use ($filters) {
                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['contact_id']), function ($query) use ($filters) {
                $query->where('contact_id', $filters['contact_id']);
            })
            ->when(! empty($filters['transaction_date']), function ($query) use ($filters) {
                $query->whereDate('transaction_date', $filters['transaction_date']);
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $sells = $query->paginate($showRecord);

        if ($curPage > $sells->lastPage()) {
            Paginator::currentPageResolver(function () use ($sells) {
                return $sells->lastPage();
            });
            $sells = $query->paginate($showRecord);
        }

        $sells->getCollection()->transform(function (self $sell) {
            return $sell->presentForIndex();
        });

        return $sells;
    }

    public static function findVisibleSellForApproval(int $id): ?self
    {
        return self::query()
            ->sells()
            ->visibleToCurrentUser()
            ->with([
                'selllines.product.brand',
                'selllines.product.itemtype',
                'selllines.productdetail',
                'selllines.unit',
                'contact',
                'company',
                'branch',
                'approvedBy',
            ])
            ->find($id);
    }

    public static function approveSell(int $id): self
    {
        $sell = self::findVisibleSell($id);

        if ($sell === null) {
            abort(404);
        }

        if ($sell->status !== 'final') {
            throw ValidationException::withMessages([
                'status' => 'Only final sells can be approved.',
            ]);
        }

        $sell->status = 'approved';
        $sell->approved_by = Auth::id();
        $sell->approved_date = now();
        $sell->save();

        return $sell;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForSellApproval(): array
    {
        $lines = collect($this->formattedSellLines())
            ->map(function (array $line): array {
                $unitPrice = SellLine::resolveNumeric($line['unit_price'] ?? 0);
                $packingQty = SellLine::resolveNumeric($line['packing_qty'] ?? 1, 1);
                $quantityIssue = SellLine::resolveNumeric($line['quantity_issue'] ?? 0);
                $quantity = $quantityIssue > 0
                    ? $quantityIssue
                    : SellLine::resolveNumeric($line['quantity'] ?? 0, 1);

                $line['unit_rate'] = $unitPrice;
                $line['line_amount'] = round($unitPrice * max($packingQty, 1), 2);
                $line['discount_rate'] = SellLine::resolveNumeric($line['unit_price_after_discount'] ?? 0);
                $line['net_amount'] = SellLine::resolveNumeric($line['row_subtotal'] ?? $line['subtotal'] ?? 0);
                $line['display_quantity'] = $quantity;

                return $line;
            })
            ->all();

        $netSubTotal = collect($lines)->sum(fn (array $line): float => (float) ($line['net_amount'] ?? 0));
        $discountVal = match ($this->discount_type) {
            'percentage' => round($netSubTotal * SellLine::resolveNumeric($this->discount_amount) / 100, 2),
            'fixed' => SellLine::resolveNumeric($this->discount_amount),
            default => 0.0,
        };
        $shippingCharges = SellLine::resolveNumeric($this->shipping_charges);
        $taxAmount = SellLine::resolveNumeric($this->tax_amount);
        $finalAmount = SellLine::resolveNumeric($this->final_amount) ?: round($netSubTotal - $discountVal + $shippingCharges + $taxAmount, 2);

        $lineGroups = collect($lines)
            ->groupBy(fn (array $line): string => (string) ($line['itemtype_name'] ?: 'Items'))
            ->map(fn ($group, $name): array => [
                'itemtype_name' => $name,
                'lines' => $group->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'transaction_date_label' => $this->transaction_date?->format('d M Y'),
            'status' => $this->status,
            'status_label' => Str::headline((string) $this->status),
            'payment_status' => $this->payment_status,
            'payment_status_label' => Str::headline((string) $this->payment_status),
            'shipping_status' => $this->shipping_status,
            'shipping_status_label' => $this->shipping_status ? Str::headline((string) $this->shipping_status) : null,
            'business_name' => $this->contact?->business_name,
            'customer_name' => $this->contact?->business_name,
            'address' => $this->contact?->address,
            'mobile' => $this->contact?->mobile,
            'company_name' => $this->company?->name,
            'company_address' => $this->company?->address,
            'branch_name' => $this->branch?->name,
            'pay_term' => $this->pay_term,
            'pay_type' => $this->pay_type,
            'shipping_details' => $this->shipping_details,
            'shipping_address' => $this->shipping_address,
            'shipping_note' => $this->shipping_note,
            'delivered_to' => $this->delivered_to,
            'billty_no' => $this->billty_no,
            'billty_date' => $this->billty_date?->format('Y-m-d'),
            'billty_date_label' => $this->billty_date?->format('d M Y'),
            'billty_image_url' => self::billtyImageUrl($this->billty_image),
            'packing' => $this->packing,
            'additional_note' => $this->additional_note,
            'discount_type' => $this->discount_type,
            'discount_amount' => SellLine::resolveNumeric($this->discount_amount),
            'discount_val' => $discountVal,
            'net_sub_total' => $netSubTotal,
            'tax_amount' => $taxAmount,
            'shipping_charges' => $shippingCharges,
            'final_amount' => $finalAmount,
            'formatted_amount' => number_format($finalAmount, 2),
            'credit_limit' => $this->contact?->credit_limit,
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->approvedBy?->full_name,
            'approved_date' => $this->approved_date?->format('d M Y'),
            'selllines' => $lines,
            'line_groups' => $lineGroups,
            'payments' => [],
            'can_approve' => $this->status === 'final',
        ];
    }

    public static function findVisibleReceivingNote(int $id): ?self
    {
        return self::query()->receivingNotes()->visibleToCurrentUser()->find($id);
    }

    public static function generateReceivingNoteNo(?int $companyId, ?string $requested = null): string
    {
        $requestedInvoice = trim((string) $requested);

        if ($requestedInvoice !== '') {
            return $requestedInvoice;
        }

        $prefix = 'GRN';

        if ($companyId !== null) {
            $settingPrefix = CompanySetting::query()
                ->where('company_id', $companyId)
                ->value('grn');

            if (is_string($settingPrefix) && trim($settingPrefix) !== '') {
                $prefix = trim($settingPrefix);
            }
        }

        $lastId = (int) self::query()
            ->withTrashed()
            ->receivingNotes()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $invoiceNo = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->receivingNotes()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('invoice_no', $invoiceNo)
                ->exists()
        );

        return $invoiceNo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function eligiblePurchases(?int $companyId, ?int $branchId, ?int $contactId): array
    {
        return self::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->where('status', 'approved')
            ->whereDoesntHave('childReceivingNotes')
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($contactId !== null, fn (Builder $query) => $query->where('contact_id', $contactId))
            ->orderByDesc('id')
            ->get(['id', 'invoice_no', 'transaction_date', 'final_amount', 'status'])
            ->map(fn (self $purchase): array => [
                'id' => $purchase->id,
                'text' => $purchase->invoice_no,
                'invoice_no' => $purchase->invoice_no,
                'transaction_date' => $purchase->transaction_date?->format('Y-m-d'),
                'final_amount' => $purchase->final_amount,
                'status' => $purchase->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function receivingNoteLines(): array
    {
        $purchase = $this->parent ?? $this;

        $purchase->loadMissing([
            'purchaselines.product:id,name,sku',
            'purchaselines.productdetail:id,product_id,name,sku,variation_name',
            'purchaselines.unit:id,name,short_name',
        ]);

        return $purchase->purchaselines
            ->map(function (PurchaseLine $line): array {
                $quantity = PurchaseLine::resolveNumeric($line->quantity, 1);
                $received = PurchaseLine::resolveNumeric($line->quantity_received);

                return [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'variation_id' => $line->variation_id,
                    'unit_id' => $line->unit_id,
                    'product_name' => $line->product?->name ?? $line->productdetail?->name,
                    'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                    'unit_name' => $line->unit?->name,
                    'unit_short_name' => $line->unit?->short_name,
                    'quantity' => $quantity,
                    'quantity_received' => $received,
                    'remaining_qty' => max($quantity - $received, 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, self>
     */
    public static function paginateReceivingNotes(array $filters = []): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortType = $filters['sort_type'] ?? 'desc';
        $showRecord = $filters['show_record'] ?? 10;
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $curPage = (int) ($filters['cur_page'] ?? $filters['page'] ?? 1);

        $query = self::query()
            ->receivingNotes()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'parent:id,invoice_no',
            ])
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(! empty($filters['payment_status']) && $filters['payment_status'] !== 'all', function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->whereAny(['invoice_no', 'sup_ref_no'], 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('parent', function ($parent) use ($search) {
                            $parent->where('invoice_no', 'like', "%{$search}%");
                        });
                });
            })
            ->when(! empty($filters['company_id']), function ($query) use ($filters) {
                $query->where('company_id', $filters['company_id']);
            })
            ->when(! empty($filters['branch_id']), function ($query) use ($filters) {
                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['contact_id']), function ($query) use ($filters) {
                $query->where('contact_id', $filters['contact_id']);
            })
            ->when(! empty($filters['transaction_date']), function ($query) use ($filters) {
                $query->whereDate('transaction_date', $filters['transaction_date']);
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $notes = $query->paginate($showRecord);

        if ($curPage > $notes->lastPage()) {
            Paginator::currentPageResolver(function () use ($notes) {
                return $notes->lastPage();
            });
            $notes = $query->paginate($showRecord);
        }

        $notes->getCollection()->transform(function (self $note) {
            $note->presentForIndex();
            $note->purchase_order_no = $note->parent?->invoice_no;

            return $note;
        });

        return $notes;
    }

    public static function createReceivingNote(object $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);
        $purchaseId = (int) self::resolveScopedId($request->transaction_id ?? $request->parent_id);

        $purchase = self::findVisiblePurchase($purchaseId);

        if ($purchase === null || $purchase->status !== 'approved') {
            throw ValidationException::withMessages([
                'transaction_id' => 'Select an approved purchase order.',
            ]);
        }

        if ($purchase->childReceivingNotes()->exists()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'A receiving note already exists for this purchase order.',
            ]);
        }

        if ($companyId !== null && (int) $purchase->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The purchase order does not belong to this company.',
            ]);
        }

        if ($branchId !== null && (int) $purchase->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'The purchase order does not belong to this branch.',
            ]);
        }

        self::applyReceivedQuantities($purchase, $request->purchaselines ?? []);

        $note = $purchase->replicate();
        $note->parent_id = $purchase->id;
        $note->type = self::TYPE_RECEIVING_NOTE;
        $note->status = 'received';
        $note->invoice_no = self::generateReceivingNoteNo($companyId);
        $note->created_by = Auth::id();
        $note->updated_by = null;
        $note->approved_by = null;
        $note->approved_date = null;
        $note->save();

        $purchase->status = 'received';
        $purchase->save();

        return $note;
    }

    public static function updateReceivingNote(object $request, int $id): self
    {
        $note = self::findVisibleReceivingNote($id);

        if ($note === null) {
            abort(404);
        }

        $purchase = self::findVisiblePurchase((int) $note->parent_id);

        if ($purchase === null) {
            abort(404);
        }

        self::applyReceivedQuantities($purchase, $request->purchaselines ?? []);

        $note->updated_by = Auth::id();
        $note->save();

        return $note;
    }

    public static function deleteReceivingNote(int $id): void
    {
        $note = self::findVisibleReceivingNote($id);

        if ($note === null) {
            abort(404);
        }

        $purchase = self::findVisiblePurchase((int) $note->parent_id);

        if ($purchase !== null) {
            $purchase->purchaselines()->update(['quantity_received' => 0]);
            $purchase->status = 'approved';
            $purchase->save();
        }

        $note->delete();
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    public static function applyReceivedQuantities(self $purchase, array $lines): void
    {
        self::assertValidLines($lines);

        $purchase->loadMissing('purchaselines');
        $indexed = $purchase->purchaselines->keyBy('id');
        $updatedIds = [];

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }

            $lineId = (int) ($row['id'] ?? 0);
            $line = $indexed->get($lineId);

            if ($line === null) {
                throw ValidationException::withMessages([
                    'purchaselines' => 'One or more lines do not belong to this purchase.',
                ]);
            }

            $quantity = PurchaseLine::resolveNumeric($line->quantity, 1);
            $received = PurchaseLine::resolveNumeric($row['quantity_received'] ?? 0);

            if ($received < 0 || $received > $quantity) {
                throw ValidationException::withMessages([
                    'purchaselines' => 'Received quantity must be between 0 and the purchased quantity.',
                ]);
            }

            $line->quantity_received = $received;
            $line->save();
            $updatedIds[] = $lineId;
        }

        if ($updatedIds === []) {
            throw ValidationException::withMessages([
                'purchaselines' => ['Add at least one product line.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentReceivingNote(): array
    {
        $this->loadMissing([
            'company:id,name,address',
            'branch:id,name',
            'contact:id,business_name,address,mobile',
            'parent:id,invoice_no,sup_ref_no,status,payment_status,transaction_date',
        ]);

        $lines = $this->receivingNoteLines();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'contact_id' => $this->contact_id,
            'transaction_id' => $this->parent_id,
            'parent_id' => $this->parent_id,
            'invoice_no' => $this->invoice_no,
            'purchase_order_no' => $this->parent?->invoice_no,
            'sup_ref_no' => $this->parent?->sup_ref_no ?? $this->sup_ref_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'transaction_date_label' => $this->transaction_date?->format('d M Y'),
            'status' => $this->status,
            'status_label' => Str::headline((string) $this->status),
            'payment_status' => $this->payment_status,
            'payment_status_label' => Str::headline((string) $this->payment_status),
            'business_name' => $this->contact?->business_name,
            'address' => $this->contact?->address,
            'mobile' => $this->contact?->mobile,
            'company_name' => $this->company?->name,
            'company_address' => $this->company?->address,
            'branch_name' => $this->branch?->name,
            'formatted_amount' => number_format((float) $this->final_amount, 2),
            'final_amount' => $this->final_amount,
            'purchaselines' => $lines,
        ];
    }

    public static function findVisibleIssueNote(int $id): ?self
    {
        return self::query()->issueNotes()->visibleToCurrentUser()->find($id);
    }

    public static function generateIssueNoteNo(?int $companyId, ?string $requested = null): string
    {
        $requestedInvoice = trim((string) $requested);

        if ($requestedInvoice !== '') {
            return $requestedInvoice;
        }

        $prefix = 'GIN';

        if ($companyId !== null) {
            $settingPrefix = CompanySetting::query()
                ->where('company_id', $companyId)
                ->value('gin');

            if (is_string($settingPrefix) && trim($settingPrefix) !== '') {
                $prefix = trim($settingPrefix);
            }
        }

        $lastId = (int) self::query()
            ->withTrashed()
            ->issueNotes()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $invoiceNo = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->issueNotes()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('invoice_no', $invoiceNo)
                ->exists()
        );

        return $invoiceNo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function eligibleSells(?int $companyId, ?int $branchId, ?int $contactId): array
    {
        return self::query()
            ->sells()
            ->visibleToCurrentUser()
            ->where('status', 'approved')
            ->whereDoesntHave('childIssueNotes')
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($contactId !== null, fn (Builder $query) => $query->where('contact_id', $contactId))
            ->orderByDesc('id')
            ->get(['id', 'invoice_no', 'transaction_date', 'final_amount', 'status'])
            ->map(fn (self $sell): array => [
                'id' => $sell->id,
                'text' => $sell->invoice_no,
                'invoice_no' => $sell->invoice_no,
                'transaction_date' => $sell->transaction_date?->format('Y-m-d'),
                'final_amount' => $sell->final_amount,
                'status' => $sell->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function issueNoteLines(): array
    {
        $sell = $this->parent ?? $this;

        $sell->loadMissing([
            'selllines.product:id,name,sku',
            'selllines.productdetail:id,product_id,name,sku,variation_name',
            'selllines.unit:id,name,short_name',
        ]);

        return $sell->selllines
            ->map(function (SellLine $line): array {
                $quantity = SellLine::resolveNumeric($line->quantity, 1);
                $issued = SellLine::resolveNumeric($line->quantity_issue);

                return [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'variation_id' => $line->variation_id,
                    'unit_id' => $line->unit_id,
                    'product_name' => $line->product?->name ?? $line->productdetail?->name,
                    'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                    'unit_name' => $line->unit?->name,
                    'unit_short_name' => $line->unit?->short_name,
                    'quantity' => $quantity,
                    'quantity_issue' => $issued,
                    'remaining_qty' => max($quantity - $issued, 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, self>
     */
    public static function paginateIssueNotes(array $filters = []): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortType = $filters['sort_type'] ?? 'desc';
        $showRecord = $filters['show_record'] ?? 10;
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $curPage = (int) ($filters['cur_page'] ?? $filters['page'] ?? 1);

        $query = self::query()
            ->issueNotes()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'parent:id,invoice_no',
            ])
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(! empty($filters['payment_status']) && $filters['payment_status'] !== 'all', function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('invoice_no', 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('parent', function ($parent) use ($search) {
                            $parent->where('invoice_no', 'like', "%{$search}%");
                        });
                });
            })
            ->when(! empty($filters['company_id']), function ($query) use ($filters) {
                $query->where('company_id', $filters['company_id']);
            })
            ->when(! empty($filters['branch_id']), function ($query) use ($filters) {
                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['contact_id']), function ($query) use ($filters) {
                $query->where('contact_id', $filters['contact_id']);
            })
            ->when(! empty($filters['transaction_date']), function ($query) use ($filters) {
                $query->whereDate('transaction_date', $filters['transaction_date']);
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $notes = $query->paginate($showRecord);

        if ($curPage > $notes->lastPage()) {
            Paginator::currentPageResolver(function () use ($notes) {
                return $notes->lastPage();
            });
            $notes = $query->paginate($showRecord);
        }

        $notes->getCollection()->transform(function (self $note) {
            $note->presentForIndex();
            $note->sell_order_no = $note->parent?->invoice_no;

            return $note;
        });

        return $notes;
    }

    public static function createIssueNote(object $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);
        $sellId = (int) self::resolveScopedId($request->transaction_id ?? $request->parent_id);

        $sell = self::findVisibleSell($sellId);

        if ($sell === null || $sell->status !== 'approved') {
            throw ValidationException::withMessages([
                'transaction_id' => 'Select an approved sell invoice.',
            ]);
        }

        if ($sell->childIssueNotes()->exists()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'An issue note already exists for this sell invoice.',
            ]);
        }

        if ($companyId !== null && (int) $sell->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The sell invoice does not belong to this company.',
            ]);
        }

        if ($branchId !== null && (int) $sell->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'The sell invoice does not belong to this branch.',
            ]);
        }

        self::applyIssuedQuantities($sell, $request->selllines ?? []);

        $note = $sell->replicate();
        $note->parent_id = $sell->id;
        $note->type = self::TYPE_ISSUE_NOTE;
        $note->status = 'issue';
        $note->invoice_no = self::generateIssueNoteNo($companyId);
        $note->created_by = Auth::id();
        $note->updated_by = null;
        $note->approved_by = null;
        $note->approved_date = null;
        $note->save();

        $sell->status = 'issue';
        $sell->save();

        return $note;
    }

    public static function updateIssueNote(object $request, int $id): self
    {
        $note = self::findVisibleIssueNote($id);

        if ($note === null) {
            abort(404);
        }

        $sell = self::findVisibleSell((int) $note->parent_id);

        if ($sell === null) {
            abort(404);
        }

        self::applyIssuedQuantities($sell, $request->selllines ?? []);

        $note->updated_by = Auth::id();
        $note->save();

        return $note;
    }

    public static function deleteIssueNote(int $id): void
    {
        $note = self::findVisibleIssueNote($id);

        if ($note === null) {
            abort(404);
        }

        $sell = self::findVisibleSell((int) $note->parent_id);

        if ($sell !== null) {
            $sell->selllines()->update(['quantity_issue' => 0]);
            $sell->status = 'approved';
            $sell->save();
        }

        $note->delete();
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    public static function applyIssuedQuantities(self $sell, array $lines): void
    {
        self::assertValidLines($lines);

        $sell->loadMissing('selllines');
        $indexed = $sell->selllines->keyBy('id');
        $updatedIds = [];

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }

            $lineId = (int) ($row['id'] ?? 0);
            $line = $indexed->get($lineId);

            if ($line === null) {
                throw ValidationException::withMessages([
                    'selllines' => 'One or more lines do not belong to this sell.',
                ]);
            }

            $quantity = SellLine::resolveNumeric($line->quantity, 1);
            $issued = SellLine::resolveNumeric($row['quantity_issue'] ?? 0);

            if ($issued < 0 || $issued > $quantity) {
                throw ValidationException::withMessages([
                    'selllines' => 'Issue quantity must be between 0 and the sold quantity.',
                ]);
            }

            $line->quantity_issue = $issued;
            $line->save();
            $updatedIds[] = $lineId;
        }

        if ($updatedIds === []) {
            throw ValidationException::withMessages([
                'selllines' => ['Add at least one product line.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentIssueNote(): array
    {
        $this->loadMissing([
            'company:id,name,address',
            'branch:id,name',
            'contact:id,business_name,address,mobile',
            'parent:id,invoice_no,status,payment_status,transaction_date',
        ]);

        $lines = $this->issueNoteLines();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'contact_id' => $this->contact_id,
            'transaction_id' => $this->parent_id,
            'parent_id' => $this->parent_id,
            'invoice_no' => $this->invoice_no,
            'sell_order_no' => $this->parent?->invoice_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'transaction_date_label' => $this->transaction_date?->format('d M Y'),
            'status' => $this->status,
            'status_label' => Str::headline((string) $this->status),
            'payment_status' => $this->payment_status,
            'payment_status_label' => Str::headline((string) $this->payment_status),
            'business_name' => $this->contact?->business_name,
            'customer_name' => $this->contact?->business_name,
            'address' => $this->contact?->address,
            'mobile' => $this->contact?->mobile,
            'company_name' => $this->company?->name,
            'company_address' => $this->company?->address,
            'branch_name' => $this->branch?->name,
            'formatted_amount' => number_format((float) $this->final_amount, 2),
            'final_amount' => $this->final_amount,
            'selllines' => $lines,
        ];
    }

    public static function findVisiblePurchaseReturn(int $id): ?self
    {
        return self::query()->purchaseReturns()->visibleToCurrentUser()->find($id);
    }

    public static function generatePurchaseReturnNo(?int $companyId, ?string $requested = null): string
    {
        $requestedInvoice = trim((string) $requested);

        if ($requestedInvoice !== '') {
            return $requestedInvoice;
        }

        $prefix = 'PR';

        if ($companyId !== null) {
            $settingPrefix = CompanySetting::query()
                ->where('company_id', $companyId)
                ->value('purchase_return');

            if (is_string($settingPrefix) && trim($settingPrefix) !== '') {
                $prefix = trim($settingPrefix);
            }
        }

        $lastId = (int) self::query()
            ->withTrashed()
            ->purchaseReturns()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $invoiceNo = $prefix.'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->purchaseReturns()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('invoice_no', $invoiceNo)
                ->exists()
        );

        return $invoiceNo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function eligibleReturnPurchases(?int $companyId, ?int $branchId, ?int $contactId): array
    {
        return self::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->where('status', 'received')
            ->whereDoesntHave('purchasereturn')
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($contactId !== null, fn (Builder $query) => $query->where('contact_id', $contactId))
            ->orderByDesc('id')
            ->get(['id', 'invoice_no', 'transaction_date', 'final_amount', 'status'])
            ->map(fn (self $purchase): array => [
                'id' => $purchase->id,
                'text' => $purchase->invoice_no,
                'invoice_no' => $purchase->invoice_no,
                'transaction_date' => $purchase->transaction_date?->format('Y-m-d'),
                'final_amount' => $purchase->final_amount,
                'status' => $purchase->status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchaseReturnLines(): array
    {
        $purchase = $this->parent ?? $this;

        $purchase->loadMissing([
            'purchaselines.product:id,name,sku',
            'purchaselines.productdetail:id,product_id,name,sku,variation_name',
            'purchaselines.unit:id,name,short_name',
        ]);

        return $purchase->purchaselines
            ->map(function (PurchaseLine $line): array {
                $quantity = PurchaseLine::resolveNumeric($line->quantity, 1);
                $received = PurchaseLine::resolveNumeric($line->quantity_received);
                $returned = PurchaseLine::resolveNumeric($line->quantity_returned);
                $rate = PurchaseLine::resolveNumeric($line->purchase_rate);

                return [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'variation_id' => $line->variation_id,
                    'unit_id' => $line->unit_id,
                    'product_name' => $line->product?->name ?? $line->productdetail?->name,
                    'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                    'unit_name' => $line->unit?->name,
                    'unit_short_name' => $line->unit?->short_name,
                    'purchase_rate' => $rate,
                    'quantity' => $quantity,
                    'quantity_received' => $received,
                    'quantity_returned' => $returned,
                    'remaining_qty' => max($received - $returned, 0),
                    'row_subtotal' => round($rate * $returned, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, self>
     */
    public static function paginatePurchaseReturns(array $filters = []): LengthAwarePaginator
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortType = $filters['sort_type'] ?? 'desc';
        $showRecord = $filters['show_record'] ?? 10;
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $curPage = (int) ($filters['cur_page'] ?? $filters['page'] ?? 1);

        $query = self::query()
            ->purchaseReturns()
            ->visibleToCurrentUser()
            ->with([
                'company:id,name',
                'branch:id,name',
                'contact:id,business_name',
                'parent:id,invoice_no',
            ])
            ->when($status !== 'all', function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when(! empty($filters['payment_status']) && $filters['payment_status'] !== 'all', function ($query) use ($filters) {
                $query->where('payment_status', $filters['payment_status']);
            })
            ->when($search, function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->whereAny(['invoice_no', 'sup_ref_no'], 'like', "%{$search}%")
                        ->orWhereHas('contact', function ($contact) use ($search) {
                            $contact->where('business_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('parent', function ($parent) use ($search) {
                            $parent->where('invoice_no', 'like', "%{$search}%");
                        });
                });
            })
            ->when(! empty($filters['company_id']), function ($query) use ($filters) {
                $query->where('company_id', $filters['company_id']);
            })
            ->when(! empty($filters['branch_id']), function ($query) use ($filters) {
                $query->where('branch_id', $filters['branch_id']);
            })
            ->when(! empty($filters['contact_id']), function ($query) use ($filters) {
                $query->where('contact_id', $filters['contact_id']);
            })
            ->when(! empty($filters['transaction_date']), function ($query) use ($filters) {
                $query->whereDate('transaction_date', $filters['transaction_date']);
            })
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(function () use ($curPage) {
            return $curPage;
        });

        $returns = $query->paginate($showRecord);

        if ($curPage > $returns->lastPage()) {
            Paginator::currentPageResolver(function () use ($returns) {
                return $returns->lastPage();
            });
            $returns = $query->paginate($showRecord);
        }

        $returns->getCollection()->transform(function (self $note) {
            $note->presentForIndex();
            $note->purchase_order_no = $note->parent?->invoice_no;

            return $note;
        });

        return $returns;
    }

    public static function createPurchaseReturn(object $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);
        $purchaseId = (int) self::resolveScopedId($request->transaction_id ?? $request->parent_id);

        $purchase = self::findVisiblePurchase($purchaseId);

        if ($purchase === null || $purchase->status !== 'received') {
            throw ValidationException::withMessages([
                'transaction_id' => 'Select a received purchase order.',
            ]);
        }

        if ($purchase->purchasereturn()->exists()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'A purchase return already exists for this purchase order.',
            ]);
        }

        if ($companyId !== null && (int) $purchase->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'The purchase order does not belong to this company.',
            ]);
        }

        if ($branchId !== null && (int) $purchase->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'The purchase order does not belong to this branch.',
            ]);
        }

        $total = self::applyReturnedQuantities($purchase, $request->purchaselines ?? []);

        $note = new self;
        $note->company_id = $companyId ?? $purchase->company_id;
        $note->branch_id = $branchId ?? $purchase->branch_id;
        $note->contact_id = $purchase->contact_id;
        $note->parent_id = $purchase->id;
        $note->type = self::TYPE_PURCHASE_RETURN;
        $note->status = 'pending';
        $note->payment_status = 'due';
        $note->pay_term = $purchase->pay_term;
        $note->pay_type = $purchase->pay_type ?: 'day';
        $note->invoice_no = self::generatePurchaseReturnNo($companyId, $request->invoice_no ?? null);
        $note->transaction_date = self::parseTransactionDate($request->transaction_date ?? now());
        $note->attachment = self::storeImage($request);
        $note->final_amount = $total;
        $note->total_item = collect($request->purchaselines ?? [])
            ->filter(fn ($row) => is_array($row) && PurchaseLine::resolveNumeric($row['quantity_returned'] ?? 0) > 0)
            ->count();
        $note->created_by = Auth::id();
        $note->save();

        return $note;
    }

    public static function updatePurchaseReturn(object $request, int $id): self
    {
        $note = self::findVisiblePurchaseReturn($id);

        if ($note === null) {
            abort(404);
        }

        $purchase = self::findVisiblePurchase((int) $note->parent_id);

        if ($purchase === null) {
            abort(404);
        }

        $total = self::applyReturnedQuantities($purchase, $request->purchaselines ?? []);

        $note->final_amount = $total;
        $note->total_item = collect($request->purchaselines ?? [])
            ->filter(fn ($row) => is_array($row) && PurchaseLine::resolveNumeric($row['quantity_returned'] ?? 0) > 0)
            ->count();
        $note->updated_by = Auth::id();
        $note->save();

        return $note;
    }

    public static function deletePurchaseReturn(int $id): void
    {
        $note = self::findVisiblePurchaseReturn($id);

        if ($note === null) {
            abort(404);
        }

        $purchase = self::findVisiblePurchase((int) $note->parent_id);

        if ($purchase !== null) {
            $purchase->purchaselines()->update(['quantity_returned' => 0]);
        }

        $note->delete();
    }

    /**
     * @param  array<int, mixed>  $lines
     */
    public static function applyReturnedQuantities(self $purchase, array $lines): float
    {
        self::assertValidLines($lines);

        $purchase->loadMissing('purchaselines');
        $indexed = $purchase->purchaselines->keyBy('id');
        $updatedIds = [];
        $total = 0.0;
        $returnedCount = 0;

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }

            $lineId = (int) ($row['id'] ?? 0);
            $line = $indexed->get($lineId);

            if ($line === null) {
                throw ValidationException::withMessages([
                    'purchaselines' => 'One or more lines do not belong to this purchase.',
                ]);
            }

            $received = PurchaseLine::resolveNumeric($line->quantity_received);
            $returned = PurchaseLine::resolveNumeric($row['quantity_returned'] ?? 0);

            if ($returned < 0 || $returned > $received) {
                throw ValidationException::withMessages([
                    'purchaselines' => 'Return quantity must be between 0 and the received quantity.',
                ]);
            }

            $line->quantity_returned = $returned;
            $line->save();
            $updatedIds[] = $lineId;
            $total += PurchaseLine::resolveNumeric($line->purchase_rate) * $returned;

            if ($returned > 0) {
                $returnedCount++;
            }
        }

        if ($updatedIds === [] || $returnedCount === 0) {
            throw ValidationException::withMessages([
                'purchaselines' => ['Enter a return quantity for at least one item.'],
            ]);
        }

        return round($total, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPurchaseReturn(): array
    {
        $this->loadMissing([
            'company:id,name,address',
            'branch:id,name',
            'contact:id,business_name,address,mobile',
            'parent:id,invoice_no,sup_ref_no,status,payment_status,transaction_date',
        ]);

        $lines = $this->purchaseReturnLines();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'contact_id' => $this->contact_id,
            'transaction_id' => $this->parent_id,
            'parent_id' => $this->parent_id,
            'invoice_no' => $this->invoice_no,
            'purchase_order_no' => $this->parent?->invoice_no,
            'sup_ref_no' => $this->parent?->sup_ref_no ?? $this->sup_ref_no,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'transaction_date_label' => $this->transaction_date?->format('d M Y'),
            'status' => $this->status,
            'status_label' => Str::headline((string) $this->status),
            'payment_status' => $this->payment_status,
            'payment_status_label' => Str::headline((string) $this->payment_status),
            'business_name' => $this->contact?->business_name,
            'address' => $this->contact?->address,
            'mobile' => $this->contact?->mobile,
            'company_name' => $this->company?->name,
            'company_address' => $this->company?->address,
            'branch_name' => $this->branch?->name,
            'attachment' => $this->attachment,
            'attachment_url' => self::imageUrl($this->attachment),
            'formatted_amount' => number_format((float) $this->final_amount, 2),
            'final_amount' => $this->final_amount,
            'purchaselines' => $lines,
        ];
    }

    private function variationDisplayName(?string $variationName): string
    {
        if ($variationName === null || $variationName === '' || $variationName === 'dummy') {
            return '-';
        }

        return $variationName;
    }
}
