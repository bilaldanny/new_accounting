<?php

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The read-only customer / supplier portal: a portal user sees only their own contact's balance and recent
 * documents and is confined to the portal, and an admin creates and removes those accounts.
 */
function prtPortalUser(array $scope, int $contactId, string $email = 'portal@example.com', string $password = 'portal-pass-1'): User
{
    $user = User::query()->create([
        'company_id' => $scope['company_id'], 'role_id' => Role::query()->where('name', 'portal')->value('id'),
        'first_name' => 'Portal', 'last_name' => 'Person', 'email' => $email, 'username' => $email, 'password' => Hash::make($password), 'is_active' => true,
    ]);

    return tap($user)->forceFill(['contact_id' => $contactId, 'email_verified_at' => now()])->save() ? $user->refresh() : $user;
}

/**
 * A company user with these menu paths.
 *
 * @param  list<string>  $paths
 */
function prtAdmin(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'portaladmin'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
}

// --- the migration -------------------------------------------------------------------------------

test('the migration gives users a contact column and adds the hidden portal role once', function () {
    $role = Role::query()->where('name', 'portal')->first();

    expect(Schema::hasColumn('users', 'contact_id'))->toBeTrue()
        ->and($role)->not->toBeNull()
        ->and($role->company_id)->toBeNull()
        ->and((int) $role->is_admin)->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $role->id)->count())->toBe(0);

    (require database_path('migrations/2026_09_23_150000_add_contact_id_to_users_and_portal_role.php'))->up();

    expect(Role::query()->where('name', 'portal')->count())->toBe(1);
});

test('the menu and grant migrations add the page and its two hidden rows to Settings and to companyadmin', function () {
    $ids = DB::table('menus')->where('route_path', 'like', '/portalusers%')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
    $groupId = (int) DB::table('menus')->where('route_path', '/software/setting')->value('parent_id');
    $admin = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    $menu = require database_path('migrations/2026_09_23_150100_add_portalusers_menu.php');
    $grant = require database_path('migrations/2026_09_23_150200_grant_companyadmin_portalusers_menu.php');
    $menu->up();
    $menu->up();
    $grant->up();
    $grant->up();

    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')->where('permissions.role_id', $admin)->pluck('menus.route_path')->sort()->values()->all();

    expect((int) DB::table('menus')->where('route_path', '/portalusers')->value('parent_id'))->toBe($groupId)
        ->and(DB::table('menus')->where('route_path', 'like', '/portalusers%')->count())->toBe(3)
        ->and($granted)->toBe(['/portalusers', '/portalusers/add', '/portalusers/delete']);

    $grant->down();
    $menu->down();
    expect(DB::table('menus')->where('route_path', 'like', '/portalusers%')->count())->toBe(0);
});

// --- what the portal shows -----------------------------------------------------------------------

test('a customer sees what they owe, their recent invoices and payments, and nothing that is a draft', function () {
    $scope = trpScope('A');
    $customer = prpContact($scope, 'customer', 'Acme Buyer');
    $invoice = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000, 'invoice_no' => 'INV-P1']);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-05', 'final_amount' => 500, 'invoice_no' => 'INV-P2']);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-06', 'final_amount' => 9000, 'status' => 'draft', 'invoice_no' => 'INV-DRAFT']);
    trpPayment($scope, $invoice, 300, '2026-09-10 10:00');
    Sanctum::actingAs(prtPortalUser($scope, $customer));

    $data = $this->getJson('/api/portal')->assertSuccessful();
    $section = $data->json('sections.0');

    expect($data->json('contact.name'))->toBe('Acme Buyer')
        ->and($data->json('sections'))->toHaveCount(1)
        ->and($section['kind'])->toBe('customer')
        ->and($section['balance'])->toEqual(1200)
        ->and($section['position'])->toBe('you_owe')
        ->and(collect($section['documents'])->pluck('invoice_no')->all())->toBe(['INV-P2', 'INV-P1'])
        ->and(collect($section['documents'])->firstWhere('invoice_no', 'INV-P1'))->toMatchArray(['amount' => 1000, 'paid' => 300, 'due' => 700])
        ->and($section['payments'])->toHaveCount(1)
        ->and($section['payments'][0])->toMatchArray(['amount' => 300, 'invoice_no' => 'INV-P1', 'method' => 'cash']);
});

test('a supplier sees what they are owed and a contact who is both sees two sections', function () {
    $scope = trpScope('A');
    $both = prpContact($scope, 'both', 'Both Ways');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $both, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 2000, 'invoice_no' => 'PO-P1']);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $both, 'transaction_date' => '2026-09-02', 'status' => 'draft', 'final_amount' => 7777]);
    trpPayment($scope, $purchase, 800, '2026-09-03 10:00');
    trpDoc($scope, 'sell', ['contact_id' => $both, 'transaction_date' => '2026-09-04', 'final_amount' => 300, 'invoice_no' => 'INV-B1']);
    Sanctum::actingAs(prtPortalUser($scope, $both));

    $sections = collect($this->getJson('/api/portal')->assertSuccessful()->json('sections'))->keyBy('kind');

    expect($sections->keys()->all())->toBe(['customer', 'supplier'])
        ->and($sections['supplier']['balance'])->toEqual(1200)
        ->and($sections['supplier']['position'])->toBe('owed_to_you')
        ->and(collect($sections['supplier']['documents'])->pluck('invoice_no')->all())->toBe(['PO-P1'])
        ->and($sections['customer']['balance'])->toEqual(300)
        ->and($sections['customer']['position'])->toBe('you_owe');
});

test('an advance shows as a credit and a settled account as settled', function () {
    $scope = trpScope('A');
    $paidAhead = prpContact($scope, 'customer', 'Paid Ahead');
    $invoice = trpDoc($scope, 'sell', ['contact_id' => $paidAhead, 'transaction_date' => '2026-09-01', 'final_amount' => 100]);
    trpPayment($scope, $invoice, 150, '2026-09-02 10:00');
    $settled = prpContact($scope, 'customer', 'Settled');

    Sanctum::actingAs(prtPortalUser($scope, $paidAhead, 'ahead@example.com'));
    expect($this->getJson('/api/portal')->json('sections.0'))->toMatchArray(['position' => 'credit', 'balance' => -50]);

    Sanctum::actingAs(prtPortalUser($scope, $settled, 'settled@example.com'));
    expect($this->getJson('/api/portal')->json('sections.0'))->toMatchArray(['position' => 'settled', 'balance' => 0]);
});

test('the list of recent documents is capped', function () {
    $scope = trpScope('A');
    $customer = prpContact($scope, 'customer', 'Busy Buyer');

    foreach (range(1, 15) as $n) {
        trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'final_amount' => 10]);
    }

    Sanctum::actingAs(prtPortalUser($scope, $customer));

    expect($this->getJson('/api/portal')->json('sections.0.documents'))->toHaveCount(10);
});

test('a portal user never sees another contact\'s or company\'s data, whatever they ask for', function () {
    $mine = trpScope('A');
    $theirs = trpScope('B');
    $me = prpContact($mine, 'customer', 'Me Buyer');
    $other = prpContact($mine, 'customer', 'Other Buyer');
    $foreign = prpContact($theirs, 'customer', 'Foreign Buyer');
    trpDoc($mine, 'sell', ['contact_id' => $me, 'final_amount' => 100, 'invoice_no' => 'INV-MINE']);
    trpDoc($mine, 'sell', ['contact_id' => $other, 'final_amount' => 999, 'invoice_no' => 'INV-OTHER']);
    trpDoc($theirs, 'sell', ['contact_id' => $foreign, 'final_amount' => 555, 'invoice_no' => 'INV-FOREIGN']);
    Sanctum::actingAs(prtPortalUser($mine, $me));

    $body = json_encode($this->getJson('/api/portal?contact_id='.$other.'&company_id='.$theirs['company_id'])->assertSuccessful()->json());

    expect($body)->toContain('INV-MINE')->not->toContain('INV-OTHER')->not->toContain('INV-FOREIGN')
        ->and($this->getJson('/api/portal')->json('sections.0.balance'))->toEqual(100);
});

test('an inactive or deleted contact has no portal, and neither has an ordinary user', function () {
    $scope = trpScope('A');
    $customer = prpContact($scope, 'customer', 'Gone Buyer');
    Sanctum::actingAs(prtPortalUser($scope, $customer));

    Contact::query()->whereKey($customer)->update(['active' => false]);
    $this->getJson('/api/portal')->assertForbidden();

    Contact::query()->whereKey($customer)->update(['active' => true]);
    $this->getJson('/api/portal')->assertSuccessful();

    Contact::query()->whereKey($customer)->delete();
    $this->getJson('/api/portal')->assertForbidden();

    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->getJson('/api/portal')->assertForbidden();
});

// --- confinement ---------------------------------------------------------------------------------

test('a portal user is refused every API endpoint outside the portal', function (string $path) {
    $scope = trpScope('A');
    Sanctum::actingAs(prtPortalUser($scope, prpContact($scope, 'customer', 'Confined Buyer')));

    $this->getJson($path)->assertForbidden()->assertJsonPath('message', 'A portal account can only use the portal.');
})->with(['/api/customers', '/api/sells', '/api/suppliers', '/api/fetchcompanies', '/api/dashboard/sales', '/api/api-keys', '/api/portal-users', '/api/reports/customer-outstanding', '/api/products', '/api/roles']);

test('a portal user may use the portal and see who they are, and writes are refused too', function () {
    $scope = trpScope('A');
    $portal = prtPortalUser($scope, prpContact($scope, 'customer', 'Allowed Buyer'));
    Sanctum::actingAs($portal);

    $this->getJson('/api/portal')->assertSuccessful();
    $this->getJson('/api/user')->assertSuccessful()->assertJsonPath('id', $portal->id);
    $this->postJson('/api/sells', [])->assertForbidden();
    $this->postJson('/api/portal-users', ['contact_id' => 1, 'email' => 'x@example.com'])->assertForbidden();
});

test('a portal user\'s API key is confined too', function () {
    $scope = trpScope('A');
    $portal = prtPortalUser($scope, prpContact($scope, 'customer', 'Key Buyer'));
    $token = $portal->createToken('portal key')->plainTextToken;

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/customers')->assertForbidden();
    $this->withToken($token)->getJson('/api/portal')->assertSuccessful();
});

test('a portal user is sent back to the portal from every other page and can open it', function (string $path) {
    $scope = trpScope('A');
    $this->actingAs(prtPortalUser($scope, prpContact($scope, 'customer', 'Paged Buyer')))->get($path)->assertRedirect('/portal');
})->with(['/dashboard', '/sell', '/customer', '/apikeys', '/portalusers', '/report/customer-outstanding']);

test('the portal page opens for a portal user and for nobody else', function () {
    $scope = trpScope('A');
    $portal = prtPortalUser($scope, prpContact($scope, 'customer', 'Page Buyer'));

    $this->get('/portal')->assertRedirect();
    $this->actingAs($portal)->get('/portal')->assertSuccessful()->assertInertia(fn ($page) => $page->component('portal/index'));
    $this->actingAs(User::query()->findOrFail(1))->get('/portal')->assertForbidden();
});

test('ordinary users are not affected by the confinement', function () {
    $this->actingAs(User::query()->findOrFail(1))->get('/dashboard')->assertOk();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->getJson('/api/customers')->assertSuccessful();
});

test('a portal user signs in with their email, lands on the portal, and a removed one cannot sign in', function () {
    $scope = trpScope('A');
    $portal = prtPortalUser($scope, prpContact($scope, 'customer', 'Login Buyer'), 'login-buyer@example.com', 'Sup3r-secret-pw');

    $this->post(route('login.store'), ['email' => 'login-buyer@example.com', 'password' => 'Sup3r-secret-pw'])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($portal);
    $this->get('/dashboard')->assertRedirect('/portal');

    $this->post('/logout');
    $portal->delete();
    $this->post(route('login.store'), ['email' => 'login-buyer@example.com', 'password' => 'Sup3r-secret-pw']);

    $this->assertGuest();
});

// --- the admin side ------------------------------------------------------------------------------

test('an admin creates a portal user: a generated password that works once, the portal role and the contact', function () {
    $scope = trpScope('A');
    $customer = prpContact($scope, 'customer', 'New Portal Buyer');
    Sanctum::actingAs(prtAdmin($scope, ['/portalusers', '/portalusers/add']));

    $response = $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'buyer@example.com'])->assertSuccessful();
    $password = $response->json('password');
    $created = User::query()->where('email', 'buyer@example.com')->firstOrFail();

    expect($password)->toHaveLength(12)
        ->and($created->contact_id)->toBe($customer)
        ->and($created->company_id)->toBe($scope['company_id'])
        ->and($created->role->name)->toBe('portal')
        ->and($created->email_verified_at)->not->toBeNull()
        ->and($created->is_active)->toBeTrue()
        ->and(Hash::check($password, $created->password))->toBeTrue()
        ->and($created->password)->not->toContain($password)
        ->and(json_encode($this->getJson('/api/portal-users')->json()))->not->toContain($password);

    app('auth')->forgetGuards();
    $this->post(route('login.store'), ['email' => 'buyer@example.com', 'password' => $password]);
    $this->assertAuthenticatedAs($created);
});

test('a portal user cannot be made for a contact that is not the company\'s active customer or supplier, or with a used email', function () {
    $scope = trpScope('A');
    $other = trpScope('B');
    $customer = prpContact($scope, 'customer', 'Real Buyer');
    $inactive = prpContact($scope, 'customer', 'Inactive Buyer', ['active' => false]);
    $foreign = prpContact($other, 'customer', 'Foreign Buyer');
    Sanctum::actingAs(prtAdmin($scope, ['/portalusers', '/portalusers/add']));

    $this->postJson('/api/portal-users', ['contact_id' => $inactive, 'email' => 'a@example.com'])->assertUnprocessable();
    $this->postJson('/api/portal-users', ['contact_id' => $foreign, 'email' => 'b@example.com'])->assertUnprocessable();
    $this->postJson('/api/portal-users', ['contact_id' => 999999, 'email' => 'c@example.com'])->assertUnprocessable();
    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    $this->postJson('/api/portal-users', ['contact_id' => ['1'], 'email' => 'd@example.com'])->assertUnprocessable();
    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => ['x@example.com']])->assertUnprocessable();
    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'admin-'.'@example.com'.str_repeat('x', 300)])->assertUnprocessable();

    expect(User::query()->whereNotNull('contact_id')->count())->toBe(0);

    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'taken@example.com'])->assertSuccessful();
    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

test('a contact has at most three portal accounts', function () {
    $scope = trpScope('A');
    $customer = prpContact($scope, 'customer', 'Popular Buyer');
    Sanctum::actingAs(prtAdmin($scope, ['/portalusers', '/portalusers/add']));

    foreach (range(1, 3) as $n) {
        $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => "user{$n}@example.com"])->assertSuccessful();
    }

    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'user4@example.com'])->assertUnprocessable();

    expect(User::query()->where('contact_id', $customer)->count())->toBe(3);
});

test('an admin sees and manages only their own company\'s portal users, and the superadmin all', function () {
    $mine = trpScope('A');
    $theirs = trpScope('B');
    $mineUser = prtPortalUser($mine, prpContact($mine, 'customer', 'My Buyer'), 'mine@example.com');
    $theirUser = prtPortalUser($theirs, prpContact($theirs, 'customer', 'Their Buyer'), 'theirs@example.com');
    $ordinary = prtAdmin($mine, []);
    Sanctum::actingAs(prtAdmin($mine, ['/portalusers', '/portalusers/add', '/portalusers/delete']));

    $emails = fn (): array => collect($this->getJson('/api/portal-users')->assertSuccessful()->json('data'))->pluck('email')->all();

    expect($emails())->toBe(['mine@example.com']);
    $this->deleteJson('/api/portal-users/'.$theirUser->id)->assertNotFound();
    $this->postJson('/api/portal-users/'.$theirUser->id.'/reset-password')->assertNotFound();
    // an ordinary user is not a portal user and cannot be removed through this page
    $this->deleteJson('/api/portal-users/'.$ordinary->id)->assertNotFound();

    Sanctum::actingAs(User::query()->findOrFail(1));
    expect($emails())->toContain('mine@example.com', 'theirs@example.com');

    expect(User::query()->find($theirUser->id))->not->toBeNull()
        ->and(User::query()->find($ordinary->id))->not->toBeNull()
        ->and($mineUser->exists)->toBeTrue();
});

test('removing a portal user stops them signing in and kills their keys, and a reset replaces the password', function () {
    $scope = trpScope('A');
    $portal = prtPortalUser($scope, prpContact($scope, 'customer', 'Reset Buyer'), 'reset@example.com', 'Old-password-1');
    $portal->createToken('key');
    Sanctum::actingAs(prtAdmin($scope, ['/portalusers', '/portalusers/add', '/portalusers/delete']));

    $new = $this->postJson('/api/portal-users/'.$portal->id.'/reset-password')->assertSuccessful()->json('password');

    expect(Hash::check('Old-password-1', $portal->refresh()->password))->toBeFalse()
        ->and(Hash::check($new, $portal->password))->toBeTrue()
        ->and($portal->tokens()->count())->toBe(0);

    $portal->createToken('another key');
    $this->deleteJson('/api/portal-users/'.$portal->id)->assertSuccessful();

    expect(User::query()->find($portal->id))->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $portal->id)->count())->toBe(0);
});

test('the admin pages and endpoints need their permissions', function () {
    $scope = trpScope('A');
    $customer = prpContact($scope, 'customer', 'Perm Buyer');
    $portal = prtPortalUser($scope, $customer);

    $this->getJson('/api/portal-users')->assertUnauthorized();

    Sanctum::actingAs(prtAdmin($scope, []));
    $this->getJson('/api/portal-users')->assertForbidden();
    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'n@example.com'])->assertForbidden();

    Sanctum::actingAs(prtAdmin($scope, ['/portalusers']));
    $this->getJson('/api/portal-users')->assertSuccessful();
    $this->postJson('/api/portal-users', ['contact_id' => $customer, 'email' => 'n@example.com'])->assertForbidden();
    $this->postJson('/api/portal-users/'.$portal->id.'/reset-password')->assertForbidden();
    $this->deleteJson('/api/portal-users/'.$portal->id)->assertForbidden();

    $this->actingAs(prtAdmin($scope, []))->get('/portalusers')->assertForbidden();
    $this->actingAs(prtAdmin($scope, ['/portalusers']))->get('/portalusers')->assertSuccessful()->assertInertia(fn ($page) => $page->component('portalusers/index'));
});
