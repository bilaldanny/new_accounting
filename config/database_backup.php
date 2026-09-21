<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database backups (Settings > Backup)
    |--------------------------------------------------------------------------
    |
    | A backup is a `mysqldump` of the whole database (every company), gzipped into `directory`.
    | Only MySQL / MariaDB connections are supported. Set MYSQLDUMP_PATH when `mysqldump` is not on the
    | server's PATH (for example the Laragon bin folder on Windows).
    |
    */

    'dump_binary' => env('MYSQLDUMP_PATH', 'mysqldump'),

    'directory' => storage_path('app/backups'),

    'timeout_seconds' => (int) env('DB_BACKUP_TIMEOUT', 900),

];
