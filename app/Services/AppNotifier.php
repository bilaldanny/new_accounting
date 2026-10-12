<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * In-app notifications (the `app_notifications` table), with an optional plain email copy through the app's mail settings.
 * Used by CRM reminders, approvals, subscription reminders and the scheduled reports. Never throws: a failed email is skipped,
 * the in-app row is what counts.
 */
class AppNotifier
{
    public static function available(): bool
    {
        return Schema::hasTable('app_notifications');
    }

    public function notify(User|int $user, string $kind, string $title, ?string $body = null, ?string $url = null, bool $email = false): void
    {
        if (! self::available()) {
            return;
        }

        $user = $user instanceof User ? $user : User::query()->find($user);

        if ($user === null) {
            return;
        }

        DB::table('app_notifications')->insert([
            'company_id' => $user->company_id, 'user_id' => $user->id, 'kind' => $kind, 'title' => mb_substr($title, 0, 160), 'body' => $body,
            'url' => $url, 'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($email && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::raw(trim($title."\n\n".(string) $body), fn ($message) => $message->to($user->email)->subject($title));
            } catch (Throwable) {
                // the in-app notification is already stored
            }
        }
    }
}
