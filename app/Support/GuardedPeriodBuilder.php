<?php

namespace App\Support;

use App\Models\Concerns\GuardsLockedPeriods;
use App\Services\PeriodLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The query builder of a model under the period lock. `update`, `delete`, `forceDelete` - and `restore`, which is an update of the
 * soft-delete column - first look at the days of the rows the query would touch, and refuse as PeriodLock does for a single model.
 * It only reads the affected rows' days, so soft deletes and global scopes work exactly as they do on the plain builder.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class GuardedPeriodBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values)
    {
        $this->guardRows($values);

        return parent::update($values);
    }

    public function delete()
    {
        $this->guardRows();

        return parent::delete();
    }

    public function forceDelete()
    {
        $this->guardRows();

        return parent::forceDelete();
    }

    /**
     * @param  array<string, mixed>  $values  what an update writes (a changed day is checked too)
     */
    private function guardRows(array $values = []): void
    {
        $model = $this->getModel();

        if (! in_array(GuardsLockedPeriods::class, class_uses_recursive($model), true)) {
            return;
        }

        $dateColumn = $model::periodLockDateColumn();
        $newDate = $values[$dateColumn] ?? null;
        $changesDate = array_key_exists($dateColumn, $values) && $newDate !== null;
        $writesOnlyBookkeeping = $values !== [] && ! $changesDate && $model->periodLockIsBookkeeping($values);

        if ($writesOnlyBookkeeping) {
            return;
        }

        $rows = $model::periodLockRows($this->toBase())->groupBy(fn (object $row): string => $row->company_id.'|'.$row->branch_id);
        $guard = app(PeriodLock::class);

        foreach ($rows as $group) {
            $first = $group->first();
            $dates = $group->pluck('lock_date')->map(fn (mixed $date): ?string => $date === null ? null : Carbon::parse((string) $date)->toDateString())->unique()->all();

            if ($changesDate) {
                $dates[] = (string) $newDate;
            }

            $guard->check(
                $first->company_id === null ? null : (int) $first->company_id,
                $first->branch_id === null ? null : (int) $first->branch_id,
                $dates,
                $changesDate && $model::periodLockAppliesDatingRules() ? [(string) $newDate] : [],
                $model::class,
            );
        }
    }
}
