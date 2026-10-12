<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Manufacturing\BomService;
use App\Services\Manufacturing\ManufacturingSettings;
use App\Services\Manufacturing\ProductionPlanner;
use App\Services\Manufacturing\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing: bills of materials (versioned, multi-level), the master production schedule, work orders and what is done on them.
 * Reading needs `/manufacturing`; BOMs, plans and settings `/manufacturing/manage`; work orders and shop-floor entries
 * `/manufacturing/produce`; activating a BOM version and releasing a work order `/manufacturing/approve` (a separate permission, and
 * covered by maker-checker and the Approval Center). Company comes from the signed-in user; every id is looked up inside it.
 */
class ManufacturingController extends Controller
{
    use ScopesToCompany;

    public function __construct(
        private readonly BomService $boms,
        private readonly WorkOrderService $orders,
        private readonly ProductionPlanner $planner,
        private readonly ManufacturingSettings $settings,
    ) {}

    /**
     * Products of the company to pick from (name search), each with its variations.
     */
    public function products(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');
        $companyId = $this->scopeCompanyId($request);
        $search = trim((string) $request->query('search', ''));
        $products = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")))
            ->orderBy('name')->limit(200)->get(['id', 'name', 'sku']);
        $variations = DB::table('product_details')->whereIn('product_id', $products->pluck('id'))->get(['id', 'product_id', 'variation_name'])->groupBy('product_id');

        return response()->json(['data' => $products->map(fn (object $row): array => [
            'id' => (int) $row->id, 'name' => $row->name, 'sku' => $row->sku,
            'variations' => ($variations[$row->id] ?? collect())->map(fn (object $v): array => ['id' => (int) $v->id, 'name' => $v->variation_name])->values()->all(),
        ])->all()]);
    }

    // ---------------------------------------------------------------- settings

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');

        return response()->json(['data' => $this->settings->all($this->scopeCompanyId($request)), 'account_slots' => ManufacturingSettings::ACCOUNT_SLOTS]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->settings->save($companyId, $request->except(['company_id']))]);
    }

    // ---------------------------------------------------------------- bills of materials

    public function boms(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');
        $companyId = $this->scopeCompanyId($request);
        $status = (string) $request->query('status', '');

        return response()->json(['data' => DB::table('boms as b')->join('products as p', 'p.id', '=', 'b.product_id')->where('b.company_id', $companyId)
            ->when($status !== '', fn ($q) => $q->where('b.status', $status))->orderBy('p.name')->orderByDesc('b.version')->limit(500)
            ->get(['b.id', 'b.product_id', 'p.name as product', 'b.code', 'b.name', 'b.version', 'b.status', 'b.output_qty', 'b.valid_from', 'b.valid_to'])]);
    }

    public function showBom(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');
        $companyId = $this->scopeCompanyId($request);
        $bom = $this->boms->find($companyId, $id);
        $quantity = max(0.0001, (float) $request->query('quantity', (float) $bom->output_qty));

        return response()->json(['data' => (array) $bom + $this->boms->detail($companyId, $id) + [
            'cost' => $this->boms->cost($companyId, $id, $quantity), 'explosion' => $this->boms->explode($companyId, (int) $bom->product_id, $quantity),
        ]]);
    }

    public function saveBom(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'product_id' => $id === null ? 'required|integer' : 'nullable|integer', 'variation_id' => 'nullable|integer', 'code' => 'nullable|string|max:30', 'name' => 'nullable|string|max:150', 'output_qty' => 'nullable|numeric|gt:0',
            'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from', 'notes' => 'nullable|string|max:300',
            'items' => 'required|array|min:1|max:200', 'outputs' => 'nullable|array|max:20', 'operations' => 'nullable|array|max:40', 'company_id' => 'nullable|integer',
        ]);

        if ($id === null) {
            $id = $this->boms->create($companyId, $this->visibleBranch(), $data, Auth::id());
        } else {
            $this->boms->update($companyId, $id, $data);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function newBomVersion(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');

        return response()->json(['message' => 'New version made', 'data' => ['id' => $this->boms->newVersion($this->scopeCompanyId($request), $id, Auth::id())]], 201);
    }

    public function activateBom(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/approve');
        $this->boms->activate($this->scopeCompanyId($request), $id, Auth::id());

        return response()->json(['message' => 'Version activated']);
    }

    public function archiveBom(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');
        $this->boms->archive($this->scopeCompanyId($request), $id);

        return response()->json(['message' => 'Version archived']);
    }

    public function deleteBom(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');
        $this->boms->delete($this->scopeCompanyId($request), $id);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- master production schedule

    public function plans(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = (string) $request->query('from', now()->startOfWeek()->toDateString());
        $to = (string) $request->query('to', now()->addWeeks(8)->toDateString());

        return response()->json(['data' => $this->planner->grid($companyId, $this->visibleBranch(), $from, $to)] + $this->planner->requirements($companyId, $this->visibleBranch(), $from, $to));
    }

    public function savePlan(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['product_id' => 'required|integer', 'period_start' => 'required|date_format:Y-m-d', 'planned_qty' => 'required|numeric|min:0', 'note' => 'nullable|string|max:200', 'company_id' => 'nullable|integer']);
        $id = $this->planner->save($companyId, $this->visibleBranch(), (int) $data['product_id'], $data['period_start'], (float) $data['planned_qty'], $data['note'] ?? null, Auth::id());

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function deletePlan(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/manage');
        $affected = DB::table('production_plans')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('branch_id', $this->visibleBranch()))->delete();
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- work orders

    public function workOrders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');
        $companyId = $this->scopeCompanyId($request);
        $status = (string) $request->query('status', '');

        return response()->json(['data' => DB::table('work_orders as w')->join('products as p', 'p.id', '=', 'w.product_id')->where('w.company_id', $companyId)
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('w.branch_id', $this->visibleBranch()))
            ->when($status !== '', fn ($q) => $q->where('w.status', $status))->orderByDesc('w.id')->limit(500)
            ->get(['w.id', 'w.wo_no', 'p.name as product', 'w.status', 'w.bom_version', 'w.planned_qty', 'w.produced_qty', 'w.scrap_qty', 'w.planned_start', 'w.planned_end', 'w.fg_unit_cost'])]);
    }

    public function showWorkOrder(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing');
        $companyId = $this->scopeCompanyId($request);
        $order = $this->order($companyId, $id);
        $names = DB::table('products')->where('company_id', $companyId)->pluck('name', 'id');
        $named = fn ($rows) => $rows->map(fn (object $row): array => (array) $row + ['name' => (string) ($names[(int) $row->product_id] ?? '')])->all();

        return response()->json(['data' => (array) $order + [
            'product' => (string) ($names[(int) $order->product_id] ?? ''), 'wip_value' => $this->orders->pool($order),
            'materials' => $named(DB::table('work_order_materials')->where('work_order_id', $id)->orderBy('id')->get()),
            'issues' => $named(DB::table('work_order_issues')->where('work_order_id', $id)->orderBy('id')->get()),
            'receipts' => $named(DB::table('work_order_receipts')->where('work_order_id', $id)->orderBy('id')->get()),
            'scraps' => DB::table('work_order_scraps')->where('work_order_id', $id)->orderBy('id')->get(),
            'labor' => DB::table('work_order_labor')->where('work_order_id', $id)->orderBy('id')->get(),
            'checks' => DB::table('work_order_checks')->where('work_order_id', $id)->orderBy('id')->get(),
        ]]);
    }

    public function createWorkOrder(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'product_id' => 'required|integer', 'planned_qty' => 'required|numeric|gt:0', 'planned_start' => 'nullable|date_format:Y-m-d', 'planned_end' => 'nullable|date_format:Y-m-d',
            'warehouse_id' => 'nullable|integer', 'notes' => 'nullable|string|max:300', 'branch_id' => 'nullable|integer', 'company_id' => 'nullable|integer',
        ]);
        $branchId = $this->scopeBranchId($request, $companyId);

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Choose the branch that makes this.']]);
        }

        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;

        if ($warehouseId !== null && ! DB::table('warehouses')->where('company_id', $companyId)->where('id', $warehouseId)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Choose a warehouse of this company.']]);
        }

        $id = $this->orders->create($companyId, $branchId, (int) $data['product_id'], (float) $data['planned_qty'], $data['planned_start'] ?? null, $data['planned_end'] ?? null, $warehouseId, $data['notes'] ?? null, Auth::id());

        return response()->json(['message' => 'Work order made', 'data' => ['id' => $id]], 201);
    }

    public function releaseWorkOrder(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/approve');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $this->orders->release($companyId, $id, Auth::id());

        return response()->json(['message' => 'Work order released']);
    }

    public function cancelWorkOrder(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $this->orders->cancel($companyId, $id);

        return response()->json(['message' => 'Work order cancelled']);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $data = $request->validate([
            'lines' => 'required|array|min:1|max:100', 'lines.*.product_id' => 'required|integer', 'lines.*.variation_id' => 'nullable|integer', 'lines.*.quantity' => 'required|numeric|gt:0',
            'date' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer',
        ]);
        $this->orders->issue($companyId, $id, $data['lines'], $data['date'] ?? now()->toDateString(), Auth::id());

        return response()->json(['message' => 'Materials issued']);
    }

    public function labor(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $data = $request->validate([
            'operation' => 'required|string|max:100', 'hours' => 'required|numeric|gt:0', 'labor_rate' => 'required|numeric|min:0', 'overhead_rate' => 'nullable|numeric|min:0',
            'employee_id' => 'nullable|integer', 'date' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer',
        ]);
        $this->orders->recordLabor($companyId, $id, $data['operation'], (float) $data['hours'], (float) $data['labor_rate'], (float) ($data['overhead_rate'] ?? 0), isset($data['employee_id']) ? (int) $data['employee_id'] : null, $data['date'] ?? now()->toDateString(), Auth::id());

        return response()->json(['message' => 'Labour recorded']);
    }

    public function standardLabor(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $this->orders->applyStandardLabor($companyId, $id, (string) $request->input('date', now()->toDateString()), Auth::id());

        return response()->json(['message' => 'Standard labour applied']);
    }

    public function scrap(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $data = $request->validate(['quantity' => 'required|numeric|gt:0', 'is_normal' => 'nullable|boolean', 'reason' => 'nullable|string|max:200', 'date' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer']);
        $this->orders->recordScrap($companyId, $id, (float) $data['quantity'], (bool) ($data['is_normal'] ?? true), $data['reason'] ?? null, $data['date'] ?? now()->toDateString(), Auth::id());

        return response()->json(['message' => 'Scrap recorded']);
    }

    public function check(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $data = $request->validate([
            'check_name' => 'required|string|max:100', 'result' => 'required|in:pass,fail', 'qty_inspected' => 'required|numeric|min:0', 'qty_failed' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:200', 'scrap_failed' => 'nullable|boolean', 'date' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer',
        ]);
        $this->orders->recordCheck($companyId, $id, $data['check_name'], $data['result'], (float) $data['qty_inspected'], (float) ($data['qty_failed'] ?? 0), $data['note'] ?? null, (bool) ($data['scrap_failed'] ?? false), $data['date'] ?? now()->toDateString(), Auth::id());

        return response()->json(['message' => 'Check recorded']);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $data = $request->validate(['quantity' => 'required|numeric|gt:0', 'date' => 'nullable|date_format:Y-m-d', 'backflush' => 'nullable|boolean', 'company_id' => 'nullable|integer']);
        $result = $this->orders->receive($companyId, $id, (float) $data['quantity'], $data['date'] ?? now()->toDateString(), (bool) ($data['backflush'] ?? false), Auth::id());

        return response()->json(['message' => 'Finished goods received', 'data' => $result]);
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/manufacturing/produce');
        $companyId = $this->scopeCompanyId($request);
        $this->order($companyId, $id);
        $variance = $this->orders->close($companyId, $id, (string) $request->input('date', now()->toDateString()));

        return response()->json(['message' => 'Work order closed', 'data' => ['variance' => $variance]]);
    }

    // ---------------------------------------------------------------- helpers

    private function visibleBranch(): ?int
    {
        $user = Auth::user();

        return $user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin') ? (int) $user->branch_id : null;
    }

    private function order(int $companyId, int $id): object
    {
        $order = $this->orders->find($companyId, $id);

        if ($this->visibleBranch() !== null && (int) $order->branch_id !== $this->visibleBranch()) {
            abort(404);
        }

        return $order;
    }
}
