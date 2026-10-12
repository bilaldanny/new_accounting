<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * CRM follow-up reminders: an open activity (call, meeting, task, follow-up) that is due within the next `$hoursAhead` hours or is
 * already overdue, and not yet reminded, notifies its assignee in the app and by email (their address), once. Marked with
 * `reminded_at` so running it every hour never repeats a reminder.
 */
class FollowUpReminders
{
    public function __construct(private readonly AppNotifier $notifier) {}

    /**
     * @return array{reminded: int}
     */
    public function run(int $hoursAhead = 24, ?int $companyId = null): array
    {
        if (! Schema::hasColumn('activities', 'reminded_at') || ! AppNotifier::available()) {
            return ['reminded' => 0];
        }

        $count = 0;

        Activity::query()
            ->withoutGlobalScopes()
            ->whereNull('completed_at')
            ->whereNull('reminded_at')
            ->whereNotNull('assigned_to')
            ->whereNotNull('due_at')
            ->whereIn('type', ['call', 'meeting', 'task', 'follow_up'])
            ->where('due_at', '<=', now()->addHours($hoursAhead))
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->each(function (Activity $activity) use (&$count): void {
                $user = User::query()->where('company_id', $activity->getRawOriginal('company_id'))->find($activity->assigned_to);

                if ($user === null) {
                    return;
                }

                $overdue = $activity->due_at->isPast();
                $this->notifier->notify(
                    $user, 'crm_reminder', ($overdue ? 'Overdue: ' : 'Due soon: ').$activity->subject,
                    ucfirst(str_replace('_', ' ', (string) $activity->type)).' due '.$activity->due_at->format('d M Y H:i').'.',
                    '/activities', true,
                );
                $activity->forceFill(['reminded_at' => now()])->save();
                $count++;
            });

        return ['reminded' => $count];
    }
}
