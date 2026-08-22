<?php

use App\Support\VariantCombiner;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

test('it builds the cartesian product for any number of variations', function () {
    $combinations = VariantCombiner::combine([
        ['name' => 'RAM', 'values' => ['4 GB', '6 GB', '8 GB']],
        ['name' => 'Storage', 'values' => ['128 GB', '256 GB']],
        ['name' => 'Color', 'values' => ['Black', 'Blue']],
    ]);

    expect($combinations)->toHaveCount(12)
        ->and($combinations[0]['variation_name'])->toBe('4 GB / 128 GB / Black')
        ->and($combinations[1]['variation_name'])->toBe('4 GB / 128 GB / Blue')
        ->and($combinations[2]['variation_name'])->toBe('4 GB / 256 GB / Black')
        ->and($combinations[11]['variation_name'])->toBe('8 GB / 256 GB / Blue')
        ->and(collect($combinations)->pluck('variation_name')->unique())->toHaveCount(12);
});

test('it works with two variations', function () {
    $combinations = VariantCombiner::combine([
        ['name' => 'Size', 'values' => ['S', 'M']],
        ['name' => 'Color', 'values' => ['Red', 'Blue']],
    ]);

    expect($combinations)->toHaveCount(4)
        ->and(collect($combinations)->pluck('variation_name')->all())->toBe([
            'S / Red',
            'S / Blue',
            'M / Red',
            'M / Blue',
        ]);
});

test('it rejects empty selected values', function () {
    VariantCombiner::combine([
        ['name' => 'RAM', 'values' => []],
    ]);
})->throws(ValidationException::class);

test('it rejects combinations above the maximum', function () {
    $values = array_map(fn (int $index): string => 'Value '.$index, range(1, 25));

    VariantCombiner::combine([
        ['name' => 'A', 'values' => $values],
        ['name' => 'B', 'values' => $values],
    ]);
})->throws(ValidationException::class);

test('it builds a deterministic sku suffix from a combination label', function () {
    expect(VariantCombiner::skuSuffix('4 GB / 128 GB / Black'))->toBe('4-GB-128-GB-BLACK');
});
