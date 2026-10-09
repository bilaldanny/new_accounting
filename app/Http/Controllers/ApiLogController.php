<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\ApiLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiLogController extends Controller
{
    use HandlesIndexAndBulkDelete;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/apilogs');

        $query = ApiLog::query()
            ->visibleToCurrentUser()
            ->with('user:id,first_name,last_name')
            ->when($request->filled('status_code'), fn ($q) => $q->where('status_code', $request->status_code))
            ->when($request->filled('path'), fn ($q) => $q->where('path', 'like', '%'.$request->path.'%'))
            ->orderByDesc('created_at');

        $logs = $this->paginateSorted($query, $request);

        return response()->json(['data' => $logs]);
    }
}
