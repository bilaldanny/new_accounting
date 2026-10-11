<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\InstallmentPlan;
use App\Models\InstallmentScheduleRow;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Installment and layaway sales. A plan sits on an ordinary posted sale and only says what falls due when (a down payment, then
 * equal installments); what was paid is read from the sale's own payments, so a payment made on the sale screen counts too and the
 * ledger is the usual one. Paying through the plan is Payment::createSellPayment. A layaway plan also holds the goods: dispatch is
 * refused until the plan is paid up.
 */
class InstallmentPlanController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/installment');

        $rows = InstallmentPlan::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        $rows->getCollection()->transform(fn (InstallmentPlan $plan): InstallmentPlan => $this->withProgress($plan));

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/installment');

        $plan = InstallmentPlan::query()->visibleToCurrentUser()->findOrFail($id);

        return response()->json($this->withProgress($plan)->toArray() + ['schedule' => $this->schedule($plan)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/installment/add');

        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'kind' => 'required|in:'.implode(',', InstallmentPlan::KINDS),
            'down_payment' => 'nullable|numeric|min:0',
            'installments' => 'required|integer|min:1|max:120',
            'frequency' => 'required|in:weekly,monthly',
            'first_due_on' => 'required|date_format:Y-m-d',
        ]);

        $sale = Transaction::query()->sells()->visibleToCurrentUser()->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)->find($data['transaction_id']);

        if ($sale === null) {
            throw ValidationException::withMessages(['transaction_id' => ['Choose a posted sale you can see.']]);
        }

        if (InstallmentPlan::query()->where('transaction_id', $sale->id)->whereIn('status', ['active', 'completed'])->exists()) {
            throw ValidationException::withMessages(['transaction_id' => ['This sale already has a plan.']]);
        }

        $total = round((float) $sale->final_amount, 2);
        $down = round((float) ($data['down_payment'] ?? 0), 2);
        $paid = Payment::paidAmountForTransaction((int) $sale->id);

        if ($down > $total) {
            throw ValidationException::withMessages(['down_payment' => ['The down payment is more than the sale.']]);
        }

        $rest = round($total - $down, 2);

        if ($rest <= 0 || $paid >= $total) {
            throw ValidationException::withMessages(['transaction_id' => ['Nothing is left to spread over installments.']]);
        }

        $plan = DB::transaction(function () use ($data, $sale, $total, $down, $rest): InstallmentPlan {
            $plan = InstallmentPlan::query()->create([
                'company_id' => $sale->company_id, 'branch_id' => $sale->branch_id, 'contact_id' => $sale->contact_id, 'transaction_id' => $sale->id,
                'kind' => $data['kind'], 'status' => 'active', 'total_amount' => $total, 'down_payment' => $down, 'installments' => $data['installments'],
                'frequency' => $data['frequency'], 'first_due_on' => $data['first_due_on'], 'created_by' => Auth::id(),
            ]);

            $each = round($rest / $data['installments'], 2);
            $due = Carbon::parse($data['first_due_on']);
            $number = 0;

            if ($down > 0) {
                InstallmentScheduleRow::query()->create(['installment_plan_id' => $plan->id, 'number' => $number++, 'due_on' => now()->toDateString(), 'amount' => $down]);
            }

            for ($i = 1; $i <= $data['installments']; $i++) {
                $amount = $i === (int) $data['installments'] ? round($rest - $each * ($i - 1), 2) : $each;
                InstallmentScheduleRow::query()->create(['installment_plan_id' => $plan->id, 'number' => $number++, 'due_on' => $due->toDateString(), 'amount' => $amount]);
                $due = $data['frequency'] === 'weekly' ? $due->copy()->addWeek() : $due->copy()->addMonthNoOverflow();
            }

            return $plan;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->withProgress($plan)->toArray() + ['schedule' => $this->schedule($plan)]], 201);
    }

    /**
     * Records a payment on the plan's sale through the ordinary sale payment.
     */
    public function pay(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/installment/pay');

        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'paid_on' => 'required|date_format:Y-m-d',
            'method' => 'required|in:cash,bank,cheque,card,online,other',
            'payment_account' => 'required|integer',
            'note' => 'nullable|string|max:300',
        ]);

        $plan = InstallmentPlan::query()->visibleToCurrentUser()->findOrFail($id);

        if ($plan->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['This plan is '.$plan->status.'.']]);
        }

        DB::transaction(function () use ($plan, $data): void {
            Payment::createSellPayment((object) [
                'transaction_id' => $plan->transaction_id, 'amount' => $data['amount'], 'paid_on' => $data['paid_on'], 'method' => $data['method'],
                'payment_account' => $data['payment_account'], 'note' => trim('Installment plan #'.$plan->id.(($data['note'] ?? null) ? ': '.$data['note'] : '')),
            ]);
        });

        $plan = $this->refreshStatus($plan);

        return response()->json(['message' => 'Payment recorded', 'data' => $this->withProgress($plan)->toArray() + ['schedule' => $this->schedule($plan)]]);
    }

    public function cancel(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/installment/:id/edit');

        $plan = InstallmentPlan::query()->visibleToCurrentUser()->findOrFail($id);

        if ($plan->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['This plan is '.$plan->status.'.']]);
        }

        $plan->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Successfully Saved', 'data' => $plan->fresh()]);
    }

    /**
     * Marks a plan completed when its sale is paid, or defaulted when an installment is overdue by more than the grace given.
     */
    private function refreshStatus(InstallmentPlan $plan): InstallmentPlan
    {
        $sale = Transaction::query()->find($plan->transaction_id);

        if ($sale !== null && Payment::paidAmountForTransaction((int) $sale->id) + 0.005 >= round((float) $sale->final_amount, 2) && $plan->status === 'active') {
            $plan->update(['status' => 'completed']);
        }

        return $plan->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schedule(InstallmentPlan $plan): array
    {
        $paid = Payment::paidAmountForTransaction((int) $plan->transaction_id);
        $left = $paid;
        $rows = [];

        foreach ($plan->schedule()->get() as $row) {
            $covered = round(min($left, (float) $row->amount), 2);
            $left = round($left - $covered, 2);
            $state = $covered + 0.005 >= $row->amount ? 'paid' : ($row->due_on->toDateString() < now()->toDateString() ? 'overdue' : 'pending');
            $rows[] = ['number' => $row->number, 'due_on' => $row->due_on->toDateString(), 'amount' => (float) $row->amount, 'paid' => $covered, 'status' => $state];
        }

        return $rows;
    }

    private function withProgress(InstallmentPlan $plan): InstallmentPlan
    {
        $paid = Payment::paidAmountForTransaction((int) $plan->transaction_id);
        $overdue = collect($this->schedule($plan))->where('status', 'overdue')->sum(fn (array $row): float => $row['amount'] - $row['paid']);

        $plan->setAttribute('paid', $paid);
        $plan->setAttribute('balance', round(max((float) $plan->total_amount - $paid, 0), 2));
        $plan->setAttribute('overdue_amount', round($overdue, 2));

        return $plan;
    }
}
