<?php

use App\Models\DatabaseBackup;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseBackups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Every test writes into its own temporary folder, never into storage/app/backups.
 */
beforeEach(function () {
    $this->bkDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'acc-backup-test-'.uniqid();
    config(['database_backup.directory' => $this->bkDirectory]);
});

afterEach(function () {
    File::deleteDirectory($this->bkDirectory);
});

/**
 * Replaces the dump step: the service writes a small SQL file instead of calling mysqldump, or fails with
 * the reason set by bkDumpFails(). (A controller instance is kept by its route across requests, so the
 * behaviour is switched on the class, not by binding a new service mid-test.)
 */
class BkFakeBackups extends DatabaseBackups
{
    public static ?string $failWith = null;

    protected function runDump(string $target): void
    {
        if (self::$failWith !== null) {
            file_put_contents($target, 'half a dump');

            throw new RuntimeException(self::$failWith);
        }

        file_put_contents($target, implode(PHP_EOL, ['-- fake dump', 'CREATE TABLE t (id int);', 'INSERT INTO t VALUES (1);', '']));
    }
}

function bkFakeDump(): void
{
    BkFakeBackups::$failWith = null;
    app()->bind(DatabaseBackups::class, BkFakeBackups::class);
}

function bkDumpFails(?string $reason): void
{
    BkFakeBackups::$failWith = $reason;
}

/**
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function bkStaff(array $paths = []): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role);
}

test('creating a backup writes a gzipped dump and records it', function () {
    bkFakeDump();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/backups')->assertSuccessful()->assertJsonPath('message', 'Backup created');

    $backup = DatabaseBackup::query()->firstOrFail();

    expect($backup->status)->toBe('completed')
        ->and($backup->file_name)->toMatch('/^backup-\d{8}-\d{6}-[a-z0-9]{4}\.sql\.gz$/')
        ->and($backup->created_by)->toBe($superadmin->id)
        ->and($backup->error)->toBeNull()
        ->and($backup->size_bytes)->toBe(filesize($backup->path()))
        ->and($response->json('id'))->toBe($backup->id)
        ->and(gzdecode((string) file_get_contents($backup->path())))->toContain('CREATE TABLE t')
        ->and(glob($this->bkDirectory.'/*.tmp'))->toBe([]);
});

test('two backups get two files', function () {
    bkFakeDump();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/backups')->assertSuccessful();
    $this->postJson('/api/backups')->assertSuccessful();

    expect(DatabaseBackup::query()->pluck('file_name')->unique()->count())->toBe(2)
        ->and(glob($this->bkDirectory.'/*.sql.gz'))->toHaveCount(2);
});

test('a failed dump answers 500 with the reason, keeps a failed row and leaves no file behind', function () {
    bkFakeDump();
    bkDumpFails('mysqldump: Got error: 1045 Access denied');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/backups')->assertStatus(500)->assertJsonPath('errormessage', 'mysqldump: Got error: 1045 Access denied');

    $backup = DatabaseBackup::query()->firstOrFail();

    expect($backup->status)->toBe('failed')
        ->and($backup->error)->toContain('Access denied')
        ->and($backup->size_bytes)->toBeNull()
        ->and(glob($this->bkDirectory.'/*'))->toBe([]);
});

test('the real dump step refuses a database that is not MySQL or MariaDB', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/backups')->assertStatus(500)->assertJsonPath('errormessage', 'Backups need a MySQL or MariaDB database (this one is sqlite).');

    expect(DatabaseBackup::query()->firstOrFail()->status)->toBe('failed');
});

test('the list shows the newest backup first with its size, status and whether the file is there', function () {
    bkFakeDump();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/backups')->assertSuccessful();
    $this->postJson('/api/backups')->assertSuccessful();
    $first = DatabaseBackup::query()->orderBy('id')->firstOrFail();
    unlink($first->path());
    bkDumpFails('boom');
    $this->postJson('/api/backups')->assertStatus(500);

    $rows = collect($this->getJson('/api/backups')->assertSuccessful()->json('data.data'));

    expect($rows->pluck('status')->all())->toBe(['failed', 'completed', 'completed'])
        ->and($rows->pluck('file_exists')->all())->toBe([false, true, false])
        ->and($rows[1]['size_bytes'])->toBeGreaterThan(0)
        ->and($rows[0]['error'])->toBe('boom');

    $this->getJson('/api/backups?show_record=1')->assertJsonCount(1, 'data.data');
    $this->getJson('/api/backups?show_record=0')->assertSuccessful();
    $this->getJson('/api/backups?show_record=99999')->assertSuccessful();
});

test('a backup can be downloaded, and only a completed one whose file exists', function () {
    bkFakeDump();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/backups')->assertSuccessful();
    $backup = DatabaseBackup::query()->firstOrFail();

    $response = $this->get('/api/backups/'.$backup->id.'/download')->assertSuccessful();

    expect($response->headers->get('content-disposition'))->toContain($backup->file_name)
        ->and(gzdecode($response->streamedContent() ?: (string) $response->getContent()))->toContain('CREATE TABLE t');

    unlink($backup->path());
    $this->getJson('/api/backups/'.$backup->id.'/download')->assertNotFound();
    $this->getJson('/api/backups/999999/download')->assertNotFound();

    bkDumpFails('boom');
    $this->postJson('/api/backups')->assertStatus(500);
    $failed = DatabaseBackup::query()->where('status', 'failed')->firstOrFail();

    $this->getJson('/api/backups/'.$failed->id.'/download')->assertNotFound();
});

test('deleting a backup removes its file and its row, and a missing file is no problem', function () {
    bkFakeDump();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/backups')->assertSuccessful();
    $this->postJson('/api/backups')->assertSuccessful();
    [$first, $second] = DatabaseBackup::query()->orderBy('id')->get()->all();
    unlink($second->path());

    $this->deleteJson('/api/backups/'.$first->id)->assertSuccessful()->assertJson(['message' => 'Successfully Deleted']);
    $this->deleteJson('/api/backups/'.$second->id)->assertSuccessful();
    $this->deleteJson('/api/backups/999999')->assertSuccessful();

    expect(DatabaseBackup::query()->count())->toBe(0)
        ->and(glob($this->bkDirectory.'/*'))->toBe([]);
});

test('nobody but the superadmin can use backups, whatever permissions their role has', function () {
    bkFakeDump();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/backups')->assertSuccessful();
    $backup = DatabaseBackup::query()->firstOrFail();

    Sanctum::actingAs(bkStaff(['/backup', '/backup/create', '/backup/download', '/backup/delete']));

    $this->getJson('/api/backups')->assertForbidden();
    $this->postJson('/api/backups')->assertForbidden();
    $this->getJson('/api/backups/'.$backup->id.'/download')->assertForbidden();
    $this->deleteJson('/api/backups/'.$backup->id)->assertForbidden();

    expect(DatabaseBackup::query()->count())->toBe(1)
        ->and(is_file($backup->path()))->toBeTrue();
});

test('the backup api requires authentication', function () {
    $this->getJson('/api/backups')->assertUnauthorized();
    $this->postJson('/api/backups')->assertUnauthorized();
    $this->getJson('/api/backups/1/download')->assertUnauthorized();
    $this->deleteJson('/api/backups/1')->assertUnauthorized();
});

test('the backup page is for the superadmin only', function () {
    $this->get(route('backup'))->assertRedirect();

    $this->actingAs(User::query()->findOrFail(1))->get(route('backup'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('backup/index'));

    $this->actingAs(bkStaff(['/backup']))->get(route('backup'))->assertForbidden();
});
