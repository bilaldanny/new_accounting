<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AppNotifier;
use App\Services\Hrm\HrSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells the HR managers of a company about employee documents that have expired or expire within the alert window of its HR
 * settings (once per document: `alerted_at`). Run daily from the scheduler; `--company` limits it to one company.
 */
class SendHrDocumentAlerts extends Command
{
    protected $signature = 'hrm:document-alerts {--company= : One company id}';

    protected $description = 'Notify HR managers of employee documents that expire soon or have expired';

    public function handle(HrSettings $settings, AppNotifier $notifier): int
    {
        if (! Schema::hasTable('employee_documents') || ! AppNotifier::available()) {
            $this->info('HR documents are not installed here.');

            return self::SUCCESS;
        }

        $alerted = 0;
        $companies = DB::table('employee_documents')->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->whereNotNull('expires_on')->whereNull('alerted_at')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $days = (int) $settings->all((int) $companyId)['document_alert_days'];
            $documents = DB::table('employee_documents as d')->join('employees as e', 'e.id', '=', 'd.employee_id')->where('d.company_id', $companyId)->whereNull('e.deleted_at')
                ->whereNull('d.alerted_at')->whereNotNull('d.expires_on')->whereDate('d.expires_on', '<=', now()->addDays($days)->toDateString())
                ->get(['d.id', 'd.title', 'd.expires_on', 'e.first_name', 'e.last_name', 'e.employee_no']);

            if ($documents->isEmpty()) {
                continue;
            }

            $recipients = User::query()->where('company_id', $companyId)->get()->filter(fn (User $user): bool => $user->hasRole('companyadmin'));

            foreach ($documents as $document) {
                $text = trim($document->first_name.' '.$document->last_name).' ('.$document->employee_no.'): '.$document->title.' expires on '.substr((string) $document->expires_on, 0, 10).'.';

                foreach ($recipients as $user) {
                    $notifier->notify((int) $user->id, 'hr_document', 'Employee document expiring', $text, '/hrm');
                }

                DB::table('employee_documents')->where('id', $document->id)->update(['alerted_at' => now()]);
                $alerted++;
            }
        }

        $this->info("Alerted {$alerted} document(s).");

        return self::SUCCESS;
    }
}
