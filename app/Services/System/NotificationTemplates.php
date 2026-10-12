<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company wording for the in-app (and email copy) notifications of AppNotifier, by notification kind. The application still decides
 * when a notification is raised and what it says; a template only wraps that text: `{title}` and `{body}` stand for what the
 * application wrote, `{url}` for the page it opens and `{company}` for the company name. A company with no template (or an
 * inactive one) gets the application's wording untouched.
 */
class NotificationTemplates
{
    /**
     * Kind => label of the notifications the application raises.
     */
    public const KINDS = [
        'crm_reminder' => 'CRM follow-up reminder',
        'approval_waiting' => 'Document waiting for your approval',
        'approval_approved' => 'Your document was approved',
        'approval_rejected' => 'Your document was rejected',
        'billing_overdue' => 'Subscription invoice overdue',
        'support_ticket' => 'New support ticket',
        'support_reply' => 'Reply on your support ticket',
    ];

    /**
     * @return array{0: string, 1: string|null}
     */
    public static function apply(?int $companyId, string $kind, string $title, ?string $body, ?string $url = null): array
    {
        if ($companyId === null || ! Schema::hasTable('notification_templates')) {
            return [$title, $body];
        }

        $template = DB::table('notification_templates')->where('company_id', $companyId)->where('kind', $kind)->where('is_active', true)->first();

        if ($template === null) {
            return [$title, $body];
        }

        $company = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $map = ['{title}' => $title, '{body}' => (string) $body, '{url}' => (string) $url, '{company}' => $company];

        return [
            $template->title !== null && trim((string) $template->title) !== '' ? trim(strtr((string) $template->title, $map)) : $title,
            $template->body !== null && trim((string) $template->body) !== '' ? trim(strtr((string) $template->body, $map)) : $body,
        ];
    }
}
