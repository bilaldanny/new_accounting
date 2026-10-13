<?php

use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The optional pickers in a real browser: a project select on the sale, purchase, expense and journal forms when the company has
 * an open project, and the foreign currency column of the journal form only when multi-currency vouchers are on.
 */
test('forms show the project picker, and the journal shows the currency column only when multi-currency is on', function () {
    $user = $this->adminUser();
    $companyId = (int) $user->company_id;
    Project::query()->create(['company_id' => $companyId, 'branch_id' => $user->branch_id, 'code' => 'DUSK-P', 'name' => 'Dusk project', 'status' => 'active']);

    $this->browse(function (Browser $browser) use ($user, $companyId) {
        $this->signIn($browser, $user)
            ->visit('/journalentry/add')->waitFor('[aria-label="Project"]', 60)
            ->assertMissing('[aria-label="Foreign currency"]')
            ->visit('/sell/add')->waitFor('#project-picker-select', 60)
            ->visit('/purchase/add')->waitFor('#project-picker-select', 60)
            ->visit('/expense/add')->waitFor('#project-picker-select', 60);

        $eur = Currency::query()->create(['currency_name' => 'Euro', 'code' => 'EUR', 'symbol' => 'E', 'is_active' => true]);
        $base = Currency::query()->create(['currency_name' => 'Rupee', 'code' => 'PKR', 'symbol' => 'Rs', 'is_active' => true]);
        CurrencyRate::query()->create(['company_id' => $companyId, 'currency_id' => $eur->id, 'rate' => 0.9]);
        DB::table('company_settings')->updateOrInsert(['company_id' => $companyId], ['currency_id' => $base->id, 'multi_currency_vouchers' => 1, 'updated_at' => now(), 'created_at' => now()]);

        $browser->visit('/journalentry/add')->waitFor('[aria-label="Foreign currency"]', 60)->screenshot('checkpoint-journal-currency');
        $this->assertNoBrowserErrors($browser);
    });
});
