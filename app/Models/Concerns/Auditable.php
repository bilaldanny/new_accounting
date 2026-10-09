<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an AuditLog row for created / updated / deleted / restored events, with a field-level diff.
 * Only attributes that really changed are stored for updates; secrets and bookkeeping timestamps never are.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditLog::record($model, 'created', null, self::auditFilter($model->getAttributes())));

        static::updated(function (Model $model): void {
            $changes = self::auditFilter($model->getChanges());

            if ($changes === []) {
                return;
            }

            AuditLog::record($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
        });

        static::deleted(function (Model $model): void {
            $event = method_exists($model, 'isForceDeleting') && $model->isForceDeleting() ? 'force_deleted' : 'deleted';

            AuditLog::record($model, $event, self::auditFilter($model->getOriginal()), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => AuditLog::record($model, 'restored', null, null));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditFilter(array $values): array
    {
        $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'updated_at', 'created_at', 'deleted_at'];

        return array_diff_key($values, array_flip($hidden));
    }
}
