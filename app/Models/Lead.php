<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * A sales lead: an unqualified prospect, tracked separately from `Contact` (a real, GL-linked
 * customer/supplier) until it converts. Converting sets `converted_contact_id` and `status =
 * 'converted'` but does not touch the lead's own fields — it stays as a record of where the contact
 * came from.
 */
class Lead extends Model
{
    use SoftDeletes;

    public const STATUSES = ['new', 'contacted', 'qualified', 'unqualified', 'converted'];

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'company_name', 'email', 'phone', 'status', 'source', 'created_at'];

    protected $fillable = [
        'company_id',
        'name',
        'company_name',
        'email',
        'phone',
        'source',
        'lead_source_id',
        'status',
        'assigned_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_to' => 'integer',
            'lead_source_id' => 'integer',
            'converted_contact_id' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
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

    public static function createLead(object $request): self
    {
        $lead = new self;
        $lead->company_id = self::scopedCompanyId($request);
        $lead->fillDetails($request);
        $lead->status = in_array($request->status, self::STATUSES, true) ? $request->status : 'new';
        $lead->save();

        return $lead;
    }

    public static function updateLead(object $request, int|string $id): self
    {
        $lead = self::query()->visibleToCurrentUser()->findOrFail($id);
        $lead->company_id = self::scopedCompanyId($request, (int) $lead->company_id) ?? $lead->company_id;
        $lead->fillDetails($request);
        $lead->status = in_array($request->status, self::STATUSES, true) ? $request->status : $lead->status;
        $lead->save();

        return $lead;
    }

    public static function deleteLead(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    /**
     * A lead submitted through the public, unauthenticated capture form (`PublicLeadCaptureController`)
     * — no `Auth::user()` exists here, so `company_id` is the resolved target company directly, not
     * derived from the signed-in user like every other write path on this model. The lead source is
     * always "Website" (auto-created for the company the first time this runs), matching the scope's
     * "Lead Source automatically set to Website" requirement.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createFromCapture(int $companyId, array $data): self
    {
        $leadSource = LeadSource::query()->firstOrCreate(
            ['company_id' => $companyId, 'name' => 'Website'],
            ['is_active' => true]
        );

        $request = Request::create('/', 'POST', [
            'company_id' => $companyId,
            'name' => (string) ($data['name'] ?? ''),
            'company_name' => (string) ($data['company_name'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'lead_source_id' => $leadSource->id,
            'notes' => (string) ($data['notes'] ?? ''),
        ]);

        $lead = new self;
        $lead->company_id = $companyId;
        $lead->fillDetails($request);
        $lead->status = 'new';
        $lead->save();

        return $lead;
    }

    private function fillDetails(object $request): void
    {
        $this->name = trim((string) $request->name);
        $this->company_name = self::blankToNull($request->company_name);
        $this->email = self::blankToNull($request->email);
        $this->phone = self::blankToNull($request->phone);
        $this->assigned_to = $request->assigned_to !== null && $request->assigned_to !== '' ? (int) $request->assigned_to : null;
        $this->notes = self::blankToNull($request->notes);

        $leadSourceId = $request->lead_source_id !== null && $request->lead_source_id !== ''
            ? (int) $request->lead_source_id
            : null;

        if ($leadSourceId !== null) {
            $this->lead_source_id = $leadSourceId;
            $this->source = LeadSource::query()->find($leadSourceId)?->name ?? $this->source;
        } else {
            $this->lead_source_id = null;
            $this->source = self::blankToNull($request->source);
        }
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Import upsert: id present -> update that lead, otherwise create a new one. Follows the app-wide
     * `{Model}::upsertFromImport(array $row): string` convention (HandlesBulkImport concern).
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $companyId = self::resolveScopedId($row['company_id'] ?? null) ?? (Auth::user()?->hasRole('superadmin') ? null : Auth::user()?->company_id);
        $request = self::buildImportRequest($row, $companyId);

        if ($id !== null) {
            $lead = self::query()->visibleToCurrentUser()->find($id);

            if ($lead === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Lead with id {$id} was not found."],
                ]);
            }

            self::updateLead($request, $id);

            return 'updated';
        }

        if (trim((string) ($row['name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'rows' => ['Lead name is required for new records.'],
            ]);
        }

        self::createLead($request);

        return 'created';
    }

    protected static function normalizeImportId(mixed $id): ?int
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }

        if (is_numeric($id)) {
            $normalized = (int) $id;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function buildImportRequest(array $row, ?int $companyId): Request
    {
        $leadSourceId = self::resolveImportLeadSourceId($row['lead_source_id'] ?? $row['source'] ?? null, $companyId);

        return Request::create('/', 'POST', [
            'company_id' => $companyId,
            'name' => (string) ($row['name'] ?? ''),
            'company_name' => (string) ($row['company_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'lead_source_id' => $leadSourceId,
            'source' => $leadSourceId === null ? (string) ($row['source'] ?? '') : '',
            'status' => (string) ($row['status'] ?? 'new'),
            'assigned_to' => $row['assigned_to'] ?? '',
            'notes' => (string) ($row['notes'] ?? ''),
        ]);
    }

    /**
     * Resolves a lead source by id or by name; an unmatched name is left as free text on `source`
     * rather than rejecting the whole row (a source is never required).
     */
    protected static function resolveImportLeadSourceId(mixed $value, ?int $companyId): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_numeric($value)) {
            $recordId = (int) $value;

            $exists = LeadSource::query()
                ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
                ->where('id', $recordId)
                ->exists();

            return $exists ? $recordId : null;
        }

        $name = trim((string) $value);

        $leadSource = LeadSource::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        return $leadSource?->id;
    }
}
