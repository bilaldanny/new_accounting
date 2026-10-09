<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One audited event. Append-only (no updated_at); written only by Concerns\Auditable.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(Model $model, string $event, ?array $old, ?array $new): void
    {
        $request = request();

        self::query()->create([
            'company_id' => (method_exists($model, 'auditCompanyId') ? $model->auditCompanyId() : $model->getAttribute('company_id')) ?: Auth::user()?->company_id,
            'branch_id' => $model->getAttribute('branch_id') ?: null,
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model::class,
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);
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
     * @return array<string, mixed>
     */
    public function presentForIndex(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $fields = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));

        return [
            'id' => $this->id,
            'event' => $this->event,
            'model' => class_basename($this->auditable_type),
            'auditable_id' => $this->auditable_id,
            'user_name' => $this->user?->full_name,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'changes' => array_map(fn (string $field): array => [
                'field' => $field,
                'old' => $old[$field] ?? null,
                'new' => $new[$field] ?? null,
            ], $fields),
        ];
    }
}
