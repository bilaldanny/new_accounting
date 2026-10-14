<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\PartyAgingReport;
use App\Services\Reports\ProfitLossReport;
use App\Services\Reports\PurchaseSaleSummary;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the top bar offers: today's date in the company's time zone, the "Today Profit" figures, the calendar dots and the signed-in
 * user's own to-do list.
 *
 *  - Today Profit needs the Profit and Loss report permission and is scoped like that report (a branch user sees their branch);
 *  - the calendar shows only the kinds of dates the user may open (follow-ups need Activities, leaves need Leave Requests, due
 *    invoices need Sales), inside the company and branch of the user;
 *  - a to-do belongs to one user of one company; nobody reads or changes another's.
 */
class HeaderToolsController extends Controller
{
    use ResolvesReportScope;

    public function today(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/report/profit-loss');
        [$companyId, $branchId] = $this->reportScope($request);
        abort_if($companyId === null, 422, 'Choose a company.');

        $today = $this->now($companyId)->toDateString();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $figures = fn (string $date): array => $this->figures($companyId, $branchId, $date);

        $current = $figures($today);
        $previous = $figures($yesterday);

        return response()->json(['data' => [
            'date' => $today, 'yesterday' => $yesterday, 'today' => $current, 'previous' => $previous,
            'change' => [
                'sales' => $current['sales'] - $previous['sales'], 'purchases' => $current['purchases'] - $previous['purchases'],
                'expenses' => $current['expenses'] - $previous['expenses'], 'net_profit' => $current['net_profit'] - $previous['net_profit'],
            ],
        ]]);
    }

    /**
     * @return array{sales: float, purchases: float, expenses: float, gross_profit: float, net_profit: float}
     */
    private function figures(int $companyId, ?int $branchId, string $date): array
    {
        $filters = ['start_date' => $date, 'end_date' => $date];
        $trade = app(PurchaseSaleSummary::class)->build($companyId, $branchId, $filters)['totals'];
        $profit = app(ProfitLossReport::class)->build($companyId, $branchId, $filters)['summary'];

        return [
            'sales' => round((float) $trade['net_sales'], 2), 'purchases' => round((float) $trade['net_purchases'], 2),
            'expenses' => round((float) $profit['expenses'], 2), 'gross_profit' => round((float) $profit['gross_profit'], 2), 'net_profit' => round((float) $profit['net_profit'], 2),
        ];
    }

    public function clock(Request $request): JsonResponse
    {
        $companyId = (int) Auth::user()?->company_id;
        $now = $this->now($companyId);

        return response()->json(['data' => ['date' => $now->toDateString(), 'label' => $now->format('D, d M Y'), 'timezone' => $now->getTimezone()->getName()]]);
    }

    private function now(int $companyId): Carbon
    {
        $name = null;

        if ($companyId > 0 && Schema::hasTable('timezones')) {
            $name = DB::table('company_settings as s')->join('timezones as z', 'z.id', '=', 's.timezone_id')->where('s.company_id', $companyId)->value('z.name');
        }

        try {
            return Carbon::now($name ?: config('app.timezone'));
        } catch (\Throwable) {
            return Carbon::now(config('app.timezone'));
        }
    }

    /**
     * Dates of one month the user may see: follow-ups, approved leaves, invoices falling due and the user's own to-dos.
     */
    public function calendar(Request $request): JsonResponse
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);
        $user = Auth::user();
        abort_if($user === null, 401);
        [$companyId, $branchId] = $this->reportScope($request);
        abort_if($companyId === null, 422, 'Choose a company.');

        $start = Carbon::parse(($request->query('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $events = [];

        if (hasMenuPermission('/activities') && Schema::hasTable('activities')) {
            DB::table('activities')->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('completed_at')->whereBetween('due_at', [$start, $end->copy()->endOfDay()])
                ->when(! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin'), fn ($q) => $q->where('assigned_to', $user->id))
                ->orderBy('due_at')->limit(200)->get(['subject', 'due_at'])
                ->each(function (object $row) use (&$events): void {
                    $events[] = ['date' => substr((string) $row->due_at, 0, 10), 'kind' => 'followup', 'title' => (string) $row->subject];
                });
        }

        if (hasMenuPermission('/hrm/leave') && Schema::hasTable('leave_requests')) {
            DB::table('leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->where('l.company_id', $companyId)->where('l.status', 'approved')
                ->when($branchId !== null, fn ($q) => $q->where('l.branch_id', $branchId))
                ->where('l.from_date', '<=', $end->toDateString())->where('l.to_date', '>=', $start->toDateString())->limit(100)
                ->get(['e.first_name', 'e.last_name', 'l.from_date', 'l.to_date'])
                ->each(function (object $row) use (&$events, $start, $end): void {
                    $from = Carbon::parse($row->from_date)->max($start);
                    $to = Carbon::parse($row->to_date)->min($end);

                    for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                        $events[] = ['date' => $day->toDateString(), 'kind' => 'leave', 'title' => trim($row->first_name.' '.$row->last_name).' on leave'];
                    }
                });
        }

        if (hasMenuPermission('/sell')) {
            Transaction::query()->where('company_id', $companyId)->where('type', Transaction::TYPE_SELL)->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)
                ->whereIn('payment_status', ['due', 'partial'])->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->where('transaction_date', '<=', $end->toDateString())->orderBy('transaction_date')->limit(500)
                ->get()->each(function (Transaction $sale) use (&$events, $start, $end): void {
                    $contact = $sale->contact_id === null ? null : DB::table('contacts')->where('id', $sale->contact_id)->first(['pay_term', 'pay_type']);
                    $due = PartyAgingReport::dueDate((string) $sale->getRawOriginal('transaction_date'), $sale->pay_term, $sale->pay_type, $contact->pay_term ?? null, $contact->pay_type ?? null);

                    if ($due->between($start, $end)) {
                        $events[] = ['date' => $due->toDateString(), 'kind' => 'due', 'title' => 'Invoice '.$sale->invoice_no.' due'];
                    }
                });
        }

        $this->mine($user)->whereNull('done_at')->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->limit(100)->get(['title', 'due_date'])->each(function (object $row) use (&$events): void {
                $events[] = ['date' => (string) $row->due_date, 'kind' => 'todo', 'title' => (string) $row->title];
            });

        return response()->json(['data' => ['month' => $start->format('Y-m'), 'events' => $events]]);
    }

    // ---------------------------------------------------------------- to-do list

    private function mine(User $user): Builder
    {
        return DB::table('user_todos')->where('user_id', $user->id)
            ->when($user->company_id === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $user->company_id));
    }

    public function todos(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);

        $rows = $this->mine($user)->orderByRaw('done_at is null desc')->orderBy('due_date')->orderByDesc('id')->limit(100)
            ->get(['id', 'title', 'due_date', 'done_at']);

        return response()->json(['data' => $rows, 'pending' => $rows->whereNull('done_at')->count()]);
    }

    public function addTodo(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);
        $data = $request->validate(['title' => 'required|string|min:1|max:200', 'due_date' => 'nullable|date_format:Y-m-d']);

        abort_if(DB::table('user_todos')->where('user_id', $user->id)->whereNull('done_at')->count() >= 200, 422, 'Finish some to-dos before adding more.');

        $id = DB::table('user_todos')->insertGetId([
            'company_id' => $user->company_id, 'user_id' => $user->id, 'title' => trim(strip_tags($data['title'])), 'due_date' => $data['due_date'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function toggleTodo(int $id): JsonResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);
        $row = $this->mine($user)->where('id', $id)->first();
        abort_if($row === null, 404);

        DB::table('user_todos')->where('id', $id)->update(['done_at' => $row->done_at === null ? now() : null, 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Updated']);
    }

    public function deleteTodo(int $id): JsonResponse
    {
        $user = Auth::user();
        abort_if($user === null, 401);
        $affected = $this->mine($user)->where('id', $id)->delete();
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }
}
