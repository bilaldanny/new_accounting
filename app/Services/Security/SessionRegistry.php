<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The list of a user's browser sessions (device list) and the way an administrator ends one. Independent of the session driver:
 * each session is recorded by a hash of its id, and a revoked row signs that session out at its next request (EnforceSecurity).
 */
class SessionRegistry
{
    public static function available(): bool
    {
        return Schema::hasTable('user_sessions');
    }

    /**
     * Records the request's session; false when it was revoked.
     */
    public function touch(Request $request, User $user): bool
    {
        $hash = hash('sha256', $request->session()->getId());
        $row = DB::table('user_sessions')->where('session_hash', $hash)->first();

        if ($row === null) {
            DB::table('user_sessions')->insert([
                'company_id' => $user->company_id, 'user_id' => $user->id, 'session_hash' => $hash, 'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'device' => self::device((string) $request->userAgent()),
                'last_activity' => now(), 'created_at' => now(),
            ]);

            return true;
        }

        if ($row->revoked_at !== null || (int) $row->user_id !== (int) $user->id) {
            return false;
        }

        if ($row->last_activity === null || now()->diffInSeconds($row->last_activity) > 60) {
            DB::table('user_sessions')->where('id', $row->id)->update(['last_activity' => now(), 'ip' => $request->ip()]);
        }

        return true;
    }

    public static function device(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $system = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown system',
        };

        return "{$browser} on {$system}";
    }
}
