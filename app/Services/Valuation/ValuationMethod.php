<?php

namespace App\Services\Valuation;

use App\Models\ValuationMethodChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Which costing method a company uses on a day. A company with no recorded change uses the weighted average, exactly as before
 * this setting existed; a change applies from its effective date forward and earlier days keep the method they had (history is
 * never recomputed). LIFO is not offered: it is not permitted under IFRS.
 */
class ValuationMethod
{
    public const AVERAGE = 'average';

    public const FIFO = 'fifo';

    public const STANDARD = 'standard';

    public const METHODS = [self::AVERAGE, self::FIFO, self::STANDARD];

    public const LIFO_NOTE = 'LIFO is not offered: it is not permitted under IFRS (IAS 2).';

    public static function available(): bool
    {
        return Schema::hasTable('valuation_method_changes');
    }

    /**
     * @return Collection<int, ValuationMethodChange>
     */
    public function changes(int $companyId): Collection
    {
        if (! self::available()) {
            return collect();
        }

        return ValuationMethodChange::query()->where('company_id', $companyId)->orderBy('effective_from')->get();
    }

    /**
     * The method in force on a day and the date it took effect (null for the default that was always there).
     *
     * @return array{method: string, effective_from: string|null}
     */
    public function methodOn(int $companyId, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $current = ['method' => self::AVERAGE, 'effective_from' => null];

        foreach ($this->changes($companyId) as $change) {
            if ($change->effective_from->toDateString() <= $date) {
                $current = ['method' => (string) $change->method, 'effective_from' => $change->effective_from->toDateString()];
            }
        }

        return $current;
    }

    /**
     * True when the company never left the weighted average: every report then takes its old, untouched path.
     */
    public function isDefault(int $companyId): bool
    {
        return $this->changes($companyId)->where('method', '!=', self::AVERAGE)->isEmpty();
    }

    /**
     * @throws ValidationException
     */
    public function record(int $companyId, string $method, string $effectiveFrom, ?string $note, ?int $userId, ?string $lockedThrough): ValuationMethodChange
    {
        if (strtolower($method) === 'lifo') {
            throw ValidationException::withMessages(['method' => [self::LIFO_NOTE]]);
        }

        if (! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['method' => ['Choose average, fifo or standard.']]);
        }

        if (! self::available()) {
            throw ValidationException::withMessages(['method' => ['The valuation tables are not installed yet.']]);
        }

        if ($lockedThrough !== null && $effectiveFrom <= $lockedThrough) {
            throw ValidationException::withMessages(['effective_from' => ["The books are locked through {$lockedThrough}; a method can only take effect after that day."]]);
        }

        $latest = $this->changes($companyId)->last();

        if ($latest !== null && $effectiveFrom <= $latest->effective_from->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => ['A method change must take effect after the previous one ('.$latest->effective_from->toDateString().'); earlier history is never rewritten.']]);
        }

        return ValuationMethodChange::query()->create([
            'company_id' => $companyId, 'method' => $method, 'effective_from' => $effectiveFrom, 'note' => $note, 'created_by' => $userId,
        ]);
    }
}
