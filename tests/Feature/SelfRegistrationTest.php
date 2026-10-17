<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\WithholdingAccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function srPlan(array $extra = []): SubscriptionPlan
{
    return SubscriptionPlan::query()->create($extra + [
        'name' => 'Starter', 'code' => 'starter-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 49, 'trial_days' => 14,
        'max_users' => 5, 'max_branches' => 1, 'max_warehouses' => 2, 'max_products' => 1000, 'max_invoices_per_month' => 300, 'max_storage_mb' => 2048,
        'sort_order' => 1, 'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function srPayload(array $extra = []): array
{
    return $extra + [
        'company_name' => 'Fresh Traders',
        'company_phone' => '+92 21 3456789',
        'address' => '12 Market Road',
        'zipcode' => '75500',
        'ntn_no' => '1234567-8',
        'strn_no' => '17-00-0000-001-37',
        'first_name' => 'Amna',
        'last_name' => 'Khan',
        'username' => 'Amna.Khan',
        'email' => 'amna@fresh-traders.test',
        'phone' => '+92 300 1234567',
        'password' => 'Str0ng!Pass',
        'password_confirmation' => 'Str0ng!Pass',
    ];
}

beforeEach(function () {
    RateLimiter::clear('self-signup');

    // The one global company-admin role every existing admin shares; the migrations keep granting menus to it.
    $template = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission((int) $template->id, '/branch');
});

test('signing up creates the company, first branch, admin, trial tenant, starter roles and withholding accounts, then signs in', function () {
    $plan = srPlan(['code' => 'starter', 'trial_days' => 14, 'max_users' => 7, 'max_branches' => 3]);

    $this->post('/register', srPayload(['plan' => 'starter']))->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'amna@fresh-traders.test')->firstOrFail();
    $company = Company::query()->findOrFail($user->company_id);
    $branch = DB::table('branches')->where('company_id', $company->id)->first();

    $this->assertAuthenticatedAs($user);

    expect($company->name)->toBe('Fresh Traders')
        ->and($company->tenant_status)->toBe('trial')
        ->and($company->subscription_plan_id)->toBe($plan->id)
        ->and($company->trial_ends_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($company->max_users)->toBe(7)
        ->and($company->max_branches)->toBe(3)
        ->and(Role::query()->findOrFail($user->role_id)->name)->toBe('companyadmin')
        ->and(Role::query()->findOrFail($user->role_id)->company_id)->toBeNull()
        ->and($user->first_name)->toBe('Amna')
        ->and($user->last_name)->toBe('Khan')
        ->and($user->username)->toBe('amna.khan')
        ->and($company->email)->toBe('amna@fresh-traders.test')
        ->and($company->address)->toBe('12 Market Road')
        ->and($company->zipcode)->toBe('75500')
        ->and($company->ntn_no)->toBe('1234567-8')
        ->and($company->strn_no)->toBe('17-00-0000-001-37')
        ->and($user->is_active)->toBeTrue()
        ->and(Role::query()->where('company_id', $company->id)->where('branch_id', $branch->id)->orderBy('name')->pluck('name')->all())
        ->toBe(['Accountant', 'Cashier', 'HR Manager', 'Manager', 'Sales Representative', 'Warehouse Keeper'])
        ->and(DB::table('chart_of_account_mappings')->where('branch_id', $branch->id)->whereIn('key', ['withholdingreceivable', 'withholdingpayable'])->whereNotNull('value')->count())->toBe(2);
});

test('without any trial plan the company still starts as a trial on the default limits', function () {
    srPlan(['code' => 'paid-only', 'trial_days' => 0]);

    $this->post('/register', srPayload())->assertRedirect('/dashboard');

    $company = Company::query()->where('name', 'Fresh Traders')->firstOrFail();

    expect($company->tenant_status)->toBe('trial')->and($company->subscription_plan_id)->toBeNull();
});

test('a plan without a trial cannot be chosen at sign-up', function () {
    srPlan(['code' => 'paid-only', 'trial_days' => 0]);

    $this->post('/register', srPayload(['plan' => 'paid-only']))->assertSessionHasErrors('plan');

    expect(Company::query()->where('name', 'Fresh Traders')->exists())->toBeFalse();
});

test('a step that fails leaves no company, branch, user or role behind', function () {
    srPlan();
    $this->app->instance(WithholdingAccountSetup::class, new class extends WithholdingAccountSetup
    {
        public function ensureForBranch(int $companyId, int $branchId): void
        {
            throw new RuntimeException('boom');
        }
    });
    $before = [Company::query()->count(), DB::table('branches')->count(), User::query()->count(), Role::query()->count(), DB::table('chart_of_accounts')->count()];

    $this->post('/register', srPayload())->assertSessionHasErrors('email');

    $this->assertGuest();
    expect([Company::query()->count(), DB::table('branches')->count(), User::query()->count(), Role::query()->count(), DB::table('chart_of_accounts')->count()])->toBe($before);
});

test('an email that is already registered gets the same generic error and nothing is created', function () {
    $this->post('/register', srPayload())->assertRedirect('/dashboard');
    auth()->logout();
    $companies = Company::query()->count();

    $response = $this->post('/register', srPayload(['company_name' => 'Another Co']));

    $response->assertSessionHasErrors('email');
    $message = session('errors')->first('email');
    expect($message)->not->toContain('taken')->not->toContain('already')
        ->and(Company::query()->count())->toBe($companies);
});

test('the honeypot field stops a bot without creating anything', function () {
    $before = User::query()->count();

    $this->post('/register', srPayload(['website' => 'http://spam.example']))->assertRedirect(route('home'));

    expect(User::query()->count())->toBe($before)->and(Company::query()->where('name', 'Fresh Traders')->exists())->toBeFalse();
    $this->assertGuest();
});

test('the sixth attempt within a minute from one address is throttled', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/register', srPayload(['website' => 'bot']))->assertRedirect(route('home'));
    }

    $this->post('/register', srPayload(['website' => 'bot']))->assertStatus(429);
});

test('with public registration switched off the page and the endpoint answer 403', function () {
    config(['landing.registration_enabled' => false]);

    $this->get('/register')->assertForbidden();
    $this->post('/register', srPayload())->assertForbidden();

    expect(User::query()->where('email', 'amna@fresh-traders.test')->exists())->toBeFalse();
});

test('a weak or unconfirmed password is refused', function () {
    $this->post('/register', srPayload(['password' => 'password', 'password_confirmation' => 'password']))->assertSessionHasErrors('password');
    $this->post('/register', srPayload(['password_confirmation' => 'Different1!']))->assertSessionHasErrors('password');

    expect(Company::query()->where('name', 'Fresh Traders')->exists())->toBeFalse();
});

test('the new admin sees only their own company', function () {
    $this->post('/register', srPayload())->assertRedirect('/dashboard');
    auth()->logout();
    $this->post('/register', srPayload(['company_name' => 'Second Shop', 'email' => 'second@shop.test', 'username' => 'second.shop']))->assertRedirect('/dashboard');

    $second = User::query()->where('email', 'second@shop.test')->firstOrFail();
    Sanctum::actingAs($second);

    $branches = $this->getJson('/api/branches')->assertOk()->json('data.data');

    expect(collect($branches)->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe(DB::table('branches')->where('company_id', $second->company_id)->pluck('id')->map(fn ($id) => (int) $id)->all());
});

test('a signed-in visitor cannot open the sign-up page', function () {
    $this->actingAs(User::query()->findOrFail(1))->get('/register')->assertRedirect('/dashboard');
});

test('a database without the shared company-admin role falls back to a role of the company\'s own', function () {
    Role::query()->whereNull('company_id')->where('name', 'companyadmin')->delete();

    $this->post('/register', srPayload())->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'amna@fresh-traders.test')->firstOrFail();
    expect(Role::query()->findOrFail($user->role_id)->company_id)->toBe($user->company_id);
});

test('a username that is taken is refused and nothing is created', function () {
    $this->post('/register', srPayload())->assertRedirect('/dashboard');
    auth()->logout();
    $companies = Company::query()->count();

    $this->post('/register', srPayload(['company_name' => 'Another Co', 'email' => 'other@fresh-traders.test', 'username' => 'AMNA.KHAN']))->assertSessionHasErrors('username');

    expect(Company::query()->count())->toBe($companies);
});

test('the first name and username are required, and the username has to be plain', function () {
    $this->post('/register', srPayload(['first_name' => '']))->assertSessionHasErrors('first_name');
    $this->post('/register', srPayload(['username' => '']))->assertSessionHasErrors('username');
    $this->post('/register', srPayload(['username' => 'no spaces!']))->assertSessionHasErrors('username');
    $this->post('/register', srPayload(['country_id' => 999999]))->assertSessionHasErrors('country_id');

    expect(Company::query()->where('name', 'Fresh Traders')->exists())->toBeFalse();
});
