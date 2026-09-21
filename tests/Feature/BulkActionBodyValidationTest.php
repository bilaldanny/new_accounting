<?php

use App\Http\Middleware\ValidateBulkActionBody;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The bulk actions (bulk_delete, bulk_delete_per, restore_records) and the duplicate action of every controller
 * answer a body that is not what they read with a 422, and a duplicate of a record that does not exist with a
 * 404, never a 500. The tests walk the route table, so a controller added later is covered without a new test.
 *
 * @return list<string>
 */
function bavUris(string ...$actions): array
{
    $uris = [];

    foreach (Route::getRoutes() as $route) {
        if (in_array($route->getActionMethod(), $actions, true) && in_array('POST', $route->methods(), true)) {
            $uris[] = '/'.$route->uri();
        }
    }

    return $uris;
}

dataset('bav bad id lists', [
    'nested lists' => [[['a', 'b']]],
    'lists in lists' => [[[1, 2], [3]]],
    'deeply nested' => [[[[1, 2, 3]]]],
    'object of arrays' => [['a' => [1, 2], 'b' => [3]]],
    'rows body' => [['rows' => [['name' => 'ok', 'iso2' => ['x']]]]],
    'text' => [['x']],
    'mixed' => [[1, 'a', [2]]],
    'float' => [[1.5]],
    'negative' => [[-1]],
    'zero' => [[0]],
    'null' => [[null]],
    'boolean' => [[true]],
    'digits with text' => [['12abc']],
    'too big' => [['99999999999999999999']],
]);

test('every bulk action rejects a body that is not a list of ids with a 422', function (array $body) {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $uris = bavUris('bulk_delete', 'bulk_delete_per', 'restore_records');
    $wrong = [];

    foreach ($uris as $uri) {
        $status = $this->postJson($uri, $body)->status();

        if ($status !== 422) {
            $wrong[$uri] = $status;
        }
    }

    expect($uris)->toHaveCount(128)
        ->and($wrong)->toBe([]);
})->with('bav bad id lists');

test('every bulk action rejects a list that is too long for one query', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $wrong = [];

    foreach (bavUris('bulk_delete', 'bulk_delete_per', 'restore_records') as $uri) {
        $response = $this->postJson($uri, range(1, ValidateBulkActionBody::MAX_IDS + 1));

        if ($response->status() !== 422 || ! isset($response->json('errors')['ids'])) {
            $wrong[$uri] = $response->status();
        }
    }

    expect($wrong)->toBe([]);
});

test('every bulk action still takes an empty list, ids that do not exist and ids as digit strings', function (array $body) {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $wrong = [];

    foreach (bavUris('bulk_delete', 'bulk_delete_per', 'restore_records') as $uri) {
        $status = $this->postJson($uri, $body)->status();

        if ($status >= 400) {
            $wrong[$uri] = $status;
        }
    }

    expect($wrong)->toBe([]);
})->with([
    'empty list' => [[]],
    'ids that do not exist' => [[999999999, 888888888]],
    'digit strings' => [['999999999', '888888888']],
    'the largest allowed list' => [fn () => range(1, ValidateBulkActionBody::MAX_IDS)],
]);

test('bulk delete, permanent delete and restore work on real records with ids as numbers or digit strings', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $first = Role::query()->create(['name' => 'Bulk One', 'is_active' => true]);
    $second = Role::query()->create(['name' => 'Bulk Two', 'is_active' => true]);
    $third = Role::query()->create(['name' => 'Bulk Three', 'is_active' => true]);

    $this->postJson('/api/roles/bulk_delete', [$first->id, (string) $second->id])->assertSuccessful();
    expect(Role::query()->whereKey([$first->id, $second->id, $third->id])->count())->toBe(1);

    $this->postJson('/api/roles/restore_records', [$first->id])->assertSuccessful();
    expect(Role::query()->whereKey([$first->id, $second->id, $third->id])->count())->toBe(2);

    $this->postJson('/api/roles/bulk_delete', [$first->id])->assertSuccessful();
    $this->postJson('/api/roles/bulk_delete_per', [$first->id, (string) $second->id])->assertSuccessful();
    expect(Role::withTrashed()->whereKey([$first->id, $second->id, $third->id])->pluck('id')->all())->toBe([$third->id]);
});

test('a rejected bulk request changes nothing', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $role = Role::query()->create(['name' => 'Untouched', 'is_active' => true]);

    $this->postJson('/api/roles/bulk_delete', [$role->id, ['x']])->assertStatus(422)->assertJsonValidationErrors(['ids']);
    $this->postJson('/api/roles/bulk_delete_per', [$role->id, 'abc'])->assertStatus(422);

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

// --- duplicate -----------------------------------------------------------------------------------

test('every duplicate action rejects a missing or malformed id with a 422', function (array $body) {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $uris = bavUris('duplicate');
    $wrong = [];

    foreach ($uris as $uri) {
        $response = $this->postJson($uri, $body);

        if ($response->status() !== 422 || ! isset($response->json('errors')['id'])) {
            $wrong[$uri] = $response->status();
        }
    }

    expect($uris)->toHaveCount(24)
        ->and($wrong)->toBe([]);
})->with([
    'no id' => [[]],
    'array id' => [['id' => ['x']]],
    'nested id' => [['id' => [['x']]]],
    'text' => [['id' => 'abc']],
    'zero' => [['id' => 0]],
    'negative' => [['id' => -3]],
    'float' => [['id' => 1.5]],
    'too big' => [['id' => '99999999999999999999']],
    'null' => [['id' => null]],
    'empty text' => [['id' => '']],
    'boolean' => [['id' => true]],
    'a list' => [[5]],
]);

test('every duplicate action answers a record that does not exist with a 404, not a 500', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $wrong = [];

    foreach (bavUris('duplicate') as $uri) {
        foreach ([['id' => 999999999], ['id' => '999999999']] as $body) {
            $status = $this->postJson($uri, $body)->status();

            if ($status < 400 || $status >= 500 || $status === 422) {
                $wrong[$uri.' '.json_encode($body)] = $status;
            }
        }
    }

    expect($wrong)->toBe([]);
});

test('a duplicate of a real record still works and a missing one changes nothing', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $role = Role::query()->create(['name' => 'Original', 'is_active' => true]);

    $this->postJson('/api/roles/duplicate', ['id' => $role->id])->assertSuccessful()->assertJsonPath('message', 'Successfully Duplicated');
    $this->postJson('/api/roles/duplicate', ['id' => (string) $role->id])->assertSuccessful();
    $before = Role::query()->count();

    $this->postJson('/api/roles/duplicate', ['id' => 999999999])->assertNotFound();

    expect(Role::query()->count())->toBe($before)
        ->and(Role::query()->where('name', 'like', 'Original Copy%')->count())->toBe(2);
});

// --- the guard itself ----------------------------------------------------------------------------

test('every bulk and duplicate route is behind the guard, after the sign-in check', function () {
    $count = 0;

    foreach (Route::getRoutes() as $route) {
        if (! in_array($route->getActionMethod(), ['bulk_delete', 'bulk_delete_per', 'restore_records', 'duplicate'], true)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $count++;

        expect($middleware)->toContain(ValidateBulkActionBody::class, 'auth:sanctum')
            ->and(array_search('auth:sanctum', $middleware, true))->toBeLessThan(array_search(ValidateBulkActionBody::class, $middleware, true));
    }

    expect($count)->toBe(152);
});

test('a guest gets a 401 on a hostile body, not the validation answer', function () {
    $this->postJson('/api/roles/bulk_delete', [['x']])->assertUnauthorized();
    $this->postJson('/api/roles/duplicate', ['id' => ['x']])->assertUnauthorized();
});

test('what counts as a record id', function (mixed $value, bool $expected) {
    expect(ValidateBulkActionBody::isRecordId($value))->toBe($expected);
})->with([
    [1, true],
    [PHP_INT_MAX, true],
    ['7', true],
    ['007', true],
    ['999999999999999999', true],
    [0, false],
    [-1, false],
    ['0', false],
    ['-1', false],
    ['1.5', false],
    [1.5, false],
    [1.0, false],
    ['', false],
    [' 1', false],
    ['1 ', false],
    ['abc', false],
    ['1e3', false],
    ['9999999999999999999', false],
    [true, false],
    [null, false],
    [[1], false],
]);
