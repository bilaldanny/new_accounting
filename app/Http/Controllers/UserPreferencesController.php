<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in user's own preferences. A user can only ever read or change their own row; nothing here takes a user id.
 */
class UserPreferencesController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        return response()->json(['data' => ['notification_sound' => (bool) (DB::table('users')->where('id', $user->id)->value('notification_sound') ?? true)]]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);
        $data = $request->validate(['notification_sound' => 'required|boolean']);

        DB::table('users')->where('id', $user->id)->update(['notification_sound' => (bool) $data['notification_sound'], 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Saved', 'data' => ['notification_sound' => (bool) $data['notification_sound']]]);
    }
}
