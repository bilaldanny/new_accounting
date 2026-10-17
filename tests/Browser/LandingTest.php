<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

/**
 * The public side of the app, seen from a browser: the landing page at `/`, its pricing, self sign-up and login.
 */
function duskLandingPlans(): void
{
    SubscriptionPlan::query()->updateOrCreate(['code' => 'dusk-starter'], [
        'name' => 'Dusk Starter', 'billing_cycle' => 'monthly', 'price' => 49, 'trial_days' => 14, 'max_users' => 5, 'max_branches' => 1,
        'max_warehouses' => 2, 'max_products' => 1000, 'max_invoices_per_month' => 300, 'max_storage_mb' => 2048, 'sort_order' => 1, 'is_active' => true,
    ]);
    SubscriptionPlan::query()->updateOrCreate(['code' => 'dusk-hidden'], [
        'name' => 'Dusk Hidden Plan', 'billing_cycle' => 'monthly', 'price' => 1, 'trial_days' => 3, 'sort_order' => 9, 'is_active' => false,
    ]);
}

test('the landing page loads for a guest with its pricing, from its own light bundle and without browser errors', function () {
    $this->world();
    duskLandingPlans();

    $this->browse(function (Browser $browser) {
        $browser->logout()->visit('/')->waitFor('#pricing', 30);
        $browser->waitForText('Dusk Starter', 10)
            ->assertSee('14-day free trial')
            ->assertSee('Payment is manual')
            ->assertDontSee('Dusk Hidden Plan')
            ->assertSee('FBR e-invoicing')
            ->assertPresent('a[href="/register?plan=dusk-starter"]');

        $scripts = $browser->driver->executeScript('return [...document.scripts].map((s) => s.src).filter(Boolean);');
        expect(collect($scripts)->filter(fn (string $src): bool => str_contains($src, '/build/assets/app-'))->all())->toBe([])
            ->and(collect($scripts)->filter(fn (string $src): bool => str_contains($src, '/build/assets/landing-'))->count())->toBe(1);

        $this->assertNoBrowserErrors($browser);
    });
});

test('the screenshot gallery switches between four screens by click and by keyboard, and every picture loads', function () {
    $this->world();

    $this->browse(function (Browser $browser) {
        $visiblePanels = fn (): array => $browser->driver->executeScript('return [...document.querySelectorAll("[role=tabpanel]")].filter((p) => p.offsetParent !== null).map((p) => p.id);');

        $browser->resize(1440, 900)->logout()->visit('/')->waitFor('#screens', 30)->pause(1500);
        $browser->script('document.querySelector("#screens").scrollIntoView();');
        $browser->pause(800)->assertPresent('#tab-dashboard[aria-selected=true]');
        expect($visiblePanels())->toBe(['panel-dashboard']);

        $browser->script('document.querySelector("#tab-sales").scrollIntoView({block: "center"});');
        $browser->pause(500)->click('#tab-sales')->pause(1200);
        expect($visiblePanels())->toBe(['panel-sales']);

        $browser->keys('#tab-sales', '{arrow_right}')->pause(600)->assertPresent('#tab-products[aria-selected=true]');
        expect($visiblePanels())->toBe(['panel-products']);
        $browser->keys('#tab-products', '{arrow_right}')->pause(400)->assertPresent('#tab-reports[aria-selected=true]');

        $loaded = $browser->driver->executeScript('return [...document.querySelectorAll("#screens img, .lp-hero img")].map((img) => img.complete && img.naturalWidth > 0);');
        expect($loaded)->toHaveCount(5)->and(in_array(false, $loaded, true))->toBeFalse();

        $this->assertNoBrowserErrors($browser);
    });
});

test('the pricing section highlights only a plan the superadmin marked as recommended', function () {
    $this->world();
    duskLandingPlans();
    SubscriptionPlan::query()->where('code', 'dusk-starter')->update(['is_featured' => true]);

    $this->browse(function (Browser $browser) {
        $browser->logout()->visit('/')->waitFor('#pricing', 30);
        expect($browser->driver->executeScript('return document.querySelectorAll(".lp-plan-featured").length;'))->toBe(1)
            ->and($browser->driver->executeScript('return document.querySelectorAll(".lp-badge").length;'))->toBe(1);
    });

    SubscriptionPlan::query()->update(['is_featured' => false]);

    $this->browse(function (Browser $browser) {
        $browser->visit('/')->waitFor('#pricing', 30);
        expect($browser->driver->executeScript('return document.querySelectorAll(".lp-badge, .lp-plan-featured").length;'))->toBe(0);
    });
});

test('the landing page fits a phone screen without sideways scrolling', function () {
    $this->world();
    duskLandingPlans();

    $this->browse(function (Browser $browser) {
        $browser->logout()->resize(375, 812)->visit('/')->waitFor('#pricing', 30)->assertSee('Menu');

        $overflow = $browser->driver->executeScript('return document.documentElement.scrollWidth > window.innerWidth;');
        $browser->resize(1920, 1080);

        expect($overflow)->toBeFalse();
    });
});

test('a visitor registers from the landing page and lands on the dashboard of a new trial company', function () {
    $this->world();
    duskLandingPlans();
    $template = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    grantMenuPermission((int) $template->id, '/dashboard');

    $this->browse(function (Browser $browser) {
        $browser->logout()->visit('/')->waitFor('#pricing', 30);
        $browser->click('a[href="/register?plan=dusk-starter"]')->waitFor('#company_name', 20);
        $browser->type('#company_name', 'Dusk Signup Traders')
            ->type('#first_name', 'Sana')
            ->type('#last_name', 'Malik')
            ->type('#username', 'sana.dusk')
            ->type('#email', 'sana@dusk-signup.test')
            ->type('#phone', '+92 300 5550000')
            ->type('#password', 'Dusk!Signup7')
            ->type('#password_confirmation', 'Dusk!Signup7')
            ->press('Create account')
            ->waitForLocation('/dashboard', 40);

        $browser->assertAuthenticated();
        $this->assertNoBrowserErrors($browser);
    });

    $company = Company::query()->where('name', 'Dusk Signup Traders')->firstOrFail();
    $user = User::query()->where('email', 'sana@dusk-signup.test')->firstOrFail();

    expect($company->tenant_status)->toBe('trial')
        ->and($company->trial_ends_at)->not->toBeNull()
        ->and((int) $user->company_id)->toBe((int) $company->id)
        ->and(DB::table('roles')->where('company_id', $company->id)->count())->toBe(6)
        ->and(DB::table('chart_of_account_mappings')->where('company_id', $company->id)->whereIn('key', ['withholdingreceivable', 'withholdingpayable'])->whereNotNull('value')->count())->toBe(2);
});

test('the register form refuses a weak password and keeps what was typed', function () {
    $this->world();
    duskLandingPlans();

    $this->browse(function (Browser $browser) {
        $browser->logout()->visit('/register')->waitFor('#company_name', 30);
        $browser->type('#company_name', 'Weak Pass Co')
            ->type('#first_name', 'Weak')
            ->type('#username', 'weak.dusk')
            ->type('#email', 'weak@dusk-signup.test')
            ->type('#password', 'password')
            ->type('#password_confirmation', 'password')
            ->press('Create account')
            ->waitForText('The password needs at least 8 characters', 20)
            ->assertValue('#company_name', 'Weak Pass Co')
            ->assertValue('#password', '');
    });

    expect(Company::query()->where('name', 'Weak Pass Co')->exists())->toBeFalse();
});

test('a user opens the login page from the landing page and signs in', function () {
    $world = $this->world();
    duskLandingPlans();
    $role = Role::query()->create(['name' => 'Dusk Login Role', 'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'is_active' => true]);
    $user = createStaffUserForRole($role, [
        'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'email' => 'login@dusk-signup.test', 'username' => 'dusklogin',
        'password' => Hash::make('Dusk!Login7pass'),
    ]);
    grantMenuPermission((int) $role->id, '/dashboard');

    $this->browse(function (Browser $browser) use ($user) {
        $browser->logout()->visit('/')->waitFor('#pricing', 30);
        $browser->assertPresent('.lp-links a[href="/login"]');
        $browser->script('document.querySelector(".lp-links a[href=\\"/login\\"]").click();');
        $browser->waitFor('input[type=password]', 30)->pause(3000);
        $browser->assertPathIs('/login');

        // The login page (full app bundle) clears its fields once, a few seconds after it appears, so fill it and submit in one go.
        $browser->driver->executeScript(
            'document.querySelector("input[placeholder=\\"name@company.com\\"]").value = arguments[0]; document.querySelector("input[type=password]").value = arguments[1]; document.querySelector("button[type=submit]").click();',
            [$user->email, 'Dusk!Login7pass'],
        );
        $browser->waitForLocation('/dashboard', 40);

        $browser->assertAuthenticatedAs($user);
    });
});

test('a signed-in user who opens the home page is taken to the dashboard', function () {
    $this->world();

    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/')->waitForLocation('/dashboard', 40)->waitUntilMissing('.dash-skel', 40);
    });
});

test('Software Settings offers a website header and footer logo next to the other logos', function () {
    $this->world();

    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->superadmin())->visit('/software/setting')->waitForText('Software Logo', 40);
        $browser->assertSee('Website Header Logo')->assertSee('Website Footer Logo')->assertSee('Login Page Logo')->assertSee('Email Logo');
        $this->assertNoBrowserErrors($browser);
    });
});
