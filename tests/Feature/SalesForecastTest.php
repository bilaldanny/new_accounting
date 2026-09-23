<?php

use App\Http\Controllers\DashboardController;
use App\Models\Role;
use App\Models\User;
use App\Services\SalesForecast;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The sales estimate: a moving average and a linear trend over the last full months (net sales, the Purchase & Sale
 * report's figure), and a run rate for the month so far. "Today" is 15 September 2026, so the history is March to
 * August and the estimate is for October.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Sells $amount on the given date; the months are given as [month number => amount] of 2026.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<int, float>  $months
 */
function sfcMonths(array $scope, array $months): void
{
    foreach ($months as $month => $amount) {
        trpDoc($scope, 'sell', ['final_amount' => $amount, 'transaction_date' => sprintf('2026-%02d-10 10:00:00', $month)]);
    }
}

/**
 * @return array<string, mixed>
 */
function sfcForecast(array $scope, ?Carbon $today = null): array
{
    return app(SalesForecast::class)->forCompany((int) $scope['company_id'], null, $today ?? today());
}

test('steady growth gives a perfect trend line: the estimate is the next step of it', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [3 => 100, 4 => 200, 5 => 300, 6 => 400, 7 => 500, 8 => 600]);

    $forecast = sfcForecast($scope);

    expect(array_column($forecast['history'], 'month'))->toBe(['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'])
        ->and(array_column($forecast['history'], 'net_sales'))->toEqual([100, 200, 300, 400, 500, 600])
        ->and($forecast['target_month'])->toBe('2026-10')
        ->and($forecast['method'])->toBe('linear')
        ->and($forecast['estimate'])->toEqual(700)
        ->and($forecast['linear'])->toEqual(700)
        ->and($forecast['moving_average'])->toEqual(500)
        ->and($forecast['low'])->toEqual(500)
        ->and($forecast['high'])->toEqual(700)
        ->and($forecast['fit'])->toEqual(1.0)
        ->and($forecast['trend'])->toBe('up')
        ->and($forecast['reason'])->toBeNull();
});

test('with three months of sales the moving average is the estimate and the trend only sets the range', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [6 => 300, 7 => 600, 8 => 900]);

    $forecast = sfcForecast($scope);

    // March to May had no sales: they are not history
    expect(array_column($forecast['history'], 'month'))->toBe(['2026-06', '2026-07', '2026-08'])
        ->and($forecast['method'])->toBe('moving_average')
        ->and($forecast['estimate'])->toEqual(600)
        ->and($forecast['moving_average'])->toEqual(600)
        ->and($forecast['linear'])->toEqual(1200)
        ->and($forecast['low'])->toEqual(600)
        ->and($forecast['high'])->toEqual(1200);
});

test('two months are enough for an estimate and one is not', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [7 => 200, 8 => 400]);

    expect(sfcForecast($scope))->toMatchArray(['method' => 'moving_average', 'estimate' => 300.0, 'reason' => null]);

    $lone = trpScope('B');
    sfcMonths($lone, [8 => 400]);
    $forecast = sfcForecast($lone);

    expect($forecast['estimate'])->toBeNull()
        ->and($forecast['reason'])->toBe('not_enough_history')
        ->and($forecast['low'])->toBeNull()
        ->and($forecast['trend'])->toBeNull()
        ->and(array_column($forecast['history'], 'net_sales'))->toEqual([400]);
});

test('a company with no sales, or only this month\'s, has no estimate but still shows the month so far', function () {
    $empty = trpScope('A');
    expect(sfcForecast($empty))->toMatchArray(['estimate' => null, 'reason' => 'not_enough_history', 'history' => []]);

    $new = trpScope('B');
    trpDoc($new, 'sell', ['final_amount' => 300, 'transaction_date' => '2026-09-05 10:00:00']);
    $forecast = sfcForecast($new);

    expect($forecast['estimate'])->toBeNull()
        ->and($forecast['this_month']['sold_so_far'])->toEqual(300);
});

test('the estimate never goes below zero for a falling business', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [5 => 100, 6 => 60, 7 => 30, 8 => 10]);

    $forecast = sfcForecast($scope);

    // the line through 100, 60, 30, 10 reaches -25 next month: nothing is not -25
    expect($forecast['linear'])->toEqual(0)
        ->and($forecast['estimate'])->toEqual(0)
        ->and($forecast['trend'])->toBe('down')
        ->and($forecast['moving_average'])->toEqual(33.33);
});

test('flat sales are flat', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [5 => 100, 6 => 100, 7 => 100, 8 => 100]);

    expect(sfcForecast($scope))->toMatchArray(['trend' => 'flat', 'estimate' => 100.0, 'fit' => 1.0]);
});

test('a quiet month in the middle counts as zero and a wobbly history has a poor fit', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [4 => 500, 6 => 500, 7 => 100, 8 => 500]);

    $forecast = sfcForecast($scope);

    expect(array_column($forecast['history'], 'net_sales'))->toEqual([500, 0, 500, 100, 500])
        ->and($forecast['fit'])->toBeLessThan(0.3);
});

test('sales are net of returns and drafts and quotations are left out', function () {
    $scope = trpScope('A');
    trpDoc($scope, 'sell', ['final_amount' => 1000, 'transaction_date' => '2026-07-10 10:00:00']);
    trpDoc($scope, 'sell', ['final_amount' => 5000, 'status' => 'draft', 'transaction_date' => '2026-07-10 10:00:00']);
    trpDoc($scope, 'sell', ['final_amount' => 700, 'status' => 'quotation', 'transaction_date' => '2026-07-10 10:00:00']);
    trpDoc($scope, 'sellreturn', ['final_amount' => 200, 'transaction_date' => '2026-07-20 10:00:00']);
    sfcMonths($scope, [8 => 600]);

    expect(array_column(sfcForecast($scope)['history'], 'net_sales'))->toEqual([800, 600]);
});

test('the run rate scales the month so far to a whole month', function () {
    $scope = trpScope('A');
    trpDoc($scope, 'sell', ['final_amount' => 300, 'transaction_date' => '2026-09-05 10:00:00']);
    trpDoc($scope, 'sell', ['final_amount' => 900, 'transaction_date' => '2026-09-20 10:00:00']);

    $month = sfcForecast($scope)['this_month'];

    expect($month)->toMatchArray(['month' => '2026-09', 'days_elapsed' => 15, 'days_in_month' => 30])
        ->and($month['sold_so_far'])->toEqual(300)
        ->and($month['run_rate'])->toEqual(600);
});

test('the history follows the calendar across a year end', function () {
    $scope = trpScope('A');
    trpDoc($scope, 'sell', ['final_amount' => 100, 'transaction_date' => '2025-11-10 10:00:00']);
    trpDoc($scope, 'sell', ['final_amount' => 200, 'transaction_date' => '2025-12-10 10:00:00']);

    $forecast = sfcForecast($scope, Carbon::parse('2026-01-12'));

    expect(array_column($forecast['history'], 'month'))->toBe(['2025-11', '2025-12'])
        ->and($forecast['target_month'])->toBe('2026-02')
        ->and($forecast['estimate'])->toEqual(150);
});

test('another company\'s sales never count', function () {
    $mine = trpScope('A');
    $theirs = trpScope('B');
    sfcMonths($mine, [7 => 100, 8 => 100]);
    sfcMonths($theirs, [7 => 9000, 8 => 9000]);

    expect(sfcForecast($mine)['estimate'])->toEqual(100);
});

// --- on the dashboard ----------------------------------------------------------------------------

test('the dashboard serves the outlook to a user who may open the Purchase and Sale report, and to nobody else', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [7 => 200, 8 => 400]);

    $viewer = Role::query()->create(['name' => 'viewer'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission($viewer->id, '/report/purchase-sale', 'report.purchase-sale.'.uniqid());
    Sanctum::actingAs(createStaffUserForRole($viewer, ['company_id' => $scope['company_id']]));

    $data = $this->getJson('/api/dashboard/forecast')->assertSuccessful()->json('data.sales_forecast');

    expect($data['estimate'])->toEqual(300)
        ->and($data['target_month'])->toBe('2026-10');

    $blocked = Role::query()->create(['name' => 'blocked'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission($blocked->id, '/sell', 'sell.'.uniqid());
    Sanctum::actingAs(createStaffUserForRole($blocked, ['company_id' => $scope['company_id']]));

    $this->getJson('/api/dashboard/forecast')->assertSuccessful()->assertJsonPath('data', []);
    expect(DashboardController::PERMISSIONS['forecast'])->toBe(['sales_forecast' => '/report/purchase-sale']);
});

test('the dashboard outlook is kept for five minutes per company and can be refreshed', function () {
    $scope = trpScope('A');
    sfcMonths($scope, [7 => 200, 8 => 400]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $query = '?company_id='.$scope['company_id'];

    $first = $this->getJson('/api/dashboard/forecast'.$query)->assertSuccessful();
    expect($first->json('data.sales_forecast.estimate'))->toEqual(300)
        ->and($first->json('cached_at'))->not->toBeNull();

    sfcMonths($scope, [6 => 0]);
    trpDoc($scope, 'sell', ['final_amount' => 600, 'transaction_date' => '2026-06-10 10:00:00']);

    expect($this->getJson('/api/dashboard/forecast'.$query)->json('data.sales_forecast.estimate'))->toEqual(300)
        ->and($this->getJson('/api/dashboard/forecast'.$query.'&refresh=1')->json('data.sales_forecast.estimate'))->toEqual(400);
});

test('a superadmin who has picked no company gets the estimate over every company', function () {
    $a = trpScope('A');
    $b = trpScope('B');
    sfcMonths($a, [7 => 100, 8 => 100]);
    sfcMonths($b, [7 => 300, 8 => 300]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/dashboard/forecast')->assertSuccessful();

    expect($response->json('data.sales_forecast.estimate'))->toEqual(400)
        ->and($response->json('needs_company'))->toBe([]);
});
