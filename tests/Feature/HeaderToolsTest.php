<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The top bar tools: today's date, Today Profit (permission and branch scoped), calendar dots and the personal to-do list.
 */
function htStaff(array $scope, array $paths): User
{
    return fcoStaff($scope, $paths, false);
}

test('Today Profit needs the profit and loss permission and answers with today, yesterday and the change', function () {
    $scope = fcoScope();

    Sanctum::actingAs(htStaff($scope, ['/dashboard']));
    $this->getJson('/api/header/today-profit')->assertForbidden();

    Sanctum::actingAs(htStaff($scope, ['/report/profit-loss']));
    $this->getJson('/api/header/today-profit')->assertOk()
        ->assertJsonStructure(['data' => ['date', 'yesterday', 'today' => ['sales', 'purchases', 'expenses', 'gross_profit', 'net_profit'], 'previous' => ['sales'], 'change' => ['sales', 'net_profit']]]);
});

test('the clock gives the date in the company time zone', function () {
    $scope = fcoScope();
    $zone = DB::table('timezones')->insertGetId(['name' => 'Pacific/Kiritimati', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_settings')->updateOrInsert(['company_id' => $scope['company_id']], ['timezone_id' => $zone, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(htStaff($scope, ['/dashboard']));
    $response = $this->getJson('/api/header/clock')->assertOk()->assertJsonPath('data.timezone', 'Pacific/Kiritimati');

    expect($response->json('data.date'))->toBe(now('Pacific/Kiritimati')->toDateString());
});

test('to-dos belong to one user of one company and nobody else can read or change them', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany('ht');
    $mine = htStaff($scope, ['/dashboard']);

    Sanctum::actingAs($mine);
    $id = $this->postJson('/api/header/todos', ['title' => '<b>Call the supplier</b>', 'due_date' => now()->toDateString()])->assertCreated()->json('data.id');
    $this->getJson('/api/header/todos')->assertOk()->assertJsonPath('pending', 1)->assertJsonPath('data.0.title', 'Call the supplier');
    $this->postJson("/api/header/todos/{$id}/toggle")->assertOk();
    $this->getJson('/api/header/todos')->assertJsonPath('pending', 0);

    // a colleague of the same company and a user of another company see nothing and cannot touch it
    Sanctum::actingAs(htStaff($scope, ['/dashboard']));
    $this->getJson('/api/header/todos')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/header/todos/{$id}/toggle")->assertNotFound();
    $this->deleteJson("/api/header/todos/{$id}")->assertNotFound();

    Sanctum::actingAs(htStaff($other, ['/dashboard']));
    $this->deleteJson("/api/header/todos/{$id}")->assertNotFound();

    Sanctum::actingAs($mine);
    $this->deleteJson("/api/header/todos/{$id}")->assertOk();
    $this->postJson('/api/header/todos', ['title' => ''])->assertUnprocessable();
});

test('the calendar shows to-dos and only the kinds of dates the user may open', function () {
    $scope = fcoScope();
    DB::table('activities')->insert(['company_id' => $scope['company_id'], 'type' => 'task', 'subject' => 'Follow up Ali', 'due_at' => now()->startOfMonth()->addDays(3)->setTime(10, 0), 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(htStaff($scope, ['/dashboard']));
    $this->postJson('/api/header/todos', ['title' => 'Send quotation', 'due_date' => now()->startOfMonth()->addDays(5)->toDateString()])->assertCreated();
    $kinds = collect($this->getJson('/api/header/calendar')->assertOk()->json('data.events'))->pluck('kind')->unique()->values()->all();
    expect($kinds)->toBe(['todo']);

    $viewer = htStaff($scope, ['/dashboard', '/activities']);
    DB::table('activities')->update(['assigned_to' => $viewer->id]);
    Sanctum::actingAs($viewer);
    $this->postJson('/api/header/todos', ['title' => 'Own to-do', 'due_date' => now()->startOfMonth()->addDays(6)->toDateString()])->assertCreated();
    $events = collect($this->getJson('/api/header/calendar')->assertOk()->json('data.events'));
    expect($events->pluck('kind')->unique()->sort()->values()->all())->toBe(['followup', 'todo']);

    // another user's follow-up is not shown to a user who is not an admin
    DB::table('activities')->update(['assigned_to' => null]);
    expect(collect($this->getJson('/api/header/calendar')->json('data.events'))->pluck('kind')->all())->not->toContain('followup');
});
