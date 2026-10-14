<?php

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * A superadmin opens a page that works for any company: there is a named company drop-down instead of an id box, opening the page
 * shows no "Choose a company" pop-up, and choosing a company loads that company's rows.
 */
test('the company picker replaces the id box and loads the chosen company', function () {
    $world = $this->world();
    // with exactly one company the picker chooses it by itself; a second one makes the choice the user's
    DB::table('companies')->insert(['code' => 'PICK-2', 'name' => 'Second Picker Company', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Project::query()->create(['company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'code' => 'PICK-1', 'name' => 'Picker project', 'status' => 'active']);

    $this->browse(function (Browser $browser) use ($world) {
        $this->signIn($browser, $this->superadmin())->visit('/project')->waitFor('[data-test="company-picker"]', 40);

        // no id box, no pop-up, a hint instead
        $browser->assertMissing('input[aria-label="Company id"]')->waitForText('Choose a company to see its data.', 20);
        $browser->pause(800);
        expect($browser->driver->executeScript('return document.body.innerText.includes("Choose a company.");'))->toBeFalse();

        // choosing the company fills the page
        $browser->waitUsing(20, 1, fn (): bool => (int) $browser->driver->executeScript('return document.querySelectorAll("#company-picker-select option").length;') > 1);
        $browser->select('#company-picker-select', (string) $world['company_id'])->waitForText('PICK-1', 20)->assertSee('Picker project');

        $this->assertNoBrowserErrors($browser);
    });
});
