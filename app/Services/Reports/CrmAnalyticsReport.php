<?php

namespace App\Services\Reports;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The four CRM Analytics angles the master list names under one menu item (Conversion, Funnel +
 * Pipeline combined — a stage-ordered count/value breakdown answers both at once, Sales Activity,
 * Salesperson Performance). Each method is company-scoped (and, for the date-ranged ones,
 * period-scoped) and returns plain arrays the controller turns straight into JSON — there's no
 * pagination here, these are small, summary-shaped datasets, not transaction lists.
 */
class CrmAnalyticsReport
{
    /**
     * Lead conversion funnel: how many leads currently sit in each status.
     *
     * @return array<string, mixed>
     */
    public static function conversion(?int $companyId): array
    {
        $counts = Lead::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stages = array_map(
            fn (string $status) => ['status' => $status, 'count' => (int) ($counts[$status] ?? 0)],
            Lead::STATUSES
        );

        $total = (int) $counts->sum();
        $converted = (int) ($counts['converted'] ?? 0);

        return [
            'stages' => $stages,
            'total_leads' => $total,
            'converted' => $converted,
            'conversion_rate' => $total > 0 ? round($converted / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Pipeline / funnel: open opportunity count and deal value per active stage, in board order —
     * this is both the "Funnel" and "Pipeline" angle the master list names, since a stage-ordered
     * count+value breakdown is what both mean in practice (same shape the Kanban board itself groups
     * cards by).
     *
     * @return array<string, mixed>
     */
    public static function pipeline(?int $companyId): array
    {
        $stages = PipelineStage::query()
            ->visibleToCurrentUser()
            ->where('is_active', true)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'is_won', 'is_lost']);

        $byStage = Opportunity::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('pipeline_stage_id, count(*) as total, sum(deal_value) as value')
            ->groupBy('pipeline_stage_id')
            ->get()
            ->keyBy('pipeline_stage_id');

        $rows = $stages->map(function (PipelineStage $stage) use ($byStage) {
            $row = $byStage->get($stage->id);

            return [
                'stage_id' => $stage->id,
                'stage' => $stage->name,
                'is_won' => (bool) $stage->is_won,
                'is_lost' => (bool) $stage->is_lost,
                'count' => (int) ($row->total ?? 0),
                'value' => round((float) ($row->value ?? 0), 2),
            ];
        })->values()->all();

        $openValue = Opportunity::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'open')
            ->sum('deal_value');

        return [
            'stages' => $rows,
            'open_value' => round((float) $openValue, 2),
        ];
    }

    /**
     * Activity counts by type over a date range (defaults to the last 30 days), plus how many of
     * those are still pending vs completed.
     *
     * @return array<string, mixed>
     */
    public static function salesActivity(?int $companyId, ?string $startDate, ?string $endDate): array
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays(29)->startOfDay();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay();

        $query = Activity::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('created_at', [$start, $end]);

        $byType = (clone $query)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $types = array_map(
            fn (string $type) => ['type' => $type, 'count' => (int) ($byType[$type] ?? 0)],
            Activity::TYPES
        );

        return [
            'types' => $types,
            'total' => (int) $byType->sum(),
            'completed' => (clone $query)->whereNotNull('completed_at')->count(),
            'pending' => (clone $query)->whereNull('completed_at')->count(),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
    }

    /**
     * Per-salesperson (the `assigned_to` user): how many leads and open opportunities they carry, how
     * many deals they've won and for how much, and how many activities they've logged.
     *
     * @return list<array<string, mixed>>
     */
    public static function salespersonPerformance(?int $companyId): array
    {
        $leadCounts = Lead::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $openOpportunityCounts = Opportunity::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('assigned_to')
            ->where('status', 'open')
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $wonOpportunities = Opportunity::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('assigned_to')
            ->where('status', 'won')
            ->selectRaw('assigned_to, count(*) as total, sum(deal_value) as value')
            ->groupBy('assigned_to')
            ->get()
            ->keyBy('assigned_to');

        $activityCounts = Activity::query()
            ->visibleToCurrentUser()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as total')
            ->groupBy('assigned_to')
            ->pluck('total', 'assigned_to');

        $userIds = collect()
            ->merge($leadCounts->keys())
            ->merge($openOpportunityCounts->keys())
            ->merge($wonOpportunities->keys())
            ->merge($activityCounts->keys())
            ->unique()
            ->values();

        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $userIds->map(function ($userId) use ($leadCounts, $openOpportunityCounts, $wonOpportunities, $activityCounts, $users) {
            $won = $wonOpportunities->get($userId);

            return [
                'user_id' => (int) $userId,
                'name' => $users->get($userId)?->full_name ?? 'Unknown',
                'leads_assigned' => (int) ($leadCounts[$userId] ?? 0),
                'open_opportunities' => (int) ($openOpportunityCounts[$userId] ?? 0),
                'won_opportunities' => (int) ($won->total ?? 0),
                'won_value' => round((float) ($won->value ?? 0), 2),
                'activities_logged' => (int) ($activityCounts[$userId] ?? 0),
            ];
        })->sortByDesc('won_value')->values()->all();
    }
}
