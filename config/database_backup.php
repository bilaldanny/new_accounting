<?php

/**
 * The newest mysqldump of a Laragon install (Windows dev / small-office servers), or null: `mysqldump`
 * is usually not on the PATH there. Sorted by version so the newest client, which can dump older servers,
 * wins.
 */
$laragonDump = (function (): ?string {
    $found = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [];

    natsort($found);

    return $found === [] ? null : end($found);
})();

return [

    /*
    |--------------------------------------------------------------------------
    | Database backups (Settings > Backup)
    |--------------------------------------------------------------------------
    |
    | A backup is a `mysqldump` of the whole database (every company), gzipped into `directory`.
    | Only MySQL / MariaDB connections are supported. MYSQLDUMP_PATH in .env names the binary; without
    | it a Laragon install's newest mysqldump is used, else `mysqldump` from the PATH.
    |
    */

    'dump_binary' => env('MYSQLDUMP_PATH') ?: ($laragonDump ?? 'mysqldump'),

    'directory' => storage_path('app/backups'),

    'timeout_seconds' => (int) env('DB_BACKUP_TIMEOUT', 900),

];
