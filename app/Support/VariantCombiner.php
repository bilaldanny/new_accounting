<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Variation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VariantCombiner
{
    public const MAX_COMBINATIONS = 500;

    public const SEPARATOR = ' / ';

    /**
     * @param  array<int, array{name?: string, values?: array<int, mixed>}>  $dimensions
     * @return array<int, array{values: list<string>, variation_name: string}>
     *
     * @throws ValidationException
     */
    public static function combine(array $dimensions): array
    {
        $normalized = self::normalizeDimensions($dimensions);

        self::assertCanCombine($normalized);

        $combinations = [[]];

        foreach ($normalized as $dimension) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($dimension['values'] as $value) {
                    $next[] = [...$combination, $value];
                }
            }

            $combinations = $next;
        }

        $labeled = array_map(fn (array $values): array => [
            'values' => $values,
            'variation_name' => self::label($values),
        ], $combinations);

        self::assertUniqueLabels($labeled);

        return array_values($labeled);
    }

    /**
     * @param  list<string>  $values
     */
    public static function label(array $values): string
    {
        return implode(self::SEPARATOR, array_values($values));
    }

    /**
     * @param  array<int, array{name?: string, values?: array<int, mixed>}>  $dimensions
     */
    public static function count(array $dimensions): int
    {
        $normalized = self::normalizeDimensions($dimensions, false);

        if ($normalized === []) {
            return 0;
        }

        return array_reduce(
            $normalized,
            fn (int $carry, array $dimension): int => $carry * count($dimension['values']),
            1,
        );
    }

    public static function skuSuffix(string $variationName): string
    {
        return collect(explode(self::SEPARATOR, $variationName))
            ->map(fn (string $part): string => Str::upper(Str::slug(trim($part))))
            ->filter()
            ->implode('-');
    }

    public static function normalizeLabel(string $variationName): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($variationName)) ?? '');
    }

    /**
     * @param  array<int, array{variation_id?: mixed, values?: mixed}>  $selections
     * @return array{combinations: list<array{values: list<string>, variation_name: string, sku: string}>, count: int}
     *
     * @throws ValidationException
     */
    public static function generateForScope(
        ?int $companyId,
        ?int $categoryId,
        ?int $subcategoryId,
        ?int $itemtypeId,
        array $selections,
        ?string $productSku = null,
    ): array {
        $normalizedSelections = self::normalizeSelections($selections);

        if ($normalizedSelections === []) {
            throw ValidationException::withMessages([
                'selections' => ['Select at least one variation with active values.'],
            ]);
        }

        $variationIds = collect($normalizedSelections)->pluck('variation_id')->unique()->values();

        $variations = Variation::query()
            ->visibleToCurrentUser()
            ->where('active', 1)
            ->whereIn('id', $variationIds)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->when($subcategoryId !== null, fn ($query) => $query->where('subcategory_id', $subcategoryId))
            ->when($itemtypeId !== null, fn ($query) => $query->where('itemtype_id', $itemtypeId))
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Variation $variation): int => (int) $variation->id);

        if ($variations->count() !== $variationIds->count()) {
            throw ValidationException::withMessages([
                'selections' => ['One or more variations are invalid for the selected category, subcategory, and item type.'],
            ]);
        }

        $dimensions = [];

        foreach ($normalizedSelections as $selection) {
            /** @var Variation $variation */
            $variation = $variations->get($selection['variation_id']);
            $label = trim((string) $variation->name);
            $displayName = $label !== '' ? $label : 'Untitled variation';
            $activeValues = self::activeValueNames($variation->values);

            if ($activeValues === []) {
                throw ValidationException::withMessages([
                    'selections' => ["{$displayName} has no active values."],
                ]);
            }

            $selectedValues = [];

            foreach ($selection['values'] as $value) {
                if (! in_array($value, $activeValues, true)) {
                    throw ValidationException::withMessages([
                        'selections' => ["{$displayName} does not include the active value \"{$value}\"."],
                    ]);
                }

                $selectedValues[] = $value;
            }

            if ($selectedValues === []) {
                throw ValidationException::withMessages([
                    'selections' => ["{$displayName} has no selected values."],
                ]);
            }

            $dimensions[] = [
                'name' => $displayName,
                'values' => $selectedValues,
            ];
        }

        $combined = self::combine($dimensions);
        $prefix = trim((string) $productSku);

        $combinations = [];

        foreach ($combined as $index => $combination) {
            $combinations[] = [
                'values' => $combination['values'],
                'variation_name' => $combination['variation_name'],
                'sku' => $prefix !== ''
                    ? Product::variationSku($prefix, $index + 1)
                    : '',
            ];
        }

        return [
            'combinations' => $combinations,
            'count' => count($combinations),
        ];
    }

    /**
     * @param  array<int, mixed>|null  $values
     * @return list<string>
     */
    public static function activeValueNames(?array $values): array
    {
        return collect(Variation::normalizeValues($values))
            ->filter(fn (array $value): bool => $value['active'] === true)
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{name?: string, values?: array<int, mixed>}>  $dimensions
     * @return array<int, array{name: string, values: list<string>}>
     */
    protected static function normalizeDimensions(array $dimensions, bool $requireValues = true): array
    {
        $normalized = [];

        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $name = trim((string) ($dimension['name'] ?? ''));
            $values = [];
            $seen = [];

            foreach ((array) ($dimension['values'] ?? []) as $value) {
                $valueName = trim((string) $value);

                if ($valueName === '') {
                    continue;
                }

                $key = self::normalizeLabel($valueName);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $values[] = $valueName;
            }

            if ($requireValues && $values === []) {
                throw ValidationException::withMessages([
                    'selections' => [
                        ($name !== '' ? $name : 'A variation').' has no selected values.',
                    ],
                ]);
            }

            if ($values === []) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'values' => $values,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{name: string, values: list<string>}>  $dimensions
     *
     * @throws ValidationException
     */
    protected static function assertCanCombine(array $dimensions): void
    {
        if ($dimensions === []) {
            throw ValidationException::withMessages([
                'selections' => ['Select at least one variation with active values.'],
            ]);
        }

        $count = array_reduce(
            $dimensions,
            fn (int $carry, array $dimension): int => $carry * count($dimension['values']),
            1,
        );

        if ($count > self::MAX_COMBINATIONS) {
            throw ValidationException::withMessages([
                'selections' => [
                    'This selection creates '.$count.' variants. Unselect some values or turn off a variation. Maximum is '.self::MAX_COMBINATIONS.'.',
                ],
            ]);
        }
    }

    /**
     * @param  array<int, array{values: list<string>, variation_name: string}>  $combinations
     *
     * @throws ValidationException
     */
    protected static function assertUniqueLabels(array $combinations): void
    {
        $labels = collect($combinations)->map(
            fn (array $combination): string => self::normalizeLabel($combination['variation_name']),
        );

        if ($labels->count() !== $labels->unique()->count()) {
            throw ValidationException::withMessages([
                'productdetail' => ['Each product variant must have a unique combination of variation values.'],
            ]);
        }
    }

    /**
     * @param  array<int, array{variation_id?: mixed, values?: mixed}>  $selections
     * @return list<array{variation_id: int, values: list<string>}>
     */
    protected static function normalizeSelections(array $selections): array
    {
        return Collection::make($selections)
            ->map(function ($selection): ?array {
                if (! is_array($selection)) {
                    return null;
                }

                $variationId = (int) ($selection['variation_id'] ?? 0);

                if ($variationId <= 0) {
                    return null;
                }

                $values = [];
                $seen = [];

                foreach ((array) ($selection['values'] ?? []) as $value) {
                    $valueName = trim((string) $value);

                    if ($valueName === '') {
                        continue;
                    }

                    $key = self::normalizeLabel($valueName);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $values[] = $valueName;
                }

                return [
                    'variation_id' => $variationId,
                    'values' => $values,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
