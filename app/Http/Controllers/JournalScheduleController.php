<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\CostCenter;
use App\Models\JournalSchedule;
use App\Models\JournalScheduleRun;
use App\Models\TAccount;
use App\Services\JournalScheduleEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Journal schedules: recurring and reversing journals, recurring expenses, prepaid / deferred amortization and accruals (see
 * Services\JournalScheduleEngine). A schedule only describes what to post; the vouchers it makes follow the company's approval rule
 * and the period lock like any journal.
 */
class JournalScheduleController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly JournalScheduleEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule');

        $schedules = JournalSchedule::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->input('kind')))
            ->orderByDesc('id')->limit(300)->get();

        $runs = JournalScheduleRun::query()->whereIn('journal_schedule_id', $schedules->pluck('id'))->where('kind', 'post')->get()->groupBy('journal_schedule_id');

        return response()->json(['data' => $schedules->map(function (JournalSchedule $schedule) use ($runs): array {
            $own = $runs->get($schedule->id, collect());
            $next = collect($this->engine->occurrences($schedule, now()->addYears(5)->toDateString()))
                ->first(fn (string $day): bool => ! $own->contains(fn (JournalScheduleRun $run): bool => $run->period_date->toDateString() === $day));

            return $schedule->toArray() + [
                'runs_count' => $own->count(),
                'last_run_on' => $own->max('period_date')?->toDateString(),
                'next_due_on' => $schedule->status === 'active' ? $next : null,
            ];
        })->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule/add');

        $companyId = $this->scopeCompanyId($request);
        $data = $this->validated($request, $companyId);

        $schedule = JournalSchedule::query()->create($data + [
            'company_id' => $companyId,
            'branch_id' => $this->branchFor($request, $companyId),
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $schedule], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule/:id/edit');

        $schedule = JournalSchedule::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request, (int) $schedule->company_id);

        if ($schedule->runs()->exists()) {
            // what was already posted stays; the rule for the days to come may change, the start and the kind may not
            unset($data['kind'], $data['start_date']);
        }

        $schedule->update($data);

        return response()->json(['message' => 'Successfully Saved', 'data' => $schedule->fresh()]);
    }

    public function setStatus(int $id, string $status): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule/:id/edit');

        $schedule = JournalSchedule::query()->visibleToCurrentUser()->findOrFail($id);

        if ($schedule->status === 'completed' || $schedule->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => ['This schedule has ended.']]);
        }

        $schedule->update(['status' => $status]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $schedule->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule/delete');

        $schedule = JournalSchedule::query()->visibleToCurrentUser()->findOrFail($id);

        if ($schedule->runs()->exists()) {
            throw ValidationException::withMessages(['schedule' => ['A schedule that has posted vouchers cannot be deleted; cancel it instead.']]);
        }

        $schedule->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function runs(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule');

        $schedule = JournalSchedule::query()->visibleToCurrentUser()->findOrFail($id);
        $runs = JournalScheduleRun::query()->where('journal_schedule_id', $schedule->id)->orderByDesc('period_date')->orderByDesc('id')->limit(200)->get();
        $states = TAccount::query()->whereIn('id', $runs->pluck('t_account_id')->filter())->get(['id', 'status', 'voucher_no'])->keyBy('id');

        return response()->json(['data' => $runs->map(fn (JournalScheduleRun $run): array => $run->toArray() + [
            'voucher_no' => $states[$run->t_account_id]->voucher_no ?? null,
            'voucher_status' => $states[$run->t_account_id]->status ?? null,
        ])->values()]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule');

        $data = $request->validate(['up_to' => 'nullable|date_format:Y-m-d', 'schedule_id' => 'nullable|integer']);

        return response()->json(['data' => $this->engine->preview($this->scopeCompanyId($request), $data['up_to'] ?? now()->toDateString(), $data['schedule_id'] ?? null)]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/journalschedule/run');

        $data = $request->validate(['up_to' => 'nullable|date_format:Y-m-d', 'schedule_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);

        if (isset($data['schedule_id']) && ! JournalSchedule::query()->where('company_id', $companyId)->whereKey($data['schedule_id'])->exists()) {
            abort(404);
        }

        return response()->json(['data' => $this->engine->run($companyId, $data['up_to'] ?? now()->toDateString(), $data['schedule_id'] ?? null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(JournalSchedule::KINDS)],
            'name' => 'required|string|min:2|max:120',
            'frequency' => ['required', Rule::in(JournalSchedule::FREQUENCIES)],
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'max_runs' => 'nullable|integer|min:1|max:1200',
            'auto_post' => 'nullable|boolean',
            'auto_reverse' => 'nullable|boolean',
            'lines' => 'nullable|array|max:40',
            'lines.*.account_id' => 'required_with:lines|integer',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.contact_id' => 'nullable|integer',
            'lines.*.cost_center_id' => 'nullable|integer',
            'lines.*.description' => 'nullable|string|max:255',
            'meta' => 'nullable|array',
            'meta.amount' => 'nullable|numeric|gt:0',
            'meta.total_amount' => 'nullable|numeric|gt:0',
            'meta.months' => 'nullable|integer|min:1|max:600',
            'meta.direction' => 'nullable|in:expense,revenue',
            'meta.expense_account_id' => 'nullable|integer',
            'meta.liability_account_id' => 'nullable|integer',
            'meta.paid_from_account_id' => 'nullable|integer',
            'meta.source_account_id' => 'nullable|integer',
            'meta.target_account_id' => 'nullable|integer',
            'meta.payee_contact_id' => 'nullable|integer',
            'meta.cost_center_id' => 'nullable|integer',
        ]);

        $kind = $data['kind'];
        $meta = (array) ($data['meta'] ?? []);
        $accounts = [];

        if (in_array($kind, ['recurring', 'reversing'], true)) {
            $lines = array_values($data['lines'] ?? []);
            $debit = round((float) collect($lines)->sum('debit'), 2);
            $credit = round((float) collect($lines)->sum('credit'), 2);

            if (count($lines) < 2 || $debit <= 0 || $debit !== $credit) {
                throw ValidationException::withMessages(['lines' => ['A journal template needs at least two lines, and debits must equal credits.']]);
            }

            foreach ($lines as $line) {
                if ((float) ($line['debit'] ?? 0) > 0 && (float) ($line['credit'] ?? 0) > 0) {
                    throw ValidationException::withMessages(['lines' => ['A line has a debit or a credit, not both.']]);
                }
            }

            $accounts = array_column($lines, 'account_id');
            $this->assertContactsAndCenters($companyId, $lines);
        }

        $required = match ($kind) {
            'accrual' => ['amount', 'expense_account_id', 'liability_account_id'],
            'expense' => ['amount', 'expense_account_id', 'paid_from_account_id'],
            'amortization' => ['total_amount', 'months', 'source_account_id', 'target_account_id'],
            default => [],
        };

        foreach ($required as $key) {
            if (empty($meta[$key])) {
                throw ValidationException::withMessages(["meta.{$key}" => ['This is required for this kind of schedule.']]);
            }
        }

        foreach (['expense_account_id', 'liability_account_id', 'paid_from_account_id', 'source_account_id', 'target_account_id'] as $key) {
            if (! empty($meta[$key])) {
                $accounts[] = $meta[$key];
            }
        }

        $found = ChartOfAccount::query()->where('company_id', $companyId)->whereIn('id', array_unique(array_map('intval', $accounts)))->count();

        if ($found !== count(array_unique(array_map('intval', $accounts)))) {
            throw ValidationException::withMessages(['lines' => ['An account is not part of this company\'s chart of accounts.']]);
        }

        $this->assertContactsAndCenters($companyId, [['contact_id' => $meta['payee_contact_id'] ?? null, 'cost_center_id' => $meta['cost_center_id'] ?? null]]);

        return [
            'kind' => $kind,
            'name' => $data['name'],
            'frequency' => $data['frequency'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'max_runs' => $data['max_runs'] ?? null,
            'auto_post' => (bool) ($data['auto_post'] ?? true),
            'auto_reverse' => in_array($kind, ['reversing', 'accrual'], true) ? true : (bool) ($data['auto_reverse'] ?? false),
            'lines' => in_array($kind, ['recurring', 'reversing'], true) ? array_values($data['lines']) : null,
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertContactsAndCenters(int $companyId, array $lines): void
    {
        $contacts = array_filter(array_column($lines, 'contact_id'));
        $centers = array_filter(array_column($lines, 'cost_center_id'));

        if ($contacts !== [] && Contact::query()->where('company_id', $companyId)->whereIn('id', $contacts)->count() !== count(array_unique($contacts))) {
            throw ValidationException::withMessages(['lines' => ['A contact is not part of this company.']]);
        }

        if ($centers !== [] && CostCenter::query()->where('company_id', $companyId)->whereIn('id', $centers)->count() !== count(array_unique($centers))) {
            throw ValidationException::withMessages(['lines' => ['A cost center is not part of this company.']]);
        }
    }

    private function branchFor(Request $request, int $companyId): int
    {
        return $this->scopeBranchId($request, $companyId)
            ?? (int) Branch::query()->where('company_id', $companyId)->orderBy('id')->value('id');
    }
}
