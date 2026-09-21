<?php

namespace App\Http\Controllers;

use App\Models\DatabaseBackup;
use App\Services\DatabaseBackups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Settings > Backup: make, download and delete database backups.
 *
 * A backup holds the data of EVERY company, so this is for the superadmin only, whatever menu
 * permissions a role has been given.
 */
class BackupController extends Controller
{
    public function __construct(private readonly DatabaseBackups $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->superadminOnly();

        $perPage = min(max($request->integer('show_record', 10), 1), 100);

        $backups = DatabaseBackup::query()
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (DatabaseBackup $backup): array => [
                'id' => $backup->id,
                'file_name' => $backup->file_name,
                'status' => $backup->status,
                'size_bytes' => $backup->size_bytes,
                'error' => $backup->error,
                'created_at' => $backup->created_at?->toDateTimeString(),
                'file_exists' => $backup->status === DatabaseBackup::STATUS_COMPLETED && is_file($backup->path()),
            ]);

        return response()->json(['data' => $backups]);
    }

    /**
     * Runs a backup now. A failed dump answers 500 with the reason, and the failed row stays in the list.
     */
    public function store(): JsonResponse
    {
        $this->superadminOnly();
        $this->authorizeMenuPermission('/backup/create');

        $backup = $this->backups->create(Auth::id());

        if ($backup->status === DatabaseBackup::STATUS_FAILED) {
            return response()->json(['errormessage' => $backup->error, 'id' => $backup->id], 500);
        }

        return response()->json(['message' => 'Backup created', 'id' => $backup->id, 'file_name' => $backup->file_name, 'size_bytes' => $backup->size_bytes]);
    }

    public function download($id): BinaryFileResponse
    {
        $this->superadminOnly();
        $this->authorizeMenuPermission('/backup/download');

        $backup = DatabaseBackup::query()->where('status', DatabaseBackup::STATUS_COMPLETED)->find($id);

        if ($backup === null || ! is_file($backup->path())) {
            abort(404);
        }

        return response()->download($backup->path(), $backup->file_name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy($id): JsonResponse
    {
        $this->superadminOnly();

        if (! deletepermission('/backup/delete')) {
            return response()->json('406');
        }

        $backup = DatabaseBackup::query()->find($id);

        if ($backup !== null) {
            $this->backups->delete($backup);
        }

        return response()->json(['message' => 'Successfully Deleted']);
    }

    private function superadminOnly(): void
    {
        abort_unless(Auth::user()?->hasRole('superadmin'), 403);
    }
}
