<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * Cash a collector took from a customer. It is `pending` until someone completes it against the
 * customer's open invoices (App\Services\CashCollections), which is the only moment anything is posted;
 * a `cancelled` one never had any effect. Only pending collections can be edited, and only pending or
 * cancelled ones deleted: a completed one is the record of the payments it made. A completed collection can
 * keep part of the cash as a customer advance and can be reversed (its payments are removed and it is pending
 * again), see CashCollections.
 */
class CashCollection extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['reference', 'collected_on', 'amount', 'status', 'created_at'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'contact_id',
        'reference',
        'collected_on',
        'amount',
        'advance_amount',
        'note',
        'status',
        'payment_account',
        'advance_payment_id',
        'collected_by',
        'completed_by',
        'completed_at',
        'cancelled_at',
        'reversed_at',
        'reversed_by',
    ];

    protected function casts(): array
    {
        return [
            'collected_on' => 'date:Y-m-d',
            'amount' => 'float',
            'advance_amount' => 'float',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reversed_at' => 'datetime',
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
    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    /**
     * @return HasMany<CashCollectionAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CashCollectionAllocation::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * What is left of the advance this collection kept: the advance less the parts already used on invoices.
     */
    public function advanceRemaining(): float
    {
        $used = (float) $this->allocations()->where('kind', CashCollectionAllocation::KIND_ADVANCE)->sum('amount');

        return round(max((float) $this->advance_amount - $used, 0), 2);
    }

    /**
     * The superadmin sees every collection, a company user their company's; a user tied to a branch (and
     * not the company admin) only that branch's.
     */
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
     * The name shown for the customer: the business name, else first and last name.
     */
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
     * `CC-00001`-style reference, unique within the company (trashed collections included).
     */
    public static function nextReference(int $companyId): string
    {
        $next = (int) self::withTrashed()->where('company_id', $companyId)->max('id') + 1;

        do {
            $reference = 'CC-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (self::withTrashed()->where('company_id', $companyId)->where('reference', $reference)->exists());

        return $reference;
    }
}
