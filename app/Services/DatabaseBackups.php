<?php

namespace App\Services;

use App\Models\DatabaseBackup;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Makes and removes database backups: a `mysqldump` of the whole database (single transaction, routines
 * and triggers included) written next to the app and gzipped. The database password is handed to the
 * process through the environment, never on the command line.
 *
 * Restoring is deliberately not offered: it overwrites live data, so a downloaded file is restored by hand
 * with the `mysql` client on the server.
 *
 * `create()` records a row first (`running`), then `completed` with the file size, or `failed` with the
 * reason and no file left behind. The dump step is `runDump()` so tests can replace it.
 */
class DatabaseBackups
{
    public function create(?int $userId = null): DatabaseBackup
    {
        $directory = rtrim((string) config('database_backup.directory'), '/\\');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the backup directory.');
        }

        $backup = DatabaseBackup::query()->create([
            'file_name' => 'backup-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)).'.sql.gz',
            'status' => DatabaseBackup::STATUS_RUNNING,
            'created_by' => $userId,
        ]);

        $raw = $backup->path().'.tmp';

        try {
            $this->runDump($raw);
            $this->compress($raw, $backup->path());

            $backup->update(['status' => DatabaseBackup::STATUS_COMPLETED, 'size_bytes' => filesize($backup->path())]);
        } catch (Throwable $e) {
            $backup->update(['status' => DatabaseBackup::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 1000)]);

            @unlink($backup->path());
        } finally {
            @unlink($raw);
        }

        return $backup->refresh();
    }

    /**
     * Removes the row and its file (a missing file is not an error).
     */
    public function delete(DatabaseBackup $backup): void
    {
        if (is_file($backup->path())) {
            unlink($backup->path());
        }

        $backup->delete();
    }

    /**
     * Writes a plain SQL dump of the default connection to $target.
     *
     * @throws RuntimeException when the driver is not MySQL / MariaDB or `mysqldump` fails
     */
    protected function runDump(string $target): void
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Backups need a MySQL or MariaDB database (this one is '.($connection['driver'] ?? 'unknown').').');
        }

        $process = new Process([
            (string) config('database_backup.dump_binary'),
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--result-file='.$target,
            $connection['database'],
        ], null, ['MYSQL_PWD' => (string) ($connection['password'] ?? '')], null, (float) config('database_backup.timeout_seconds'));

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'mysqldump failed.');
        }

        if (! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('mysqldump produced no output.');
        }
    }

    private function compress(string $source, string $destination): void
    {
        $input = fopen($source, 'rb');
        $output = gzopen($destination, 'wb6');

        if ($input === false || $output === false) {
            throw new RuntimeException('Cannot write the backup file.');
        }

        while (! feof($input)) {
            gzwrite($output, (string) fread($input, 1024 * 1024));
        }

        fclose($input);
        gzclose($output);
    }
}
