<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A deal in progress. Optionally traced back to the `Lead` it came from (`lead_id`, set once via
 * "Convert to Opportunity" and never required — an Opportunity can also be created directly) and/or
 * to a real `Contact` once one exists. `status` (open/won/lost) is not set directly by the user on the
 * standard form — it follows the `PipelineStage` the opportunity is moved onto (see `moveToStage()`),
 * matching how a Kanban board's Won/Lost columns behave in every mainstream CRM.
 */
class Opportunity extends Model
{
    use SoftDeletes;

    public const STATUSES = ['open', 'won', 'lost'];

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'deal_value', 'expected_closing_date', 'status', 'created_at'];

    protected $fillable = [
        'company_id',
        'lead_id',
        'contact_id',
        'name',
        'deal_value',
        'expected_closing_date',
        'pipeline_stage_id',
        'assigned_to',
        'status',
        'lost_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'contact_id' => 'integer',
            'deal_value' => 'decimal:2',
            'expected_closing_date' => 'date',
            'pipeline_stage_id' => 'integer',
            'assigned_to' => 'integer',
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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

    public static function createOpportunity(object $request): self
    {
        $opportunity = new self;
        $opportunity->company_id = self::scopedCompanyId($request);
        $opportunity->fillDetails($request);
        $opportunity->applyStatusFromStage();
        $opportunity->save();

        return $opportunity;
    }

    public static function updateOpportunity(object $request, int|string $id): self
    {
        $opportunity = self::query()->visibleToCurrentUser()->findOrFail($id);
        $opportunity->company_id = self::scopedCompanyId($request, (int) $opportunity->company_id) ?? $opportunity->company_id;
        $opportunity->fillDetails($request);
        $opportunity->applyStatusFromStage();
        $opportunity->save();

        return $opportunity;
    }

    public static function deleteOpportunity(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    /**
     * Moves the opportunity onto a different stage (the Kanban board's drag-and-drop action) and
     * follows the stage's won/lost flag into `status`.
     */
    public function moveToStage(int $pipelineStageId): void
    {
        $this->pipeline_stage_id = $pipelineStageId;
        $this->applyStatusFromStage();
        $this->save();
    }

    private function applyStatusFromStage(): void
    {
        $stage = $this->pipeline_stage_id
            ? PipelineStage::query()->find($this->pipeline_stage_id)
            : null;

        $this->status = match (true) {
            $stage?->is_won => 'won',
            $stage?->is_lost => 'lost',
            default => 'open',
        };
    }

    private function fillDetails(object $request): void
    {
        $this->lead_id = self::resolveScopedId($request->lead_id);
        $this->contact_id = self::resolveScopedId($request->contact_id);
        $this->name = trim((string) $request->name);
        $this->deal_value = round((float) ($request->deal_value ?? 0), 2);
        $this->expected_closing_date = self::blankToNull($request->expected_closing_date);
        $this->pipeline_stage_id = self::resolveScopedId($request->pipeline_stage_id);
        $this->assigned_to = self::resolveScopedId($request->assigned_to);
        $this->lost_reason = self::blankToNull($request->lost_reason);
        $this->notes = self::blankToNull($request->notes);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
