<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Activity Log / Audit Trail list. Rows are written by the Auditable model trait only.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/auditlogs');

        $query = AuditLog::query()
            ->visibleToCurrentUser()
            ->with('user:id,first_name,last_name')
            ->when($request->filled('model'), fn ($q) => $q->where('auditable_type', 'like', '%\\'.$request->input('model')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->input('user_id')))
            ->when($request->filled('auditable_id'), fn ($q) => $q->where('auditable_id', $request->input('auditable_id')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            ->orderByDesc('id');

        $logs = $query->paginate(min((int) ($request->input('show_record') ?: 25), 200));
        $logs->getCollection()->transform(fn (AuditLog $log) => $log->presentForIndex());

        return response()->json(['data' => $logs]);
    }
}
