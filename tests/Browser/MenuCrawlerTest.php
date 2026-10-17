<?php

use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Dusk\Browser;

/**
 * The crawler: opens every menu page that has a screen, as the platform superadmin, as a company admin and as a limited role, and writes
 * one table: page | status | console errors | overflow | blank | spinner | screenshot (docs/page-crawl.md).
 *
 * It is slow (a few seconds a page, three roles), so it runs only on request: CRAWL=1, with the inline sqlite environment of the other
 * Dusk runs. CRAWL_ROLES=superadmin,companyadmin,limited narrows the roles; CRAWL_LIMIT=50 stops after that many pages a role.
 *
 * A failure is a page that answers 4xx / 5xx for a role that holds it, throws a console error, comes up blank, scrolls sideways or
 * keeps a spinner. A 403 for the limited role is the expected denial and is listed as `denied`, not as a failure.
 */
function crawlPages(): array
{
    $pages = [];

    foreach (DB::table('menus')->where('is_active', 1)->where('is_hidden', 0)->where('route_path', '!=', '')->whereNotNull('route_path')->orderBy('route_path')->pluck('route_path') as $path) {
        $path = (string) $path;

        if (str_contains($path, ':') || str_contains($path, '{') || str_starts_with($path, '/api')) {
            continue;
        }

        $uri = ltrim($path, '/');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && $route->uri() === $uri) {
                $pages[$path] = true;

                break;
            }
        }
    }

    return array_keys($pages);
}

test('crawl every menu page for three roles', function () {
    if (getenv('CRAWL') !== '1') {
        $this->markTestSkipped('Only runs on request (CRAWL=1).');
    }

    $world = $this->world();
    $pages = crawlPages();

    if (getenv('CRAWL_ONLY')) {
        $pages = array_values(array_intersect($pages, explode(',', (string) getenv('CRAWL_ONLY'))));
    }
    $limit = (int) (getenv('CRAWL_LIMIT') ?: 0);
    $wanted = array_filter(explode(',', (string) (getenv('CRAWL_ROLES') ?: 'superadmin,companyadmin,limited')));

    // the company admin of this run holds every company-level menu (the platform's own pages stay out), like a live company admin
    $adminRole = (int) $this->adminUser()->role_id;
    $platform = '#^/(subscriptionplans|tenants|subscriptioninvoices|subscriptionbilling|saasadmin|software/setting|company/add|company/delete|company/restore|menu|blogposts|coupons|webhooks|backup)#';

    foreach (DB::table('menus')->where('is_active', 1)->get(['id', 'route_path']) as $menu) {
        if (preg_match($platform, (string) $menu->route_path) === 1 || DB::table('permissions')->where('role_id', $adminRole)->where('menu_id', $menu->id)->exists()) {
            continue;
        }

        DB::table('permissions')->insert(['company_id' => null, 'branch_id' => null, 'department_id' => null, 'role_id' => $adminRole, 'menu_id' => $menu->id, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::forget("{$key}:{$adminRole}");
    }

    // a limited role: the dashboard and the customer list only
    $limited = Role::query()->firstOrCreate(['name' => 'crawl-limited', 'company_id' => $world['company_id']], ['is_active' => true]);

    foreach (DB::table('menus')->whereIn('route_path', ['/dashboard', '/customer'])->pluck('id') as $menuId) {
        DB::table('permissions')->updateOrInsert(['role_id' => $limited->id, 'menu_id' => $menuId], ['status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $limitedUser = createStaffUserForRole($limited, ['company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'email_verified_at' => now()]);
    $users = ['superadmin' => $this->superadmin(), 'companyadmin' => $this->adminUser(), 'limited' => $limitedUser];
    $results = [];
    @mkdir(base_path('docs/page-crawl'), 0777, true);

    foreach ($users as $roleName => $user) {
        if (! in_array($roleName, $wanted, true)) {
            continue;
        }

        $this->browse(function (Browser $browser) use ($roleName, $user, $pages, $limit, $platform, &$results) {
            $this->signIn($browser, $user)->resize(1440, 900);
            $count = 0;

            foreach ($pages as $path) {
                if ($limit > 0 && ++$count > $limit) {
                    break;
                }

                try {
                    $step = 'log';
                    $browser->driver->manage()->getLog('browser');
                    $step = 'visit';
                    $browser->visit($path);
                    $step = 'probe';
                    usleep(1800000);

                    $js = fn (string $script): mixed => $browser->driver->executeScript($script);
                    $probe = [
                        'status' => (int) $js('var n = performance.getEntriesByType("navigation")[0]; return n ? (n.responseStatus || 0) : 0;'),
                        'blank' => (bool) $js('return (document.body.innerText || "").trim().length < 20;'),
                        'overflow' => (bool) $js('return document.documentElement.scrollWidth > document.documentElement.clientWidth + 2;'),
                        'error' => (bool) preg_match('/(403|404|419|500|503)/', (string) $js('return (document.body.innerText || "").trim().slice(0, 200);')),
                    ];                // a list may still be loading on the single threaded test server: wait for the spinner to go, up to 15 s; one that stays is stuck
                    $spinnerJs = 'return Array.from(document.querySelectorAll(".spinner-border, [aria-busy=true]")).some(function (e) { var r = e.getBoundingClientRect(); return e.offsetParent !== null && r.width > 0 && r.height > 0 && !e.closest("button, .modal:not(.show), [hidden]"); });';
                    $step = 'spin';
                    usleep(1500000);
                    $stillSpinning = (bool) $browser->driver->executeScript($spinnerJs);

                    for ($wait = 0; $stillSpinning && $wait < 15; $wait++) {
                        sleep(1);
                        $stillSpinning = (bool) $browser->driver->executeScript($spinnerJs);
                    }

                    $step = 'console';
                    $spinnerHtml = $stillSpinning ? (string) $browser->driver->executeScript('var e = Array.from(document.querySelectorAll(".spinner-border, [aria-busy=true]")).find(function (x) { var r = x.getBoundingClientRect(); return x.offsetParent !== null && r.width > 0 && r.height > 0 && !x.closest("button, .modal:not(.show), [hidden]"); }); return e ? e.outerHTML.slice(0, 140) + " IN " + (e.parentElement ? e.parentElement.className : "") : "";') : '';
                    $console = collect($browser->driver->manage()->getLog('browser'))
                        ->filter(fn (array $entry): bool => in_array($entry['level'], ['SEVERE', 'WARNING'], true) && ! str_contains($entry['message'], 'favicon') && ! str_contains($entry['message'], '/api/notifications') && ! str_contains($entry['message'], 'Failed to load resource'))
                        ->map(fn (array $entry): string => mb_substr($entry['message'], 0, 160))->values()->all();

                    $status = (int) ($probe['status'] ?? 0);
                    // the platform operator's own pages are refused to a company admin on purpose, and everything but the dashboard and customers to the limited role
                    $denied = ($roleName === 'limited' && ($status === 403 || ($probe['error'] ?? false))) || ($roleName !== 'superadmin' && $status === 403 && preg_match($platform, $path) === 1);
                    $failed = ! $denied && ($status >= 400 || ($probe['error'] ?? false) || $console !== [] || ($probe['blank'] ?? false) || ($probe['overflow'] ?? false) || $stillSpinning);
                    $shot = '';

                    if ($failed) {
                        $name = 'crawl-'.$roleName.'-'.preg_replace('/[^a-z0-9]+/i', '-', trim($path, '/'));
                        $browser->screenshot($name);
                        $shot = "tests/Browser/screenshots/{$name}.png";
                    }

                    $results[] = [$roleName, $path, $denied ? 'denied' : ($failed ? 'FAIL' : 'ok'), $status, count($console), $probe['overflow'] ? 'yes' : '', $probe['blank'] ? 'yes' : '', $stillSpinning ? 'yes' : '', $shot, $console[0] ?? ($spinnerHtml !== '' ? 'spinner: '.$spinnerHtml : '')];
                } catch (Throwable $e) {
                    // a driver error on one page (a dialog, a download, a crashed tab) is that page's failure, not the end of the crawl
                    $results[] = [$roleName, $path, 'FAIL', 0, 0, '', '', '', '', 'driver at '.($step ?? '?').': '.mb_substr((string) preg_replace('/\s+/', ' ', get_class($e).' line '.$e->getTrace()[1]['line'].' '.($e->getTrace()[2]['function'] ?? '').': '.$e->getMessage()), 0, 300)];

                    try {
                        $browser->driver->switchTo()->alert()->dismiss();
                    } catch (Throwable) {
                        // no dialog
                    }
                }
            }
        });
    }

    $lines = ['# Page crawl', '', 'Written by `tests/Browser/MenuCrawlerTest.php` (run with `CRAWL=1`). `denied` is the expected 403 of the limited role.', '',
        '| Role | Page | Result | HTTP | Console errors | Overflow | Blank | Spinner | Screenshot | First console message |', '|---|---|---|---|---|---|---|---|---|---|'];

    foreach ($results as $row) {
        $lines[] = '| '.implode(' | ', array_map(fn ($cell): string => str_replace('|', '/', (string) $cell), $row)).' |';
    }

    $failures = collect($results)->where(2, 'FAIL')->count();
    file_put_contents(base_path('docs/page-crawl.md'), implode("\n", $lines)."\n\n".count($results)." page visits, {$failures} failures.\n");

    expect($failures)->toBe(0, "the crawl found {$failures} failing pages: see docs/page-crawl.md");
});
