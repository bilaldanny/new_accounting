<?php

use App\Models\GiftCard;
use App\Models\GiftCardOrphanRefund;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The Orphaned Refunds page of Gift Card: its menu row and companyadmin grant, the page, the list it reads and
 * the Resolve action. The Gift Card page row the new row hangs under comes from a legacy anchor (`/sell`) that
 * exists only in live data, so the migration tests seed it.
 */
function gorpMenuMigration(): object
{
    return require database_path('migrations/2026_09_22_150000_add_giftcard_orphan_refunds_menu.php');
}

function gorpGrantMigration(): object
{
    return require database_path('migrations/2026_09_22_150100_grant_companyadmin_giftcard_orphan_refunds_menu.php');
}

/**
 * The Sell anchor and the Gift Card page with its hidden rows, the way the live data has them.
 */
function gorpSeedGiftCardMenu(): void
{
    DB::table('menus')->where('route_path', '/sell')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Sell', 'route_name' => 'gorp-group', 'route_path' => '#gorp-group',
    ]));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-gorp-anchor', 'route_path' => '/sell']));

    (require database_path('migrations/2026_09_21_210100_add_giftcard_menu.php'))->up();
}

function gorpRole(string $name = 'companyadmin', ?int $companyId = null): Role
{
    return Role::query()->create(['name' => $name, 'company_id' => $companyId, 'is_active' => true]);
}

/**
 * A user of a company whose role holds exactly these menu paths.
 *
 * @param  list<string>  $paths
 */
function gorpUser(int $companyId, array $paths): User
{
    $role = gorpRole('companyadmin', $companyId);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

function gorpCompany(string $code): int
{
    return DB::table('companies')->insertGetId(['code' => $code, 'name' => 'Company '.$code, 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function gorpRefund(int $companyId, array $attributes = []): GiftCardOrphanRefund
{
    return GiftCardOrphanRefund::query()->create(array_merge([
        'company_id' => $companyId, 'gift_card_code' => 'GC-GONE', 'amount' => 75.5, 'reason' => 'Card no longer exists; sale deleted: refund owed to the customer',
    ], $attributes));
}

// --- menu row and grant --------------------------------------------------------------------------

test('the menu migration adds one hidden permission row under the Gift Card page', function () {
    gorpSeedGiftCardMenu();
    $pageId = (int) DB::table('menus')->where('route_path', '/giftcard')->value('id');

    gorpMenuMigration()->up();

    $row = DB::table('menus')->where('route_path', '/giftcard/orphan-refunds')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->parent_id)->toBe($pageId)
        ->and($row->route_name)->toBe('giftcard.orphan-refunds')
        ->and((int) $row->is_hidden)->toBe(1)
        ->and((int) $row->is_active)->toBe(1)
        ->and((int) $row->sort_order)->toBe(9)
        ->and(DB::table('menus')->where('route_path', 'like', '/giftcard%')->count())->toBe(10);
});

test('the menu migration is idempotent, grants nothing and does nothing without the Gift Card page', function () {
    $roleId = gorpRole()->id;

    gorpMenuMigration()->up();
    expect(DB::table('menus')->where('route_path', '/giftcard/orphan-refunds')->count())->toBe(0);

    gorpSeedGiftCardMenu();
    gorpMenuMigration()->up();
    gorpMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', '/giftcard/orphan-refunds')->count())->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('rolling the menu migration back removes only its row and the permissions on it', function () {
    gorpSeedGiftCardMenu();
    gorpMenuMigration()->up();
    $roleId = gorpRole('accountant')->id;
    $rowId = (int) DB::table('menus')->where('route_path', '/giftcard/orphan-refunds')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $rowId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    gorpMenuMigration()->down();

    expect(DB::table('menus')->where('route_path', '/giftcard/orphan-refunds')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $rowId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', 'like', '/giftcard%')->count())->toBe(9);
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    gorpSeedGiftCardMenu();
    $roleId = gorpRole('accountant')->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    gorpMenuMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});

test('the grant gives the global companyadmin role exactly the one row and leaves other roles alone', function () {
    gorpSeedGiftCardMenu();
    gorpMenuMigration()->up();
    $admin = gorpRole()->id;
    $other = gorpRole('accountant')->id;
    $companyRole = gorpRole('companyadmin', gorpCompany('GORP01'))->id;
    $rowId = (int) DB::table('menus')->where('route_path', '/giftcard/orphan-refunds')->value('id');

    gorpGrantMigration()->up();
    gorpGrantMigration()->up();

    $granted = DB::table('permissions')->where('role_id', $admin)->get();

    expect($granted)->toHaveCount(1)
        ->and((int) $granted[0]->menu_id)->toBe($rowId)
        ->and((int) $granted[0]->status)->toBe(1)
        ->and(DB::table('permissions')->whereIn('role_id', [$other, $companyRole])->count())->toBe(0);

    gorpGrantMigration()->down();

    expect(DB::table('permissions')->where('role_id', $admin)->count())->toBe(0);
});

test('the grant does nothing without the menu row or the role', function () {
    gorpGrantMigration()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    gorpSeedGiftCardMenu();
    gorpMenuMigration()->up();
    gorpGrantMigration()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

// --- the page ------------------------------------------------------------------------------------

test('guests are sent away from the page', function () {
    $this->get(route('giftcard.orphan-refunds'))->assertRedirect();
});

test('the superadmin can open the page', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('giftcard.orphan-refunds'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('giftcard/orphan-refunds'));
});

test('a user without the page permission gets a 403, and the topup permission alone does not open it', function () {
    $companyId = gorpCompany('GORP02');

    $this->actingAs(gorpUser($companyId, []))->get(route('giftcard.orphan-refunds'))->assertForbidden();
    $this->actingAs(gorpUser($companyId, ['/giftcard/topup']))->get(route('giftcard.orphan-refunds'))->assertForbidden();
    $this->actingAs(gorpUser($companyId, ['/giftcard/orphan-refunds']))->get(route('giftcard.orphan-refunds'))->assertSuccessful();
});

// --- the list ------------------------------------------------------------------------------------

test('the list needs the page permission', function () {
    $companyId = gorpCompany('GORP03');
    gorpRefund($companyId);

    Sanctum::actingAs(gorpUser($companyId, ['/giftcard/topup']));
    $this->getJson('/api/gift-cards/orphan-refunds')->assertForbidden();

    Sanctum::actingAs(gorpUser($companyId, ['/giftcard/orphan-refunds']));
    $this->getJson('/api/gift-cards/orphan-refunds')->assertSuccessful()->assertJsonCount(1, 'data');
});

test('each row carries its amount, sale, card code, date and status, and the sale shows even though it was deleted', function () {
    $scope = seedSellScope();
    $companyId = (int) $scope['company_id'];
    $sale = Transaction::query()->forceCreate([
        'company_id' => $companyId, 'type' => Transaction::TYPE_SELL, 'status' => 'final', 'invoice_no' => 'INV-GORP-1', 'final_amount' => 75.5,
    ]);
    $sale->delete();
    gorpRefund($companyId, ['transaction_id' => $sale->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $row = $this->getJson('/api/gift-cards/orphan-refunds')->assertSuccessful()->json('data.0');

    expect($row['gift_card_code'])->toBe('GC-GONE')
        ->and($row['transaction_id'])->toBe($sale->id)
        ->and($row['invoice_no'])->toBe('INV-GORP-1')
        ->and($row['amount'])->toBe(75.5)
        ->and($row['resolved_at'])->toBeNull()
        ->and($row['created_at'])->not->toBeNull()
        ->and($row['gift_card_id'])->toBeNull()
        ->and($row['company_name'])->not->toBeNull();
});

test('a card issued again under the same code is pointed out', function () {
    $companyId = gorpCompany('GORP04');
    gorpRefund($companyId);
    $card = GiftCard::query()->create(['company_id' => $companyId, 'code' => 'GC-GONE', 'initial_value' => 10, 'balance' => 10, 'is_active' => true]);
    Sanctum::actingAs(gorpUser($companyId, ['/giftcard/orphan-refunds']));

    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonPath('data.0.gift_card_id', $card->id);
});

test('the list shows open rows first and all of them on request, with the open total', function () {
    $companyId = gorpCompany('GORP05');
    gorpRefund($companyId, ['amount' => 40]);
    gorpRefund($companyId, ['amount' => 25.25]);
    gorpRefund($companyId, ['amount' => 900, 'resolved_at' => now(), 'resolved_note' => 'Paid in cash']);
    Sanctum::actingAs(gorpUser($companyId, ['/giftcard/orphan-refunds']));

    $open = $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(2, 'data');
    $all = $this->getJson('/api/gift-cards/orphan-refunds?status=all')->assertJsonCount(3, 'data');

    expect($open->json('open_total'))->toBe(65.25)
        ->and($all->json('open_total'))->toBe(65.25)
        ->and(collect($all->json('data'))->whereNotNull('resolved_at')->first()['resolved_note'])->toBe('Paid in cash');
});

test('a company sees only its own rows', function () {
    $mine = gorpCompany('GORP06');
    $theirs = gorpCompany('GORP07');
    gorpRefund($mine, ['gift_card_code' => 'GC-MINE']);
    gorpRefund($theirs, ['gift_card_code' => 'GC-THEIRS']);

    Sanctum::actingAs(gorpUser($mine, ['/giftcard/orphan-refunds']));
    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(1, 'data')->assertJsonPath('data.0.gift_card_code', 'GC-MINE');

    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(2, 'data');
});

// --- resolve -------------------------------------------------------------------------------------

test('resolving marks the row resolved with its note and date, and it leaves the open list', function () {
    $companyId = gorpCompany('GORP08');
    $row = gorpRefund($companyId);
    Sanctum::actingAs(gorpUser($companyId, ['/giftcard/orphan-refunds', '/giftcard/topup']));

    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => '  Paid back in cash  '])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    expect($row->refresh()->resolved_at)->not->toBeNull()
        ->and($row->resolved_note)->toBe('Paid back in cash');

    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(0, 'data')->assertJsonPath('open_total', 0);
    $this->getJson('/api/gift-cards/orphan-refunds?status=all')->assertJsonCount(1, 'data')->assertJsonPath('data.0.resolved_note', 'Paid back in cash');
});

test('a resolved row cannot be resolved again and keeps its first note and date', function () {
    $companyId = gorpCompany('GORP09');
    $row = gorpRefund($companyId);
    Sanctum::actingAs(gorpUser($companyId, ['/giftcard/orphan-refunds', '/giftcard/topup']));

    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'First settlement'])->assertSuccessful();
    $first = $row->refresh()->resolved_at;
    $this->travel(2)->hours();

    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'Second attempt'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This refund has already been resolved.');

    expect($row->refresh()->resolved_note)->toBe('First settlement')
        ->and($row->resolved_at->equalTo($first))->toBeTrue();
});

test('resolving needs the topup permission, a real note and a row of the own company', function () {
    $mine = gorpCompany('GORP10');
    $theirs = gorpCompany('GORP11');
    $row = gorpRefund($mine);
    $foreign = gorpRefund($theirs);

    Sanctum::actingAs(gorpUser($mine, ['/giftcard/orphan-refunds']));
    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'No permission'])->assertForbidden();

    Sanctum::actingAs(gorpUser($mine, ['/giftcard/orphan-refunds', '/giftcard/topup']));
    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', [])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $this->postJson('/api/gift-cards/orphan-refunds/'.$foreign->id.'/resolve', ['note' => 'Not my company'])->assertNotFound();
    $this->postJson('/api/gift-cards/orphan-refunds/999999/resolve', ['note' => 'No such row'])->assertNotFound();

    expect($row->refresh()->resolved_at)->toBeNull()
        ->and($foreign->refresh()->resolved_at)->toBeNull();
});
