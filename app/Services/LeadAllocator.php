<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assigns a new lead that arrives with no owner, by the company's active rules in priority order:
 *
 *   round_robin  the next user of the rule's pool, in turn;
 *   by_source    a rule tied to a lead source applies to leads of that source (then in turn within its pool);
 *   by_branch    a rule tied to a branch applies when the lead is entered by a user of that branch.
 *
 * A lead that already has an owner, a company with no active rule, and a pool with no active user are all left exactly as they
 * were. Hooked on the Lead `creating` event, so the form, the public capture form and imports are all covered.
 */
class LeadAllocator
{
    public static function available(): bool
    {
        return Schema::hasTable('lead_allocation_rules');
    }

    public function assign(Lead $lead): void
    {
        $companyId = (int) ($lead->getAttributes()['company_id'] ?? 0);

        if ($lead->assigned_to !== null || $companyId === 0 || ! self::available()) {
            return;
        }

        $creatorBranch = Auth::user()?->branch_id;

        $rules = DB::table('lead_allocation_rules')->where('company_id', $companyId)->where('is_active', true)->orderBy('priority')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if ($rule->strategy === 'by_source' && (int) $rule->lead_source_id !== (int) $lead->lead_source_id) {
                continue;
            }

            if ($rule->strategy === 'by_branch' && ($creatorBranch === null || (int) $rule->branch_id !== (int) $creatorBranch)) {
                continue;
            }

            $pool = User::query()->where('company_id', $companyId)->where('is_active', true)->whereIn('id', (array) json_decode((string) $rule->user_ids, true))->orderBy('id')->pluck('id')->all();

            if ($pool === []) {
                continue;
            }

            $next = (int) $rule->cursor % count($pool);
            $lead->assigned_to = $pool[$next];
            DB::table('lead_allocation_rules')->where('id', $rule->id)->update(['cursor' => $next + 1, 'updated_at' => now()]);

            return;
        }
    }
}
