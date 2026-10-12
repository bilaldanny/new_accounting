<?php

namespace App\Services\System;

use App\Models\DatabaseBackup;
use App\Services\DatabaseBackups;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Two additions to the database backups, for the superadmin only:
 *
 *  - copy a finished backup to a remote disk: any filesystem disk the platform has configured (S3, an S3-compatible store, an
 *    SFTP or a Google Drive disk, whichever adapter package is installed) named in `system_settings`; the local and public disks
 *    are refused so a "remote" copy is never just another folder on this server;
 *  - restore a backup over the live database: it needs the typed confirmation `RESTORE <file name>`, makes a fresh backup of the
 *    current state first and stops if that fails, and runs the `mysql` client with the decompressed dump. The runner is injectable.
 */
class BackupTools
{
    public const REMOTE_SETTING = 'backup_remote_disk';

    public function remoteDisk(): ?string
    {
        $name = DB::table('system_settings')->where('key', self::REMOTE_SETTING)->value('value');

        return $name === null || $name === '' ? null : (string) $name;
    }

    /**
     * @return list<string>
     */
    public function availableDisks(): array
    {
        return collect(array_keys((array) config('filesystems.disks')))->reject(fn (string $disk): bool => in_array($disk, ['local', 'public'], true))->values()->all();
    }

    public function chooseDisk(?string $disk): void
    {
        if ($disk !== null && $disk !== '' && ! in_array($disk, $this->availableDisks(), true)) {
            throw new RuntimeException('Choose one of the configured remote disks: '.implode(', ', $this->availableDisks()).'.');
        }

        DB::table('system_settings')->updateOrInsert(['key' => self::REMOTE_SETTING], ['value' => $disk ?: null, 'updated_at' => now(), 'created_at' => now()]);
    }

    /**
     * @return array{ok: bool, path: string|null, error: string|null}
     */
    public function copyToRemote(DatabaseBackup $backup): array
    {
        $disk = $this->remoteDisk();

        if ($disk === null) {
            return ['ok' => false, 'path' => null, 'error' => 'No remote backup disk is chosen.'];
        }

        if ($backup->status !== DatabaseBackup::STATUS_COMPLETED || ! is_file($backup->path())) {
            return ['ok' => false, 'path' => null, 'error' => 'The backup file is not available.'];
        }

        try {
            $stream = fopen($backup->path(), 'rb');
            $path = 'database-backups/'.$backup->file_name;
            $stored = Storage::disk($disk)->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $stored) {
                return ['ok' => false, 'path' => null, 'error' => 'The remote disk refused the file.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'path' => null, 'error' => mb_substr($e->getMessage(), 0, 250)];
        }

        return ['ok' => true, 'path' => $path, 'error' => null];
    }

    /**
     * @param  (callable(string): void)|null  $runner  receives the path of the plain SQL file; the default runs the mysql client
     * @return array{ok: bool, safety_backup: string|null, error: string|null}
     */
    public function restore(DatabaseBackup $backup, string $confirmation, ?int $userId, ?callable $runner = null): array
    {
        if (trim($confirmation) !== 'RESTORE '.$backup->file_name) {
            return ['ok' => false, 'safety_backup' => null, 'error' => 'Type RESTORE followed by the file name to confirm.'];
        }

        if ($backup->status !== DatabaseBackup::STATUS_COMPLETED || ! is_file($backup->path())) {
            return ['ok' => false, 'safety_backup' => null, 'error' => 'The backup file is not available.'];
        }

        $safety = app(DatabaseBackups::class)->create($userId);

        if ($safety->status !== DatabaseBackup::STATUS_COMPLETED) {
            return ['ok' => false, 'safety_backup' => null, 'error' => 'The safety backup of the current data failed, so nothing was restored: '.$safety->error];
        }

        $plain = $backup->path().'.restore.sql';

        try {
            $in = gzopen($backup->path(), 'rb');
            $out = fopen($plain, 'wb');

            while (! gzeof($in)) {
                fwrite($out, (string) gzread($in, 1024 * 1024));
            }

            gzclose($in);
            fclose($out);
            ($runner ?? $this->mysqlRunner(...))($plain);
        } catch (Throwable $e) {
            return ['ok' => false, 'safety_backup' => $safety->file_name, 'error' => mb_substr($e->getMessage(), 0, 300)];
        } finally {
            @unlink($plain);
        }

        return ['ok' => true, 'safety_backup' => $safety->file_name, 'error' => null];
    }

    private function mysqlRunner(string $sqlFile): void
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Restoring needs a MySQL or MariaDB database.');
        }

        $binary = (string) (config('database_backup.restore_binary') ?: str_replace('mysqldump', 'mysql', (string) config('database_backup.dump_binary')));
        $process = new Process([$binary, '--host='.$connection['host'], '--port='.$connection['port'], '--user='.$connection['username'], $connection['database']], null, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], null, (float) config('database_backup.timeout_seconds'));
        $process->setInput(fopen($sqlFile, 'rb'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'The mysql client failed.');
        }
    }
}
