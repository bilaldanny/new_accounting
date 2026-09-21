<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The record of one database backup (see App\Services\DatabaseBackups). The dump file is
 * `config('database_backup.directory')/{file_name}`; a `failed` row has no file.
 */
class DatabaseBackup extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'file_name',
        'status',
        'size_bytes',
        'error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function path(): string
    {
        return rtrim((string) config('database_backup.directory'), '/\\').DIRECTORY_SEPARATOR.$this->file_name;
    }
}
