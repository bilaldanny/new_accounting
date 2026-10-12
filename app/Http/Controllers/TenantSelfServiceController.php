<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Company;
use App\Services\AppNotifier;
use App\Services\Saas\PlanFeatures;
use App\Services\Saas\TenantLifecycle;
use App\Services\Saas\TenantServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a company administers about its own subscription: plan, usage trend, onboarding checklist, cancel at period end or renew,
 * data export, deletion after cancellation, custom domain and branding, and support tickets. The superadmin reads and answers
 * the tickets of every company.
 */
class TenantSelfServiceController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly TenantLifecycle $lifecycle, private readonly TenantServices $services) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing');

        $companyId = $this->scopeCompanyId($request);
        $company = Company::query()->findOrFail($companyId);
        $plan = $company->subscriptionPlan;

        return response()->json(['data' => [
            'company' => ['id' => $company->id, 'name' => $company->name, 'code' => $company->code, 'tenant_status' => $company->tenant_status, 'cancel_at_period_end' => (bool) $company->cancel_at_period_end,
                'current_period_ends_at' => $company->current_period_ends_at?->toDateString(), 'suspension_reason' => $company->suspension_reason, 'brand_name' => $company->brand_name,
                'brand_color' => $company->brand_color, 'hide_powered_by' => (bool) $company->hide_powered_by],
            'plan' => $plan === null ? null : ['name' => $plan->name, 'price' => (float) $plan->price, 'billing_cycle' => $plan->billing_cycle,
                'features' => collect(PlanFeatures::CATALOG)->map(fn (array $d, string $key): array => ['key' => $key, 'label' => $d[0], 'enabled' => PlanFeatures::enabled($companyId, $key)])->values()],
            'invoices' => DB::table('subscription_invoices')->where('company_id', $companyId)->orderByDesc('id')->limit(24)->get(['id', 'invoice_no', 'period_start', 'period_end', 'total_amount', 'addons_amount', 'status', 'due_date', 'paid_at']),
            'usage' => $this->lifecycle->trend($companyId, 30),
            'onboarding' => $this->services->onboarding($companyId),
            'addons' => DB::table('tenant_addons as t')->join('addons as a', 'a.id', '=', 't.addon_id')->where('t.company_id', $companyId)->get(['t.id', 'a.name', 'a.price', 't.quantity', 't.starts_on', 't.ends_on']),
            'deletion' => DB::table('tenant_deletion_requests')->where('company_id', $companyId)->orderByDesc('id')->first(['id', 'status', 'scheduled_for', 'confirmed_at']),
            'domains' => DB::table('custom_domains')->where('company_id', $companyId)->get(['id', 'domain', 'verification_token', 'verified_at']),
            'exports' => DB::table('tenant_data_exports')->where('company_id', $companyId)->orderByDesc('id')->limit(10)->get(['id', 'size', 'tables', 'created_at']),
        ]]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $company = Company::query()->findOrFail($this->scopeCompanyId($request));

        if (! in_array($company->tenant_status, ['trial', 'active'], true)) {
            throw ValidationException::withMessages(['company' => ['Only an active subscription can be set to cancel.']]);
        }

        $company->forceFill(['cancel_at_period_end' => true])->save();

        return response()->json(['message' => 'The subscription will end on '.($company->current_period_ends_at?->toDateString() ?? 'its period end').'. You can renew until then.']);
    }

    public function renew(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        Company::query()->findOrFail($this->scopeCompanyId($request))->forceFill(['cancel_at_period_end' => false])->save();

        return response()->json(['message' => 'The subscription will renew as usual.']);
    }

    public function export(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $export = $this->lifecycle->export($this->scopeCompanyId($request), Auth::id());

        return response()->json(['message' => 'Export ready', 'data' => ['id' => $export['id'], 'size' => $export['size'], 'tables' => $export['tables']]], 201);
    }

    public function downloadExport(Request $request, int $id): StreamedResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $row = DB::table('tenant_data_exports')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->first();
        abort_if($row === null || ! Storage::disk('local')->exists('tenant-exports/'.$row->path), 404);

        return Storage::disk('local')->download('tenant-exports/'.$row->path);
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $company = Company::query()->findOrFail($this->scopeCompanyId($request));
        $id = $this->lifecycle->requestDeletion($company, Auth::id());

        return response()->json(['message' => 'Deletion requested. Confirm it by typing the company code; nothing is removed for '.TenantLifecycle::DELETION_GRACE_DAYS.' days after that.', 'id' => $id], 201);
    }

    public function confirmDeletion(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $data = $request->validate(['code' => 'required|string|max:60']);
        $this->lifecycle->confirmDeletion(Company::query()->findOrFail($this->scopeCompanyId($request)), $data['code']);

        return response()->json(['message' => 'Confirmed. Your data will be deleted after the grace period unless you cancel.']);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        abort_unless($this->lifecycle->cancelDeletion(Company::query()->findOrFail($this->scopeCompanyId($request))), 404);

        return response()->json(['message' => 'Deletion cancelled.']);
    }

    public function addDomain(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $data = $request->validate(['domain' => 'required|string|max:190']);

        return response()->json(['data' => $this->services->addDomain($this->scopeCompanyId($request), $data['domain'])], 201);
    }

    public function verifyDomain(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $row = DB::table('custom_domains')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->first();
        abort_if($row === null, 404);

        $ok = $this->services->verifyDomain($row);

        return response()->json(['message' => $ok ? 'Domain verified.' : 'The TXT record _erp-verify.'.$row->domain.' does not hold the token yet (DNS can take time).', 'verified' => $ok]);
    }

    public function removeDomain(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        abort_if(DB::table('custom_domains')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete() === 0, 404);

        return response()->json(['message' => 'Domain removed']);
    }

    public function saveBranding(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/tenant-billing/manage');

        $data = $request->validate(['brand_name' => 'nullable|string|max:120', 'brand_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'hide_powered_by' => 'nullable|boolean']);
        DB::table('companies')->where('id', $this->scopeCompanyId($request))->update([
            'brand_name' => $data['brand_name'] ?? null, 'brand_color' => $data['brand_color'] ?? null, 'hide_powered_by' => (bool) ($data['hide_powered_by'] ?? false), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * Public: the branding to show on the sign-in page of a custom domain.
     */
    public function branding(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->services->brandingForHost($request->getHost())]);
    }

    // ------------------------------------------------------------------------------------------------ support tickets

    public function tickets(Request $request): JsonResponse
    {
        $user = $request->user();
        $staff = $user->hasRole('superadmin');

        $rows = DB::table('support_tickets as t')->join('companies as c', 'c.id', '=', 't.company_id')
            ->when(! $staff, fn ($q) => $q->where('t.company_id', $user->company_id))
            ->when($request->filled('status'), fn ($q) => $q->where('t.status', $request->input('status')))
            ->orderByDesc('t.id')->limit(200)->get(['t.id', 't.subject', 't.category', 't.priority', 't.status', 't.created_at', 'c.name as company']);

        return response()->json(['data' => $rows]);
    }

    public function openTicket(Request $request): JsonResponse
    {
        $data = $request->validate(['subject' => 'required|string|min:3|max:160', 'body' => 'required|string|min:3|max:5000', 'category' => 'nullable|string|max:30', 'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])]]);
        $user = $request->user();
        abort_if($user->company_id === null, 403);

        $id = DB::transaction(function () use ($data, $user): int {
            $id = DB::table('support_tickets')->insertGetId([
                'company_id' => $user->company_id, 'user_id' => $user->id, 'subject' => $data['subject'], 'category' => $data['category'] ?? 'general',
                'priority' => $data['priority'] ?? 'normal', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('support_ticket_messages')->insert(['support_ticket_id' => $id, 'user_id' => $user->id, 'is_staff' => false, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        foreach (DB::table('users')->whereIn('role_id', DB::table('roles')->where('name', 'superadmin')->pluck('id'))->pluck('id') as $staffId) {
            app(AppNotifier::class)->notify((int) $staffId, 'support_ticket', 'New support ticket: '.$data['subject'], null, '/support', true);
        }

        return response()->json(['message' => 'Ticket opened', 'id' => $id], 201);
    }

    public function ticket(Request $request, int $id): JsonResponse
    {
        $ticket = $this->findTicket($request, $id);

        return response()->json(['data' => (array) $ticket + ['messages' => DB::table('support_ticket_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.support_ticket_id', $id)->orderBy('m.id')->get(['m.id', 'm.is_staff', 'm.body', 'm.created_at', 'u.email'])]]);
    }

    public function replyTicket(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|min:1|max:5000', 'close' => 'nullable|boolean']);
        $ticket = $this->findTicket($request, $id);
        $staff = (bool) $request->user()->hasRole('superadmin');

        DB::table('support_ticket_messages')->insert(['support_ticket_id' => $id, 'user_id' => $request->user()->id, 'is_staff' => $staff, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now()]);
        DB::table('support_tickets')->where('id', $id)->update([
            'status' => ! empty($data['close']) ? 'closed' : ($staff ? 'answered' : 'open'), 'closed_at' => ! empty($data['close']) ? now() : null, 'updated_at' => now(),
        ]);

        if ($staff) {
            app(AppNotifier::class)->notify((int) $ticket->user_id, 'support_reply', 'Support replied: '.$ticket->subject, null, '/support', true);
        }

        return response()->json(['message' => 'Saved']);
    }

    private function findTicket(Request $request, int $id): object
    {
        $user = $request->user();
        $ticket = DB::table('support_tickets')->where('id', $id)->when(! $user->hasRole('superadmin'), fn ($q) => $q->where('company_id', $user->company_id))->first();
        abort_if($ticket === null, 404);

        return $ticket;
    }
}
