<?php

use App\Models\Transporter;
use App\Models\User;
use App\Support\ListSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The shared list sorting / paging (HandlesIndexAndBulkDelete::paginateSorted) must never answer 500 to
 * odd query-string values; it falls back to the default page size, the first page and newest first.
 */
function lpiSeed(int $count): void
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'LPI001', 'name' => 'Pagination Company', 'address' => '1 Test Street', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (range(1, $count) as $number) {
        Transporter::query()->create(['company_id' => $companyId, 'name' => 'Carrier '.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'is_active' => true]);
    }
}

test('a page size that is not a whole number of at least one means the default of 10', function (string $value) {
    lpiSeed(25);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/transporters?show_record='.urlencode($value))->assertSuccessful();

    expect($response->json('data.per_page'))->toBe(10)
        ->and($response->json('data.data'))->toHaveCount(10)
        ->and($response->json('data.total'))->toBe(25);
})->with(['abc', '0', '-5', '1.5', '', '1e3', '10; drop table transporters']);

test('a valid page size is used, and a huge one is capped at 1000', function () {
    lpiSeed(25);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect($this->getJson('/api/transporters?show_record=7')->json('data.per_page'))->toBe(7)
        ->and($this->getJson('/api/transporters?show_record=25')->json('data.data'))->toHaveCount(25)
        ->and($this->getJson('/api/transporters?show_record=100000')->json('data.per_page'))->toBe(1000)
        ->and($this->getJson('/api/transporters?show_record=1')->json('data.per_page'))->toBe(1);
});

test('a page number that is not a whole number of at least one means the first page', function (string $value) {
    lpiSeed(25);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/transporters?sort_by=name&sort_type=asc&cur_page='.urlencode($value))->assertSuccessful();

    expect($response->json('data.current_page'))->toBe(1)
        ->and($response->json('data.data.0.name'))->toBe('Carrier 001');
})->with(['abc', '0', '-3', '2.5', '']);

test('a page past the last one shows the last page', function () {
    lpiSeed(25);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/transporters?sort_by=name&sort_type=asc&cur_page=999')->assertSuccessful();

    expect($response->json('data.current_page'))->toBe(3)
        ->and($response->json('data.data'))->toHaveCount(5)
        ->and($response->json('data.data.4.name'))->toBe('Carrier 025');
});

test('the sort direction is asc or desc, anything else means newest first', function () {
    lpiSeed(3);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (string $query) => collect($this->getJson('/api/transporters?sort_by=name&'.$query)->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names('sort_type=asc'))->toBe(['Carrier 001', 'Carrier 002', 'Carrier 003'])
        ->and($names('sort_type=desc'))->toBe(['Carrier 003', 'Carrier 002', 'Carrier 001'])
        ->and($names('sort_type=sideways'))->toBe(['Carrier 003', 'Carrier 002', 'Carrier 001'])
        ->and($names('sort_type='))->toBe(['Carrier 003', 'Carrier 002', 'Carrier 001']);
});

test('the older lists that pass the sort column straight through survive garbage in it', function (string $endpoint) {
    Sanctum::actingAs(User::query()->findOrFail(1));

    foreach (['id; drop table users', 'name`--', '1', '(select 1)', 'a b', "x'y", '../../etc'] as $column) {
        $this->getJson($endpoint.'?sort_by='.urlencode($column).'&sort_type=sideways&show_record=abc&cur_page=xyz')->assertSuccessful();
    }

    expect(User::query()->count())->toBeGreaterThan(0);
})->with([
    'roles' => ['/api/roles'],
    'menus' => ['/api/menus'],
    'branches' => ['/api/branches'],
    'banks' => ['/api/banks'],
    'units' => ['/api/units'],
    'expenses' => ['/api/expenses'],
    'financial years' => ['/api/financialyears'],
    'issue notes' => ['/api/issue-notes'],
    'receiving notes' => ['/api/receiving-notes'],
    'purchase returns' => ['/api/purchase-returns'],
    'sell returns' => ['/api/sell-returns'],
    'purchases' => ['/api/purchases'],
    'sells' => ['/api/sells'],
    'stock adjustments' => ['/api/stockadjustments'],
]);

test('ListSort keeps well-formed values and replaces the rest', function () {
    expect(ListSort::column('name'))->toBe('name')
        ->and(ListSort::column(' name '))->toBe('name')
        ->and(ListSort::column('companies.name'))->toBe('companies.name')
        ->and(ListSort::column('a.b.c'))->toBe('created_at')
        ->and(ListSort::column('name desc'))->toBe('created_at')
        ->and(ListSort::column(''))->toBe('created_at')
        ->and(ListSort::column(null))->toBe('created_at')
        ->and(ListSort::column(['name']))->toBe('created_at')
        ->and(ListSort::column('9lives', 'id'))->toBe('id')
        ->and(ListSort::direction('ASC'))->toBe('asc')
        ->and(ListSort::direction(' Desc '))->toBe('desc')
        ->and(ListSort::direction('up'))->toBe('desc')
        ->and(ListSort::direction(null, 'asc'))->toBe('asc')
        ->and(ListSort::wholeNumber('12', 10))->toBe(12)
        ->and(ListSort::wholeNumber(12, 10))->toBe(12)
        ->and(ListSort::wholeNumber('0', 10))->toBe(10)
        ->and(ListSort::wholeNumber('-1', 10))->toBe(10)
        ->and(ListSort::wholeNumber('2.5', 10))->toBe(10)
        ->and(ListSort::wholeNumber(null, 10))->toBe(10)
        ->and(ListSort::wholeNumber('5000', 10, 1000))->toBe(1000)
        ->and(ListSort::wholeNumber('5000', 10))->toBe(5000);
});
