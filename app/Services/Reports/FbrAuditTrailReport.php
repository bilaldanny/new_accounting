<?php

namespace App\Services\Reports;

use App\Models\FbrSubmission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit trail of invoice submissions to FBR: every submission attempt of the range with its invoice, status, the number returned,
 * the attempts made and the error. The gateway in this application is a STUB: nothing is sent to FBR and the numbers it returns
 * (`FBR-STUB-...`) are not FBR invoice numbers, so this report is a log of what the application did and must not be used as
 * evidence of compliance. Rows with status `stub` say so; `status` narrows by status.
 */
class FbrAuditTrailReport
{
    public const SORTABLE = [
        'submitted_at' => 'submitted_at',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'final_amount' => 'final_amount',
        'status' => 'status',
        'fbr_invoice_number' => 'fbr_invoice_number',
        'attempts' => 'attempts',
    ];

    public const DEFAULT_SORT = 'submitted_at';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $status = trim((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('fbr_submissions as f')
            ->join('transactions as t', 't.id', '=', 'f.transaction_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->when($companyId !== null, fn ($q) => $q->where('f.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('f.status', $status))
            ->whereDate('f.created_at', '>=', $from)->whereDate('f.created_at', '<=', $to)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('t.invoice_no', 'like', "%{$search}%")->orWhere('f.fbr_invoice_number', 'like', "%{$search}%")))
            ->orderByDesc('f.id')
            ->get(['f.id', 'f.status', 'f.fbr_invoice_number', 'f.error', 'f.attempts', 'f.submitted_at', 'f.created_at', 't.invoice_no', 't.final_amount', 't.transaction_date', 'c.business_name', 'c.first_name', 'c.last_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'submitted_at' => substr((string) ($row->submitted_at ?? $row->created_at), 0, 16),
                'invoice_no' => (string) $row->invoice_no,
                'transaction_date' => substr((string) $row->transaction_date, 0, 10),
                'contact_name' => trim((string) ($row->business_name ?: $row->first_name.' '.$row->last_name)),
                'final_amount' => round((float) $row->final_amount, 2),
                'status' => (string) $row->status,
                'fbr_invoice_number' => (string) ($row->fbr_invoice_number ?? ''),
                'attempts' => (int) $row->attempts,
                'error' => (string) ($row->error ?? ''),
                'is_real' => $row->status === FbrSubmission::STATUS_SUBMITTED,
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int|string>
     */
    public function summary(Collection $rows): array
    {
        $by = fn (string $status): int => $rows->where('status', $status)->count();

        return [
            'count' => $rows->count(),
            'stub' => $by(FbrSubmission::STATUS_STUB),
            'submitted' => $by(FbrSubmission::STATUS_SUBMITTED),
            'failed' => $by(FbrSubmission::STATUS_FAILED),
            'pending' => $by(FbrSubmission::STATUS_PENDING),
            'note' => 'The FBR gateway is a stub: nothing was sent to FBR and stub numbers are not FBR invoice numbers.',
        ];
    }
}
