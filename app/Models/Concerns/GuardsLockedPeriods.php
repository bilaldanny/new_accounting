<?php

namespace App\Models\Concerns;

use App\Services\PeriodLock;
use App\Support\GuardedPeriodBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puts a ledger-writing model under the period lock (PeriodLock). Every write path ends in one of two places, and both are
 * covered here:
 *
 * - the model events (saving, deleting, restoring), for code that works on a model, and
 * - GuardedPeriodBuilder, for code that works on a query (`Model::whereIn(...)->delete()`, `->restore()`, `->update()`), which
 *   fires no model events at all.
 *
 * The model names its date column; a save that changes nothing but the bookkeeping attributes (a payment status, a print flag)
 * is not a change to the books and passes. Writes made with the query builder directly (`DB::table(...)`) cannot be seen from here.
 *
 * @mixin Model
 */
trait GuardsLockedPeriods
{
    /**
     * The column holding the day the row belongs to (for a row without one, see periodLockRows).
     */
    abstract public static function periodLockDateColumn(): string;

    /**
     * Attributes that may change on a row of a locked period: they do not change what the books say.
     *
     * @return list<string>
     */
    public static function periodLockBookkeeping(): array
    {
        return ['updated_at', 'updated_by'];
    }

    /**
     * A row that never reaches the books (a delivery note that is a copy of its sale) is left out of the lock.
     */
    public function periodLockIsExempt(): bool
    {
        return false;
    }

    /**
     * Whether the dating rules (back-dating / future-dating) apply when this model is created or its date is changed.
     */
    public static function periodLockAppliesDatingRules(): bool
    {
        return true;
    }

    /**
     * Whether a change of only these attributes is bookkeeping. Models override this for rules that need the old and the new
     * value (a workflow status that does not touch the books).
     *
     * @param  array<string, mixed>  $dirty
     */
    public function periodLockIsBookkeeping(array $dirty): bool
    {
        return array_diff(array_keys($dirty), static::periodLockBookkeeping()) === [];
    }

    /**
     * For a line that belongs to a document: `['table' => 'transactions', 'foreign_key' => 'transaction_id', 'date' => 'transaction_date']`.
     * A line has no day of its own; it follows its document's, so it cannot be added, changed or removed in a locked period
     * even when the document's header is left as it is. Null for a model that carries its own day.
     *
     * @return array{table: string, foreign_key: string, date: string}|null
     */
    public static function periodLockParent(): ?array
    {
        return null;
    }

    /**
     * The company, branch and day of this row.
     *
     * @return array{company_id: int|null, branch_id: int|null, date: mixed}
     */
    public function periodLockScope(bool $original = false): array
    {
        $parent = static::periodLockParent();

        if ($parent !== null) {
            $key = $original ? $this->getOriginal($parent['foreign_key']) : $this->getAttribute($parent['foreign_key']);

            return self::periodLockParentScope($parent['table'], $key, $parent['date']) ?? ['company_id' => null, 'branch_id' => null, 'date' => null];
        }

        $get = fn (string $key): mixed => $original ? $this->getOriginal($key) : $this->getAttribute($key);

        return [
            'company_id' => $get('company_id') === null ? null : (int) $get('company_id'),
            'branch_id' => $get('branch_id') === null ? null : (int) $get('branch_id'),
            'date' => $get(static::periodLockDateColumn()),
        ];
    }

    /**
     * What a query is about to write to: one object per row with its id, company, branch and day.
     *
     * @return Collection<int, object{id: int, company_id: int|null, branch_id: int|null, lock_date: mixed}>
     */
    public static function periodLockRows(QueryBuilder $base): Collection
    {
        $parent = static::periodLockParent();

        if ($parent !== null) {
            $keys = $base->cloneWithout(['columns', 'orders', 'limit', 'offset'])->distinct()->pluck($parent['foreign_key'])->filter()->all();

            return DB::table($parent['table'])->whereIn('id', $keys)->get(['id', 'company_id', 'branch_id', $parent['date'].' as lock_date']);
        }

        $model = new static;
        $table = $model->getTable();
        $date = static::periodLockDateColumn();

        return $base->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->get(["{$table}.{$model->getKeyName()} as id", "{$table}.company_id", "{$table}.branch_id", "{$table}.{$date} as lock_date"]);
    }

    /**
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): GuardedPeriodBuilder
    {
        return new GuardedPeriodBuilder($query);
    }

    public static function bootGuardsLockedPeriods(): void
    {
        static::saving(function (Model $model): void {
            /** @var Model&self $model */
            if ($model->periodLockIsExempt()) {
                return;
            }

            $new = $model->periodLockScope();
            $guard = app(PeriodLock::class);

            if (! $model->exists) {
                $guard->check($new['company_id'], $new['branch_id'], [$new['date']], static::periodLockAppliesDatingRules() ? [$new['date']] : [], $model);

                return;
            }

            $dirty = $model->getDirty();

            if ($dirty === [] || $model->periodLockIsBookkeeping($dirty)) {
                return;
            }

            $old = $model->periodLockScope(true);
            $dateChanged = array_key_exists(static::periodLockDateColumn(), $dirty);

            $guard->check(
                $old['company_id'],
                $old['branch_id'],
                [$old['date'], $new['date']],
                $dateChanged && static::periodLockAppliesDatingRules() ? [$new['date']] : [],
                $model,
            );

            if ($new['company_id'] !== $old['company_id'] || $new['branch_id'] !== $old['branch_id']) {
                $guard->check($new['company_id'], $new['branch_id'], [$new['date']], [], $model);
            }
        });

        static::deleting(function (Model $model): void {
            if ($model->periodLockIsExempt()) {
                return;
            }

            $scope = $model->periodLockScope();
            app(PeriodLock::class)->check($scope['company_id'], $scope['branch_id'], [$scope['date']], [], $model);
        });

        if (method_exists(static::class, 'restoring')) {
            static::restoring(function (Model $model): void {
                if ($model->periodLockIsExempt()) {
                    return;
                }

                $scope = $model->periodLockScope();
                app(PeriodLock::class)->check($scope['company_id'], $scope['branch_id'], [$scope['date']], [], $model);
            });
        }
    }

    /**
     * For a row whose day lives on its parent: the parent's company, branch and day, by key.
     *
     * @return array{company_id: int|null, branch_id: int|null, date: mixed}|null
     */
    protected static function periodLockParentScope(string $table, mixed $key, string $dateColumn): ?array
    {
        $row = DB::table($table)->where('id', $key)->first(['company_id', 'branch_id', $dateColumn]);

        return $row === null ? null : [
            'company_id' => $row->company_id === null ? null : (int) $row->company_id,
            'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
            'date' => $row->{$dateColumn},
        ];
    }
}
