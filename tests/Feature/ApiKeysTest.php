<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * API keys: Sanctum tokens a user creates in Settings. A key acts as its owner, is shown once, can expire and be
 * revoked, and cannot make another key.
 */
function apkCompany(string $code): int
{
    return DB::table('companies')->insertGetId(['code' => $code, 'name' => 'Company '.$code, 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A user of the company whose role has these menu paths.
 *
 * @param  list<string>  $paths
 */
function apkUser(int $companyId, array $paths, string $roleName = 'developer'): User
{
    $role = Role::query()->create(['name' => $roleName, 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

/**
 * Makes the next request a plain one with only a bearer token (no signed-in user carried over).
 */
function apkAsKey(string $plainText): void
{
    app('auth')->forgetGuards();
    test()->flushSession();
    test()->withToken($plainText);
}

/**
 * The fresh test database already has the rows the migrations added; the tests start from none.
 */
function apkClearMenu(): void
{
    $ids = DB::table('menus')->where('route_path', 'like', '/apikeys%')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
}

// --- the menu rows -------------------------------------------------------------------------------

test('the menu and grant migrations add the page and its two hidden rows to the Settings group and to companyadmin', function () {
    apkClearMenu();
    DB::table('menus')->where('route_path', '/software/setting')->delete();
    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];
    $groupId = DB::table('menus')->insertGetId($row(['parent_id' => null, 'name' => 'Settings', 'route_name' => 'apk-group', 'route_path' => '#apk-group']));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Software setting', 'route_name' => 'legacy-apk-anchor', 'route_path' => '/software/setting']));
    $admin = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    $menu = require database_path('migrations/2026_09_23_130100_add_apikeys_menu.php');
    $grant = require database_path('migrations/2026_09_23_130200_grant_companyadmin_apikeys_menu.php');
    $menu->up();
    $menu->up();
    $grant->up();
    $grant->up();

    $page = DB::table('menus')->where('route_path', '/apikeys')->first();
    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')->where('permissions.role_id', $admin)->pluck('menus.route_path')->sort()->values()->all();

    expect((int) $page->parent_id)->toBe($groupId)
        ->and((int) $page->is_hidden)->toBe(0)
        ->and(DB::table('menus')->where('route_path', 'like', '/apikeys%')->count())->toBe(3)
        ->and(DB::table('menus')->where('parent_id', $page->id)->where('is_hidden', 1)->count())->toBe(2)
        ->and($granted)->toBe(['/apikeys', '/apikeys/add', '/apikeys/delete']);

    $grant->down();
    $menu->down();
    expect(DB::table('menus')->where('route_path', 'like', '/apikeys%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('role_id', $admin)->count())->toBe(0);
});

test('the menu migration does nothing without the Settings group', function () {
    apkClearMenu();
    DB::table('menus')->where('route_path', '/software/setting')->delete();

    (require database_path('migrations/2026_09_23_130100_add_apikeys_menu.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/apikeys%')->count())->toBe(0);
});

// --- the page ------------------------------------------------------------------------------------

test('the page needs its permission', function () {
    $company = apkCompany('APK01');

    $this->get(route('apikeys'))->assertRedirect();
    $this->actingAs(apkUser($company, []))->get(route('apikeys'))->assertForbidden();
    $this->actingAs(apkUser($company, ['/apikeys']))->get(route('apikeys'))->assertSuccessful()->assertInertia(fn ($page) => $page->component('apikey/index'));
    $this->actingAs(User::query()->findOrFail(1))->get(route('apikeys'))->assertSuccessful();
});

// --- creating, using and revoking ----------------------------------------------------------------

test('a key is created once, stored only as a hash, and works as a bearer token for its owner', function () {
    $owner = apkUser(apkCompany('APK02'), ['/apikeys', '/apikeys/add']);
    Sanctum::actingAs($owner);

    $response = $this->postJson('/api/api-keys', ['name' => 'Accounting export'])->assertSuccessful();
    $plain = $response->json('token');
    $stored = PersonalAccessToken::query()->firstOrFail();

    expect($plain)->toMatch('/^\d+\|[A-Za-z0-9]{40,}$/')
        ->and($stored->name)->toBe('Accounting export')
        ->and($stored->token)->not->toContain(substr($plain, strpos($plain, '|') + 1))
        ->and($stored->token)->toHaveLength(64)
        ->and((int) $stored->tokenable_id)->toBe($owner->id)
        ->and($stored->expires_at)->toBeNull()
        ->and($response->json('key.owner_email'))->toBe($owner->email)
        ->and(json_encode($this->getJson('/api/api-keys')->json()))->not->toContain(substr($plain, strpos($plain, '|') + 1));

    apkAsKey($plain);
    $this->getJson('/api/user')->assertSuccessful()->assertJsonPath('id', $owner->id);

    expect($stored->refresh()->last_used_at)->not->toBeNull();
});

test('a key can do exactly what its owner can and no more', function () {
    $company = apkCompany('APK03');
    $limited = apkUser($company, []);
    $token = $limited->createToken('read only person')->plainTextToken;

    apkAsKey($token);
    $this->getJson('/api/api-keys')->assertForbidden();
    $this->postJson('/api/api-keys', ['name' => 'nope'])->assertStatus(403);
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('a key cannot create another key', function () {
    $owner = apkUser(apkCompany('APK04'), ['/apikeys', '/apikeys/add']);
    $token = $owner->createToken('first')->plainTextToken;

    apkAsKey($token);
    $this->postJson('/api/api-keys', ['name' => 'second'])->assertForbidden()->assertJsonPath('message', 'An API key cannot create another key. Create keys from the web app.');

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('a key with an expiry stops working when it runs out, and one without never does', function () {
    $owner = apkUser(apkCompany('APK05'), ['/apikeys', '/apikeys/add']);
    Sanctum::actingAs($owner);

    $short = $this->postJson('/api/api-keys', ['name' => 'thirty days', 'expires_in_days' => 30])->assertSuccessful();
    $forever = $this->postJson('/api/api-keys', ['name' => 'forever'])->assertSuccessful();

    expect(PersonalAccessToken::query()->find($short->json('key.id'))->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
        ->and($short->json('key.is_expired'))->toBeFalse()
        ->and($forever->json('key.expires_at'))->toBeNull();

    apkAsKey($short->json('token'));
    $this->getJson('/api/user')->assertSuccessful();

    $this->travel(31)->days();
    apkAsKey($short->json('token'));
    $this->getJson('/api/user')->assertUnauthorized();

    apkAsKey($forever->json('token'));
    $this->getJson('/api/user')->assertSuccessful();
});

test('revoking a key stops it at once', function () {
    $owner = apkUser(apkCompany('APK06'), ['/apikeys', '/apikeys/add', '/apikeys/delete']);
    Sanctum::actingAs($owner);
    $created = $this->postJson('/api/api-keys', ['name' => 'temp'])->assertSuccessful();

    $this->deleteJson('/api/api-keys/'.$created->json('key.id'))->assertSuccessful()->assertJsonPath('message', 'API key revoked');

    expect(PersonalAccessToken::query()->count())->toBe(0);

    apkAsKey($created->json('token'));
    $this->getJson('/api/user')->assertUnauthorized();
});

test('creating and revoking need their own permissions', function () {
    $company = apkCompany('APK07');
    $viewer = apkUser($company, ['/apikeys']);
    Sanctum::actingAs($viewer);

    $this->getJson('/api/api-keys')->assertSuccessful();
    $this->postJson('/api/api-keys', ['name' => 'Denied'])->assertForbidden();

    $key = $viewer->createToken('mine')->accessToken;
    $this->deleteJson('/api/api-keys/'.$key->id)->assertForbidden();

    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('the name and lifetime are validated, arrays included, and the number of keys is capped', function () {
    $owner = apkUser(apkCompany('APK08'), ['/apikeys', '/apikeys/add']);
    Sanctum::actingAs($owner);

    $this->postJson('/api/api-keys', [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/api-keys', ['name' => ['x']])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/api-keys', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/api-keys', ['name' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/api-keys', ['name' => 'Ok name', 'expires_in_days' => 45])->assertUnprocessable()->assertJsonValidationErrors(['expires_in_days']);
    $this->postJson('/api/api-keys', ['name' => 'Ok name', 'expires_in_days' => ['30']])->assertUnprocessable()->assertJsonValidationErrors(['expires_in_days']);
    expect(PersonalAccessToken::query()->count())->toBe(0);

    foreach (range(1, 20) as $n) {
        $this->postJson('/api/api-keys', ['name' => 'Key '.$n])->assertSuccessful();
    }

    $this->postJson('/api/api-keys', ['name' => 'One too many'])->assertUnprocessable();

    expect(PersonalAccessToken::query()->count())->toBe(20);
});

// --- who sees what -------------------------------------------------------------------------------

test('a user sees their own keys, a company admin the company\'s, the superadmin all, and nobody revokes another company\'s', function () {
    $mine = apkCompany('APK09');
    $theirs = apkCompany('APK10');
    $paths = ['/apikeys', '/apikeys/add', '/apikeys/delete'];

    $alice = apkUser($mine, $paths);
    $bob = apkUser($mine, $paths);
    $admin = apkUser($mine, $paths, 'companyadmin');
    $stranger = apkUser($theirs, $paths, 'companyadmin');

    $aliceKey = $alice->createToken('alice key')->accessToken;
    $bobKey = $bob->createToken('bob key')->accessToken;
    $strangerKey = $stranger->createToken('stranger key')->accessToken;

    $names = fn (): array => collect($this->getJson('/api/api-keys')->assertSuccessful()->json('data'))->pluck('name')->sort()->values()->all();

    Sanctum::actingAs($alice);
    expect($names())->toBe(['alice key']);
    $this->deleteJson('/api/api-keys/'.$bobKey->id)->assertNotFound();

    Sanctum::actingAs($admin);
    expect($names())->toBe(['alice key', 'bob key']);
    $this->deleteJson('/api/api-keys/'.$strangerKey->id)->assertNotFound();
    $this->deleteJson('/api/api-keys/'.$bobKey->id)->assertSuccessful();

    Sanctum::actingAs(User::query()->findOrFail(1));
    expect($names())->toBe(['alice key', 'stranger key']);

    expect(PersonalAccessToken::query()->whereKey($aliceKey->id)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($strangerKey->id)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($bobKey->id)->exists())->toBeFalse();
});

test('a listed key shows who owns it, when it was used and whether it has expired', function () {
    $company = apkCompany('APK11');
    $owner = apkUser($company, ['/apikeys']);
    $old = $owner->createToken('old', ['*'], now()->subDay())->accessToken;
    $current = $owner->createToken('current')->accessToken;
    $current->forceFill(['last_used_at' => now()->subHour()])->save();
    Sanctum::actingAs($owner);

    $rows = collect($this->getJson('/api/api-keys')->assertSuccessful()->json('data'))->keyBy('name');

    expect($rows['old']['is_expired'])->toBeTrue()
        ->and($rows['current']['is_expired'])->toBeFalse()
        ->and($rows['current']['is_mine'])->toBeTrue()
        ->and($rows['current']['owner_email'])->toBe($owner->email)
        ->and($rows['current']['last_used_at'])->not->toBeNull()
        ->and($rows['old']['id'])->toBe($old->id);
});

test('guests get a 401 on the key endpoints', function () {
    Cache::flush();
    $this->getJson('/api/api-keys')->assertUnauthorized();
    $this->postJson('/api/api-keys', ['name' => 'anon'])->assertUnauthorized();
    $this->deleteJson('/api/api-keys/1')->assertUnauthorized();
});
