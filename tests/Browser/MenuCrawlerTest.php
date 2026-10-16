<?php

use App\Models\Role;
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
    $limit = (int) (getenv('CRAWL_LIMIT') ?: 0);
    $wanted = array_filter(explode(',', (string) (getenv('CRAWL_ROLES') ?: 'superadmin,companyadmin,limited')));

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

        $this->browse(function (Browser $browser) use ($roleName, $user, $pages, $limit, &$results) {
            $this->signIn($browser, $user)->resize(1440, 900);
            $count = 0;

            foreach ($pages as $path) {
                if ($limit > 0 && ++$count > $limit) {
                    break;
                }

                $browser->driver->manage()->getLog('browser');
                $browser->visit($path);
                usleep(1800000);

                $probe = (array) $browser->driver->executeScript(<<<'JS'
                    const nav = performance.getEntriesByType('navigation')[0];
                    const text = (document.body.innerText || '').trim();
                    return {
                        status: nav ? nav.responseStatus : 0,
                        blank: text.length < 20,
                        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
                        spinner: !!document.querySelector('.spinner-border:not(.d-none), [aria-busy="true"]'),
                        error: /(^|\s)(403|404|419|500|503)\s*(\||-|:)?\s*(forbidden|not found|server error|page expired|service unavailable)/i.test(text.slice(0, 400)),
                    };
JS);
                usleep(2500000);
                $stillSpinning = (bool) $browser->driver->executeScript('return !!document.querySelector(\'.spinner-border:not(.d-none), [aria-busy="true"]\');');
                $console = collect($browser->driver->manage()->getLog('browser'))
                    ->filter(fn (array $entry): bool => in_array($entry['level'], ['SEVERE', 'WARNING'], true) && ! str_contains($entry['message'], 'favicon') && ! str_contains($entry['message'], '/api/notifications') && ! str_contains($entry['message'], 'Failed to load resource'))
                    ->map(fn (array $entry): string => mb_substr($entry['message'], 0, 160))->values()->all();

                $status = (int) ($probe['status'] ?? 0);
                $denied = $roleName === 'limited' && ($status === 403 || ($probe['error'] ?? false));
                $failed = ! $denied && ($status >= 400 || ($probe['error'] ?? false) || $console !== [] || ($probe['blank'] ?? false) || ($probe['overflow'] ?? false) || $stillSpinning);
                $shot = '';

                if ($failed) {
                    $name = 'crawl-'.$roleName.'-'.preg_replace('/[^a-z0-9]+/i', '-', trim($path, '/'));
                    $browser->screenshot($name);
                    $shot = "tests/Browser/screenshots/{$name}.png";
                }

                $results[] = [$roleName, $path, $denied ? 'denied' : ($failed ? 'FAIL' : 'ok'), $status, count($console), $probe['overflow'] ? 'yes' : '', $probe['blank'] ? 'yes' : '', $stillSpinning ? 'yes' : '', $shot, $console[0] ?? ''];
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
