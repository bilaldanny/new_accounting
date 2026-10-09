<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Browser tests run against a real server (`php artisan serve` on port 8099) with its own throw-away SQLite file
 * (database/dusk.sqlite), migrated fresh once per run, so they never touch the development database. They need the built
 * front end (`npm run build`); a running `npm run dev` (public/hot) is set aside for the run and put back afterwards.
 *
 * Run them with `php artisan dusk` (or `composer test:browser`); `composer test:all` runs the Pest suite and then these.
 */
abstract class DuskTestCase extends BaseTestCase
{
    public const PORT = 8099;

    /**
     * @var array<string, mixed>|null
     */
    protected static ?array $world = null;

    protected static ?Process $server = null;

    protected static bool $prepared = false;

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::$prepared) {
            static::$prepared = true;

            if (! is_file(static::projectPath('public/build/manifest.json'))) {
                throw new RuntimeException('The front end is not built: run `npm run build` before the browser tests.');
            }

            static::setViteHotFileAside();
            static::freshDatabase();
            static::startServer();

            register_shutdown_function(function (): void {
                static::$server?->stop(3);
                static::restoreViteHotFile();
            });
        }

        // Dusk stops ChromeDriver after every test class, so it is started again for each one.
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected static function projectPath(string $path = ''): string
    {
        return dirname(__DIR__).($path === '' ? '' : DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
    }

    protected static function databasePath(): string
    {
        return static::projectPath('database/dusk.sqlite');
    }

    /**
     * @return array<string, string>
     */
    protected static function serverEnvironment(): array
    {
        return [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'APP_URL' => 'http://127.0.0.1:'.self::PORT,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => static::databasePath(),
            'DB_URL' => '',
            'SESSION_DRIVER' => 'file',
            'SESSION_DOMAIN' => 'null',
            'SESSION_SECURE_COOKIE' => 'false',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BCRYPT_ROUNDS' => '4',
            'SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:'.self::PORT,
        ];
    }

    protected static function freshDatabase(): void
    {
        @unlink(static::databasePath());
        touch(static::databasePath());

        $migrate = new Process([PHP_BINARY, '-d', 'memory_limit=-1', 'artisan', 'migrate:fresh', '--force'], static::projectPath(), static::serverEnvironment(), null, 600);
        $migrate->run();

        if (! $migrate->isSuccessful()) {
            throw new RuntimeException('Could not migrate the browser test database: '.$migrate->getErrorOutput().$migrate->getOutput());
        }

        // The server caches menu permissions per role id in files; a fresh database restarts the ids, so an old run's cache must go.
        (new Process([PHP_BINARY, 'artisan', 'cache:clear'], static::projectPath(), static::serverEnvironment(), null, 120))->run();
    }

    protected static function startServer(): void
    {
        static::$server = new Process([PHP_BINARY, '-d', 'memory_limit=-1', 'artisan', 'serve', '--host=127.0.0.1', '--port='.self::PORT, '--no-reload'], static::projectPath(), static::serverEnvironment(), null, null);
        static::$server->start();

        $deadline = microtime(true) + 30;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::PORT, $errorCode, $errorMessage, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(250_000);
        }

        throw new RuntimeException('The browser test server did not start: '.static::$server->getErrorOutput());
    }

    protected static function setViteHotFileAside(): void
    {
        $hot = static::projectPath('public/hot');
        $aside = static::projectPath('public/hot.dusk-aside');

        if (is_file($hot)) {
            @rename($hot, $aside);
        }
    }

    protected static function restoreViteHotFile(): void
    {
        $aside = static::projectPath('public/hot.dusk-aside');

        if (is_file($aside) && ! is_file(static::projectPath('public/hot'))) {
            @rename($aside, static::projectPath('public/hot'));
        }
    }

    /**
     * Seeds the company the browser tests work in once per run: a branch, a customer, a product, and a "Dusk Admin" role that
     * may open every page under test, with one user in it.
     *
     * @return array<string, mixed>
     */
    protected function world(): array
    {
        if (static::$world !== null) {
            return static::$world;
        }

        $scope = seedSellScope();

        $role = Role::query()->create(['name' => 'Dusk Admin', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'is_active' => true]);

        foreach (static::pagePaths() as $path) {
            grantMenuPermission((int) $role->id, $path);
        }

        $user = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'first_name' => 'Dusk', 'last_name' => 'Admin']);

        return static::$world = $scope + ['role_id' => (int) $role->id, 'user_id' => (int) $user->id, 'user' => $user];
    }

    /**
     * The menu paths the Dusk Admin role is granted: every page the browser tests open, with the actions on it.
     *
     * @return list<string>
     */
    protected static function pagePaths(): array
    {
        $bases = [
            '/dashboard', '/leads', '/leadsources', '/pipeline', '/pipelinestages', '/opportunities', '/activities', '/crmanalytics',
            '/webhooks', '/apilogs', '/customersubscriptionplans', '/customersubscriptions', '/customersubscriptioninvoices', '/customersubscriptionanalytics',
            '/fixedasset', '/assetcategory', '/depreciation', '/costcenter', '/budget', '/assetapproval', '/bankreconciliation', '/landedcost', '/bulkpriceupdate',
            '/report/warehouse-stock', '/report/stock-movement-history', '/report/warehouse-usage', '/report/serial-traceability', '/report/batch-expiry',
            '/report/budget-vs-actual', '/report/cost-center-analysis', '/warehouse', '/warehouselocation', '/stocktracking', '/taxexemption', '/tax',
            '/purchase', '/sell', '/supplier', '/customer', '/product', '/company/setting', '/purchasereturn/approval', '/stockadjustment/approval',
            '/stocktransfer/approval', '/cashcollection/approval', '/pricelist/approval', '/creditlimit/approval', '/contacts/duplicates', '/auditlogs', '/posshift',
            '/chart-of-account', '/role', '/journalentry', '/journalentry/approval', '/expense', '/sell/pos', '/cashcollection', '/cashcollection/reverse',
        ];

        $paths = [];

        foreach ($bases as $base) {
            array_push($paths, $base, $base.'/add', $base.'/:id/edit', $base.'/delete', $base.'/restore', $base.'/view');
        }

        array_push($paths, '/stocktracking/edit', '/fixedasset/acquire', '/fixedasset/dispose', '/bankreconciliation/match', '/bankreconciliation/reconcile', '/depreciation/run');

        return array_values(array_unique($paths));
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1600,1000',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            '--no-sandbox',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()
                ->setCapability(ChromeOptions::CAPABILITY, $options)
                ->setCapability('goog:loggingPrefs', ['browser' => 'ALL'])
        );
    }

    /**
     * The screenshot and console log file names come from the test name, which contains quotes for a dataset: a file name on
     * Windows cannot, and a failing test would then hide its real failure behind a file error.
     */
    protected function getCallerName(): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', parent::getCallerName());
    }

    /**
     * Fails when the page logged a JavaScript error or one of our own requests failed (a 4xx / 5xx from this server). Requests
     * to other hosts (web fonts, maps) are ignored: the test machine may be offline.
     */
    protected function assertNoBrowserErrors(Browser $browser): void
    {
        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => $entry['level'] === 'SEVERE')
            ->map(fn (array $entry): string => (string) $entry['message'])
            ->reject(fn (string $message): bool => str_contains($message, 'favicon') || (str_contains($message, 'Failed to load resource') && ! str_contains($message, '127.0.0.1')))
            ->values()
            ->all();

        $this->assertSame([], $errors, 'The page logged browser errors.');
    }

    /**
     * Signs the given user in on a clean slate: the previous user is signed out and the browser console log of earlier pages is
     * thrown away, so a request an earlier test left pending cannot be blamed on this one.
     */
    protected function signIn(Browser $browser, User $user): Browser
    {
        $browser->logout()->loginAs($user);
        $browser->driver->manage()->getLog('browser');

        return $browser;
    }

    /**
     * Signs the browser in as the Dusk Admin user of the seeded company.
     */
    protected function adminUser(): User
    {
        return User::query()->findOrFail($this->world()['user_id']);
    }

    protected function superadmin(): User
    {
        return User::query()->findOrFail(1);
    }

    /**
     * Counts the rows of a table, for the "create" assertions that check the database after a browser action.
     */
    protected function rows(string $table, array $where = []): int
    {
        return DB::table($table)->where($where)->count();
    }
}
