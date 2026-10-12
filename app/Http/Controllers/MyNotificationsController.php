<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's own in-app notifications (reminders, approvals, reports). Nobody can read another user's.
 */
class MyNotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('app_notifications')->where('user_id', Auth::id())
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')->limit(100)->get(['id', 'kind', 'title', 'body', 'url', 'read_at', 'created_at']);

        return response()->json(['data' => $rows, 'unread' => DB::table('app_notifications')->where('user_id', Auth::id())->whereNull('read_at')->count()]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $changed = DB::table('app_notifications')->where('user_id', Auth::id())->where('id', $id)->update(['read_at' => now()]);
        abort_if($changed === 0, 404);

        return response()->json(['message' => 'Marked as read']);
    }

    public function readAll(): JsonResponse
    {
        DB::table('app_notifications')->where('user_id', Auth::id())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All marked as read']);
    }
}
