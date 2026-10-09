<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An internal request for products before a Purchase Order exists. Staff ask for what they need
 * (product, quantity, unit — no price, no supplier), a manager/companyadmin approves it, and only an
 * approved, not-yet-converted requisition can become a real Purchase Order (PurchaseController,
 * `purchase_requisition_id`), which is the only moment a supplier and a price are chosen.
 *
 * Status flow: `draft`/`pending` -> `approved`/`rejected` -> `converted`. A rejected requisition is
 * corrected in place and resubmitted (same record, same requisition_no): editing it recomputes the
 * status the same way a new one would (pending, or approved straight away if the company's
 * `purchaserequisition` auto-approval toggle is on). Only a draft, pending or rejected requisition can
 * be edited or deleted; an approved or converted one is left alone.
 *
 * This is a standalone table, not a `t_accounts` voucher and not a `transactions` row: it has no
 * accounting or stock effect of its own.
 */
class PurchaseRequisition extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED = 'converted';

    /**
     * Statuses that may still be edited or deleted.
     *
     * @var list<string>
     */
    public const EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_REJECTED];

    protected $fillable = [
        'company_id',
        'branch_id',
        'contact_id',
        'requested_by',
        'requisition_no',
        'requisition_date',
        'status',
        'note',
        'purchase_order_id',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'requisition_date' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'purchase_order_id');
    }

    /**
     * @return HasMany<PurchaseRequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionLine::class);
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

        if ($user->branch_id && ! $user->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * @param  array{status?: mixed, search?: mixed, company_id?: mixed, branch_id?: mixed}  $filters
     */
    public function scopeMatchingListFilters(Builder $query, array $filters): Builder
    {
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : 'all';
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        return $query
            ->when($status !== 'all' && $status !== '', fn (Builder $q) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where('requisition_no', 'like', "%{$search}%"))
            ->when(! empty($filters['company_id']), fn (Builder $q) => $q->where('company_id', $filters['company_id']))
            ->when(! empty($filters['branch_id']), fn (Builder $q) => $q->where('branch_id', $filters['branch_id']));
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function findVisible(int $id): ?self
    {
        return self::query()->visibleToCurrentUser()->find($id);
    }

    public static function nextRequisitionNo(?int $companyId): string
    {
        $lastId = (int) self::query()
            ->withTrashed()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->max('id');

        $next = $lastId + 1;

        do {
            $number = 'PR-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (
            self::query()
                ->withTrashed()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('requisition_no', $number)
                ->exists()
        );

        return $number;
    }

    /**
     * The status a requisition (new or resubmitted) starts in: `draft` if explicitly asked for,
     * otherwise `pending` unless the company's auto-approval toggle skips straight to `approved`.
     */
    public static function resolveStatus(?int $companyId, ?string $requested): string
    {
        if ($requested === self::STATUS_DRAFT) {
            return self::STATUS_DRAFT;
        }

        return CompanySetting::autoApproves($companyId, 'purchaserequisition') ? self::STATUS_APPROVED : self::STATUS_PENDING;
    }

    /**
     * @throws ValidationException
     */
    public static function assertValidLines(?array $lines): void
    {
        if ($lines === null || $lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['Add at least one product line.'],
            ]);
        }
    }

    public static function createRequisition(Request $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);

        self::assertValidLines($request->input('lines') ?? null);

        $requisition = new self;
        $requisition->company_id = $companyId;
        $requisition->branch_id = $branchId;
        $requisition->contact_id = self::resolveScopedId($request->contact_id);
        $requisition->requested_by = Auth::id();
        $requisition->requisition_no = self::nextRequisitionNo($companyId);
        $requisition->requisition_date = $request->input('requisition_date') ?: now()->toDateString();
        $requisition->note = $request->input('note');
        $requisition->status = self::resolveStatus($companyId, $request->input('status'));
        $requisition->save();

        $requisition->syncLines($request->input('lines', []));

        return $requisition;
    }

    /**
     * @throws ValidationException
     */
    public static function updateRequisition(Request $request, int $id): self
    {
        $requisition = self::findVisible($id);

        if ($requisition === null) {
            abort(404);
        }

        if (! in_array($requisition->status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Only a draft, pending or rejected requisition can be edited.'],
            ]);
        }

        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id) ?? $requisition->company_id;
        $branchId = self::resolveScopedId($request->branch_id) ?? $requisition->branch_id;

        self::assertValidLines($request->input('lines') ?? null);

        $wasRejected = $requisition->status === self::STATUS_REJECTED;

        $requisition->company_id = $companyId;
        $requisition->branch_id = $branchId;
        $requisition->contact_id = self::resolveScopedId($request->contact_id);
        $requisition->requisition_date = $request->input('requisition_date') ?: $requisition->requisition_date;
        $requisition->note = $request->input('note');

        // a rejected requisition being edited is a resubmission: it goes back through the same
        // approval decision a brand new one would (pending, or approved if the toggle is on)
        if ($wasRejected) {
            $requisition->status = self::resolveStatus($companyId, $request->input('status'));
            $requisition->rejected_by = null;
            $requisition->rejected_at = null;
        } elseif ($requisition->status === self::STATUS_DRAFT && $request->input('status') !== self::STATUS_DRAFT) {
            $requisition->status = self::resolveStatus($companyId, $request->input('status'));
        }

        $requisition->save();
        $requisition->syncLines($request->input('lines', []));

        return $requisition;
    }

    public static function deleteRequisition(int $id): void
    {
        $requisition = self::findVisible($id);

        if ($requisition === null) {
            abort(404);
        }

        if (! in_array($requisition->status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Only a draft, pending or rejected requisition can be deleted.'],
            ]);
        }

        $requisition->delete();
    }

    /**
     * Approve a pending requisition. The lines are re-checked first so an empty requisition can
     * never reach the eligible-for-conversion list.
     */
    public static function approveRequisition(int $id): self
    {
        $requisition = self::findVisible($id);

        if ($requisition === null) {
            abort(404);
        }

        if ($requisition->status !== self::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only a pending requisition can be approved.'],
            ]);
        }

        self::assertValidLines($requisition->lines()->get()->all());

        $requisition->status = self::STATUS_APPROVED;
        $requisition->approved_by = Auth::id();
        $requisition->approved_at = now();
        $requisition->save();

        return $requisition;
    }

    public static function rejectRequisition(int $id, ?string $reason = null): self
    {
        $requisition = self::findVisible($id);

        if ($requisition === null) {
            abort(404);
        }

        if ($requisition->status !== self::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only a pending requisition can be rejected.'],
            ]);
        }

        $reason = trim((string) $reason);

        if ($reason !== '') {
            $requisition->note = trim(($requisition->note ? $requisition->note."\n" : '').'Rejected: '.$reason);
        }

        $requisition->status = self::STATUS_REJECTED;
        $requisition->rejected_by = Auth::id();
        $requisition->rejected_at = now();
        $requisition->save();

        return $requisition;
    }

    /**
     * Approved requisitions of the company/branch not yet converted into a Purchase Order.
     *
     * @return list<array<string, mixed>>
     */
    public static function eligible(?int $companyId, ?int $branchId): array
    {
        return self::query()
            ->visibleToCurrentUser()
            ->where('status', self::STATUS_APPROVED)
            ->whereNull('purchase_order_id')
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->orderByDesc('id')
            ->get(['id', 'requisition_no', 'requisition_date'])
            ->map(fn (self $requisition): array => [
                'id' => $requisition->id,
                'text' => $requisition->requisition_no,
                'requisition_no' => $requisition->requisition_no,
                'requisition_date' => $requisition->requisition_date?->format('Y-m-d'),
            ])
            ->values()
            ->all();
    }

    /**
     * This requisition's lines, shaped for prefilling a Purchase Order's line editor: product,
     * variation and unit carry over, quantity is the requested one, but there is no rate — the buyer
     * fills that in at PO time.
     *
     * @return list<array<string, mixed>>
     */
    public function linesForConversion(): array
    {
        $this->loadMissing([
            'lines.product:id,name,sku',
            'lines.productdetail:id,product_id,name,sku,variation_name',
            'lines.unit:id,name,short_name',
        ]);

        return $this->lines
            ->map(fn (PurchaseRequisitionLine $line): array => [
                'product_id' => $line->product_id,
                'variation_id' => $line->variation_id,
                'unit_id' => $line->unit_id,
                'product_name' => $line->product?->name ?? $line->productdetail?->name,
                'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                'unit_name' => $line->unit?->short_name ?? $line->unit?->name,
                'quantity' => $line->requested_quantity,
                'note' => $line->note,
            ])
            ->values()
            ->all();
    }

    /**
     * Marks this requisition as converted into the given Purchase Order. Refused if it is not an
     * eligible (approved, not already converted) requisition, so a requisition can convert at most
     * once.
     *
     * @throws ValidationException
     */
    public function markConverted(int $purchaseOrderId): void
    {
        if ($this->status !== self::STATUS_APPROVED || $this->purchase_order_id !== null) {
            throw ValidationException::withMessages([
                'purchase_requisition_id' => ['This requisition is not eligible for conversion (it must be approved and not already converted).'],
            ]);
        }

        $this->status = self::STATUS_CONVERTED;
        $this->purchase_order_id = $purchaseOrderId;
        $this->save();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(array $lines): void
    {
        $this->lines()->delete();

        foreach ($lines as $row) {
            if (! is_array($row)) {
                continue;
            }

            PurchaseRequisitionLine::createFromRow($row, $this->id);
        }
    }

    public function contactName(): ?string
    {
        $contact = $this->contact;

        if ($contact === null) {
            return null;
        }

        $name = trim((string) $contact->business_name);

        return $name !== '' ? $name : (trim($contact->first_name.' '.$contact->last_name) ?: null);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForIndex(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'requisition_no' => $this->requisition_no,
            'requisition_date' => $this->requisition_date?->format('Y-m-d'),
            'contact_name' => $this->contactName(),
            'requested_by_name' => $this->requestedBy?->full_name,
            'status' => $this->status,
            'status_label' => Str::headline($this->status),
            'note' => $this->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForForm(): array
    {
        $this->loadMissing([
            'lines.product:id,name,sku',
            'lines.productdetail:id,product_id,name,sku,variation_name',
            'lines.unit:id,name,short_name',
            'company:id,name',
            'branch:id,name',
            'contact:id,business_name,first_name,last_name',
            'requestedBy:id,first_name,last_name',
            'approvedBy:id,first_name,last_name',
            'rejectedBy:id,first_name,last_name',
        ]);

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->contactName(),
            'requested_by' => $this->requested_by,
            'requested_by_name' => $this->requestedBy?->full_name,
            'requisition_no' => $this->requisition_no,
            'requisition_date' => $this->requisition_date?->format('Y-m-d'),
            'note' => $this->note,
            'status' => $this->status,
            'status_label' => Str::headline($this->status),
            'is_editable' => in_array($this->status, self::EDITABLE_STATUSES, true),
            'can_approve' => $this->status === self::STATUS_PENDING,
            'purchase_order_id' => $this->purchase_order_id,
            'approved_by' => $this->approved_by,
            'approved_by_name' => $this->approvedBy?->full_name,
            'approved_at' => $this->approved_at?->format('d M Y'),
            'rejected_by' => $this->rejected_by,
            'rejected_by_name' => $this->rejectedBy?->full_name,
            'rejected_at' => $this->rejected_at?->format('d M Y'),
            'lines' => $this->lines->map(fn (PurchaseRequisitionLine $line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'variation_id' => $line->variation_id,
                'unit_id' => $line->unit_id,
                'product_name' => $line->product?->name ?? $line->productdetail?->name,
                'sku' => $line->productdetail?->sku ?? $line->product?->sku,
                'unit_name' => $line->unit?->short_name ?? $line->unit?->name,
                'requested_quantity' => $line->requested_quantity,
                'note' => $line->note,
            ])->values()->all(),
        ];
    }
}
