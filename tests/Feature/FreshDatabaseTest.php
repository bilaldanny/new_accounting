<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Fresh-database proof (integration checkpoint, part 2). RefreshDatabase runs every migration file, in filename order, on an empty
 * sqlite database, so reaching the first assertion already proves "migrate:fresh" works end to end.
 */
it('runs every migration file on an empty database, in filename order', function () {
    $files = collect(glob(database_path('migrations/*.php')))->map(fn (string $path) => basename($path, '.php'))->values();
    $ran = DB::table('migrations')->orderBy('id')->pluck('migration');

    expect($files)->not->toBeEmpty();
    expect($ran->all())->toBe($files->sort()->values()->all());
});

it('has well formed, unique migration names', function () {
    $names = collect(glob(database_path('migrations/*.php')))->map(fn (string $path) => basename($path, '.php'));

    foreach ($names as $name) {
        expect($name)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/');
    }

    // both are anonymous classes with different timestamps (the second adds the wave 7 approval pages), so they cannot collide
    $reviewedDuplicates = ['add_approval_center_menus'];
    $duplicates = $names->map(fn (string $name) => substr($name, 18))->duplicates()->values()->diff($reviewedDuplicates);
    expect($duplicates->all())->toBe([], 'two migrations share a name: '.$duplicates->implode(', '));
});

it('does not leave pending migrations after a fresh run', function () {
    $this->artisan('migrate:status', ['--pending' => true])->assertSuccessful();
    expect(DB::table('migrations')->count())->toBe(count(glob(database_path('migrations/*.php'))));
});

it('accepts the default seeder on a fresh database', function () {
    $this->seed();

    expect(DB::table('users')->where('email', 'test@example.com')->exists())->toBeTrue();
});

/**
 * The tenant boundary is applied per model through `visibleToCurrentUser` (and the controllers' scopeCompanyId), not by a global scope.
 * This is a ratchet: a model whose table has company_id must either carry that scope or be on the list below on purpose, so a new
 * tenant table cannot be added without somebody deciding how it is isolated.
 */
it('knows how every company_id model is isolated', function () {
    $withScope = [];
    $withoutScope = [];

    foreach (glob(app_path('Models/*.php')) as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $table = (new $class)->getTable();

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'company_id')) {
            continue;
        }

        method_exists($class, 'scopeVisibleToCurrentUser') ? $withScope[] = $class : $withoutScope[] = $class;
    }

    expect($withScope)->not->toBeEmpty();

    $known = require __DIR__.'/fixtures/tenant_models_without_scope.php';
    $unknown = array_values(array_diff($withoutScope, $known));

    expect($unknown)->toBe([], 'company_id models with no visibleToCurrentUser scope and not on the reviewed list: '.implode(', ', $unknown));
});
