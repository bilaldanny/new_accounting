<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A follow-up/task/call/meeting (Step 4) or a communication-log entry (Step 5, `type` note/email) —
 * one table for both, attached to a Lead and/or an Opportunity and/or a Contact via explicit nullable
 * FKs (see the creating migration for why this isn't a polymorphic relation).
 */
class Activity extends Model
{
    use SoftDeletes;

    public const TYPES = ['call', 'meeting', 'task', 'follow_up', 'note', 'email'];

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['subject', 'type', 'due_at', 'completed_at', 'created_at'];

    protected $fillable = [
        'company_id',
        'lead_id',
        'opportunity_id',
        'contact_id',
        'type',
        'subject',
        'description',
        'due_at',
        'assigned_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'opportunity_id' => 'integer',
            'contact_id' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'assigned_to' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
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

        return $query->where('company_id', $user->company_id);
    }

    /**
     * The company a record is saved under: the superadmin picks one, everyone else is always their own
     * company whatever the request says.
     */
    private static function scopedCompanyId(object $request, ?int $current = null): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return self::resolveScopedId($request->company_id) ?? $current;
        }

        return $user?->company_id ? (int) $user->company_id : $current;
    }

    public static function createActivity(object $request): self
    {
        $activity = new self;
        $activity->company_id = self::scopedCompanyId($request);
        $activity->created_by = Auth::id();
        $activity->fillDetails($request);
        $activity->save();

        return $activity;
    }

    public static function updateActivity(object $request, int|string $id): self
    {
        $activity = self::query()->visibleToCurrentUser()->findOrFail($id);
        $activity->company_id = self::scopedCompanyId($request, (int) $activity->company_id) ?? $activity->company_id;
        $activity->fillDetails($request);
        $activity->save();

        return $activity;
    }

    public static function deleteActivity(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    /**
     * Toggles completion (the list/detail page's "mark done"/"reopen" action).
     */
    public function toggleComplete(): void
    {
        $this->completed_at = $this->completed_at ? null : now();
        $this->save();
    }

    private function fillDetails(object $request): void
    {
        $this->lead_id = self::resolveScopedId($request->lead_id);
        $this->opportunity_id = self::resolveScopedId($request->opportunity_id);
        $this->contact_id = self::resolveScopedId($request->contact_id);
        $this->type = in_array($request->type, self::TYPES, true) ? $request->type : 'task';
        $this->subject = trim((string) $request->subject);
        $this->description = self::blankToNull($request->description);
        $this->due_at = self::blankToNull($request->due_at);
        $this->assigned_to = self::resolveScopedId($request->assigned_to);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
