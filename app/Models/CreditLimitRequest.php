<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Approval Center (Phase 1, easy half) — Credit Limit: a request to change a customer/supplier's
 * `contacts.credit_limit`. No such request/approval concept existed in the codebase, so this is a
 * small new table rather than reuse of an existing one (see Contact::credit_limit, which this writes
 * to only once a request is approved). Approve+reject, mirroring the VoucherApprovalController family.
 */
class CreditLimitRequest extends Model
{
    use Auditable;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    public const SORTABLE = ['current_limit', 'requested_limit', 'status', 'created_at'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'contact_id',
        'current_limit',
        'requested_limit',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'current_limit' => 'float',
            'requested_limit' => 'float',
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

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function createRequest(object $request): self
    {
        $user = Auth::user();
        $companyId = self::resolveScopedId($request->company_id) ?? self::resolveScopedId($user?->company_id);
        $branchId = self::resolveScopedId($request->branch_id) ?? self::resolveScopedId($user?->branch_id);
        $contactId = (int) self::resolveScopedId($request->contact_id);

        $contact = Contact::findVisibleContact($contactId);

        if ($contact === null) {
            throw ValidationException::withMessages([
                'contact_id' => ['The selected contact was not found.'],
            ]);
        }

        $creditLimitRequest = new self;
        $creditLimitRequest->company_id = $companyId;
        $creditLimitRequest->branch_id = $branchId;
        $creditLimitRequest->contact_id = $contact->id;
        $creditLimitRequest->current_limit = (float) $contact->credit_limit;
        $creditLimitRequest->requested_limit = (float) $request->requested_limit;
        $creditLimitRequest->reason = $request->reason ?? null;
        $creditLimitRequest->status = self::STATUS_PENDING;
        $creditLimitRequest->requested_by = Auth::id();
        $creditLimitRequest->save();

        return $creditLimitRequest;
    }

    /**
     * @throws ValidationException
     */
    public static function approve(int $id): self
    {
        $creditLimitRequest = self::query()->visibleToCurrentUser()->find($id);

        if ($creditLimitRequest === null) {
            abort(404);
        }

        if ($creditLimitRequest->status !== self::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only pending credit limit requests can be approved.',
            ]);
        }

        $contact = Contact::findVisibleContact((int) $creditLimitRequest->contact_id);

        if ($contact === null) {
            abort(404);
        }

        $contact->credit_limit = $creditLimitRequest->requested_limit;
        $contact->save();

        $creditLimitRequest->status = self::STATUS_APPROVED;
        $creditLimitRequest->approved_by = Auth::id();
        $creditLimitRequest->approved_at = now();
        $creditLimitRequest->save();

        return $creditLimitRequest;
    }

    /**
     * @throws ValidationException
     */
    public static function reject(int $id, ?string $reason = null): self
    {
        $creditLimitRequest = self::query()->visibleToCurrentUser()->find($id);

        if ($creditLimitRequest === null) {
            abort(404);
        }

        if ($creditLimitRequest->status !== self::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only pending credit limit requests can be rejected.',
            ]);
        }

        $creditLimitRequest->status = self::STATUS_REJECTED;
        $creditLimitRequest->rejected_by = Auth::id();
        $creditLimitRequest->rejected_at = now();
        $creditLimitRequest->rejection_reason = $reason;
        $creditLimitRequest->save();

        return $creditLimitRequest;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForIndex(): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'contact_id' => $this->contact_id,
            'contact_name' => $this->contact?->business_name ?: trim((string) $this->contact?->first_name.' '.(string) $this->contact?->last_name),
            'current_limit' => (float) $this->current_limit,
            'requested_limit' => (float) $this->requested_limit,
            'reason' => $this->reason,
            'status' => $this->status,
            'status_label' => ucfirst((string) $this->status),
            'requested_by_name' => $this->requestedBy?->full_name,
            'created_at' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
