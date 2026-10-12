<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Service\ServiceJobs;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Repair and field service job cards. Reading the board needs `/service`; creating, editing, assigning, scheduling and invoicing
 * `/service/manage`; the technician's own steps (progress, parts, signature, my jobs) `/service/work`, which only ever reaches the
 * jobs assigned to that user. Company and branch come from the signed-in user, every id is looked up inside the company.
 */
class ServiceJobController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly ServiceJobs $jobs) {}

    public function meta(Request $request): JsonResponse
    {
        abort_unless(hasAnyMenuPermission('/service', '/service/work'), 403, 'You do not have permission to perform this action.');
        $companyId = $this->scopeCompanyId($request);
        $search = trim((string) $request->query('search', ''));

        return response()->json(['data' => [
            'types' => ServiceJobs::TYPES, 'statuses' => ServiceJobs::STATUSES, 'flow' => ServiceJobs::FLOW,
            'products' => DB::table('products')->where('company_id', $companyId)->where('active', 1)->whereNull('deleted_at')
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))->orderBy('name')->limit(200)->get(['id', 'name']),
            'technicians' => DB::table('users')->where('company_id', $companyId)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (object $u): array => ['id' => (int) $u->id, 'name' => trim($u->first_name.' '.$u->last_name)])->all(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/service');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['status' => 'nullable|string|max:20', 'type' => 'nullable|string|max:12', 'technician_id' => 'nullable|integer', 'date' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $this->query($companyId)
            ->when($request->filled('status'), fn ($q) => $q->where('j.status', (string) $request->query('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('j.type', (string) $request->query('type')))
            ->when($request->filled('technician_id'), fn ($q) => $q->where('j.technician_id', (int) $request->query('technician_id')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('j.scheduled_at', (string) $request->query('date')))
            ->orderByRaw('case when j.scheduled_at is null then 1 else 0 end')->orderBy('j.scheduled_at')->orderByDesc('j.id')->limit(500)->get()]);
    }

    public function myJobs(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/service/work');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => $this->query($companyId)->where('j.technician_id', Auth::id())->whereNotIn('j.status', ['invoiced', 'closed', 'cancelled'])
            ->orderByRaw('case when j.scheduled_at is null then 1 else 0 end')->orderBy('j.scheduled_at')->limit(200)->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = $this->scopeCompanyId($request);
        $job = $this->visibleJob($companyId, $id);
        $parts = DB::table('service_job_parts as p')->join('products as pr', 'pr.id', '=', 'p.product_id')->where('p.service_job_id', $id)->orderBy('p.id')
            ->get(['p.id', 'p.product_id', 'p.variation_id', 'pr.name', 'p.quantity', 'p.unit_price', 'p.note']);
        $sale = $job->sale_id === null ? null : DB::table('transactions')->where('company_id', $companyId)->where('id', $job->sale_id)->first(['id', 'invoice_no', 'status', 'payment_status', 'final_amount']);

        return response()->json(['data' => (array) $job + [
            'parts' => $parts, 'events' => DB::table('service_job_events')->where('service_job_id', $id)->orderBy('id')->get(), 'has_signature' => $job->signature_path !== null,
            'invoice' => $sale === null ? null : (array) $sale + ['paid' => (float) DB::table('payments')->where('transaction_id', $sale->id)->where('is_return', 0)->sum('amount')],
            'warranty_claim' => $job->warranty_claim_id === null ? null : DB::table('warranty_claims')->where('company_id', $companyId)->where('id', $job->warranty_claim_id)->first(['id', 'claim_no', 'status', 'in_warranty']),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/service/manage');
        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);
        $id = $this->jobs->create($companyId, $branchId, $this->payload($request), Auth::id());

        return response()->json(['message' => 'Job card made', 'data' => ['id' => $id]], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/service/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->visibleJob($companyId, $id);
        $this->jobs->update($companyId, $id, $this->payload($request), Auth::id());

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/service/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->visibleJob($companyId, $id);
        $data = $request->validate(['technician_id' => 'nullable|integer', 'scheduled_at' => 'nullable|date', 'company_id' => 'nullable|integer']);
        $this->jobs->assign($companyId, $id, isset($data['technician_id']) ? (int) $data['technician_id'] : null, ! empty($data['scheduled_at']) ? date('Y-m-d H:i:s', (int) strtotime($data['scheduled_at'])) : null, Auth::id());

        return response()->json(['message' => 'Job assigned']);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['status' => ['required', Rule::in(ServiceJobs::STATUSES)], 'note' => 'nullable|string|max:300', 'company_id' => 'nullable|integer']);
        $this->jobs->move($companyId, $id, $data['status'], $data['note'] ?? null, Auth::id(), $this->actorTechnician('/service/manage'));

        return response()->json(['message' => 'Job updated']);
    }

    public function addPart(Request $request, int $id): JsonResponse
    {
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['product_id' => 'required|integer', 'variation_id' => 'nullable|integer', 'quantity' => 'required|numeric|gt:0', 'unit_price' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:200', 'company_id' => 'nullable|integer']);
        $partId = $this->jobs->addPart(
            $companyId, $id, (int) $data['product_id'], isset($data['variation_id']) ? (int) $data['variation_id'] : null, (float) $data['quantity'],
            isset($data['unit_price']) ? (float) $data['unit_price'] : null, $data['note'] ?? null, Auth::id(), $this->actorTechnician('/service/manage'),
        );

        return response()->json(['message' => 'Part added', 'data' => ['id' => $partId]], 201);
    }

    public function removePart(Request $request, int $id, int $partId): JsonResponse
    {
        $companyId = $this->scopeCompanyId($request);
        $this->jobs->removePart($companyId, $id, $partId, $this->actorTechnician('/service/manage'));

        return response()->json(['message' => 'Part removed']);
    }

    public function invoice(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/service/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->visibleJob($companyId, $id);

        return response()->json(['message' => 'Draft invoice made', 'data' => ['sale_id' => $this->jobs->invoice($companyId, $id, Auth::id())]], 201);
    }

    public function signature(Request $request, int $id): JsonResponse
    {
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['name' => 'required|string|max:100', 'image' => 'required|string', 'company_id' => 'nullable|integer']);
        $this->jobs->sign($companyId, $id, $data['name'], $data['image'], Auth::id(), $this->actorTechnician('/service/manage'));

        return response()->json(['message' => 'Signature kept']);
    }

    public function report(Request $request, int $id): Response
    {
        $companyId = $this->scopeCompanyId($request);
        $this->visibleJob($companyId, $id);

        return response($this->jobs->report($companyId, $id), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A user with `/service/manage` reaches every job; a technician with only `/service/work` reaches theirs. Returns the technician id
     * to restrict to, or null for a manager. Without either permission the request is refused.
     */
    private function actorTechnician(string $managePath): ?int
    {
        if (hasMenuPermission($managePath)) {
            return null;
        }

        $this->authorizeMenuPermission('/service/work');

        return (int) Auth::id();
    }

    /**
     * The job for reading: the board (`/service`), or a technician's own.
     */
    private function visibleJob(int $companyId, int $id): object
    {
        $job = $this->jobs->find($companyId, $id);

        if (hasMenuPermission('/service')) {
            return $job;
        }

        $this->authorizeMenuPermission('/service/work');
        abort_if((int) $job->technician_id !== (int) Auth::id(), 404);

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(ServiceJobs::TYPES)], 'title' => 'required|string|max:150', 'description' => 'nullable|string|max:2000', 'contact_id' => 'nullable|integer',
            'item_description' => 'nullable|string|max:200', 'serial_no' => 'nullable|string|max:100', 'warranty_claim_id' => 'nullable|integer', 'is_warranty' => 'nullable|boolean',
            'technician_id' => 'nullable|integer', 'priority' => 'nullable|in:low,normal,high,urgent', 'scheduled_at' => 'nullable|date', 'site_address' => 'nullable|string|max:255',
            'service_charge' => 'nullable|numeric|min:0', 'service_product_id' => 'nullable|integer', 'report_notes' => 'nullable|string|max:4000', 'branch_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);

        return collect($data)->except(['branch_id', 'company_id'])->all();
    }

    private function query(int $companyId): Builder
    {
        return DB::table('service_jobs as j')->leftJoin('contacts as c', 'c.id', '=', 'j.contact_id')->leftJoin('users as u', 'u.id', '=', 'j.technician_id')->where('j.company_id', $companyId)
            ->select(['j.id', 'j.job_no', 'j.type', 'j.title', 'j.status', 'j.priority', 'j.scheduled_at', 'j.technician_id', 'j.site_address', 'j.is_warranty', 'j.sale_id', 'j.contact_id',
                'c.business_name as customer', 'u.first_name as technician_first', 'u.last_name as technician_last']);
    }
}
